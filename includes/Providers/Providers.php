<?php
/**
 * Detects the multilingual plugin and returns its adapter.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Providers;

use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Registry.
 */
class Providers {

	/**
	 * Test override.
	 *
	 * @var Base_Provider|null
	 */
	public static $override = null;

	/**
	 * Request cache.
	 *
	 * @var Base_Provider[]|null
	 */
	private static $all = null;

	/**
	 * All adapters (WPML, Polylang, TranslatePress, plus filter additions).
	 *
	 * @return Base_Provider[] id => adapter
	 */
	public static function all() {
		if ( null === self::$all ) {
			$list = array(
				'wpml'           => new Wpml_Provider(),
				'polylang'       => new Polylang_Provider(),
				'translatepress' => new TranslatePress_Provider(),
			);
			/**
			 * Adds or replaces translation-provider adapters (each must extend Base_Provider).
			 *
			 * @param Base_Provider[] $list Adapters by id.
			 */
			$list      = apply_filters( 'dev3tl_providers', $list );
			self::$all = array_filter(
				(array) $list,
				static function ( $provider ) {
					return $provider instanceof Base_Provider;
				}
			);
		}
		return self::$all;
	}

	/**
	 * Active adapter (setting "provider": auto or an id).
	 *
	 * @return Base_Provider|null
	 */
	public static function active() {
		if ( self::$override ) {
			return self::$override;
		}
		$all    = self::all();
		$chosen = (string) Settings_Store::get( 'provider', 'auto' );
		if ( 'auto' !== $chosen && isset( $all[ $chosen ] ) && $all[ $chosen ]->is_active() ) {
			return $all[ $chosen ];
		}
		foreach ( $all as $provider ) {
			if ( $provider->is_active() ) {
				return $provider;
			}
		}
		return null;
	}

	/**
	 * Detection summary for the dashboard.
	 *
	 * @return array[]
	 */
	public static function detected() {
		$active = self::active();
		$out    = array();
		foreach ( self::all() as $id => $provider ) {
			$out[] = array(
				'id'       => $id,
				'label'    => $provider->label(),
				'detected' => $provider->is_active(),
				'active'   => $active && $active->id() === $id,
				'mode'     => $provider->mode(),
			);
		}
		return $out;
	}

	/**
	 * Clears caches (tests).
	 */
	public static function reset() {
		self::$all      = null;
		self::$override = null;
	}
}
