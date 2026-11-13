<?php
/**
 * Plugin Name: Inpulsia Medios
 * Plugin URI: https://github.com/javierlopezcamin-arch/Inpulsia_Medios
 * Description: Optimiza las imágenes y completa nombres, títulos y textos alternativos en bloque: tabla editable, CSV/Excel, plantillas e IA, con redirecciones y deshacer.
 * Version: 1.11.2
 * Domain Path: /languages
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

define( 'INPULSIA_MEDIOS_VERSION', '1.11.2' );
define( 'INPULSIA_MEDIOS_PATH', plugin_dir_path( __FILE__ ) );
define( 'INPULSIA_MEDIOS_URL', plugin_dir_url( __FILE__ ) );
define( 'INPULSIA_MEDIOS_CLEAR', '[vaciar]' );
define( 'INPULSIA_MEDIOS_BATCH', 10 );

require_once INPULSIA_MEDIOS_PATH . 'includes/core.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/redirects.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/grace.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/sizes.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/library.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/usage.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/auto.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/perf.php';
require_once INPULSIA_MEDIOS_PATH . 'includes/updater.php';

add_action( 'init', 'inpulsia_medios_load_textdomain' );

// Texts are written in Spanish; English and Catalan ship in /languages. Regional variants (en_GB, ca_ES…) fall back to them.
function inpulsia_medios_load_textdomain() {
	$locale = determine_locale();
	$dir    = INPULSIA_MEDIOS_PATH . 'languages/';
	foreach ( [ $locale, substr( $locale, 0, 2 ) === 'en' ? 'en_US' : substr( $locale, 0, 2 ) ] as $try ) {
		if ( file_exists( $dir . 'inpulsia-medios-' . $try . '.mo' ) ) {
			load_textdomain( 'inpulsia-medios', $dir . 'inpulsia-medios-' . $try . '.mo', $locale );
			return;
		}
	}
}

register_activation_hook( __FILE__, 'inpulsia_medios_install' );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'inpulsia_medios_cleanup' );
} );
new Inpulsia_Medios_Updater( __FILE__ );

if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once INPULSIA_MEDIOS_PATH . 'includes/parser.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/export.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/optimize.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/ai.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/cleanup.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/ux.php';
}
if ( is_admin() ) {
	require_once INPULSIA_MEDIOS_PATH . 'includes/admin.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/i18n-js.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/media.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/cleanup-admin.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/editors.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/bulk-filter.php';
	require_once INPULSIA_MEDIOS_PATH . 'includes/guide.php';
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once INPULSIA_MEDIOS_PATH . 'includes/cli.php';
}
