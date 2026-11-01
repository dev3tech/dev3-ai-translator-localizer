<?php
/**
 * Auto-sync on update: when a translated source post is saved, queue a delta sync for its languages.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Sync;

use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;
use Dev3\AiTranslatorLocalizer\Providers\Providers;
use Dev3\AiTranslatorLocalizer\Queue\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks post saves.
 */
class Auto_Sync {

	/**
	 * Posts already queued in this request.
	 *
	 * @var array
	 */
	private static $queued = array();

	/**
	 * Registers hooks.
	 */
	public static function register() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 99, 3 );
	}

	/**
	 * Handles save_post.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Update.
	 */
	public static function on_save( $post_id, $post, $update ) {
		if ( ! $update || Post_Writer::$writing || ! Settings_Store::get( 'auto_sync', true ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status || 'trash' === $post->post_status || isset( self::$queued[ $post_id ] ) ) {
			return;
		}
		$stats = Sync_Engine::stats( $post_id );
		if ( ! $stats || ! Providers::active() || Queue::has_pending( $post_id ) ) {
			return;
		}
		self::$queued[ $post_id ] = true;
		Queue::create(
			array(
				'type'      => 'sync',
				'post_ids'  => array( (int) $post_id ),
				'languages' => array_keys( $stats ),
			)
		);
	}
}
