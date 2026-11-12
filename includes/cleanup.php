<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INPULSIA_MEDIOS_HASH_KEY = '_inpulsia_medios_md5';

/* ---------- Safe delete (undoable from Historial) ---------- */

/**
 * Deletes an image after copying its files, post row and meta to a backup,
 * so "Deshacer" can bring it back with the same ID and URLs.
 */
function inpulsia_medios_trash_image( $id ) {
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		return [ 'status' => 'error', 'msg' => __( 'Medio no encontrado.', 'inpulsia-medios' ) ];
	}
	if ( ! current_user_can( 'delete_post', $id ) ) {
		return [ 'status' => 'error', 'msg' => __( 'No tienes permisos para borrar este medio.', 'inpulsia-medios' ) ];
	}
	if ( inpulsia_medios_siblings( $id ) ) {
		return [ 'status' => 'skip', 'msg' => __( 'Tiene traducciones que usan el mismo archivo: bórrala desde el plugin de idiomas.', 'inpulsia-medios' ) ];
	}
	$key = inpulsia_medios_backup_create( $id );
	if ( is_wp_error( $key ) ) {
		return [ 'status' => 'error', 'msg' => $key->get_error_message() ];
	}
	$dir  = inpulsia_medios_backup_dir() . '/' . $key;
	$data = json_decode( (string) file_get_contents( $dir . '/backup.json' ), true );

	$data['post']    = get_post( $id, ARRAY_A );
	$data['postmeta'] = get_post_meta( $id );
	file_put_contents( $dir . '/backup.json', wp_json_encode( $data ) );

	$bytes = (int) get_post_meta( $id, INPULSIA_MEDIOS_BYTES_KEY, true );
	if ( ! wp_delete_attachment( $id, true ) ) {
		inpulsia_medios_rmdir( $dir );
		return [ 'status' => 'error', 'msg' => __( 'WordPress no ha podido borrar la imagen.', 'inpulsia-medios' ) ];
	}
	$GLOBALS['inpulsia_medios_changed'] = true;
	return [
		'status' => 'ok',
		'msg'    => sprintf( __( 'Enviada a la papelera (%s liberados). Se puede deshacer desde el Historial.', 'inpulsia-medios' ), size_format( $bytes, 1 ) ),
		'prev'   => [ 'undelete' => $key ],
		'before' => $bytes,
		'after'  => 0,
	];
}

