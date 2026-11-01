<?php
/**
 * Base test case: tables installed, API key set, Polylang languages en/de/fr.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

use Dev3\AiTranslatorLocalizer\Core3\Core\Schema;
use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;
use Dev3\AiTranslatorLocalizer\Data\Glossary;
use Dev3\AiTranslatorLocalizer\Providers\Providers;
use Dev3\AiTranslatorLocalizer\Sync\Sync_Engine;

/**
 * Shared setup.
 */
abstract class Dev3tl_Test_Case extends WP_UnitTestCase {

	/**
	 * Mock.
	 *
	 * @var Dev3tl_Mock_Gemini|null
	 */
	protected $mock = null;

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		Schema::install( DEV3TL_DB_VERSION );
		Settings_Store::flush();
		Settings_Store::update(
			array(
				'api_key'           => 'AIza-test-key-0000',
				'translation_model' => 'gemini-2.5-pro',
				'auto_sync'         => false,
			)
		);
		Providers::reset();
		Glossary::flush();
		Sync_Engine::$translator = null;
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		if ( $this->mock ) {
			$this->mock->stop();
			$this->mock = null;
		}
		Providers::reset();
		parent::tear_down();
	}

	/**
	 * Creates Polylang languages (once per test; the DB is rolled back after each test).
	 *
	 * @return bool Whether Polylang is usable.
	 */
	protected function polylang() {
		if ( ! function_exists( 'PLL' ) || ! isset( $GLOBALS['polylang'] ) ) {
			return false;
		}
		$model = PLL()->model;
		self::clean_pll_cache( $model ); // The DB is rolled back between tests; Polylang's in-memory cache is not.
		if ( ! $model->get_language( 'en' ) ) {
			foreach ( array(
				array( 'en_US', 'en', 'English', 0 ),
				array( 'de_DE', 'de', 'Deutsch', 1 ),
				array( 'fr_FR', 'fr', 'Français', 2 ),
			) as $l ) {
				$args = array(
					'locale'         => $l[0],
					'slug'           => $l[1],
					'name'           => $l[2],
					'term_group'     => $l[3],
					'no_default_cat' => true,
				);
				if ( isset( $model->languages ) && method_exists( $model->languages, 'add' ) ) {
					$model->languages->add( $args );
				} else {
					$model->add_language( $args );
				}
			}
			self::clean_pll_cache( $model );
		}
		$options = get_option( 'polylang' );
		if ( is_array( $options ) && ( empty( $options['default_lang'] ) || 'en' !== $options['default_lang'] ) ) {
			$options['default_lang'] = 'en';
			update_option( 'polylang', $options );
		}
		if ( isset( $model->options ) && is_object( $model->options ) && method_exists( $model->options, 'set' ) ) {
			$model->options->set( 'default_lang', 'en' );
		}
		return (bool) PLL()->model->get_language( 'de' );
	}

	/**
	 * Clears Polylang's language caches.
	 *
	 * @param object $model PLL model.
	 */
	private static function clean_pll_cache( $model ) {
		if ( isset( $model->languages ) && method_exists( $model->languages, 'clean_cache' ) ) {
			$model->languages->clean_cache();
		} elseif ( method_exists( $model, 'clean_languages_cache' ) ) {
			$model->clean_languages_cache();
		}
		if ( isset( $model->cache ) && method_exists( $model->cache, 'clean' ) ) {
			$model->cache->clean();
		}
	}

	/**
	 * Source post in English.
	 *
	 * @param string $content Content.
	 * @param array  $extra   Extra post data.
	 * @return int
	 */
	protected function source_post( $content, array $extra = array() ) {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => 'Standing desk guide',
					'post_content' => $content,
					'post_excerpt' => '',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				),
				$extra
			)
		);
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $id, 'en' );
		}
		return $id;
	}

	/**
	 * Admin user.
	 *
	 * @return int
	 */
	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}
}
