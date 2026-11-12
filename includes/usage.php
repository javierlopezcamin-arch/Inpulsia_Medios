<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// IDs of attachments referenced anywhere on the site. Cached for an hour.
function inpulsia_medios_used_ids( $refresh = false ) {
	$cached = get_transient( 'inpulsia_medios_used' );
	if ( $cached !== false && ! $refresh ) {
		return $cached;
	}
	global $wpdb;
	$used  = [];
	$valid = "p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('revision','attachment')";

	foreach ( $wpdb->get_col( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_thumbnail_id' AND {$valid}" ) as $v ) {
		$used[ (int) $v ] = true;
	}
	foreach ( $wpdb->get_col( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_product_image_gallery' AND pm.meta_value <> '' AND {$valid}" ) as $v ) {
		foreach ( explode( ',', $v ) as $img ) {
			$used[ (int) $img ] = true;
		}
	}
	$used[ (int) get_theme_mod( 'custom_logo' ) ] = true;
	$used[ (int) get_option( 'site_icon' ) ]      = true;

	$by_path = [];
	foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" ) as $r ) {
		$by_path[ inpulsia_medios_path_key( $r->meta_value ) ] = (int) $r->post_id;
	}
	$scan = function ( $text ) use ( &$used, $by_path ) {
		$text = str_replace( '\/', '/', $text );
		if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$used[ (int) $id ] = true;
			}
		}
		if ( preg_match_all( '#/uploads/([^"\'\s<>()]+?\.(?:jpe?g|png|gif|webp|avif))#i', $text, $m ) ) {
			foreach ( $m[1] as $path ) {
				$key = inpulsia_medios_path_key( $path );
				if ( isset( $by_path[ $key ] ) ) {
					$used[ $by_path[ $key ] ] = true;
				}
			}
		}
	};

	$like = '%' . $wpdb->esc_like( '/uploads/' ) . '%';
	$like_e = '%' . $wpdb->esc_like( '\/uploads\/' ) . '%';
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT p.post_content FROM {$wpdb->posts} p WHERE {$valid} AND (p.post_content LIKE %s OR p.post_content LIKE %s)", $like, '%wp-image-%' ) ) as $text ) {
		$scan( $text );
	}
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE {$valid} AND pm.meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata') AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)", $like, $like_e ) ) as $text ) {
		$scan( $text );
	}
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND (option_value LIKE %s OR option_value LIKE %s)", $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', $like, $like_e ) ) as $text ) {
		$scan( $text );
	}

	unset( $used[0] );
	$ids = array_map( 'intval', array_keys( $used ) );
	sort( $ids );
	set_transient( 'inpulsia_medios_used', $ids, HOUR_IN_SECONDS );
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
