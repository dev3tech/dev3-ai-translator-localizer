<?php
/**
 * Writes rendered translations into the translation post (posts-mode providers).
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Sync;

use Dev3\AiTranslatorLocalizer\Content\Post_Document;
use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;
use Dev3\AiTranslatorLocalizer\Providers\Base_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or updates the translation in place, copies meta, terms and WooCommerce data.
 */
class Post_Writer {

	/**
	 * True while we write, so our own save_post does not trigger auto-sync.
	 *
	 * @var bool
	 */
	public static $writing = false;

	/**
	 * Meta never copied to a translation.
	 */
	const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_sku', '_dev3tl_sync', '_dev3tl_source', '_dev3tl_written', '_dev3tl_lang', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wc_review_count', '_wc_average_rating', '_wc_rating_count', 'total_sales', '_product_attributes' );

	/**
	 * Writes.
	 *
	 * @param \WP_Post      $source    Source post.
	 * @param Base_Provider $provider  Provider.
	 * @param string        $lang      Target language.
	 * @param int           $target_id Existing translation (0 = create).
	 * @param array         $values    Rendered field values.
	 * @return int|\WP_Error Translation id.
	 */
	public static function write( $source, Base_Provider $provider, $lang, $target_id, array $values ) {
		self::$writing = true;
		$kses          = self::maybe_disable_kses();
		try {
			$result = self::write_post( $source, $provider, $lang, (int) $target_id, $values );
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
			self::$writing = false;
		}
		return $result;
	}

	/**
	 * Body of write().
	 *
	 * @param \WP_Post      $source    Source.
	 * @param Base_Provider $provider  Provider.
	 * @param string        $lang      Language.
	 * @param int           $target_id Target.
	 * @param array         $values    Values.
	 * @return int|\WP_Error
	 */
	private static function write_post( $source, Base_Provider $provider, $lang, $target_id, array $values ) {
		$title   = isset( $values['title'] ) ? $values['title'] : $source->post_title;
		$content = isset( $values['content'] ) ? $values['content'] : $source->post_content;
		$excerpt = isset( $values['excerpt'] ) ? $values['excerpt'] : $source->post_excerpt;
		$created = false;

		if ( $target_id ) {
			$data = array(
				'ID'           => $target_id,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			);
			if ( Settings_Store::get( 'translate_slug', true ) && '' === (string) get_post_field( 'post_name', $target_id ) ) {
				$data['post_name'] = sanitize_title( $title );
			}
			$ok = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		} else {
			$status = (string) Settings_Store::get( 'translated_status', 'same' );
			$status = 'same' === $status ? $source->post_status : $status;
			$parent = 0;
			if ( $source->post_parent ) {
				$parent = $provider->get_translation( $source->post_parent, $lang );
				$parent = $parent ? $parent : (int) $source->post_parent;
			}
			$target_id = $provider->create_translation(
				$source->ID,
				$lang,
				array(
					'post_type'      => $source->post_type,
					'post_status'    => $status,
					'post_author'    => (int) $source->post_author,
					'post_date'      => $source->post_date,
					'post_date_gmt'  => $source->post_date_gmt,
					'post_parent'    => $parent,
					'menu_order'     => (int) $source->menu_order,
					'comment_status' => $source->comment_status,
					'ping_status'    => $source->ping_status,
					'post_password'  => $source->post_password,
					'post_title'     => $title,
					'post_content'   => $content,
					'post_excerpt'   => $excerpt,
					'post_name'      => Settings_Store::get( 'translate_slug', true ) ? sanitize_title( $title ) : $source->post_name,
				)
			);
			if ( is_wp_error( $target_id ) ) {
				return $target_id;
			}
			$created = true;
			if ( Settings_Store::get( 'copy_meta', true ) ) {
				self::copy_meta( $source->ID, $target_id, array_keys( Post_Document::meta_keys( $source ) ) );
			}
		}

		foreach ( $values as $field => $value ) {
			if ( 0 === strpos( $field, 'meta:' ) ) {
				update_post_meta( $target_id, substr( $field, 5 ), wp_slash( $value ) );
			}
		}
		$term_map = self::sync_terms( $source, $provider, $lang, $target_id, $values );
		if ( 'product' === $source->post_type ) {
			self::sync_product( $source, $target_id, $values, $term_map, $created );
		}
		update_post_meta( $target_id, '_dev3tl_source', (int) $source->ID );
		update_post_meta( $target_id, '_dev3tl_lang', $lang );
		update_post_meta( $target_id, '_dev3tl_written', self::fingerprint( $target_id ) );
		return (int) $target_id;
	}

	/**
	 * Content fingerprint used to detect manual edits of a translation.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function fingerprint( $post_id ) {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		return $post ? md5( $post->post_title . "\0" . $post->post_content . "\0" . $post->post_excerpt ) : '';
	}

	/**
	 * Whether someone edited the translation after our last write.
	 *
	 * @param int $post_id Translation.
	 * @return bool
	 */
	public static function edited_manually( $post_id ) {
		$written = (string) get_post_meta( $post_id, '_dev3tl_written', true );
		return '' !== $written && self::fingerprint( $post_id ) !== $written;
	}

	/**
	 * Lets users with unfiltered_html keep markup exactly (scripts, iframes, block JSON).
	 *
	 * @return bool Whether filters were removed.
	 */
	private static function maybe_disable_kses() {
		if ( current_user_can( 'unfiltered_html' ) && false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			kses_remove_filters();
			return true;
		}
		return false;
	}

	/**
	 * Copies meta on creation.
	 *
	 * @param int   $from Source.
	 * @param int   $to   Target.
	 * @param array $skip Translated keys (written separately).
	 */
	private static function copy_meta( $from, $to, array $skip ) {
		/**
		 * Meta keys never copied from the source to a new translation.
		 *
		 * @param string[] $keys Keys.
		 */
		$exclude = array_merge( self::SKIP_META, $skip, (array) apply_filters( 'dev3tl_copy_meta_exclude', array() ) );
		foreach ( (array) get_post_meta( $from ) as $key => $values ) {
			if ( in_array( $key, $exclude, true ) || 0 === strpos( $key, '_pll_' ) || 0 === strpos( $key, '_icl_' ) ) {
				continue;
			}
			delete_post_meta( $to, $key );
			foreach ( (array) $values as $value ) {
				add_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	/**
	 * Assigns translated terms (creating missing translations) and copies untranslatable ones.
	 *
	 * @param \WP_Post      $source    Source.
	 * @param Base_Provider $provider  Provider.
	 * @param string        $lang      Language.
	 * @param int           $target_id Target.
	 * @param array         $values    Values (term:{id}:name / desc).
	 * @return array taxonomy => [ source term id => target term id ]
	 */
	private static function sync_terms( $source, Base_Provider $provider, $lang, $target_id, array $values ) {
		$map = array();
		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			if ( in_array( $taxonomy, Post_Document::INTERNAL_TAXONOMIES, true ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $source->ID, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$ids = array();
			if ( ! $provider->is_translatable_taxonomy( $taxonomy ) ) {
				$ids = wp_list_pluck( $terms, 'term_id' );
			} else {
				foreach ( $terms as $term ) {
					$translated = $provider->get_term_translation( $term->term_id, $taxonomy, $lang );
					if ( ! $translated ) {
						$parent = 0;
						if ( $term->parent ) {
							$parent = $provider->get_term_translation( $term->parent, $taxonomy, $lang );
						}
						$key        = 'term:' . $term->term_id;
						$translated = $provider->create_term_translation(
							$term,
							$lang,
							array(
								'name'        => isset( $values[ $key . ':name' ] ) ? $values[ $key . ':name' ] : $term->name,
								'description' => isset( $values[ $key . ':desc' ] ) ? $values[ $key . ':desc' ] : $term->description,
								'parent'      => (int) $parent,
							)
						);
						if ( is_wp_error( $translated ) ) {
							continue;
						}
					}
					$map[ $taxonomy ][ $term->term_id ] = (int) $translated;
					$ids[]                              = (int) $translated;
				}
			}
			wp_set_object_terms( $target_id, array_map( 'intval', $ids ), $taxonomy );
		}
		return $map;
	}

	/**
	 * WooCommerce: attributes and variations.
	 *
	 * @param \WP_Post $source    Product.
	 * @param int      $target_id Translation.
	 * @param array    $values    Values.
	 * @param array    $term_map  Term map.
	 * @param bool     $created   Translation was just created.
	 */
	private static function sync_product( $source, $target_id, array $values, array $term_map, $created ) {
		$attributes = get_post_meta( $source->ID, '_product_attributes', true );
		$options    = array();
		if ( is_array( $attributes ) ) {
			foreach ( $attributes as $key => $attribute ) {
				if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) ) {
					continue;
				}
				$field = 'wc_attr:' . $key;
				if ( isset( $values[ $field . ':name' ] ) ) {
					$attributes[ $key ]['name'] = $values[ $field . ':name' ];
				}
				$parts = array_map( 'trim', explode( '|', (string) $attribute['value'] ) );
				foreach ( $parts as $i => $part ) {
					$translated               = isset( $values[ $field . ':opt:' . $i ] ) ? $values[ $field . ':opt:' . $i ] : $part;
					$options[ $key ][ $part ] = $translated;
					$parts[ $i ]              = $translated;
				}
				$attributes[ $key ]['value'] = implode( ' | ', $parts );
			}
			update_post_meta( $target_id, '_product_attributes', $attributes );
		}

		// Variations: create once, keep descriptions in sync; map attribute values to the translation.
		$children = get_children(
			array(
				'post_parent' => $source->ID,
				'post_type'   => 'product_variation',
				'post_status' => array( 'publish', 'private' ),
			)
		);
		if ( ! $children ) {
			return;
		}
		$existing = array();
		foreach ( get_children(
			array(
				'post_parent' => $target_id,
				'post_type'   => 'product_variation',
				'post_status' => array( 'publish', 'private' ),
			)
		) as $child ) {
			$origin = (int) get_post_meta( $child->ID, '_dev3tl_source', true );
			if ( $origin ) {
				$existing[ $origin ] = $child->ID;
			}
		}
		foreach ( $children as $variation ) {
			$id = isset( $existing[ $variation->ID ] ) ? $existing[ $variation->ID ] : 0;
			if ( ! $id ) {
				$id = wp_insert_post(
					wp_slash(
						array(
							'post_type'   => 'product_variation',
							'post_status' => $variation->post_status,
							'post_parent' => $target_id,
							'menu_order'  => $variation->menu_order,
							'post_title'  => $variation->post_title,
						)
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					continue;
				}
				self::copy_meta( $variation->ID, $id, array( '_variation_description' ) );
				update_post_meta( $id, '_dev3tl_source', (int) $variation->ID );
			}
			if ( isset( $values[ 'var:' . $variation->ID ] ) ) {
				update_post_meta( $id, '_variation_description', wp_slash( $values[ 'var:' . $variation->ID ] ) );
			}
			foreach ( (array) get_post_meta( $variation->ID ) as $key => $meta ) {
				if ( 0 !== strpos( $key, 'attribute_' ) ) {
					continue;
				}
				$value = (string) maybe_unserialize( $meta[0] );
				$attr  = substr( $key, 10 );
				if ( taxonomy_exists( $attr ) && isset( $term_map[ $attr ] ) ) {
					$term = get_term_by( 'slug', $value, $attr );
					if ( $term && isset( $term_map[ $attr ][ $term->term_id ] ) ) {
						$translated = get_term( $term_map[ $attr ][ $term->term_id ], $attr );
						$value      = $translated && ! is_wp_error( $translated ) ? $translated->slug : $value;
					}
				} elseif ( isset( $options[ $attr ] ) ) {
					foreach ( $options[ $attr ] as $from => $to ) {
						if ( $from === $value || sanitize_title( $from ) === $value ) {
							$value = $to;
						}
					}
				}
				update_post_meta( $id, $key, wp_slash( $value ) );
			}
		}
		unset( $created );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $target_id );
		}
	}
}
