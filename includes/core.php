<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function inpulsia_medios_fields() {
	return [ 'filename', 'title', 'alt', 'caption', 'description' ];
}

function inpulsia_medios_field_labels() {
	return [
		'filename'    => 'Nombre',
		'title'       => 'Título',
		'alt'         => 'Alt',
		'caption'     => 'Leyenda',
		'description' => 'Descripción',
	];
}

// Base name without size/scaled suffixes, taken from the original upload.
function inpulsia_medios_stem( $id ) {
	$meta = wp_get_attachment_metadata( $id );
	if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
		return pathinfo( $meta['original_image'], PATHINFO_FILENAME );
	}
	$file = get_attached_file( $id );
	return $file ? pathinfo( $file, PATHINFO_FILENAME ) : '';
}

function inpulsia_medios_current( $id ) {
	$post = get_post( $id );
	return [
		'filename'    => inpulsia_medios_stem( $id ),
		'title'       => $post->post_title,
		'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'caption'     => $post->post_excerpt,
		'description' => $post->post_content,
	];
}

// Null means "leave unchanged".
function inpulsia_medios_target( $field, $value, $undo = false ) {
	$value = trim( (string) $value );
	if ( $value === '' ) {
		return null;
	}
	if ( $value === INPULSIA_MEDIOS_CLEAR ) {
		return $field === 'filename' ? null : '';
	}
	if ( $field === 'filename' ) {
		$value = preg_replace( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?|heic|pdf|mp4|mov|webm|mp3)$/i', '', $value );
		return $undo ? sanitize_file_name( $value ) : sanitize_title( $value );
	}
	return $value;
}

function inpulsia_medios_changes( $id, $row, $undo = false ) {
	$cur     = inpulsia_medios_current( $id );
	$changes = [];
	foreach ( inpulsia_medios_fields() as $f ) {
		$t = inpulsia_medios_target( $f, $row[ $f ] ?? '', $undo );
		if ( $t !== null && $t !== $cur[ $f ] ) {
			$changes[ $f ] = $t;
		}
	}
	return [ $cur, $changes ];
}

