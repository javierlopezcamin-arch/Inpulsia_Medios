<?php
$root = dirname( __DIR__ );

require $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: $root . '/vendor/wp-phpunit/wp-phpunit';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () use ( $root ) {
	require $root . '/inpulsia-medios.php';
	require_once $root . '/includes/parser.php';
	require_once $root . '/includes/export.php';
} );

require $tests_dir . '/includes/bootstrap.php';
