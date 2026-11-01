<?php
/**
 * Review: side-by-side segments of a translated post, edit + approve.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Sync;

use Dev3\AiTranslatorLocalizer\Content\Document;
use Dev3\AiTranslatorLocalizer\Content\Post_Document;
use Dev3\AiTranslatorLocalizer\Content\Renderer;
use Dev3\AiTranslatorLocalizer\Data\Translation_Memory;
use Dev3\AiTranslatorLocalizer\Providers\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Edited segments become "human" entries in the translation memory, which delta sync never overwrites.
 */
class Review {

	/**
	 * Translated source posts.
	 *
	 * @param array $args { search, page, per_page, lang, status }.
	 * @return array { items, total }
	 */
	public static function list_items( array $args ) {
		$provider = Providers::active();
		$query    = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'meta_key'       => Sync_Engine::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'posts_per_page' => max( 1, min( 100, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) ),
				'paged'          => max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 ),
				's'              => isset( $args['search'] ) ? (string) $args['search'] : '',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'lang'           => '',
			)
		);
		$items    = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$langs = array();
			foreach ( Sync_Engine::stats( $post->ID ) as $lang => $stats ) {
				if ( ! empty( $args['lang'] ) && $args['lang'] !== $lang ) {
					continue;
				}
				$target  = isset( $stats['target_id'] ) ? (int) $stats['target_id'] : 0;
				$langs[] = array(
					'lang'      => $lang,
					'stats'     => $stats,
					'target_id' => $target,
					'view_url'  => $provider ? $provider->url( $post->ID, $lang ) : '',
					'edit_url'  => $target && $target !== $post->ID ? get_edit_post_link( $target, 'raw' ) : '',
				);
			}
			if ( ! $langs ) {
				continue;
			}
			$items[] = array(
				'id'        => $post->ID,
				'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'post_type' => $post->post_type,
				'modified'  => $post->post_modified_gmt,
				'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
				'languages' => $langs,
			);
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Builds the document the way the sync engine does.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $lang Language.
	 * @return Document|\WP_Error
	 */
	private static function document( $post, $lang ) {
		$provider = Providers::active();
		if ( ! $provider ) {
			return new \WP_Error( 'dev3tl_no_provider', __( 'No supported multilingual plugin is active.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		return 'strings' === $provider->mode() ? Sync_Engine::strings_document( $post, $provider, $lang ) : Post_Document::build( $post, $provider, $lang );
	}

	/**
	 * Segments of one post/language with their translations.
	 *
	 * @param int    $post_id Source post.
	 * @param string $lang    Language.
	 * @return array|\WP_Error
	 */
	public static function get( $post_id, $lang ) {
		$post     = get_post( (int) $post_id );
		$provider = Providers::active();
		if ( ! $post || ! $provider || ! $provider->language( $lang ) ) {
			return new \WP_Error( 'dev3tl_not_found', __( 'Translation not found.', 'dev3-ai-translator-localizer' ), array( 'status' => 404 ) );
		}
		$doc = self::document( $post, $lang );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$source_locale = $provider->locale( $provider->post_language( $post->ID ) ? $provider->post_language( $post->ID ) : $provider->default_language() );
		$target_locale = $provider->locale( $lang );
		$hits          = Translation_Memory::lookup( array_column( $doc->segments, 'hash' ), $source_locale, $target_locale );
		$segments      = array();
		$seen          = array();
		foreach ( $doc->segments as $id => $segment ) {
			if ( isset( $seen[ $segment['hash'] ] ) ) {
				continue; // One row per distinct text.
			}
			$seen[ $segment['hash'] ] = true;
			$hit                      = isset( $hits[ $segment['hash'] ] ) ? $hits[ $segment['hash'] ] : null;
			$field                    = isset( $doc->fields[ $segment['field'] ] ) ? $doc->fields[ $segment['field'] ] : array();
			$segments[]               = array(
				'id'           => $id,
				'hash'         => $segment['hash'],
				'field'        => $segment['field'],
				'label'        => isset( $field['label'] ) ? $field['label'] : $segment['field'],
				'kind'         => $segment['kind'],
				'context'      => $segment['context'],
				'source'       => $segment['source'],
				'translation'  => $hit ? $hit['translation'] : '',
				'status'       => $hit ? $hit['status'] : 'missing',
				'placeholders' => Renderer::placeholders( $segment['source'] ),
				'updated'      => $hit ? $hit['updated'] : '',
			);
		}
		$stats  = Sync_Engine::stats( $post->ID );
		$target = isset( $stats[ $lang ]['target_id'] ) ? (int) $stats[ $lang ]['target_id'] : $provider->get_translation( $post->ID, $lang );
		return array(
			'post'     => array(
				'id'       => $post->ID,
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
				'view_url' => get_permalink( $post ),
			),
			'lang'     => $lang,
			'language' => $provider->language( $lang ),
			'target'   => array(
				'id'       => $target,
				'edit_url' => $target && $target !== $post->ID ? get_edit_post_link( $target, 'raw' ) : '',
				'view_url' => $provider->url( $post->ID, $lang ),
			),
			'stats'    => isset( $stats[ $lang ] ) ? $stats[ $lang ] : null,
			'segments' => $segments,
		);
	}

	/**
	 * Saves edits / approvals, then re-renders the translation from the memory (no AI call).
	 *
	 * @param int    $post_id Source post.
	 * @param string $lang    Language.
	 * @param array  $changes List of { hash, translation?, approve? }.
	 * @return array|\WP_Error Fresh review data.
	 */
	public static function save( $post_id, $lang, array $changes ) {
		$current = self::get( $post_id, $lang );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$provider      = Providers::active();
		$post          = get_post( (int) $post_id );
		$source_locale = $provider->locale( $provider->post_language( $post->ID ) ? $provider->post_language( $post->ID ) : $provider->default_language() );
		$target_locale = $provider->locale( $lang );
		$by_hash       = array();
		foreach ( $current['segments'] as $segment ) {
			$by_hash[ $segment['hash'] ] = $segment;
		}
		$errors = array();
		foreach ( $changes as $change ) {
			$hash = isset( $change['hash'] ) ? (string) $change['hash'] : '';
			if ( ! isset( $by_hash[ $hash ] ) ) {
				continue;
			}
			$segment = $by_hash[ $hash ];
			if ( isset( $change['translation'] ) && (string) $change['translation'] !== $segment['translation'] ) {
				$text = self::sanitize_edit( (string) $change['translation'] );
				if ( '' === $text ) {
					continue;
				}
				if ( 'attr' !== $segment['kind'] && Renderer::placeholders( $text ) !== $segment['placeholders'] ) {
					/* translators: %s: placeholders such as <1></1> */
					$errors[ $hash ] = sprintf( __( 'Keep exactly these markup placeholders: %s', 'dev3-ai-translator-localizer' ), implode( ' ', $segment['placeholders'] ) );
					continue;
				}
				Translation_Memory::save( $segment['source'], $text, $source_locale, $target_locale, 'human', $segment['context'] );
			} elseif ( ! empty( $change['approve'] ) && '' !== $segment['translation'] ) {
				Translation_Memory::set_status( $hash, $source_locale, $target_locale, 'approved' );
			}
		}
		if ( $errors ) {
			return new \WP_Error(
				'dev3tl_placeholders',
				implode( ' ', array_unique( $errors ) ),
				array(
					'status'   => 400,
					'segments' => $errors,
				)
			);
		}
		$synced = Sync_Engine::sync(
			$post_id,
			$lang,
			array(
				'no_ai'            => true,
				'overwrite_manual' => true,
			)
		);
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}
		return self::get( $post_id, $lang );
	}

	/**
	 * Reviewer input: plain text plus our numbered placeholders (<1>…</1>, <2/>); real tags are removed.
	 *
	 * @param string $raw Reviewer input.
	 * @return string
	 */
	public static function sanitize_edit( $raw ) {
		$parts = preg_split( '/(<\/?\d+\/?>)/', trim( (string) $raw ), -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$parts[ $i ] = wp_kses( $part, array() );
			}
		}
		return trim( implode( '', $parts ) );
	}
}
