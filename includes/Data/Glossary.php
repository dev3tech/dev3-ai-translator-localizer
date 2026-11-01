<?php
/**
 * Glossary & brand constraints.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Terms are either do-not-translate (kept verbatim) or carry forced per-locale translations.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
class Glossary {

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * All terms.
	 *
	 * @return array[] { id, term, dnt, case_sensitive, translations{locale:string}, notes }
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		global $wpdb;
		$table       = Tables::name( 'glossary' );
		$rows        = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY term ASC", ARRAY_A );
		self::$cache = array_map( array( __CLASS__, 'shape' ), $rows );
		return self::$cache;
	}

	/**
	 * Normalises a DB row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function shape( array $row ) {
		$translations = json_decode( (string) $row['translations'], true );
		return array(
			'id'             => (int) $row['id'],
			'term'           => (string) $row['term'],
			'dnt'            => (bool) $row['dnt'],
			'case_sensitive' => (bool) $row['case_sensitive'],
			'translations'   => is_array( $translations ) ? $translations : array(),
			'notes'          => (string) $row['notes'],
			'updated'        => (string) $row['updated'],
		);
	}

	/**
	 * Sanitises input.
	 *
	 * @param array $input Raw.
	 * @return array|\WP_Error
	 */
	public static function sanitize( array $input ) {
		$term = isset( $input['term'] ) ? trim( sanitize_text_field( (string) $input['term'] ) ) : '';
		if ( '' === $term ) {
			return new \WP_Error( 'dev3tl_glossary_term', __( 'Enter a term.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		$translations = array();
		if ( ! empty( $input['translations'] ) && is_array( $input['translations'] ) ) {
			foreach ( $input['translations'] as $locale => $value ) {
				$locale = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $locale );
				$value  = trim( sanitize_text_field( (string) $value ) );
				if ( '' !== $locale && '' !== $value ) {
					$translations[ $locale ] = $value;
				}
			}
		}
		return array(
			'term'           => mb_substr( $term, 0, 191 ),
			'dnt'            => ! empty( $input['dnt'] ) ? 1 : 0,
			'case_sensitive' => ! empty( $input['case_sensitive'] ) ? 1 : 0,
			'translations'   => wp_json_encode( (object) $translations ),
			'notes'          => isset( $input['notes'] ) ? sanitize_textarea_field( (string) $input['notes'] ) : '',
			'updated'        => current_time( 'mysql', true ),
		);
	}

	/**
	 * Creates or updates a term.
	 *
	 * @param array $input Raw.
	 * @param int   $id    Existing id (0 = new).
	 * @return array|\WP_Error Saved term.
	 */
	public static function save( array $input, $id = 0 ) {
		global $wpdb;
		$data = self::sanitize( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$table = Tables::name( 'glossary' );
		if ( $id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $id ) );
		} else {
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		self::$cache = null;
		$row         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::shape( $row ) : new \WP_Error( 'dev3tl_glossary_save', __( 'Could not save the term.', 'dev3-ai-translator-localizer' ), array( 'status' => 500 ) );
	}

	/**
	 * Deletes a term.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		self::$cache = null;
		return (bool) $wpdb->delete( Tables::name( 'glossary' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Clears the request cache.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Forced translation of a term for a locale (exact locale, then language prefix).
	 *
	 * @param array  $term   Term.
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function forced( array $term, $locale ) {
		$lang = strtolower( substr( (string) $locale, 0, 2 ) );
		foreach ( array( $locale, strtolower( $locale ), $lang ) as $key ) {
			if ( isset( $term['translations'][ $key ] ) && '' !== $term['translations'][ $key ] ) {
				return (string) $term['translations'][ $key ];
			}
		}
		return '';
	}

	/**
	 * Whether a text contains a term.
	 *
	 * @param string $text           Text.
	 * @param string $needle         Term.
	 * @param bool   $case_sensitive Case sensitive.
	 * @return bool
	 */
	public static function contains( $text, $needle, $case_sensitive ) {
		$needle = (string) $needle;
		if ( '' === $needle ) {
			return false;
		}
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u' . ( $case_sensitive ? '' : 'i' );
		return (bool) preg_match( $pattern, (string) $text );
	}

	/**
	 * Terms that occur in any of the given texts and apply to a locale.
	 *
	 * @param string[] $texts  Source texts.
	 * @param string   $locale Target locale.
	 * @return array[]
	 */
	public static function relevant( array $texts, $locale ) {
		$joined = implode( "\n", $texts );
		$out    = array();
		foreach ( self::all() as $term ) {
			if ( ! $term['dnt'] && '' === self::forced( $term, $locale ) ) {
				continue;
			}
			if ( self::contains( $joined, $term['term'], $term['case_sensitive'] ) ) {
				$out[] = $term;
			}
		}
		return $out;
	}

	/**
	 * Post-check of one translation.
	 *
	 * @param string $source      Source.
	 * @param string $translation Translation.
	 * @param string $locale      Target locale.
	 * @param array  $terms       Terms to check (defaults to all).
	 * @return string[] Human-readable violations.
	 */
	public static function violations( $source, $translation, $locale, ?array $terms = null ) {
		$terms = null === $terms ? self::all() : $terms;
		$out   = array();
		foreach ( $terms as $term ) {
			if ( ! self::contains( $source, $term['term'], $term['case_sensitive'] ) ) {
				continue;
			}
			$forced = self::forced( $term, $locale );
			if ( '' !== $forced ) {
				if ( ! self::contains( $translation, $forced, false ) ) {
					/* translators: 1: glossary term, 2: required translation */
					$out[] = sprintf( __( '"%1$s" must be translated as "%2$s"', 'dev3-ai-translator-localizer' ), $term['term'], $forced );
				}
			} elseif ( $term['dnt'] && ! self::contains( $translation, $term['term'], $term['case_sensitive'] ) ) {
				/* translators: %s: glossary term */
				$out[] = sprintf( __( '"%s" must stay untranslated', 'dev3-ai-translator-localizer' ), $term['term'] );
			}
		}
		return $out;
	}
}
