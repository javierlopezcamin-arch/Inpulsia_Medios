<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'inpulsia\_medios\_%' OR option_name LIKE '\_transient\_inpulsia\_medios\_%' OR option_name LIKE '\_transient\_timeout\_inpulsia\_medios\_%'" );
