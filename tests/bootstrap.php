<?php
$root = dirname( __DIR__ );

require $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: $root . '/vendor/wp-phpunit/wp-phpunit';
require_once $tests_dir . '/includes/functions.php';

// Assertions use the Spanish source texts; translations have their own test.
tests_add_filter( 'determine_locale', function () {
	return 'es_ES';
} );

tests_add_filter( 'muplugins_loaded', function () use ( $root ) {
	require $root . '/inpulsia-medios.php';
	require_once $root . '/includes/parser.php';
	require_once $root . '/includes/export.php';
	require_once $root . '/includes/usage.php';
	require_once $root . '/includes/optimize.php';
	require_once $root . '/includes/ai.php';
	require_once $root . '/includes/admin.php';
	require_once $root . '/includes/media.php';
	require_once $root . '/includes/guide.php';
	require_once $root . '/includes/i18n-js.php';
	require_once $root . '/includes/cleanup.php';
	require_once $root . '/includes/cleanup-admin.php';
	require_once $root . '/includes/bulk-filter.php';
	require_once $root . '/includes/ux.php';
	require_once $root . '/includes/sections.php';
} );

require $tests_dir . '/includes/bootstrap.php';