// Recreates an image deleted by inpulsia_medios_trash_image().
function inpulsia_medios_undelete( $key ) {
	global $wpdb;
	$key  = sanitize_file_name( $key );
	$path = inpulsia_medios_backup_dir() . '/' . $key;
	$data = is_file( $path . '/backup.json' ) ? json_decode( (string) file_get_contents( $path . '/backup.json' ), true ) : null;
	if ( ! $data || empty( $data['post'] ) ) {
		return new WP_Error( 'no_backup', __( 'La copia de seguridad ya no existe.', 'inpulsia-medios' ) );
	}
	$id = (int) $data['id'];
	if ( get_post( $id ) ) {
		return new WP_Error( 'exists', __( 'Ya existe un medio con este ID.', 'inpulsia-medios' ) );
	}
	$rel = dirname( $data['file'] );
	$dir = untrailingslashit( wp_get_upload_dir()['basedir'] ) . ( $rel === '.' ? '' : '/' . $rel );
	wp_mkdir_p( $dir );
	foreach ( $data['files'] as $f ) {
		if ( ! @copy( $path . '/' . $f, $dir . '/' . $f ) ) {
			return new WP_Error( 'restore', sprintf( __( 'No se pudo restaurar %s.', 'inpulsia-medios' ), $f ) );
		}
		inpulsia_medios_fix_perms( $dir . '/' . $f );
	}
	$row = array_intersect_key( $data['post'], array_flip( [ 'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_name', 'post_modified', 'post_modified_gmt', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type' ] ) );
	$wpdb->insert( $wpdb->posts, $row );
	foreach ( (array) $data['postmeta'] as $meta_key => $values ) {
		foreach ( (array) $values as $v ) {
			add_post_meta( $id, $meta_key, maybe_unserialize( $v ) );
		}
	}
	clean_post_cache( $id );
	inpulsia_medios_rmdir( $path );
	return true;
}

/* ---------- Unused images ---------- */

// Images with no detected use, oldest first. Images uploaded in the last 7 days are left out (they may be in an unsaved draft).
function inpulsia_medios_unused_ids() {
	$ids = get_posts( inpulsia_medios_editar_args( [ 'usage' => 'unused' ] ) + [
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'date_query'     => [ [ 'before' => '7 days ago', 'column' => 'post_date_gmt' ] ],
	] );
	return array_map( 'intval', $ids );
}

/* ---------- Duplicates ---------- */

function inpulsia_medios_hash_source( $id ) {
	$file = get_attached_file( $id );
	$meta = wp_get_attachment_metadata( $id );
	if ( $file && ! empty( $meta['original_image'] ) && file_exists( dirname( $file ) . '/' . $meta['original_image'] ) ) {
		return dirname( $file ) . '/' . $meta['original_image'];
	}
	return $file;
}

// Hashes images that do not have one yet (a file change clears it, see sizes.php).
function inpulsia_medios_hashes_sync( $limit = 2000 ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		 WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type LIKE %s AND m.meta_id IS NULL LIMIT %d",
		INPULSIA_MEDIOS_HASH_KEY,
		'image/%',
		$limit
	) );
	foreach ( $ids as $id ) {
		$src = inpulsia_medios_hash_source( (int) $id );
		update_post_meta( (int) $id, INPULSIA_MEDIOS_HASH_KEY, $src && file_exists( $src ) ? md5_file( $src ) : 'missing' );
	}
	return count( $ids );
}

/**
 * Groups of identical images stored as different files. Translations (same file) count as one.
 * Each group: [ 'keep' => id, 'dupes' => [ ids ] ]; the image in use (or the oldest) is kept.
 */
function inpulsia_medios_duplicate_groups() {
	global $wpdb;
	inpulsia_medios_hashes_sync();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT m.post_id, m.meta_value AS hash, f.meta_value AS file FROM {$wpdb->postmeta} m
		 JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 JOIN {$wpdb->postmeta} f ON f.post_id = m.post_id AND f.meta_key = '_wp_attached_file'
		 WHERE m.meta_key = %s AND m.meta_value <> 'missing' AND p.post_type = 'attachment' AND p.post_status <> 'trash'
		 AND m.meta_value IN ( SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value HAVING COUNT(*) > 1 )
		 ORDER BY m.post_id",
		INPULSIA_MEDIOS_HASH_KEY,
		INPULSIA_MEDIOS_HASH_KEY
	) );
	$by_hash = [];
	foreach ( $rows as $r ) {
		$by_hash[ $r->hash ][ $r->file ] = $by_hash[ $r->hash ][ $r->file ] ?? (int) $r->post_id;
	}
	$used   = array_flip( inpulsia_medios_used_ids() );
	$groups = [];
	foreach ( $by_hash as $files ) {
		if ( count( $files ) < 2 ) {
			continue;
		}
		$ids = array_values( $files );
		usort( $ids, function ( $a, $b ) use ( $used ) {
			return [ isset( $used[ $b ] ), -$b ] <=> [ isset( $used[ $a ] ), -$a ];
		} );
		$groups[] = [ 'keep' => array_shift( $ids ), 'dupes' => $ids ];
	}
	return $groups;
}

