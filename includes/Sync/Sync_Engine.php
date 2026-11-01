<?php
/**
 * Translation pipeline with translation memory and delta sync.
 *
 * Source → segments → TM lookup (unchanged segments reused) → AI for new/changed segments only →
 * TM write → reassemble → provider write (translation post updated in place, or TranslatePress dictionary).
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Sync;

use Dev3\AiTranslatorLocalizer\Content\Document;
use Dev3\AiTranslatorLocalizer\Content\Post_Document;
use Dev3\AiTranslatorLocalizer\Content\Segmenter;
use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;
use Dev3\AiTranslatorLocalizer\Data\Translation_Memory;
use Dev3\AiTranslatorLocalizer\Providers\Base_Provider;
use Dev3\AiTranslatorLocalizer\Providers\Providers;
use Dev3\AiTranslatorLocalizer\Translation\Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Sync one post into one language.
 */
class Sync_Engine {

	const META = '_dev3tl_sync';

	/**
	 * Translator (tests may inject).
	 *
	 * @var Translator|null
	 */
	public static $translator = null;

	/**
	 * Translates or delta-syncs a post.
	 *
	 * @param int    $post_id Source post.
	 * @param string $lang    Target language code.
	 * @param array  $opts {
	 *     Options.
	 *
	 *     @type bool   $force            Re-translate machine segments (human/approved ones are kept).
	 *     @type bool   $no_ai            Only use the translation memory.
	 *     @type bool   $overwrite_manual Overwrite a translation that was edited outside the plugin.
	 *     @type string $context          Usage log context.
	 * }
	 * @return array|\WP_Error Stats { status, total, reused, sent, failed, review, tokens, requests, target_id, message }.
	 */
	public static function sync( $post_id, $lang, array $opts = array() ) {
		$opts     = array_merge(
			array(
				'force'            => false,
				'no_ai'            => false,
				'overwrite_manual' => false,
				'context'          => 'admin',
			),
			$opts
		);
		$provider = Providers::active();
		if ( ! $provider ) {
			return new \WP_Error( 'dev3tl_no_provider', __( 'No supported multilingual plugin (WPML, Polylang or TranslatePress) is active.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		$post = get_post( (int) $post_id );
		if ( ! $post || 'revision' === $post->post_type || ! $provider->is_translatable_post_type( $post->post_type ) ) {
			return new \WP_Error( 'dev3tl_bad_post', __( 'This post cannot be translated (missing, or its post type is not translatable).', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		if ( ! $provider->language( $lang ) ) {
			return new \WP_Error( 'dev3tl_bad_lang', __( 'Unknown target language.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		$source_lang = $provider->post_language( $post->ID );
		$source_lang = '' === $source_lang ? $provider->default_language() : $source_lang;
		if ( $source_lang === $lang ) {
			return new \WP_Error( 'dev3tl_same_lang', __( 'The target language is the language of the source post.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		$source_locale = $provider->locale( $source_lang );
		$target_locale = $provider->locale( $lang );

		if ( 'strings' === $provider->mode() ) {
			return self::sync_strings( $post, $provider, $lang, $source_locale, $target_locale, $opts );
		}

		$target_id = $provider->get_translation( $post->ID, $lang );
		if ( $target_id && ! $opts['overwrite_manual'] && Settings_Store::get( 'protect_manual_edits', true ) && Post_Writer::edited_manually( $target_id ) ) {
			$stats = array(
				'status'    => 'skipped',
				'message'   => __( 'The translation was edited manually; it was not overwritten.', 'dev3-ai-translator-localizer' ),
				'target_id' => $target_id,
			);
			return $stats + self::empty_stats();
		}

		$doc      = Post_Document::build( $post, $provider, $lang );
		$resolved = self::resolve( $doc, $source_locale, $target_locale, $opts );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$values    = $doc->render( $resolved['translations'] );
		$target_id = Post_Writer::write( $post, $provider, $lang, $target_id, $values );
		if ( is_wp_error( $target_id ) ) {
			return $target_id;
		}
		$stats = $resolved['stats'] + array(
			'status'    => $resolved['stats']['failed'] ? 'partial' : 'done',
			'target_id' => (int) $target_id,
			'message'   => '',
		);
		self::record( $post->ID, $lang, $stats );
		return $stats;
	}

	/**
	 * TranslatePress flow.
	 *
	 * @param \WP_Post      $post          Post.
	 * @param Base_Provider $provider      Provider.
	 * @param string        $lang          Language.
	 * @param string        $source_locale Source locale.
	 * @param string        $target_locale Target locale.
	 * @param array         $opts          Options.
	 * @return array|\WP_Error
	 */
	private static function sync_strings( $post, Base_Provider $provider, $lang, $source_locale, $target_locale, array $opts ) {
		$doc      = self::strings_document( $post, $provider, $lang );
		$resolved = self::resolve( $doc, $source_locale, $target_locale, $opts );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$values = $doc->render( $resolved['translations'] );
		$map    = array();
		foreach ( $doc->fields as $field => $data ) {
			if ( ! isset( $values[ $field ] ) || $values[ $field ] === $data['original'] ) {
				continue;
			}
			$human = false;
			foreach ( $doc->ids_for( $field ) as $id ) {
				$human = $human || in_array( isset( $resolved['status'][ $id ] ) ? $resolved['status'][ $id ] : '', array( 'human', 'approved' ), true );
			}
			$map[ $data['original'] ] = array(
				'translation' => $values[ $field ],
				'human'       => $human,
			);
		}
		$provider->save_strings( $map, $lang );
		$stats = $resolved['stats'] + array(
			'status'    => $resolved['stats']['failed'] ? 'partial' : 'done',
			'target_id' => (int) $post->ID,
			'message'   => '',
		);
		self::record( $post->ID, $lang, $stats );
		return $stats;
	}

	/**
	 * Document of TranslatePress strings for a post (one field per dictionary string).
	 *
	 * @param \WP_Post      $post     Post.
	 * @param Base_Provider $provider TranslatePress provider.
	 * @param string        $lang     Language.
	 * @return Document
	 */
	public static function strings_document( $post, Base_Provider $provider, $lang ) {
		$doc = new Document();
		foreach ( $provider->strings_for_post( $post, $lang ) as $i => $string ) {
			$field = 'tp:' . ( $i + 1 );
			$extra = array(
				'label' => self::string_label( $string['type'] ),
				'tp'    => true,
			);
			if ( 'text' === $string['kind'] ) {
				Segmenter::add_text( $doc, $field, $string['original'], $string['type'], $extra );
			} else {
				Segmenter::add_html( $doc, $field, $string['original'], $extra );
			}
		}
		return $doc;
	}

	/**
	 * Readable label for a TranslatePress node type.
	 *
	 * @param string $type Node type.
	 * @return string
	 */
	private static function string_label( $type ) {
		$labels = array(
			'text'        => __( 'Text', 'dev3-ai-translator-localizer' ),
			'block'       => __( 'Paragraph with markup', 'dev3-ai-translator-localizer' ),
			'image_alt'   => __( 'Image alt text', 'dev3-ai-translator-localizer' ),
			'title'       => __( 'Title attribute', 'dev3-ai-translator-localizer' ),
			'placeholder' => __( 'Placeholder', 'dev3-ai-translator-localizer' ),
			'submit'      => __( 'Button', 'dev3-ai-translator-localizer' ),
			'button'      => __( 'Button', 'dev3-ai-translator-localizer' ),
			'meta_desc'   => __( 'Meta description', 'dev3-ai-translator-localizer' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : (string) $type;
	}

	/**
	 * TM lookup + AI for the rest.
	 *
	 * @param Document $doc           Document.
	 * @param string   $source_locale Source locale.
	 * @param string   $target_locale Target locale.
	 * @param array    $opts          Options.
	 * @return array|\WP_Error { translations, status, stats }
	 */
	public static function resolve( Document $doc, $source_locale, $target_locale, array $opts ) {
		$opts         = array_merge(
			array(
				'force'   => false,
				'no_ai'   => false,
				'context' => 'admin',
			),
			$opts
		);
		$hits         = Translation_Memory::lookup( array_column( $doc->segments, 'hash' ), $source_locale, $target_locale );
		$translations = array();
		$status       = array();
		$send         = array();
		$reused_ids   = array();
		$unique_send  = array();
		foreach ( $doc->segments as $id => $segment ) {
			$hit = isset( $hits[ $segment['hash'] ] ) ? $hits[ $segment['hash'] ] : null;
			if ( $hit && ( ! $opts['force'] || in_array( $hit['status'], array( 'human', 'approved' ), true ) ) ) {
				$translations[ $id ] = $hit['translation'];
				$status[ $id ]       = $hit['status'];
				$reused_ids[]        = (int) $hit['id'];
				continue;
			}
			// Identical source text inside one document is sent once.
			if ( ! isset( $unique_send[ $segment['hash'] ] ) ) {
				$unique_send[ $segment['hash'] ] = $id;
				$send[ $id ]                     = $segment;
			}
		}
		$stats = array(
			'total'    => count( $doc->segments ),
			'reused'   => count( $reused_ids ),
			'sent'     => 0,
			'failed'   => 0,
			'review'   => 0,
			'tokens'   => 0,
			'requests' => 0,
		);
		if ( $send && ! $opts['no_ai'] ) {
			$translator = self::$translator ? self::$translator : new Translator( $opts['context'] );
			$result     = $translator->translate( $send, $source_locale, $target_locale );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( $result['translations'] as $id => $text ) {
				$state = isset( $result['status'][ $id ] ) ? $result['status'][ $id ] : 'machine';
				Translation_Memory::save( $doc->segments[ $id ]['source'], $text, $source_locale, $target_locale, $state, $doc->segments[ $id ]['context'] );
			}
			// Re-read so human entries that raced in win, and duplicates inside the document get their text.
			$fresh = Translation_Memory::lookup( array_keys( $unique_send ), $source_locale, $target_locale );
			foreach ( $doc->segments as $id => $segment ) {
				if ( isset( $translations[ $id ] ) || ! isset( $fresh[ $segment['hash'] ] ) ) {
					continue;
				}
				$translations[ $id ] = $fresh[ $segment['hash'] ]['translation'];
				$status[ $id ]       = $fresh[ $segment['hash'] ]['status'];
			}
			$stats['sent']     = count( $send );
			$stats['failed']   = count( $result['failed'] );
			$stats['review']   = count( array_keys( $result['status'], 'review', true ) );
			$stats['tokens']   = (int) $result['tokens'];
			$stats['requests'] = (int) $result['requests'];
		} elseif ( $send ) {
			$stats['failed'] = count( $send );
		}
		Translation_Memory::touch( $reused_ids );
		return array(
			'translations' => $translations,
			'status'       => $status,
			'stats'        => $stats,
		);
	}

	/**
	 * Stores per-language stats on the source post.
	 *
	 * @param int    $post_id Post.
	 * @param string $lang    Language.
	 * @param array  $stats   Stats.
	 */
	public static function record( $post_id, $lang, array $stats ) {
		$all          = self::stats( $post_id );
		$all[ $lang ] = array_merge(
			array_intersect_key(
				$stats,
				self::empty_stats() + array(
					'status'    => '',
					'target_id' => 0,
				)
			),
			array( 'at' => time() )
		);
		update_post_meta( $post_id, self::META, $all );
	}

	/**
	 * Stats per language for a source post.
	 *
	 * @param int $post_id Post.
	 * @return array
	 */
	public static function stats( $post_id ) {
		$all = get_post_meta( $post_id, self::META, true );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Zeroed stats.
	 *
	 * @return array
	 */
	public static function empty_stats() {
		return array(
			'total'    => 0,
			'reused'   => 0,
			'sent'     => 0,
			'failed'   => 0,
			'review'   => 0,
			'tokens'   => 0,
			'requests' => 0,
		);
	}
}
