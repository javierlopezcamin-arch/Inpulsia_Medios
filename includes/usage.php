<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Usage index: which images each post shows, plus those referenced by options/theme.
 * It is built once and then kept up to date post by post when content is saved,
 * so large sites do not rescan everything every hour.
 */
const INPULSIA_MEDIOS_USAGE_KEY = 'inpulsia_medios_usage_idx';

add_action( 'save_post', 'inpulsia_medios_usage_touch' );
add_action( 'deleted_post', 'inpulsia_medios_usage_touch' );
add_action( 'trashed_post', 'inpulsia_medios_usage_touch' );
add_action( 'added_post_meta', 'inpulsia_medios_usage_meta_touch', 10, 3 );
add_action( 'updated_post_meta', 'inpulsia_medios_usage_meta_touch', 10, 3 );
add_action( 'deleted_post_meta', 'inpulsia_medios_usage_meta_touch', 10, 3 );

function inpulsia_medios_usage_touch( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || get_post_type( $post_id ) === 'attachment' ) {
		return;
	}
	$GLOBALS['inpulsia_medios_usage_dirty'][ (int) $post_id ] = true;
	if ( ! has_action( 'shutdown', 'inpulsia_medios_usage_flush' ) ) {
		add_action( 'shutdown', 'inpulsia_medios_usage_flush' );
	}
}

function inpulsia_medios_usage_meta_touch( $meta_id, $post_id, $key ) {
	if ( in_array( $key, [ '_thumbnail_id', '_product_image_gallery', '_elementor_data' ], true ) ) {
		inpulsia_medios_usage_touch( $post_id );
	}
}

// Rescans the posts saved during this request (only if the index already exists).
function inpulsia_medios_usage_flush() {
	$dirty = $GLOBALS['inpulsia_medios_usage_dirty'] ?? [];
	$GLOBALS['inpulsia_medios_usage_dirty'] = [];
	$idx   = get_option( INPULSIA_MEDIOS_USAGE_KEY );
	if ( ! $dirty || ! is_array( $idx ) ) {
		return;
	}
	foreach ( array_keys( $dirty ) as $pid ) {
		$ids = inpulsia_medios_usage_scan_post( $pid );
		if ( $ids ) {
			$idx['posts'][ $pid ] = $ids;
		} else {
			unset( $idx['posts'][ $pid ] );
		}
	}
	update_option( INPULSIA_MEDIOS_USAGE_KEY, $idx, false );
}

// "path key" => attachment IDs (translations share a file, so all of them count).
function inpulsia_medios_usage_paths() {
	static $by_path = null;
	if ( $by_path === null || ! empty( $GLOBALS['inpulsia_medios_usage_paths_reset'] ) ) {
		global $wpdb;
		$GLOBALS['inpulsia_medios_usage_paths_reset'] = false;
		$by_path = [];
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" ) as $r ) {
			$by_path[ inpulsia_medios_path_key( $r->meta_value ) ][] = (int) $r->post_id;
		}
	}
	return $by_path;
}

function inpulsia_medios_usage_scan_text( $text, &$found ) {
	$text = str_replace( '\/', '/', (string) $text );
	if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
		foreach ( $m[1] as $id ) {
			$found[ (int) $id ] = true;
		}
	}
	if ( preg_match_all( '#/uploads/([^"\'\s<>()]+?\.(?:jpe?g|png|gif|webp|avif))#i', $text, $m ) ) {
		$by_path = inpulsia_medios_usage_paths();
		foreach ( $m[1] as $path ) {
			foreach ( $by_path[ inpulsia_medios_path_key( $path ) ] ?? [] as $id ) {
				$found[ $id ] = true;
			}
		}
	}
}

