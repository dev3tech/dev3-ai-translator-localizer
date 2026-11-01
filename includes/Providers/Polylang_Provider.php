<?php
/**
 * Polylang adapter (pll_* API).
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * One post per language, linked through Polylang translation groups.
 */
class Polylang_Provider extends Base_Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'polylang';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Polylang';
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_set_post_language' ) && function_exists( 'PLL' );
	}

	/**
	 * Languages.
	 *
	 * @return array[]
	 */
	public function languages() {
		if ( ! $this->is_active() ) {
			return array();
		}
		$default = (string) pll_default_language( 'slug' );
		$out     = array();
		foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $language ) {
			if ( ! is_object( $language ) ) {
				continue;
			}
			$out[] = array(
				'code'    => (string) $language->slug,
				'locale'  => (string) $language->locale,
				'name'    => (string) $language->name,
				'default' => $language->slug === $default,
			);
		}
		return $out;
	}

	/**
	 * Default language.
	 *
	 * @return string
	 */
	public function default_language() {
		return $this->is_active() ? (string) pll_default_language( 'slug' ) : '';
	}

	/**
	 * Post language.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function post_language( $post_id ) {
		return $this->is_active() ? (string) pll_get_post_language( (int) $post_id, 'slug' ) : '';
	}

	/**
	 * Translatable post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_translatable_post_type( $post_type ) {
		return $this->is_active() && pll_is_translated_post_type( $post_type );
	}

	/**
	 * Translatable taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public function is_translatable_taxonomy( $taxonomy ) {
		return $this->is_active() && pll_is_translated_taxonomy( $taxonomy );
	}

	/**
	 * Translation id.
	 *
	 * @param int    $post_id Post id.
	 * @param string $lang    Code.
	 * @return int
	 */
	public function get_translation( $post_id, $lang ) {
		if ( ! $this->is_active() ) {
			return 0;
		}
		$id = (int) pll_get_post( (int) $post_id, $lang );
		return $id && $id !== (int) $post_id ? $id : 0;
	}

	/**
	 * Creates a translation.
	 *
	 * @param int    $source_id Source id.
	 * @param string $lang      Code.
	 * @param array  $postarr   Post data.
	 * @return int|\WP_Error
	 */
	public function create_translation( $source_id, $lang, array $postarr ) {
		$id = $this->insert_post( $postarr );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$this->link_translations( $source_id, $id, $lang );
		return (int) $id;
	}

	/**
	 * Links translations.
	 *
	 * @param int    $source_id Source.
	 * @param int    $target_id Target.
	 * @param string $lang      Target code.
	 */
	public function link_translations( $source_id, $target_id, $lang ) {
		pll_set_post_language( (int) $target_id, $lang );
		$translations = (array) pll_get_post_translations( (int) $source_id );
		$source_lang  = $this->post_language( $source_id );
		if ( '' === $source_lang ) {
			$source_lang = $this->default_language();
			pll_set_post_language( (int) $source_id, $source_lang );
		}
		$translations[ $source_lang ] = (int) $source_id;
		$translations[ $lang ]        = (int) $target_id;
		pll_save_post_translations( array_map( 'intval', $translations ) );
	}

	/**
	 * Term translation.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @param string $lang     Code.
	 * @return int
	 */
	public function get_term_translation( $term_id, $taxonomy, $lang ) {
		unset( $taxonomy );
		if ( ! $this->is_active() ) {
			return 0;
		}
		$id = (int) pll_get_term( (int) $term_id, $lang );
		return $id && $id !== (int) $term_id ? $id : 0;
	}

	/**
	 * Creates a term translation.
	 *
	 * @param \WP_Term $term Source.
	 * @param string   $lang Code.
	 * @param array    $args Args.
	 * @return int|\WP_Error
	 */
	public function create_term_translation( $term, $lang, array $args ) {
		$result = $this->insert_term( $term, $lang, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result['term_id'];
		pll_set_term_language( $id, $lang );
		$translations = (array) pll_get_term_translations( (int) $term->term_id );
		$source_lang  = (string) pll_get_term_language( (int) $term->term_id, 'slug' );
		if ( '' === $source_lang ) {
			$source_lang = $this->default_language();
			pll_set_term_language( (int) $term->term_id, $source_lang );
		}
		$translations[ $source_lang ] = (int) $term->term_id;
		$translations[ $lang ]        = $id;
		pll_save_term_translations( array_map( 'intval', $translations ) );
		return $id;
	}

	/**
	 * Polylang string translations (Languages → Translations), stored per language in PLL_MO.
	 *
	 * @param string $original    Original.
	 * @param string $translation Translation.
	 * @param string $lang        Code.
	 * @param array  $context     Context.
	 * @return bool
	 */
	public function translate_string( $original, $translation, $lang, array $context = array() ) {
		if ( ! $this->is_active() || ! class_exists( '\PLL_MO' ) ) {
			return false;
		}
		$language = PLL()->model->get_language( $lang );
		if ( ! $language ) {
			return false;
		}
		if ( function_exists( 'pll_register_string' ) && ! empty( $context['name'] ) ) {
			pll_register_string( (string) $context['name'], (string) $original, isset( $context['group'] ) ? (string) $context['group'] : 'DEV3 AI Translator' );
		}
		$mo = new \PLL_MO();
		$mo->import_from_db( $language );
		$mo->add_entry( $mo->make_entry( (string) $original, (string) $translation ) );
		$mo->export_to_db( $language );
		return true;
	}
}
