<?php
/**
 * Builds the translatable Document of a post: title, content, excerpt, SEO meta, WooCommerce fields,
 * whitelisted custom fields and missing taxonomy terms.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Content;

use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;
use Dev3\AiTranslatorLocalizer\Providers\Base_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Field names: title, content, excerpt, meta:{key}, wc_attr:{key}:name, wc_attr:{key}:opt:{i}, var:{id},
 * term:{id}:name, term:{id}:desc.
 */
class Post_Document {

	/**
	 * SEO meta keys (Yoast SEO, Rank Math) → label.
	 */
	const SEO_META = array(
		'_yoast_wpseo_title'                 => 'Yoast SEO title',
		'_yoast_wpseo_metadesc'              => 'Yoast meta description',
		'_yoast_wpseo_focuskw'               => 'Yoast focus keyphrase',
		'_yoast_wpseo_opengraph-title'       => 'Yoast Facebook title',
		'_yoast_wpseo_opengraph-description' => 'Yoast Facebook description',
		'_yoast_wpseo_twitter-title'         => 'Yoast X title',
		'_yoast_wpseo_twitter-description'   => 'Yoast X description',
		'rank_math_title'                    => 'Rank Math SEO title',
		'rank_math_description'              => 'Rank Math description',
		'rank_math_focus_keyword'            => 'Rank Math focus keyword',
		'rank_math_facebook_title'           => 'Rank Math Facebook title',
		'rank_math_facebook_description'     => 'Rank Math Facebook description',
		'rank_math_twitter_title'            => 'Rank Math X title',
		'rank_math_twitter_description'      => 'Rank Math X description',
	);

	/**
	 * Taxonomies that belong to the multilingual plugin itself.
	 */
	const INTERNAL_TAXONOMIES = array( 'language', 'post_translations', 'term_language', 'term_translations' );

	/**
	 * Builds the document.
	 *
	 * @param \WP_Post           $post     Source post.
	 * @param Base_Provider|null $provider Provider (terms are only added in posts mode).
	 * @param string             $lang     Target language code.
	 * @return Document
	 */
	public static function build( $post, $provider = null, $lang = '' ) {
		$doc = new Document();
		Segmenter::add_text( $doc, 'title', $post->post_title, 'title', array( 'label' => __( 'Title', 'dev3-ai-translator-localizer' ) ) );
		Segmenter::add_html( $doc, 'content', $post->post_content, array( 'label' => __( 'Content', 'dev3-ai-translator-localizer' ) ) );
		if ( '' !== trim( $post->post_excerpt ) ) {
			Segmenter::add_html( $doc, 'excerpt', $post->post_excerpt, array( 'label' => 'product' === $post->post_type ? __( 'Short description', 'dev3-ai-translator-localizer' ) : __( 'Excerpt', 'dev3-ai-translator-localizer' ) ) );
		}

		foreach ( self::meta_keys( $post ) as $key => $label ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}
			$field = 'meta:' . $key;
			if ( preg_match( '/<[a-z][^>]*>/i', $value ) ) {
				Segmenter::add_html( $doc, $field, $value, array( 'label' => $label ) );
			} else {
				Segmenter::add_text( $doc, $field, $value, isset( self::SEO_META[ $key ] ) ? 'SEO: ' . $label : 'custom field', array( 'label' => $label ) );
			}
		}

		if ( 'product' === $post->post_type && Settings_Store::get( 'woo_fields', true ) ) {
			self::add_product_fields( $doc, $post );
		}

		if ( $provider && 'posts' === $provider->mode() && '' !== $lang ) {
			self::add_terms( $doc, $post, $provider, $lang );
		}

		/**
		 * Lets extensions add fields to a post's translation document.
		 *
		 * @param Document $doc  Document.
		 * @param \WP_Post $post Post.
		 * @param string   $lang Target language.
		 */
		do_action( 'dev3tl_build_document', $doc, $post, $lang );
		return $doc;
	}

	/**
	 * SEO + whitelisted meta keys present on the post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array key => label
	 */
	public static function meta_keys( $post ) {
		$keys = array();
		if ( Settings_Store::get( 'seo_meta', true ) ) {
			foreach ( self::SEO_META as $key => $label ) {
				if ( metadata_exists( 'post', $post->ID, $key ) ) {
					$keys[ $key ] = $label;
				}
			}
		}
		foreach ( (array) Settings_Store::get( 'custom_fields', array() ) as $key ) {
			$key = (string) $key;
			if ( '' !== $key && metadata_exists( 'post', $post->ID, $key ) ) {
				/* translators: %s: custom field key */
				$keys[ $key ] = sprintf( __( 'Custom field %s', 'dev3-ai-translator-localizer' ), $key );
			}
		}
		/**
		 * Filters the meta keys translated for a post.
		 *
		 * @param array    $keys key => label.
		 * @param \WP_Post $post Post.
		 */
		return (array) apply_filters( 'dev3tl_translatable_meta', $keys, $post );
	}

	/**
	 * WooCommerce: local attribute names/values and variation descriptions.
	 *
	 * @param Document $doc  Document.
	 * @param \WP_Post $post Product.
	 */
	private static function add_product_fields( Document $doc, $post ) {
		$attributes = get_post_meta( $post->ID, '_product_attributes', true );
		if ( is_array( $attributes ) ) {
			foreach ( $attributes as $key => $attribute ) {
				if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) ) {
					continue;
				}
				Segmenter::add_text( $doc, 'wc_attr:' . $key . ':name', (string) $attribute['name'], 'product attribute name', array( 'label' => __( 'Attribute name', 'dev3-ai-translator-localizer' ) ) );
				$options = array_map( 'trim', explode( '|', (string) $attribute['value'] ) );
				foreach ( $options as $i => $option ) {
					Segmenter::add_text( $doc, 'wc_attr:' . $key . ':opt:' . $i, $option, 'product attribute value', array( 'label' => __( 'Attribute value', 'dev3-ai-translator-localizer' ) ) );
				}
			}
		}
		$variations = get_children(
			array(
				'post_parent' => $post->ID,
				'post_type'   => 'product_variation',
				'post_status' => array( 'publish', 'private' ),
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
			)
		);
		foreach ( $variations as $variation ) {
			$description = (string) get_post_meta( $variation->ID, '_variation_description', true );
			if ( '' !== trim( $description ) ) {
				Segmenter::add_html( $doc, 'var:' . $variation->ID, $description, array( 'label' => __( 'Variation description', 'dev3-ai-translator-localizer' ) ) );
			}
		}
	}

	/**
	 * Terms that still need a translation in the target language.
	 *
	 * @param Document      $doc      Document.
	 * @param \WP_Post      $post     Post.
	 * @param Base_Provider $provider Provider.
	 * @param string        $lang     Language.
	 */
	private static function add_terms( Document $doc, $post, Base_Provider $provider, $lang ) {
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( in_array( $taxonomy, self::INTERNAL_TAXONOMIES, true ) || ! $provider->is_translatable_taxonomy( $taxonomy ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( $provider->get_term_translation( $term->term_id, $taxonomy, $lang ) ) {
					continue;
				}
				Segmenter::add_text( $doc, 'term:' . $term->term_id . ':name', $term->name, 'taxonomy term (' . $taxonomy . ')', array( 'label' => __( 'Term', 'dev3-ai-translator-localizer' ) ) );
				if ( '' !== trim( $term->description ) ) {
					Segmenter::add_html( $doc, 'term:' . $term->term_id . ':desc', $term->description, array( 'label' => __( 'Term description', 'dev3-ai-translator-localizer' ) ) );
				}
			}
		}
	}
}