// Image IDs shown by one post: featured image, gallery, content and meta (Elementor, ACF…).
function inpulsia_medios_usage_scan_post( $pid ) {
	global $wpdb;
	$p = get_post( $pid );
	if ( ! $p || in_array( $p->post_status, [ 'trash', 'auto-draft' ], true ) || in_array( $p->post_type, [ 'revision', 'attachment' ], true ) ) {
		return [];
	}
	$found = [];
	$thumb = (int) get_post_meta( $pid, '_thumbnail_id', true );
	if ( $thumb ) {
		$found[ $thumb ] = true;
	}
	foreach ( explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) as $img ) {
		if ( (int) $img ) {
			$found[ (int) $img ] = true;
		}
	}
	inpulsia_medios_usage_scan_text( $p->post_content, $found );
	$like   = '%' . $wpdb->esc_like( '/uploads/' ) . '%';
	$like_e = '%' . $wpdb->esc_like( '\/uploads\/' ) . '%';
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata') AND (meta_value LIKE %s OR meta_value LIKE %s)", $pid, $like, $like_e ) ) as $text ) {
		inpulsia_medios_usage_scan_text( $text, $found );
	}
	$ids = array_map( 'intval', array_keys( $found ) );
	sort( $ids );
	return $ids;
}

// Full rebuild: every post plus options and theme settings.
function inpulsia_medios_usage_build() {
	global $wpdb;
	$GLOBALS['inpulsia_medios_usage_paths_reset'] = true;
	$like   = '%' . $wpdb->esc_like( '/uploads/' ) . '%';
	$like_e = '%' . $wpdb->esc_like( '\/uploads\/' ) . '%';
	$skip   = "p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('revision','attachment')";
	$pids   = array_merge(
		$wpdb->get_col( "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ('_thumbnail_id','_product_image_gallery') AND pm.meta_value <> '' AND $skip" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant SQL.
		$wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE $skip AND (p.post_content LIKE %s OR p.post_content LIKE %s)", $like, '%wp-image-%' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant SQL.
		$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE $skip AND pm.meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata') AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)", $like, $like_e ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant SQL.
	);
	$posts = [];
	foreach ( array_unique( array_map( 'intval', $pids ) ) as $pid ) {
		$ids = inpulsia_medios_usage_scan_post( $pid );
		if ( $ids ) {
			$posts[ $pid ] = $ids;
		}
	}

	$global = [ (int) get_theme_mod( 'custom_logo' ) => true, (int) get_option( 'site_icon' ) => true ];
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name <> %s AND (option_value LIKE %s OR option_value LIKE %s)", $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', INPULSIA_MEDIOS_USAGE_KEY, $like, $like_e ) ) as $text ) {
		inpulsia_medios_usage_scan_text( $text, $global );
	}
	unset( $global[0] );

	$idx = [ 'posts' => $posts, 'global' => array_map( 'intval', array_keys( $global ) ), 'time' => time() ];
	update_option( INPULSIA_MEDIOS_USAGE_KEY, $idx, false );
	return $idx;
}

// IDs of existing images referenced anywhere on the site.
function inpulsia_medios_used_ids( $refresh = false ) {
	global $wpdb;
	$idx = get_option( INPULSIA_MEDIOS_USAGE_KEY );
	// Options and theme settings have no save hook: refresh them with a full rebuild once a day.
	if ( $refresh || ! is_array( $idx ) || ( $idx['time'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
		$idx = inpulsia_medios_usage_build();
	}
	$used = array_fill_keys( $idx['global'], true );
	foreach ( $idx['posts'] as $ids ) {
		foreach ( $ids as $id ) {
			$used[ $id ] = true;
		}
	}
	$existing = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash' AND post_mime_type LIKE 'image/%'" ) ) );
	$ids      = array_map( 'intval', array_keys( array_intersect_key( $used, $existing ) ) );
	sort( $ids );
	return $ids;
}

// "2026/01/Foto-300x200.jpg", ".../foto-scaled.jpg" and "foto.jpg.webp" all map to "2026/01/foto".
function inpulsia_medios_path_key( $path ) {
	$path = strtolower( rawurldecode( ltrim( $path, '/' ) ) );
	$dir  = dirname( $path );
	$name = preg_replace( '/\.(jpe?g|png|gif|webp|avif)$/', '', basename( $path ) );
	$name = preg_replace( '/\.(jpe?g|png|gif)$/', '', $name );
	$name = preg_replace( '/(-\d+x\d+|-scaled|-rotated|-e\d{10,})+$/', '', $name );
	return ( $dir === '.' ? '' : $dir . '/' ) . $name;
}
