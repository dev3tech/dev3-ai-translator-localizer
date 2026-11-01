<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3tl_settings_defaults / dev3tl_settings_sanitize.
 */
class Settings {

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'provider'             => 'auto',
				'translation_model'    => 'pro',
				'thinking_budget'      => 1024,
				'request_timeout'      => 150,
				'batch_size'           => 40,
				'batch_chars'          => 6000,
				'auto_sync'            => true,
				'post_types'           => array(),
				'custom_fields'        => array(),
				'translated_status'    => 'same',
				'translate_slug'       => true,
				'protect_manual_edits' => true,
				'seo_meta'             => true,
				'woo_fields'           => true,
				'copy_meta'            => true,
				'use_action_scheduler' => true,
				'tick_seconds'         => 20,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		if ( isset( $input['provider'] ) && in_array( $input['provider'], array( 'auto', 'wpml', 'polylang', 'translatepress' ), true ) ) {
			$out['provider'] = $input['provider'];
		}
		if ( isset( $input['translation_model'] ) ) {
			$model = strtolower( trim( (string) $input['translation_model'] ) );
			if ( in_array( $model, array( 'pro', 'default' ), true ) || preg_match( '/^[a-z0-9][a-z0-9._-]{1,79}$/', $model ) ) {
				$out['translation_model'] = $model;
			}
		}
		$ints = array(
			'thinking_budget' => array( 128, 24576 ),
			'request_timeout' => array( 30, 600 ),
			'batch_size'      => array( 1, 150 ),
			'batch_chars'     => array( 500, 30000 ),
			'tick_seconds'    => array( 5, 120 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		foreach ( array( 'auto_sync', 'translate_slug', 'protect_manual_edits', 'seo_meta', 'woo_fields', 'copy_meta', 'use_action_scheduler' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['translated_status'] ) && in_array( $input['translated_status'], array( 'same', 'draft', 'pending', 'publish' ), true ) ) {
			$out['translated_status'] = $input['translated_status'];
		}
		if ( isset( $input['post_types'] ) ) {
			$out['post_types'] = array_values( array_filter( array_map( 'sanitize_key', (array) $input['post_types'] ) ) );
		}
		if ( isset( $input['custom_fields'] ) ) {
			$fields               = is_array( $input['custom_fields'] ) ? $input['custom_fields'] : preg_split( '/[\s,]+/', (string) $input['custom_fields'] );
			$out['custom_fields'] = array_values(
				array_unique(
					array_filter(
						array_map(
							static function ( $key ) {
								return preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $key );
							},
							(array) $fields
						)
					)
				)
			);
		}
		return $out;
	}
}
