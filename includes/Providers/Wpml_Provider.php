<?php
/**
 * WPML adapter, built only on WPML's documented public hooks
 * (wpml_active_languages, wpml_default_language, wpml_post_language_details, wpml_object_id,
 * wpml_element_trid, wpml_set_element_language_details, wpml_register_single_string).
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * One post per language, linked through a WPML translation group (trid).
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public hook API.
 */
class Wpml_Provider extends Base_Provider {

	const STRING_CONTEXT = 'DEV3 AI Translator';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wpml';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'WPML';
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || ( has_filter( 'wpml_active_languages' ) && has_action( 'wpml_set_element_language_details' ) );
	}

	/**
	 * Languages.
	 *
	 * @return array[]
	 */
	public function languages() {
		$list    = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$default = $this->default_language();
		$out     = array();
		foreach ( is_array( $list ) ? $list : array() as $code => $language ) {
			$code  = isset( $language['code'] ) ? (string) $language['code'] : (string) $code;
			$out[] = array(
				'code'    => $code,
				'locale'  => isset( $language['default_locale'] ) && $language['default_locale'] ? (string) $language['default_locale'] : $code,
				'name'    => isset( $language['translated_name'] ) ? (string) $language['translated_name'] : ( isset( $language['native_name'] ) ? (string) $language['native_name'] : $code ),
				'default' => $code === $default,
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
		return (string) apply_filters( 'wpml_default_language', null );
	}

	/**
	 * Element type for a post.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	private function post_element_type( $post_id ) {
		return 'post_' . get_post_type( (int) $post_id );
	}

	/**
	 * Post language.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public function post_language( $post_id ) {
		$details = apply_filters( 'wpml_post_language_details', null, (int) $post_id );
		if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
			return (string) $details['language_code'];
		}
		return (string) apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => (int) $post_id,
				'element_type' => $this->post_element_type( $post_id ),
			)
		);
	}

	/**
	 * Translatable post type.
	 *
	 * @param string $post_type Type.
	 * @return bool
	 */
	public function is_translatable_post_type( $post_type ) {
		return (bool) apply_filters( 'wpml_is_translated_post_type', false, $post_type );
	}

	/**
	 * Translatable taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public function is_translatable_taxonomy( $taxonomy ) {
		return (bool) apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy );
	}

	/**
	 * Translation id.
	 *
	 * @param int    $post_id Post.
	 * @param string $lang    Code.
	 * @return int
	 */
	public function get_translation( $post_id, $lang ) {
		$id = (int) apply_filters( 'wpml_object_id', (int) $post_id, get_post_type( (int) $post_id ), false, $lang );
		return $id && $id !== (int) $post_id ? $id : 0;
	}

	/**
	 * Creates a translation.
	 *
	 * @param int    $source_id Source.
	 * @param string $lang      Code.
	 * @param array  $postarr   Data.
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
	 * Links a post into the source's translation group.
	 *
	 * @param int    $source_id Source.
	 * @param int    $target_id Target.
	 * @param string $lang      Code.
	 */
	public function link_translations( $source_id, $target_id, $lang ) {
		$type = $this->post_element_type( $source_id );
		$trid = apply_filters( 'wpml_element_trid', null, (int) $source_id, $type );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => (int) $target_id,
				'element_type'         => $type,
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => $this->post_language( $source_id ),
			)
		);
	}

	/**
	 * Term translation (wpml_object_id works with term ids).
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @param string $lang     Code.
	 * @return int
	 */
	public function get_term_translation( $term_id, $taxonomy, $lang ) {
		$id = (int) apply_filters( 'wpml_object_id', (int) $term_id, $taxonomy, false, $lang );
		return $id && $id !== (int) $term_id ? $id : 0;
	}

	/**
	 * Creates a term translation (WPML language details use term_taxonomy_id).
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
		$type        = 'tax_' . $term->taxonomy;
		$trid        = apply_filters( 'wpml_element_trid', null, (int) $term->term_taxonomy_id, $type );
		$source_lang = apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => (int) $term->term_taxonomy_id,
				'element_type' => $type,
			)
		);
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => (int) $result['term_taxonomy_id'],
				'element_type'         => $type,
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => $source_lang ? $source_lang : $this->default_language(),
			)
		);
		return (int) $result['term_id'];
	}

	/**
	 * WPML String Translation.
	 *
	 * @param string $original    Original.
	 * @param string $translation Translation.
	 * @param string $lang        Code.
	 * @param array  $context     { name, group }.
	 * @return bool
	 */
	public function translate_string( $original, $translation, $lang, array $context = array() ) {
		$domain = isset( $context['group'] ) ? (string) $context['group'] : self::STRING_CONTEXT;
		$name   = isset( $context['name'] ) ? (string) $context['name'] : md5( (string) $original );
		do_action( 'wpml_register_single_string', $domain, $name, (string) $original );
		if ( ! function_exists( 'icl_get_string_id' ) || ! function_exists( 'icl_add_string_translation' ) ) {
			return false;
		}
		$string_id = (int) icl_get_string_id( (string) $original, $domain, $name );
		if ( ! $string_id ) {
			return false;
		}
		return (bool) icl_add_string_translation( $string_id, $lang, (string) $translation, defined( 'ICL_TM_COMPLETE' ) ? ICL_TM_COMPLETE : 10 );
	}
}
