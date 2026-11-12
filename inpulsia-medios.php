<?php
/**
 * Plugin Name: Inpulsia Medios
 * Plugin URI: https://github.com/javierlopezcamin-arch/Inpulsia_Medios
 * Description: Actualización masiva de medios (nombre de archivo, título, alt, leyenda y descripción) mediante CSV con indicaciones SEO.
 * Version: 1.3.3
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Inpulsia
 * Author URI: https://inpulsia.es
 * License: GPL-2.0+
 * Text Domain: inpulsia-medios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INPULSIA_MEDIOS_VERSION', '1.3.3' );
define( 'INPULSIA_MEDIOS_PATH', plugin_dir_path( __FILE__ ) );
define( 'INPULSIA_MEDIOS_URL', plugin_dir_url( __FILE__ ) );
define( 'INPULSIA_MEDIOS_CLEAR', '[vaciar]' );
define( 'INPULSIA_MEDIOS_BATCH', 10 );

require_once INPULSIA_MEDIOS_PATH . 'includes/core.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/redirects.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/updater.php';

register_activation_hook( __FILE__, 'inpulsia_medios_install' );
new Inpulsia_Medios_Updater( __FILE__ );

if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once INPULSIA_MEDIOS_PATH . 'includes/parser.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/export.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/usage.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/optimize.php';
}
if ( is_admin() ) {
	require_once INPULSIA_MEDIOS_PATH . 'includes/admin.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/ai.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/guide.php';
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once INPULSIA_MEDIOS_PATH . 'includes/cli.php';
}