// Points every use of $dupe to $keep (URLs, block/Elementor IDs, featured images, galleries) and redirects its old URLs.
function inpulsia_medios_merge_into( $dupe, $keep ) {
	global $wpdb;
	$d_meta = wp_get_attachment_metadata( $dupe ) ?: [];
	$k_meta = wp_get_attachment_metadata( $keep ) ?: [];
	$d_main = basename( (string) get_attached_file( $dupe ) );
	$k_main = basename( (string) get_attached_file( $keep ) );
	$map    = [ $d_main => $k_main ];
	if ( ! empty( $d_meta['original_image'] ) ) {
		$map[ $d_meta['original_image'] ] = $k_meta['original_image'] ?? $k_main;
	}
	foreach ( $d_meta['sizes'] ?? [] as $name => $s ) {
		$map[ $s['file'] ] = $k_meta['sizes'][ $name ]['file'] ?? $k_main;
	}
	$d_rel = inpulsia_medios_rel_dir( $dupe );
	$k_rel = inpulsia_medios_rel_dir( $keep );
	$n     = inpulsia_medios_replace_urls( $d_rel, inpulsia_medios_stem( $dupe ), $map, $dupe, $k_rel );

	// IDs in blocks, image classes and Elementor settings, only where the kept image now appears.
	$like  = '%' . $wpdb->esc_like( inpulsia_medios_stem( $keep ) ) . '%';
	$ids   = [ 'wp-image-' . $dupe . '"' => 'wp-image-' . $keep . '"', 'wp-image-' . $dupe . ' ' => 'wp-image-' . $keep . ' ', '"id":' . $dupe . ',' => '"id":' . $keep . ',', '"id":' . $dupe . '}' => '"id":' . $keep . '}' ];
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_content LIKE %s", $like ) ) as $p ) {
		$c = strtr( $p->post_content, $ids );
		if ( $c !== $p->post_content ) {
			$wpdb->update( $wpdb->posts, [ 'post_content' => $c ], [ 'ID' => $p->ID ] );
			clean_post_cache( $p->ID );
		}
	}
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s", $like ) ) as $m ) {
		$v = strtr( $m->meta_value, $ids );
		if ( $v !== $m->meta_value ) {
			$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $v ], [ 'meta_id' => $m->meta_id ] );
			delete_post_meta( $m->post_id, '_elementor_css' );
			delete_post_meta( $m->post_id, '_elementor_element_cache' );
			wp_cache_delete( $m->post_id, 'post_meta' );
			$GLOBALS['inpulsia_medios_elementor_dirty'] = true;
		}
	}
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s", (string) $dupe ) ) as $pid ) {
		update_post_meta( (int) $pid, '_thumbnail_id', $keep );
		$n++;
	}
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value LIKE %s", '%' . $wpdb->esc_like( (string) $dupe ) . '%' ) ) as $g ) {
		if ( ! in_array( (string) $dupe, explode( ',', $g->meta_value ), true ) ) {
			continue;
		}
		$list = array_unique( array_map( function ( $i ) use ( $dupe, $keep ) {
			return (int) $i === $dupe ? $keep : (int) $i;
		}, explode( ',', $g->meta_value ) ) );
		update_post_meta( (int) $g->post_id, '_product_image_gallery', implode( ',', $list ) );
		$n++;
	}
	inpulsia_medios_redirects_add( $d_rel, $map, $k_rel );
	return $n;
}

function inpulsia_medios_dedupe( $dupe, $keep ) {
	if ( ! get_post( $keep ) ) {
		return [ 'status' => 'error', 'msg' => __( 'La imagen que se conserva ya no existe.', 'inpulsia-medios' ) ];
	}
	if ( ! current_user_can( 'delete_post', $dupe ) || ! current_user_can( 'edit_post', $keep ) ) {
		return [ 'status' => 'error', 'msg' => __( 'No tienes permisos para borrar este medio.', 'inpulsia-medios' ) ];
	}
	if ( inpulsia_medios_siblings( $dupe ) ) {
		return [ 'status' => 'skip', 'msg' => __( 'Tiene traducciones que usan el mismo archivo: bórrala desde el plugin de idiomas.', 'inpulsia-medios' ) ];
	}
	$n = inpulsia_medios_merge_into( $dupe, $keep );
	$r = inpulsia_medios_trash_image( $dupe );
	if ( $r['status'] === 'ok' ) {
		$r['msg'] = sprintf( __( 'Unificada con la imagen %1$d (%2$d usos actualizados). %3$s', 'inpulsia-medios' ), $keep, $n, $r['msg'] );
	}
	return $r;
}

/* ---------- Broken images ---------- */

