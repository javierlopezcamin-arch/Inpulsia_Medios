<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Weight of the served file, kept in a numeric meta so the library can be filtered and sorted by it.
const INPULSIA_MEDIOS_BYTES_KEY = '_inpulsia_medios_bytes';

add_filter( 'wp_update_attachment_metadata', 'inpulsia_medios_track_size', 10, 2 );

function inpulsia_medios_weights() {
	return [
		'300k' => [ 300 * KB_IN_BYTES, 'Más de 300 KB' ],
		'1m'   => [ MB_IN_BYTES, 'Más de 1 MB' ],
		'2m'   => [ 2 * MB_IN_BYTES, 'Más de 2 MB' ],
	];
}

function inpulsia_medios_formats() {
	return [
		'jpg'   => [ [ 'image/jpeg' ], 'JPG' ],
		'png'   => [ [ 'image/png' ], 'PNG' ],
		'webp'  => [ [ 'image/webp' ], 'WebP' ],
		'heavy' => [ [ 'image/png', 'image/gif', 'image/bmp', 'image/tiff' ], 'PNG, GIF y otros pesados' ],
	];
}

function inpulsia_medios_store_size( $id ) {
	$file = get_attached_file( $id );
	if ( $file && file_exists( $file ) ) {
		update_post_meta( $id, INPULSIA_MEDIOS_BYTES_KEY, (int) filesize( $file ) );
	}
}

function inpulsia_medios_track_size( $data, $id ) {
	clearstatcache();
	inpulsia_medios_store_size( $id );
	return $data;
}

// Fills the index for images that do not have it yet (older uploads, REST uploads before activation…).
function inpulsia_medios_sizes_sync( $limit = 3000 ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		 WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type LIKE %s AND m.meta_id IS NULL
		 LIMIT %d",
		INPULSIA_MEDIOS_BYTES_KEY,
		'image/%',
		$limit
	) );
	foreach ( $ids as $id ) {
		inpulsia_medios_store_size( (int) $id );
	}
	return count( $ids );
}
