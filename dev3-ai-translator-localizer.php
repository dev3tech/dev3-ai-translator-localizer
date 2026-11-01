<?php
/**
 * Plugin Name:       DEV3 AI Translator & Cultural Localizer for WPML, TranslatePress & Polylang
 * Plugin URI:        https://dev3.tech/item/dev3-ai-translator-localizer
 * Description:       AI translation with cultural adaptation, glossary, translation memory and delta sync for WPML, TranslatePress and Polylang.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-translator-localizer
 * Domain Path:       /languages
 *
 * @package Dev3\AiTranslatorLocalizer
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3TL_VERSION', '1.0.0' );
define( 'DEV3TL_FILE', __FILE__ );
define( 'DEV3TL_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3TL_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3TL_SLUG', 'dev3-ai-translator-localizer' );
define( 'DEV3TL_NAME', 'DEV3 AI Translator & Cultural Localizer for WPML, TranslatePress & Polylang' );
define( 'DEV3TL_MIN_PHP', '7.4' );
define( 'DEV3TL_MIN_WP', '6.0' );
define( 'DEV3TL_DB_VERSION', '1.1.0' );

if ( version_compare( PHP_VERSION, DEV3TL_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\\AiTranslatorLocalizer\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3TL_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiTranslatorLocalizer\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiTranslatorLocalizer\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiTranslatorLocalizer\Plugin', 'instance' ) );
