<?php
/**
 * Cost estimate for a bulk job: segments, characters, translation-memory coverage, tokens, approximate cost.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Queue;

use Dev3\AiTranslatorLocalizer\Content\Post_Document;
use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;
use Dev3\AiTranslatorLocalizer\Data\Translation_Memory;
use Dev3\AiTranslatorLocalizer\Providers\Providers;
use Dev3\AiTranslatorLocalizer\Sync\Sync_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Samples up to 150 posts and extrapolates.
 */
class Estimator {

	const SAMPLE = 150;

	/**
	 * USD per million tokens (input, output) — public list prices, filterable.
	 *
	 * @return array model prefix => [ input, output ]
	 */
	public static function prices() {
		/**
		 * Filters model prices used for estimates (USD per 1M tokens).
		 *
		 * @param array $prices prefix => [ input, output ].
		 */
		return (array) apply_filters(
			'dev3tl_model_prices',
			array(
				'gemini-2.5-pro'        => array( 1.25, 10.0 ),
				'gemini-2.5-flash-lite' => array( 0.10, 0.40 ),
				'gemini-2.5-flash'      => array( 0.30, 2.50 ),
				'gemini-3'              => array( 2.0, 12.0 ),
			)
		);
	}

	/**
	 * Resolved model id for translation.
	 *
	 * @return string
	 */
	public static function model() {
		$model = (string) Settings_Store::get( 'translation_model', 'pro' );
		if ( 'pro' === $model ) {
			return (string) Settings_Store::get( 'pro_model' );
		}
		if ( 'default' === $model || '' === $model ) {
			return (string) Settings_Store::get( 'default_model' );
		}
		return $model;
	}

	/**
	 * Estimate.
	 *
	 * @param array $args { post_types, languages, only_missing }.
	 * @return array|\WP_Error
	 */
	public static function estimate( array $args ) {
		$provider = Providers::active();
		if ( ! $provider ) {
			return new \WP_Error( 'dev3tl_no_provider', __( 'No supported multilingual plugin is active.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		$valid     = wp_list_pluck( $provider->target_languages(), 'code' );
		$languages = array_values( array_intersect( array_map( 'strval', (array) ( isset( $args['languages'] ) ? $args['languages'] : array() ) ), $valid ) );
		$posts     = Queue::source_posts( array_map( 'sanitize_key', (array) ( isset( $args['post_types'] ) ? $args['post_types'] : array() ) ) );
		$only      = ! empty( $args['only_missing'] );
		$sample    = array_slice( $posts, 0, self::SAMPLE );
		$factor    = $sample ? count( $posts ) / count( $sample ) : 0;

		$items    = 0;
		$segments = 0;
		$reused   = 0;
		$chars    = 0;
		foreach ( $languages as $lang ) {
			$source_locale = $provider->locale( $provider->default_language() );
			$target_locale = $provider->locale( $lang );
			foreach ( $sample as $post_id ) {
				if ( $only && ( ( 'posts' === $provider->mode() && $provider->get_translation( $post_id, $lang ) ) || ( 'strings' === $provider->mode() && isset( Sync_Engine::stats( $post_id )[ $lang ] ) ) ) ) {
					continue;
				}
				++$items;
				$doc  = Post_Document::build( get_post( $post_id ) );
				$hits = Translation_Memory::lookup( array_column( $doc->segments, 'hash' ), $source_locale, $target_locale );
				foreach ( $doc->segments as $segment ) {
					++$segments;
					if ( isset( $hits[ $segment['hash'] ] ) ) {
						++$reused;
					} else {
						$chars += mb_strlen( $segment['source'] );
					}
				}
			}
		}
		$items    = (int) round( $items * $factor );
		$segments = (int) round( $segments * $factor );
		$reused   = (int) round( $reused * $factor );
		$chars    = (int) round( $chars * $factor );

		$batch_chars = max( 500, (int) Settings_Store::get( 'batch_chars', 6000 ) );
		// Every item/language is synced on its own, so there is at least one request per item.
		$batches     = $chars ? max( $items, (int) ceil( $chars / $batch_chars ) ) : 0;
		$text_tokens = (int) ceil( $chars / 3.5 );
		$input       = $text_tokens + $batches * 900;
		$output      = (int) ceil( $text_tokens * 1.3 + $batches * (int) Settings_Store::get( 'thinking_budget', 1024 ) * 0.8 );
		$model       = self::model();
		$price       = array( 1.25, 10.0 );
		foreach ( self::prices() as $prefix => $p ) {
			if ( 0 === strpos( $model, $prefix ) ) {
				$price = $p;
				break;
			}
		}
		return array(
			'posts'         => count( $posts ),
			'languages'     => count( $languages ),
			'items'         => $items,
			'segments'      => $segments,
			'reused'        => $reused,
			'to_translate'  => max( 0, $segments - $reused ),
			'characters'    => $chars,
			'input_tokens'  => $input,
			'output_tokens' => $output,
			'tokens'        => $input + $output,
			'model'         => $model,
			'cost_usd'      => round( $input / 1e6 * $price[0] + $output / 1e6 * $price[1], 2 ),
			'sampled'       => count( $sample ) < count( $posts ),
		);
	}
}
