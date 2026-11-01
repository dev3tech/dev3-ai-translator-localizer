<?php
/**
 * Batched AI translation with structured output, glossary enforcement and one corrective retry.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Translation;

use Dev3\AiTranslatorLocalizer\Content\Renderer;
use Dev3\AiTranslatorLocalizer\Core3\Api\Ai;
use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;
use Dev3\AiTranslatorLocalizer\Data\Glossary;
use Dev3\AiTranslatorLocalizer\Localization\Locale_Profiles;
use Dev3\AiTranslatorLocalizer\Localization\Unit_Converter;

defined( 'ABSPATH' ) || exit;

/**
 * Sends segments to the model in batches with stable short ids.
 */
class Translator {

	/**
	 * Usage context for the log (admin, cron…).
	 *
	 * @var string
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param string $context Usage context.
	 */
	public function __construct( $context = 'admin' ) {
		$this->context = (string) $context;
	}

	/**
	 * Translates segments.
	 *
	 * @param array  $segments      Segments { id, kind, source, context }.
	 * @param string $source_locale Source locale.
	 * @param string $target_locale Target locale.
	 * @return array|\WP_Error { translations: id=>text, status: id=>machine|review, problems: id=>[], tokens, requests, failed: id[] }
	 */
	public function translate( array $segments, $source_locale, $target_locale ) {
		$result = array(
			'translations' => array(),
			'status'       => array(),
			'problems'     => array(),
			'tokens'       => 0,
			'requests'     => 0,
			'failed'       => array(),
		);
		if ( ! $segments ) {
			return $result;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$profile = Locale_Profiles::get( $target_locale );
		foreach ( $this->batches( array_values( $segments ) ) as $batch ) {
			$done = $this->batch( $batch, $source_locale, $target_locale, $profile );
			if ( is_wp_error( $done ) ) {
				if ( ! $result['translations'] ) {
					return $done;
				}
				foreach ( $batch as $segment ) {
					$result['failed'][] = $segment['id'];
				}
				$result['error'] = $done;
				continue;
			}
			foreach ( array( 'translations', 'status', 'problems' ) as $key ) {
				$result[ $key ] = array_merge( $result[ $key ], $done[ $key ] );
			}
			$result['failed']    = array_merge( $result['failed'], $done['failed'] );
			$result['tokens']   += $done['tokens'];
			$result['requests'] += $done['requests'];
		}
		return $result;
	}

	/**
	 * Splits segments by count and characters.
	 *
	 * @param array $segments Segments.
	 * @return array[]
	 */
	public function batches( array $segments ) {
		$max_items = max( 1, (int) Settings_Store::get( 'batch_size', 40 ) );
		$max_chars = max( 500, (int) Settings_Store::get( 'batch_chars', 6000 ) );
		$batches   = array();
		$current   = array();
		$chars     = 0;
		foreach ( $segments as $segment ) {
			$len = mb_strlen( $segment['source'] );
			if ( $current && ( count( $current ) >= $max_items || $chars + $len > $max_chars ) ) {
				$batches[] = $current;
				$current   = array();
				$chars     = 0;
			}
			$current[] = $segment;
			$chars    += $len;
		}
		if ( $current ) {
			$batches[] = $current;
		}
		return $batches;
	}

	/**
	 * One batch + corrective retry.
	 *
	 * @param array  $batch         Segments.
	 * @param string $source_locale Source locale.
	 * @param string $target_locale Target locale.
	 * @param array  $profile       Profile.
	 * @return array|\WP_Error
	 */
	private function batch( array $batch, $source_locale, $target_locale, array $profile ) {
		$by_short = array();
		$items    = array();
		$plain    = array();
		foreach ( $batch as $i => $segment ) {
			$short              = 's' . ( $i + 1 );
			$by_short[ $short ] = $segment;
			$plain[]            = self::strip( $segment['source'] );
			$item               = array(
				'id'   => $short,
				'text' => $segment['source'],
			);
			if ( ! empty( $segment['context'] ) ) {
				$item['context'] = $segment['context'];
			}
			$conversions = array_merge(
				Unit_Converter::hints( self::strip( $segment['source'] ), $profile['units'] ),
				Unit_Converter::currency_hints( self::strip( $segment['source'] ), $profile )
			);
			if ( $conversions ) {
				$item['conversions'] = $conversions;
			}
			$items[ $short ] = $item;
		}
		$terms  = Glossary::relevant( $plain, $target_locale );
		$system = Prompt_Builder::system( $source_locale, $target_locale, $profile, $terms );

		$out = array(
			'translations' => array(),
			'status'       => array(),
			'problems'     => array(),
			'tokens'       => 0,
			'requests'     => 0,
			'failed'       => array(),
		);

		$answer = $this->call( $system, $items, array() );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$out['tokens']   += $answer['tokens'];
		$out['requests'] += 1;
		$check            = $this->check( $answer['map'], $by_short, $target_locale, $terms );

		if ( $check['problems'] ) {
			$retry_items = array_intersect_key( $items, $check['problems'] );
			$retry       = $this->call( $system, $retry_items, $check['problems'] );
			if ( ! is_wp_error( $retry ) ) {
				$out['tokens']   += $retry['tokens'];
				$out['requests'] += 1;
				$second           = $this->check( $retry['map'], array_intersect_key( $by_short, $check['problems'] ), $target_locale, $terms );
				foreach ( array_keys( $check['problems'] ) as $short ) {
					if ( isset( $second['ok'][ $short ] ) ) {
						$check['ok'][ $short ] = $second['ok'][ $short ];
						unset( $check['problems'][ $short ] );
					} elseif ( isset( $retry['map'][ $short ] ) ) {
						$answer['map'][ $short ]     = $retry['map'][ $short ];
						$check['problems'][ $short ] = $second['problems'][ $short ];
					}
				}
			}
		}

		foreach ( $check['ok'] as $short => $text ) {
			$id                         = $by_short[ $short ]['id'];
			$out['translations'][ $id ] = $text;
			$out['status'][ $id ]       = 'machine';
		}
		foreach ( $check['problems'] as $short => $problems ) {
			$id = $by_short[ $short ]['id'];
			if ( ! isset( $answer['map'][ $short ] ) ) {
				$out['failed'][] = $id;
				continue;
			}
			// Keep the best attempt, repaired so markup stays valid, and flag it for review.
			$out['translations'][ $id ] = self::repair( $answer['map'][ $short ], $by_short[ $short ]['source'] );
			$out['status'][ $id ]       = 'review';
			$out['problems'][ $id ]     = $problems;
		}
		return $out;
	}

	/**
	 * Calls the model.
	 *
	 * @param string $system System instruction.
	 * @param array  $items  Items keyed by short id.
	 * @param array  $fixes  Problems from the previous attempt.
	 * @return array|\WP_Error { map: short=>translation, tokens }
	 */
	private function call( $system, array $items, array $fixes ) {
		$chars = 0;
		$max   = 200;
		foreach ( $items as $item ) {
			$len    = mb_strlen( $item['text'] );
			$chars += $len;
			$max    = max( $max, $len * 4 + 100 );
		}
		$budget = (int) Settings_Store::get( 'thinking_budget', 1024 );
		$res    = Ai::run(
			'translate',
			array(
				'model'           => (string) Settings_Store::get( 'translation_model', 'pro' ),
				'system'          => $system,
				'prompt'          => Prompt_Builder::user( $items, $fixes ),
				'schema'          => Prompt_Builder::schema( count( $items ), min( 20000, $max ) ),
				'temperature'     => 0.2,
				'thinking_budget' => $budget,
				'max_tokens'      => (int) min( 65536, max( 4096, $chars * 2 + $budget + 1024 ) ),
				'timeout'         => (int) Settings_Store::get( 'request_timeout', 150 ),
			),
			$this->context
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$map  = array();
		$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$data = $data['items'];
		}
		foreach ( $data as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['translation'] ) && isset( $items[ (string) $row['id'] ] ) ) {
				$map[ (string) $row['id'] ] = (string) $row['translation'];
			}
		}
		return array(
			'map'    => $map,
			'tokens' => isset( $res['usage']['total'] ) ? (int) $res['usage']['total'] : 0,
		);
	}

	/**
	 * Validates answers.
	 *
	 * @param array  $map           short => translation.
	 * @param array  $by_short      short => segment.
	 * @param string $target_locale Locale.
	 * @param array  $terms         Glossary terms.
	 * @return array { ok: short=>text, problems: short=>[] }
	 */
	public function check( array $map, array $by_short, $target_locale, array $terms ) {
		$ok       = array();
		$problems = array();
		foreach ( $by_short as $short => $segment ) {
			if ( ! isset( $map[ $short ] ) || '' === trim( $map[ $short ] ) ) {
				$problems[ $short ] = array( __( 'missing translation', 'dev3-ai-translator-localizer' ) );
				continue;
			}
			$text   = $map[ $short ];
			$issues = array();
			if ( 'attr' !== $segment['kind'] && Renderer::placeholders( $segment['source'] ) !== Renderer::placeholders( $text ) ) {
				/* translators: %s: list of placeholders */
				$issues[] = sprintf( __( 'placeholders must be exactly %s', 'dev3-ai-translator-localizer' ), implode( ' ', Renderer::placeholders( $segment['source'] ) ) );
			}
			$issues = array_merge( $issues, Glossary::violations( self::strip( $segment['source'] ), self::strip( $text ), $target_locale, $terms ) );
			if ( $issues ) {
				$problems[ $short ] = $issues;
			} else {
				$ok[ $short ] = $text;
			}
		}
		return array(
			'ok'       => $ok,
			'problems' => $problems,
		);
	}

	/**
	 * Removes placeholders (for glossary checks and unit detection).
	 *
	 * @param string $text Masked text.
	 * @return string
	 */
	public static function strip( $text ) {
		return (string) preg_replace( '/<\/?\d+\/?>/', '', (string) $text );
	}

	/**
	 * Makes a translation's placeholders consistent with the source so the rendered HTML stays valid.
	 *
	 * @param string $translation Masked translation.
	 * @param string $source      Masked source.
	 * @return string
	 */
	public static function repair( $translation, $source ) {
		preg_match_all( Renderer::PLACEHOLDER, $source, $sm, PREG_SET_ORDER );
		$want = array();
		foreach ( $sm as $m ) {
			$want[ $m[0] ] = true;
		}
		// Drop unknown or duplicate placeholders.
		$seen        = array();
		$translation = preg_replace_callback(
			Renderer::PLACEHOLDER,
			static function ( $m ) use ( $want, &$seen ) {
				if ( ! isset( $want[ $m[0] ] ) || isset( $seen[ $m[0] ] ) ) {
					return '';
				}
				$seen[ $m[0] ] = true;
				return $m[0];
			},
			(string) $translation
		);
		foreach ( $sm as $m ) {
			$n = $m[2];
			if ( '/' === $m[3] && ! isset( $seen[ $m[0] ] ) ) {
				$translation .= $m[0];
			}
			if ( '' === $m[1] && '' === $m[3] ) {
				$open  = isset( $seen[ '<' . $n . '>' ] );
				$close = isset( $seen[ '</' . $n . '>' ] );
				if ( $open && ! $close ) {
					$translation .= '</' . $n . '>';
				} elseif ( ! $open && $close ) {
					$translation = str_replace( '</' . $n . '>', '', $translation );
				}
				if ( $open && $close && strpos( $translation, '</' . $n . '>' ) < strpos( $translation, '<' . $n . '>' ) ) {
					$translation = str_replace( array( '<' . $n . '>', '</' . $n . '>' ), '', $translation );
				}
			}
		}
		return $translation;
	}
}
