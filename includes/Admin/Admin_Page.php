<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Admin;

use Dev3\AiTranslatorLocalizer\Core3\Core\Assets;
use Dev3\AiTranslatorLocalizer\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3TL_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3TL_NAME, __( 'DEV3 AI Translator', 'dev3-ai-translator-localizer' ), Capabilities::manage(), DEV3TL_SLUG, array( $this, 'render' ), 'dashicons-translation', 82 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		echo '<div class="wrap"><div id="dev3tl-admin-root" class="dev3tl-root"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3TL_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-translator-localizer' ) . '</a>' );
		return $links;
	}
}
