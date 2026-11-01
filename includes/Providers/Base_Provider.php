<?php
/**
 * Shared helpers for adapters.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Default implementations.
 */
abstract class Base_Provider implements Translation_Provider {

	/**
	 * Posts mode by default.
	 *
	 * @return string
	 */
	public function mode() {
		return 'posts';
	}

	/**
	 * Locale for a language code.
	 *
	 * @param string $lang Code.
	 * @return string
	 */
	public function locale( $lang ) {
		foreach ( $this->languages() as $language ) {
			if ( $language['code'] === $lang ) {
				return $language['locale'];
			}
		}
		return (string) $lang;
	}

	/**
	 * Language entry by code.
	 *
	 * @param string $lang Code.
	 * @return array|null
	 */
	public function language( $lang ) {
		foreach ( $this->languages() as $language ) {
			if ( $language['code'] === $lang ) {
				return $language;
			}
		}
		return null;
	}

	/**
	 * Target languages (all but the default).
	 *
	 * @return array[]
	 */
	public function target_languages() {
		$default = $this->default_language();
		return array_values(
			array_filter(
				$this->languages(),
				static function ( $language ) use ( $default ) {
					return $language['code'] !== $default;
				}
			)
		);
	}

	/**
	 * Public URL of a post in a language (posts mode: the translation's permalink).
	 *
	 * @param int    $post_id Source post id.
	 * @param string $lang    Code.
	 * @return string
	 */
	public function url( $post_id, $lang ) {
		$target = $this->get_translation( $post_id, $lang );
		return $target ? (string) get_permalink( $target ) : '';
	}

	/**
	 * Inserts a post (slashed for wp_insert_post).
	 *
	 * @param array $postarr Data.
	 * @return int|\WP_Error
	 */
	protected function insert_post( array $postarr ) {
		return wp_insert_post( wp_slash( $postarr ), true );
	}

	/**
	 * Inserts a term, retrying with a language-suffixed slug when the name clashes.
	 *
	 * @param \WP_Term $term Source term.
	 * @param string   $lang Code.
	 * @param array    $args { name, description, parent }.
	 * @return array|\WP_Error wp_insert_term() result.
	 */
	protected function insert_term( $term, $lang, array $args ) {
		$data   = array(
			'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
			'parent'      => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
			'slug'        => sanitize_title( $args['name'] ) . ( sanitize_title( $args['name'] ) === $term->slug ? '-' . sanitize_title( $lang ) : '' ),
		);
		$result = wp_insert_term( wp_slash( (string) $args['name'] ), $term->taxonomy, wp_slash( $data ) );
		if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
			$data['slug'] = sanitize_title( $args['name'] ) . '-' . sanitize_title( $lang );
			$result       = wp_insert_term( wp_slash( (string) $args['name'] . ' (' . $lang . ')' ), $term->taxonomy, wp_slash( $data ) );
		}
		return $result;
	}
}