// Image URLs in content and Elementor that point to files missing from uploads, with a fix when one can be found.
function inpulsia_medios_broken_scan() {
	global $wpdb;
	$base   = wp_get_upload_dir();
	$upath  = wp_parse_url( $base['baseurl'], PHP_URL_PATH );
	$re     = '#(?:https?:)?(?://[^/"\'\s]+)?' . preg_quote( $upath, '#' ) . '/([^"\'\s<>()?]+?\.(?:jpe?g|png|gif|webp|avif))#i';
	$like   = '%' . $wpdb->esc_like( $upath . '/' ) . '%';
	$like_e = '%' . $wpdb->esc_like( str_replace( '/', '\/', $upath . '/' ) ) . '%';
	$sources = [];
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID AS pid, post_content AS txt FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s", $like ) ) as $r ) {
		$sources[] = $r;
	}
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id AS pid, m.meta_value AS txt FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_status NOT IN ('trash','auto-draft') AND p.post_type <> 'revision' AND (m.meta_value LIKE %s OR m.meta_value LIKE %s)", $like, $like_e ) ) as $r ) {
		$sources[] = $r;
	}
	$GLOBALS['inpulsia_medios_usage_paths_reset'] = true;
	$by_path = inpulsia_medios_usage_paths();
	$out     = [];
	foreach ( $sources as $s ) {
		if ( ! preg_match_all( $re, str_replace( '\/', '/', $s->txt ), $m ) ) {
			continue;
		}
		foreach ( array_unique( $m[1] ) as $rel ) {
			$rel = rawurldecode( $rel );
			if ( file_exists( $base['basedir'] . '/' . $rel ) ) {
				continue;
			}
			$key = $s->pid . '|' . $rel;
			if ( isset( $out[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = [ 'post' => (int) $s->pid, 'path' => $rel, 'fix' => inpulsia_medios_broken_fix( $rel, $by_path ) ];
		}
	}
	$out = array_values( $out );
	set_transient( 'inpulsia_medios_broken', [ 'time' => time(), 'items' => $out ], DAY_IN_SECONDS );
	return $out;
}

// Replacement for a missing uploads path: a 301 we created, or the same image under another size/format.
function inpulsia_medios_broken_fix( $rel, $by_path ) {
	global $wpdb;
	$upath  = inpulsia_medios_uploads_path();
	$target = $wpdb->get_var( $wpdb->prepare( 'SELECT target FROM %i WHERE source = %s', inpulsia_medios_redirects_table(), $upath . '/' . $rel ) );
	if ( $target && strpos( $target, $upath . '/' ) === 0 ) {
		return substr( $target, strlen( $upath ) + 1 );
	}
	foreach ( $by_path[ inpulsia_medios_path_key( $rel ) ] ?? [] as $id ) {
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( $file === '' ) {
			continue;
		}
		$meta = wp_get_attachment_metadata( $id ) ?: [];
		$dir  = dirname( $file ) === '.' ? '' : dirname( $file ) . '/';
		if ( preg_match( '/-(\d+)x(\d+)\.\w+$/', $rel, $sz ) ) {
			foreach ( $meta['sizes'] ?? [] as $s ) {
				if ( (int) $s['width'] === (int) $sz[1] && (int) $s['height'] === (int) $sz[2] ) {
					return $dir . $s['file'];
				}
			}
		}
		return $file;
	}
	return '';
}

// Rewrites one missing path to its fix in the given post (content and Elementor data).
function inpulsia_medios_broken_repair( $post_id, $path, $fix ) {
	global $wpdb;
	if ( $fix === '' || ! current_user_can( 'edit_post', $post_id ) ) {
		return false;
	}
	$upath = wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
	$pairs = [ $upath . '/' . $path => $upath . '/' . $fix ];
	$pairs[ str_replace( '/', '\/', $upath . '/' . $path ) ] = str_replace( '/', '\/', $upath . '/' . $fix );
	$done  = false;
	$post  = get_post( $post_id );
	$c     = strtr( (string) $post->post_content, $pairs );
	if ( $c !== $post->post_content ) {
		$wpdb->update( $wpdb->posts, [ 'post_content' => $c ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
		$done = true;
	}
	$data = get_post_meta( $post_id, '_elementor_data', true );
	if ( is_string( $data ) && $data !== '' ) {
		$new = strtr( $data, $pairs );
		if ( $new !== $data ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( $new ) );
			delete_post_meta( $post_id, '_elementor_css' );
			delete_post_meta( $post_id, '_elementor_element_cache' );
			$GLOBALS['inpulsia_medios_elementor_dirty'] = true;
			$done = true;
		}
	}
	return $done;
}
