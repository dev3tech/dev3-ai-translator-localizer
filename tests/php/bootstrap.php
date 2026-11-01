<?php
/**
 * PHPUnit bootstrap: WordPress test suite from wp-env (WP_TESTS_DIR) + this plugin and its dependencies.
 *
 * Run: npx wp-env run tests-cli --env-cwd=wp-content/plugins/dev3-ai-translator-localizer vendor/bin/phpunit
 *
 * @package Dev3\AiTranslatorLocalizer
 */

$dev3tl_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';
if ( ! file_exists( $dev3tl_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test suite not found in {$dev3tl_tests_dir}. Run the tests inside wp-env (tests-cli).\n"; // phpcs:ignore
	exit( 1 );
}
require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $dev3tl_tests_dir . '/includes/functions.php';

// Polylang is exercised for real; WooCommerce is not needed by the suite.
tests_add_filter(
	'dev3tl_test_dependencies',
	static function ( $deps ) {
		$deps[] = 'polylang.latest-stable/polylang.php';
		return $deps;
	}
);

// Polylang only boots when languages exist; force its admin context so the API is available.
tests_add_filter(
	'pll_context',
	static function () {
		return 'PLL_Admin';
	}
);

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// Load required third-party plugins first when they are installed in wp-env (e.g. WooCommerce).
		foreach ( (array) apply_filters( 'dev3tl_test_dependencies', array() ) as $dev3tl_dep ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $dev3tl_dep ) ) {
				require WP_PLUGIN_DIR . '/' . $dev3tl_dep;
			}
		}
		require dirname( __DIR__, 2 ) . '/dev3-ai-translator-localizer.php';
	}
);

require $dev3tl_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/helpers/class-dev3tl-mock-gemini.php';
require __DIR__ . '/helpers/class-dev3tl-test-case.php';
