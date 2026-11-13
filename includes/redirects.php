<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INPULSIA_MEDIOS_DB_VERSION = '1';

add_action( 'plugins_loaded', 'inpulsia_medios_maybe_install' );
add_action( 'init', 'inpulsia_medios_redirect', 0 );

function inpulsia_medios_redirects_table() {
	global $wpdb;
	return $wpdb->prefix . 'inpulsia_medios_redirects';
}

function inpulsia_medios_maybe_install() {
	if ( get_option( 'inpulsia_medios_db' ) !== INPULSIA_MEDIOS_DB_VERSION ) {
		inpulsia_medios_install();
	}
}

function inpulsia_medios_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = inpulsia_medios_redirects_table();
	dbDelta( "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		source varchar(191) NOT NULL,
		target varchar(191) NOT NULL,
		hits bigint(20) unsigned NOT NULL DEFAULT 0,
		created datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY source (source)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'inpulsia_medios_db', INPULSIA_MEDIOS_DB_VERSION );
}

function inpulsia_medios_uploads_path() {
	$path = wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
	return untrailingslashit( (string) $path );
}

// $map: old file name => new file name, both inside the uploads sub-folder $rel.
function inpulsia_medios_redirects_add( $rel, $map, $new_rel = null ) {
	global $wpdb;
	$table = inpulsia_medios_redirects_table();
	$dir   = inpulsia_medios_uploads_path() . '/' . ( $rel === '' ? '' : $rel . '/' );
	$to    = $new_rel === null ? $dir : inpulsia_medios_uploads_path() . '/' . ( $new_rel === '' ? '' : $new_rel . '/' );

	foreach ( $map as $old => $new ) {
		$source = $dir . $old;
		$target = $to . $new;
		if ( strlen( $source ) > 191 || strlen( $target ) > 191 ) {
			continue;
		}
		// The target exists again as a real file, so any redirect away from it is obsolete.
		$wpdb->delete( $table, [ 'source' => $target ] );
		$wpdb->update( $table, [ 'target' => $target ], [ 'target' => $source ] );
		$wpdb->replace( $table, [
			'source'  => $source,
			'target'  => $target,
			'hits'    => 0,
			'created' => current_time( 'mysql' ),
		] );
	}
}

// Only requests for missing files reach WordPress, so no is_404() check (plain permalinks never set it).
function inpulsia_medios_redirect() {
	// Not sanitized on purpose: sanitize_text_field() would drop %-encoded characters; the value only feeds a prepared query.
	$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( ! $path || strpos( $path, inpulsia_medios_uploads_path() . '/' ) !== 0 ) {
		return;
	}
	global $wpdb;
	$table = inpulsia_medios_redirects_table();
	$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, target FROM %i WHERE source = %s', $table, rawurldecode( $path ) ) );
	if ( ! $row ) {
		return;
	}
	// Never send visitors to a file that is not there: a plain 404 is better than a redirect to one.
	$upath = inpulsia_medios_uploads_path();
	if ( strpos( $row->target, $upath . '/' ) === 0 && ! file_exists( wp_get_upload_dir()['basedir'] . '/' . rawurldecode( substr( $row->target, strlen( $upath ) + 1 ) ) ) ) {
		return;
	}
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1 WHERE id = %d', $table, $row->id ) );
	wp_safe_redirect( $row->target, 301, 'Inpulsia Medios' );
	exit;
}

function inpulsia_medios_redirects_list( $limit = 200 ) {
	global $wpdb;
	$table = inpulsia_medios_redirects_table();
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ) );
}

function inpulsia_medios_redirects_count() {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', inpulsia_medios_redirects_table() ) );
}
