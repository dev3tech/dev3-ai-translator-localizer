<?php
/**
 * Thin bridge to TranslatePress' own classes (TRP_Translate_Press components).
 *
 * TranslatePress translates the rendered page: it parses the HTML, looks every text node / attribute up in
 * its dictionary table ({prefix}trp_dictionary_{default}_{language}) and swaps in translations. We use its
 * own parser (TRP_Translation_Render::translate_page via the trp_translateable_strings filter) to learn the
 * exact original strings, and TRP_Query to write translations.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Real backend; tests inject an array-based double with the same public methods.
 */
class TranslatePress_Backend {

	const NOT_TRANSLATED     = 0;
	const MACHINE_TRANSLATED = 1;
	const HUMAN_REVIEWED     = 2;
	const BLOCK_REGULAR      = 0;
	const BLOCK_ACTIVE       = 1;

	/**
	 * Available.
	 *
	 * @return bool
	 */
	public function available() {
		return class_exists( '\TRP_Translate_Press' );
	}

	/**
	 * Component.
	 *
	 * @param string $name Component name.
	 * @return object|null
	 */
	private function component( $name ) {
		if ( ! $this->available() ) {
			return null;
		}
		$trp = \TRP_Translate_Press::get_trp_instance();
		return $trp ? $trp->get_component( $name ) : null;
	}

	/**
	 * TranslatePress settings.
	 *
	 * @return array
	 */
	public function settings() {
		$settings = $this->component( 'settings' );
		if ( $settings && method_exists( $settings, 'get_settings' ) ) {
			return (array) $settings->get_settings();
		}
		$stored = get_option( 'trp_settings', array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Language names.
	 *
	 * @param string[] $codes Codes.
	 * @return array code => name
	 */
	public function language_names( array $codes ) {
		$languages = $this->component( 'languages' );
		if ( $languages && method_exists( $languages, 'get_language_names' ) ) {
			return (array) $languages->get_language_names( $codes, 'english_name' );
		}
		return array();
	}

	/**
	 * Strings TranslatePress extracts from a piece of HTML for a language.
	 *
	 * Runs TranslatePress' own parser with machine translation disabled; originals that are new to the
	 * dictionary are inserted by TranslatePress as "not translated" rows, exactly as on a page visit.
	 *
	 * @param string $html Rendered HTML.
	 * @param string $lang Language code.
	 * @return array[] { original, type }
	 */
	public function extract( $html, $lang ) {
		$render = $this->component( 'translation_render' );
		if ( ! $render || ! method_exists( $render, 'translate_page' ) ) {
			return array();
		}
		global $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$previous     = $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$TRP_LANGUAGE = $lang; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$captured     = array();
		$capture      = static function ( $info ) use ( &$captured ) {
			$captured = $info;
			return $info;
		};
		$home         = static function () {
			return home_url( '/' );
		};
		// TranslatePress refuses to parse inside REST/xmlrpc requests; present the home URL instead. The URL is
		// cached per request, so the cache is primed (and restored afterwards) as well as filtered.
		$cached = array();
		foreach ( array( 'cur_page_url_translated_slugs', 'cur_page_url_untranslated_slugs' ) as $cache_key ) {
			$cached[ $cache_key ] = wp_cache_get( $cache_key, 'trp' );
			wp_cache_set( $cache_key, home_url( '/' ), 'trp' );
		}
		add_filter( 'trp_translateable_strings', $capture, PHP_INT_MAX );
		add_filter( 'trp_curpageurl', $home, PHP_INT_MAX );
		add_filter( 'trp_machine_translator_is_available', '__return_false', PHP_INT_MAX );
		add_filter( 'trp_stop_translating_page', '__return_false', PHP_INT_MAX );
		try {
			$render->translate_page( '<html><body>' . $html . '</body></html>' );
		} finally {
			remove_filter( 'trp_translateable_strings', $capture, PHP_INT_MAX );
			remove_filter( 'trp_curpageurl', $home, PHP_INT_MAX );
			remove_filter( 'trp_machine_translator_is_available', '__return_false', PHP_INT_MAX );
			remove_filter( 'trp_stop_translating_page', '__return_false', PHP_INT_MAX );
			foreach ( $cached as $cache_key => $value ) {
				if ( false === $value ) {
					wp_cache_delete( $cache_key, 'trp' );
				} else {
					wp_cache_set( $cache_key, $value, 'trp' );
				}
			}
			$TRP_LANGUAGE = $previous; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		}
		$out = array();
		if ( empty( $captured['translateable_strings'] ) ) {
			return $out;
		}
		foreach ( $captured['translateable_strings'] as $i => $original ) {
			$type  = isset( $captured['nodes'][ $i ]['type'] ) ? (string) $captured['nodes'][ $i ]['type'] : 'text';
			$out[] = array(
				'original' => (string) $original,
				'type'     => $type,
			);
		}
		return $out;
	}

	/**
	 * Dictionary rows for originals.
	 *
	 * @param string[] $originals Originals.
	 * @param string   $lang      Language.
	 * @return array original => row (array)
	 */
	public function rows( array $originals, $lang ) {
		$query = $this->component( 'query' );
		if ( ! $query || ! $originals ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( array_values( $originals ), 100 ) as $chunk ) {
			foreach ( (array) $query->get_string_rows( array(), $chunk, $lang, ARRAY_A ) as $row ) {
				$out[ $row['original'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Inserts originals (as untranslated rows).
	 *
	 * @param string[] $originals  Originals.
	 * @param string   $lang       Language.
	 * @param int      $block_type 0 regular, 1 translation block.
	 */
	public function insert( array $originals, $lang, $block_type = self::BLOCK_REGULAR ) {
		$query = $this->component( 'query' );
		if ( $query && $originals ) {
			$query->insert_strings( array_values( $originals ), $lang, $block_type );
		}
	}

	/**
	 * Writes translations.
	 *
	 * @param array  $rows Rows { id, original, translated, status, block_type }.
	 * @param string $lang Language.
	 * @return bool
	 */
	public function update( array $rows, $lang ) {
		$query = $this->component( 'query' );
		if ( ! $query || ! $rows ) {
			return false;
		}
		$clean = array();
		foreach ( $rows as $row ) {
			$clean[] = array(
				'id'         => (int) $row['id'],
				'original'   => (string) $row['original'],
				'translated' => function_exists( 'trp_sanitize_string' ) ? trp_sanitize_string( (string) $row['translated'] ) : wp_kses_post( (string) $row['translated'] ),
				'status'     => (int) $row['status'],
				'block_type' => (int) $row['block_type'],
			);
		}
		return (bool) $query->update_strings( $clean, $lang, array( 'id', 'translated', 'status', 'block_type' ) );
	}

	/**
	 * URL of a page in a language.
	 *
	 * @param string $url  Default-language URL.
	 * @param string $lang Language.
	 * @return string
	 */
	public function url( $url, $lang ) {
		$converter = $this->component( 'url_converter' );
		if ( $converter && method_exists( $converter, 'get_url_for_language' ) ) {
			return (string) $converter->get_url_for_language( $lang, $url, '' );
		}
		return $url;
	}

	/**
	 * Trim exactly like TranslatePress does before looking strings up.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function trim( $text ) {
		return function_exists( 'trp_full_trim' ) ? trp_full_trim( $text ) : trim( (string) $text );
	}
}
