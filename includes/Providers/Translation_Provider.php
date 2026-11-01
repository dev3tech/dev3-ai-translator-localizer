<?php
/**
 * Adapter contract for multilingual plugins.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Language codes are the provider's own codes (Polylang slug, WPML code, TranslatePress locale).
 */
interface Translation_Provider {

	/**
	 * Machine id (polylang, wpml, translatepress).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether the multilingual plugin is installed and active.
	 *
	 * @return bool
	 */
	public function is_active();

	/**
	 * "posts" (one post per language) or "strings" (rendered strings stored in a dictionary).
	 *
	 * @return string
	 */
	public function mode();

	/**
	 * Languages: list of { code, locale, name, default }.
	 *
	 * @return array[]
	 */
	public function languages();

	/**
	 * Default (source) language code.
	 *
	 * @return string
	 */
	public function default_language();

	/**
	 * Language code of a post ('' if unknown).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function post_language( $post_id );

	/**
	 * Whether a post type is translatable.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_translatable_post_type( $post_type );

	/**
	 * Whether a taxonomy is translatable.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public function is_translatable_taxonomy( $taxonomy );

	/**
	 * Translation post id of a post in a language (0 when missing).
	 *
	 * @param int    $post_id Source post id.
	 * @param string $lang    Language code.
	 * @return int
	 */
	public function get_translation( $post_id, $lang );

	/**
	 * Creates the translation post and links it to the source.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Language code.
	 * @param array  $postarr   wp_insert_post() data (unslashed).
	 * @return int|\WP_Error New post id.
	 */
	public function create_translation( $source_id, $lang, array $postarr );

	/**
	 * Links an existing post as the translation of the source.
	 *
	 * @param int    $source_id Source post id.
	 * @param int    $target_id Translation post id.
	 * @param string $lang      Language code of the translation.
	 */
	public function link_translations( $source_id, $target_id, $lang );

	/**
	 * Translation of a term in a language (0 when missing).
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @param string $lang     Language code.
	 * @return int
	 */
	public function get_term_translation( $term_id, $taxonomy, $lang );

	/**
	 * Creates a term translation and links it.
	 *
	 * @param \WP_Term $term Source term.
	 * @param string   $lang Language code.
	 * @param array    $args { name, description, parent }.
	 * @return int|\WP_Error New term id.
	 */
	public function create_term_translation( $term, $lang, array $args );

	/**
	 * Stores a translation of a standalone string (site title, tagline, labels).
	 *
	 * @param string $original    Original.
	 * @param string $translation Translation.
	 * @param string $lang        Language code.
	 * @param array  $context     { name, group }.
	 * @return bool
	 */
	public function translate_string( $original, $translation, $lang, array $context = array() );
}
