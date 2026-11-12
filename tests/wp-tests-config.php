<?php
define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress', '/' ) . '/' );

define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASS' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

if ( getenv( 'WP_SQLITE_DIR' ) ) {
	define( 'DB_DIR', getenv( 'WP_SQLITE_DIR' ) );
	define( 'DB_FILE', 'tests.sqlite' );
}

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Inpulsia Medios Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
