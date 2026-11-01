<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer;

use Dev3\AiTranslatorLocalizer\Core3\Core\Assets;
use Dev3\AiTranslatorLocalizer\Core3\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3tl_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3TL_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'dev3tl_license_check' ); // Licence check from builds before licensing was removed.
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3tl_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3tl_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3tl_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3tl_schema_sql', array( Data\Tables::class, 'sql' ), 10, 2 );
		}
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		load_plugin_textdomain( 'dev3-ai-translator-localizer', false, dirname( plugin_basename( DEV3TL_FILE ) ) . '/languages' );
		self::register_schema();
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3TL_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', static fn() => Assets::register( array( 'admin' ) ) );
		add_action( Queue\Queue::HOOK, array( Queue\Queue::class, 'process' ) );
		Sync\Auto_Sync::register();
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
			( new Admin\Post_Metabox() )->register();
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Translator_Controller() )->register_routes();
	}
}
