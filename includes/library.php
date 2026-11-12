<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------- Friendly names ---------- */

// Camera/generic names, or names with capitals, spaces, underscores or accents.
function inpulsia_medios_is_bad_name( $stem ) {
	$stem = preg_replace( '/(-\d+x\d+|-scaled|-rotated)+$/', '', (string) $stem );
	if ( $stem === '' ) {
		return false;
	}
	return inpulsia_medios_is_camera_name( $stem ) || (bool) preg_match( '/[^a-z0-9-]|--|^.{0,3}$/', $stem );
}

// IDs of images whose file name is not SEO friendly.
function inpulsia_medios_bad_name_ids() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT p.ID, m.meta_value FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
		 WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type LIKE 'image/%'"
	);
	$ids = [];
	foreach ( $rows as $r ) {
		if ( inpulsia_medios_is_bad_name( pathinfo( $r->meta_value, PATHINFO_FILENAME ) ) ) {
			$ids[] = (int) $r->ID;
		}
	}
	return $ids;
}

/* ---------- Where an image is used ---------- */

// Up to $limit published posts that show the image: featured, in content or in Elementor.
function inpulsia_medios_where_used( $id, $limit = 5 ) {
	global $wpdb;
	$rel    = (string) get_post_meta( $id, '_wp_attached_file', true );
	$stem   = inpulsia_medios_stem( $id );
	$dir    = dirname( $rel );
	$prefix = '/' . ( $dir === '.' ? '' : $dir . '/' ) . $stem;
	$like   = '%' . $wpdb->esc_like( $prefix ) . '%';
	$like_e = '%' . $wpdb->esc_like( str_replace( '/', '\/', $prefix ) ) . '%';

	$ids = array_merge(
		$wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT %d", $id, $limit ) ),
		$wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status NOT IN ('trash','auto-draft') AND (post_content LIKE %s OR post_content LIKE %s) LIMIT %d", $like, '%wp-image-' . (int) $id . '"%', $limit ) ),
		$wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND (meta_value LIKE %s OR meta_value LIKE %s) LIMIT %d", $like, $like_e, $limit ) )
	);
	$out = [];
	foreach ( array_unique( array_map( 'intval', $ids ) ) as $pid ) {
		$p = get_post( $pid );
		if ( ! $p || in_array( $p->post_status, [ 'trash', 'auto-draft', 'inherit' ], true ) || $p->post_type === 'revision' ) {
			continue;
		}
		$out[] = [
			'id'    => $pid,
			'title' => $p->post_title !== '' ? $p->post_title : '#' . $pid,
			'url'     => $p->post_type === 'elementor_library' ? (string) get_edit_post_link( $pid, 'raw' ) : (string) get_permalink( $pid ),
			'keyword' => inpulsia_medios_focus_keyword( $pid ),
		];
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

// Main keyword set in Rank Math, Yoast SEO or SEOPress for a post.
function inpulsia_medios_focus_keyword( $post_id ) {
	foreach ( [ 'rank_math_focus_keyword', '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw' ] as $key ) {
		$kw = trim( explode( ',', (string) get_post_meta( $post_id, $key, true ) )[0] );
		if ( $kw !== '' ) {
			return $kw;
		}
	}
	return '';
}

/* ---------- WPML / Polylang ---------- */

function inpulsia_medios_multilang() {
	if ( function_exists( 'pll_get_post_language' ) ) {
		return 'polylang';
	}
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		return 'wpml';
	}
	return '';
}

function inpulsia_medios_lang( $id ) {
	$plugin = inpulsia_medios_multilang();
	if ( $plugin === 'polylang' ) {
		return (string) pll_get_post_language( $id );
	}
	if ( $plugin === 'wpml' ) {
		return (string) apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $id, 'element_type' => 'attachment' ] );
	}
	return '';
}

// Query args that show every language instead of only the active one.
function inpulsia_medios_all_langs_args( $args ) {
	$args['lang']             = '';
	$args['suppress_filters'] = true;
	return $args;
}

// Other attachments (translations) that point to the same file.
function inpulsia_medios_siblings( $id ) {
	global $wpdb;
	$rel = (string) get_post_meta( $id, '_wp_attached_file', true );
	if ( $rel === '' ) {
		return [];
	}
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d",
		$rel,
		$id
	) ) );
}

// After a rename/optimization, translations keep pointing to the same (new) file.
function inpulsia_medios_siblings_sync( $id, $siblings ) {
	global $wpdb;
	if ( ! $siblings ) {
		return;
	}
	$rel  = get_post_meta( $id, '_wp_attached_file', true );
	$meta = wp_get_attachment_metadata( $id );
	$mime = get_post_mime_type( $id );
	foreach ( $siblings as $sid ) {
		update_post_meta( $sid, '_wp_attached_file', $rel );
		if ( is_array( $meta ) ) {
			update_post_meta( $sid, '_wp_attachment_metadata', $meta );
		}
		$wpdb->update( $wpdb->posts, [ 'post_mime_type' => $mime ], [ 'ID' => $sid ] );
		$wpdb->update( $wpdb->posts, [ 'guid' => wp_get_attachment_url( $sid ) ], [ 'ID' => $sid ] );
		update_post_meta( $sid, INPULSIA_MEDIOS_BYTES_KEY, (int) get_post_meta( $id, INPULSIA_MEDIOS_BYTES_KEY, true ) );
		clean_post_cache( $sid );
	}
}

/* ---------- Library health ---------- */

// 0-100: alt texts weigh most, then weight, names and format.
function inpulsia_medios_score( $s ) {
	if ( empty( $s['total'] ) ) {
		return 100;
	}
	$t   = $s['total'];
	$pen = 40 * $s['missing'] / $t + 30 * $s['big'] / $t + 20 * $s['names'] / $t + 10 * $s['png'] / $t;
	return (int) max( 0, min( 100, round( 100 - $pen ) ) );
}
