<?php
/**
 * "DEV3 AI Translation" box on the edit screen of translatable posts.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Admin;

use Dev3\AiTranslatorLocalizer\Core3\Core\Capabilities;
use Dev3\AiTranslatorLocalizer\Providers\Providers;
use Dev3\AiTranslatorLocalizer\Sync\Sync_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Shows per-language status and "Translate now" links (admin-post, nonce-protected).
 */
class Post_Metabox {

	const ACTION = 'dev3tl_translate_post';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Adds the box.
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post      Post.
	 */
	public function add( $post_type, $post = null ) {
		$provider = Providers::active();
		if ( ! $provider || ! $post || ! $provider->is_translatable_post_type( $post_type ) || ! Capabilities::can_generate() ) {
			return;
		}
		if ( get_post_meta( $post->ID, '_dev3tl_source', true ) ) {
			add_meta_box( 'dev3tl-translation', __( 'DEV3 AI Translation', 'dev3-ai-translator-localizer' ), array( $this, 'render_translation' ), $post_type, 'side', 'default' );
			return;
		}
		$lang = $provider->post_language( $post->ID );
		if ( '' !== $lang && $lang !== $provider->default_language() ) {
			return;
		}
		add_meta_box( 'dev3tl-translation', __( 'DEV3 AI Translation', 'dev3-ai-translator-localizer' ), array( $this, 'render' ), $post_type, 'side', 'default' );
	}

	/**
	 * Box on a source post.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render( $post ) {
		$provider = Providers::active();
		$stats    = Sync_Engine::stats( $post->ID );
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: multilingual plugin name */
				__( 'Translates this item with DEV3 AI through %s. Save your changes first.', 'dev3-ai-translator-localizer' ),
				$provider->label()
			)
		) . '</p><ul style="margin:0">';
		foreach ( $provider->target_languages() as $language ) {
			$code = $language['code'];
			$url  = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::ACTION,
						'post'   => $post->ID,
						'lang'   => $code,
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION . '_' . $post->ID
			);
			echo '<li style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f0f0f1"><span><strong>' . esc_html( $language['name'] ) . '</strong>';
			if ( isset( $stats[ $code ] ) ) {
				$s = $stats[ $code ];
				echo '<br><small>' . esc_html(
					sprintf(
						/* translators: 1: reused, 2: total */
						__( '%1$d of %2$d segments reused', 'dev3-ai-translator-localizer' ),
						(int) $s['reused'],
						(int) $s['total']
					)
				) . '</small>';
			} else {
				echo '<br><small>' . esc_html__( 'Not translated yet', 'dev3-ai-translator-localizer' ) . '</small>';
			}
			echo '</span><a class="button button-small" href="' . esc_url( $url ) . '">' . ( isset( $stats[ $code ] ) ? esc_html__( 'Sync', 'dev3-ai-translator-localizer' ) : esc_html__( 'Translate', 'dev3-ai-translator-localizer' ) ) . '</a></li>';
		}
		echo '</ul>';
		if ( $stats ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3TL_SLUG . '#/review?post=' . $post->ID ) ) . '">' . esc_html__( 'Review translations', 'dev3-ai-translator-localizer' ) . '</a></p>';
		}
	}

	/**
	 * Box on a translation created by the plugin.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_translation( $post ) {
		$source = (int) get_post_meta( $post->ID, '_dev3tl_source', true );
		$lang   = (string) get_post_meta( $post->ID, '_dev3tl_lang', true );
		echo '<p>' . esc_html__( 'This translation is kept in sync with its source by DEV3 AI Translator. Edit segments in the review screen so your changes are protected from future syncs.', 'dev3-ai-translator-localizer' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . DEV3TL_SLUG . '#/review?post=' . $source . '&lang=' . rawurlencode( $lang ) ) ) . '">' . esc_html__( 'Open review', 'dev3-ai-translator-localizer' ) . '</a></p>';
	}

	/**
	 * Handles the admin-post request.
	 */
	public function handle() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$lang    = isset( $_GET['lang'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( wp_unslash( $_GET['lang'] ) ) ) : '';
		check_admin_referer( self::ACTION . '_' . $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! Capabilities::can_generate() ) {
			wp_die( esc_html__( 'You do not have permission to translate this item.', 'dev3-ai-translator-localizer' ) );
		}
		$result = Sync_Engine::sync( $post_id, $lang, array( 'overwrite_manual' => true ) );
		$notice = is_wp_error( $result ) ? 'error:' . $result->get_error_message() : 'ok:' . (int) $result['reused'] . ':' . (int) $result['total'] . ':' . (int) $result['sent'];
		set_transient( 'dev3tl_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/**
	 * Result notice after a redirect.
	 */
	public function notice() {
		$key    = 'dev3tl_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		$parts = explode( ':', (string) $notice, 2 );
		if ( 'error' === $parts[0] ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $parts[1] ) . '</p></div>';
			return;
		}
		$n = array_map( 'intval', explode( ':', $parts[1] ) );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: 1: reused, 2: total, 3: sent to AI */
				__( 'Translation updated: %1$d of %2$d segments reused, %3$d sent to AI.', 'dev3-ai-translator-localizer' ),
				$n[0],
				isset( $n[1] ) ? $n[1] : 0,
				isset( $n[2] ) ? $n[2] : 0
			)
		) . '</p></div>';
	}
}