function inpulsia_medios_apply_row( $id, $row, $undo = false ) {
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		return [ 'status' => 'error', 'msg' => 'Medio no encontrado.' ];
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return [ 'status' => 'error', 'msg' => 'No tienes permisos para editar este medio.' ];
	}

	list( $cur, $changes ) = inpulsia_medios_changes( $id, $row, $undo );
	if ( ! $changes ) {
		return [ 'status' => 'skip', 'msg' => 'Sin cambios.' ];
	}

	$prev = [];
	foreach ( $changes as $f => $v ) {
		$prev[ $f ] = $cur[ $f ] === '' ? INPULSIA_MEDIOS_CLEAR : $cur[ $f ];
	}

	$labels = inpulsia_medios_field_labels();
	$done   = [];
	$errors = [];

	if ( isset( $changes['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $changes['alt'] ) );
		$done[] = $labels['alt'];
	}

	$update = [ 'ID' => $id ];
	if ( isset( $changes['title'] ) ) {
		$update['post_title'] = sanitize_text_field( $changes['title'] );
		$done[]               = $labels['title'];
	}
	if ( isset( $changes['caption'] ) ) {
		$update['post_excerpt'] = wp_kses_post( $changes['caption'] );
		$done[]                 = $labels['caption'];
	}
	if ( isset( $changes['description'] ) ) {
		$update['post_content'] = wp_kses_post( $changes['description'] );
		$done[]                 = $labels['description'];
	}
	if ( count( $update ) > 1 ) {
		wp_update_post( wp_slash( $update ) );
	}

	if ( isset( $changes['filename'] ) ) {
		$r = inpulsia_medios_rename( $id, $changes['filename'] );
		if ( is_wp_error( $r ) ) {
			$errors[] = 'Nombre: ' . $r->get_error_message();
			unset( $prev['filename'] );
		} else {
			$done[] = $labels['filename'] . ( $r > 0 ? " ({$r} contenidos actualizados)" : '' );
		}
	}

	$msg = $done ? 'Actualizado: ' . implode( ', ', $done ) . '.' : '';
	if ( $errors ) {
		$msg .= ( $msg ? ' ' : '' ) . implode( ' ', $errors );
	}

	return [
		'status' => $errors ? ( $done ? 'partial' : 'error' ) : 'ok',
		'msg'    => $msg,
		'prev'   => $prev,
	];
}

// Renames the file, its sub-sizes and original image, and rewrites URLs in content. Returns posts updated.
function inpulsia_medios_rename( $id, $new_stem ) {
	global $wpdb;

	$file = get_attached_file( $id );
	if ( ! $file || ! file_exists( $file ) ) {
		return new WP_Error( 'not_found', 'archivo no encontrado en el servidor.' );
	}
	if ( $new_stem === '' ) {
		return new WP_Error( 'empty', 'nombre vacío.' );
	}
	$old_stem = inpulsia_medios_stem( $id );
	if ( $new_stem === $old_stem ) {
		return 0;
	}

	$dir  = dirname( $file );
	$meta = wp_get_attachment_metadata( $id );
	$meta = is_array( $meta ) ? $meta : [];

	$swap = function ( $name, $fallback_suffix ) use ( $old_stem, $new_stem ) {
		if ( strpos( $name, $old_stem ) === 0 ) {
			return $new_stem . substr( $name, strlen( $old_stem ) );
		}
		return $new_stem . $fallback_suffix . '.' . pathinfo( $name, PATHINFO_EXTENSION );
	};

	$map               = [];
	$main_old          = basename( $file );
	$map[ $main_old ]  = $swap( $main_old, '' );
	if ( ! empty( $meta['original_image'] ) ) {
		$map[ $meta['original_image'] ] = $swap( $meta['original_image'], '' );
	}
	foreach ( $meta['sizes'] ?? [] as $s ) {
		if ( ! isset( $map[ $s['file'] ] ) ) {
			$map[ $s['file'] ] = $swap( $s['file'], '-' . $s['width'] . 'x' . $s['height'] );
		}
	}

	foreach ( $map as $new ) {
		if ( file_exists( $dir . '/' . $new ) && ! isset( $map[ $new ] ) ) {
			return new WP_Error( 'exists', "ya existe «{$new}» en la carpeta." );
		}
	}

	$moved = [];
	foreach ( $map as $old => $new ) {
		if ( ! file_exists( $dir . '/' . $old ) ) {
			continue;
		}
		if ( ! @rename( $dir . '/' . $old, $dir . '/' . $new ) ) {
			foreach ( array_reverse( $moved, true ) as $o => $n ) {
				@rename( $dir . '/' . $n, $dir . '/' . $o );
			}
			return new WP_Error( 'rename_failed', 'no se pudo renombrar (permisos del servidor).' );
		}
		$moved[ $old ] = $new;
	}

	update_attached_file( $id, $dir . '/' . $map[ $main_old ] );

	if ( $meta ) {
		if ( ! empty( $meta['file'] ) ) {
			$d            = dirname( $meta['file'] );
			$meta['file'] = ( $d === '.' ? '' : $d . '/' ) . $map[ $main_old ];
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$meta['original_image'] = $map[ $meta['original_image'] ];
		}
		foreach ( $meta['sizes'] ?? [] as $k => $s ) {
			$meta['sizes'][ $k ]['file'] = $map[ $s['file'] ];
		}
		wp_update_attachment_metadata( $id, $meta );
	}

	$wpdb->update( $wpdb->posts, [ 'guid' => wp_get_attachment_url( $id ) ], [ 'ID' => $id ] );
	clean_post_cache( $id );

	$rel = dirname( (string) get_post_meta( $id, '_wp_attached_file', true ) );
	return inpulsia_medios_replace_urls( $rel === '.' ? '' : $rel, $old_stem, $map );
}

function inpulsia_medios_replace_urls( $rel, $old_stem, $map ) {
	global $wpdb;

	$prefix = '/' . ( $rel === '' ? '' : $rel . '/' );
	$plain  = [];
	$esc    = [];
	foreach ( $map as $o => $n ) {
		$plain[ $prefix . $o ]                       = $prefix . $n;
		$esc[ str_replace( '/', '\/', $prefix . $o ) ] = str_replace( '/', '\/', $prefix . $n );
	}
	$like_plain = '%' . $wpdb->esc_like( $prefix . $old_stem ) . '%';
	$like_esc   = '%' . $wpdb->esc_like( str_replace( '/', '\/', $prefix . $old_stem ) ) . '%';
	$touched    = [];

	$posts = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
		 WHERE post_type NOT IN ('revision','attachment') AND (post_content LIKE %s OR post_excerpt LIKE %s)",
		$like_plain,
		$like_plain
	) );
	foreach ( $posts as $p ) {
		$content = strtr( $p->post_content, $plain );
		$excerpt = strtr( $p->post_excerpt, $plain );
		if ( $content !== $p->post_content || $excerpt !== $p->post_excerpt ) {
			$wpdb->update( $wpdb->posts, [ 'post_content' => $content, 'post_excerpt' => $excerpt ], [ 'ID' => $p->ID ] );
			clean_post_cache( $p->ID );
			$touched[ $p->ID ] = true;
		}
	}

	$metas = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_elementor_data' AND (meta_value LIKE %s OR meta_value LIKE %s)",
		$like_plain,
		$like_esc
	) );
	foreach ( $metas as $m ) {
		$value = strtr( strtr( $m->meta_value, $esc ), $plain );
		if ( $value !== $m->meta_value ) {
			$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $value ], [ 'meta_id' => $m->meta_id ] );
			delete_post_meta( $m->post_id, '_elementor_css' );
			wp_cache_delete( $m->post_id, 'post_meta' );
			$touched[ $m->post_id ]                 = true;
			$GLOBALS['inpulsia_medios_elementor_dirty'] = true;
		}
	}

	return count( $touched );
}

function inpulsia_medios_flush_elementor() {
	if ( empty( $GLOBALS['inpulsia_medios_elementor_dirty'] ) ) {
		return;
	}
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}

function inpulsia_medios_history() {
	$h = get_option( 'inpulsia_medios_history', [] );
	return is_array( $h ) ? $h : [];
}

function inpulsia_medios_history_add( $entry ) {
	$h = inpulsia_medios_history();
	array_unshift( $h, $entry );
	foreach ( array_slice( $h, 20 ) as $old ) {
		delete_option( 'inpulsia_medios_log_' . $old['token'] );
	}
	update_option( 'inpulsia_medios_history', array_slice( $h, 0, 20 ), false );
}

function inpulsia_medios_history_update( $token, $fields ) {
	$h = inpulsia_medios_history();
	foreach ( $h as &$e ) {
		if ( $e['token'] === $token ) {
			$e = array_merge( $e, $fields );
		}
	}
	update_option( 'inpulsia_medios_history', $h, false );
}

function inpulsia_medios_history_find( $token ) {
	foreach ( inpulsia_medios_history() as $e ) {
		if ( $e['token'] === $token ) {
			return $e;
		}
	}
	return null;
}
