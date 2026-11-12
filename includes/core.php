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

function inpulsia_medios_rel_dir( $id ) {
	$rel = dirname( (string) get_post_meta( $id, '_wp_attached_file', true ) );
	return $rel === '.' ? '' : $rel;
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

// Validates rows and returns [ preview items, rows that will change ].
function inpulsia_medios_build_preview( $rows ) {
	$items = [];
	$valid = [];
	$stems = [];
	$seen  = [];

	foreach ( $rows as $row ) {
		$id   = absint( $row['id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || $post->post_type !== 'attachment' ) {
			$items[] = [ 'id' => $row['id'] ?? '', 'error' => 'No existe un medio con este ID.' ];
			continue;
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			$items[] = [ 'id' => $id, 'error' => 'No tienes permisos para editar este medio.' ];
			continue;
		}
		if ( isset( $seen[ $id ] ) ) {
			$items[] = [ 'id' => $id, 'error' => 'ID repetido: se usa solo la primera fila.' ];
			continue;
		}
		$seen[ $id ] = true;

		list( $cur, $changes ) = inpulsia_medios_changes( $id, $row );
		$warn      = [];
		$final_alt = $changes['alt'] ?? $cur['alt'];
		$final_fn  = $changes['filename'] ?? $cur['filename'];

		if ( wp_attachment_is_image( $id ) && $final_alt === '' ) {
			$warn[] = 'Sin texto alternativo.';
		}
		if ( mb_strlen( $final_alt ) > 125 ) {
			$warn[] = 'Alt de ' . mb_strlen( $final_alt ) . ' caracteres (recomendado ≤ 125).';
		}
		if ( $final_alt !== '' && sanitize_title( $final_alt ) === sanitize_title( $final_fn ) ) {
			$warn[] = 'El alt repite el nombre de archivo.';
		}
		if ( isset( $changes['filename'] ) ) {
			$stems[ $final_fn ][] = $id;
			$file                 = get_attached_file( $id );
			if ( $file && file_exists( dirname( $file ) . '/' . $final_fn . '.' . pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
				$warn[] = 'Ya existe un archivo con ese nombre en la carpeta: no se renombrará.';
			}
		}

		$items[ 'id' . $id ] = [
			'id'       => $id,
			'edit'     => get_edit_post_link( $id, 'raw' ),
			'thumb'    => (string) wp_get_attachment_image_url( $id, 'thumbnail', true ),
			'current'  => $cur,
			'changes'  => $changes,
			'warnings' => $warn,
		];
		if ( $changes ) {
			$valid[] = $row;
		}
	}

	foreach ( $stems as $ids ) {
		if ( count( $ids ) > 1 ) {
			foreach ( $ids as $id ) {
				$items[ 'id' . $id ]['warnings'][] = 'Nombre repetido (IDs ' . implode( ', ', $ids ) . ').';
			}
		}
	}

	return [ array_values( $items ), $valid ];
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
		$alt = sanitize_text_field( $changes['alt'] );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		$synced = inpulsia_medios_sync_alt( $id, $alt );
		$done[] = $labels['alt'] . ( $synced ? " ({$synced} en contenido)" : '' );
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

	if ( $done ) {
		$GLOBALS['inpulsia_medios_changed'] = true;
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

// Applies rows under a history token, logging previous values so they can be undone.
function inpulsia_medios_apply_rows( $token, $rows ) {
	$log     = get_option( 'inpulsia_medios_log_' . $token, [] );
	$results = [];
	foreach ( $rows as $row ) {
		$id = absint( $row['id'] );
		$r  = inpulsia_medios_apply_row( $id, $row );
		if ( ! empty( $r['prev'] ) ) {
			$log[] = [ 'id' => $id, 'prev' => $r['prev'] ];
			update_option( 'inpulsia_medios_log_' . $token, $log, false );
		}
		unset( $r['prev'] );
		$results[] = [ 'id' => $id ] + $r;
	}
	update_option( 'inpulsia_medios_log_' . $token, $log, false );
	inpulsia_medios_history_update( $token, [ 'changed' => count( $log ) ] );
	inpulsia_medios_flush_elementor();
	return $results;
}

// Optimizes attachments under a history token; returns results with before/after bytes.
function inpulsia_medios_optimize_ids( $token, $ids, $opts ) {
	$log     = get_option( 'inpulsia_medios_log_' . $token, [] );
	$results = [];
	foreach ( $ids as $id ) {
		$r = inpulsia_medios_optimize( (int) $id, $opts );
		if ( ! empty( $r['prev'] ) ) {
			$log[] = [ 'id' => (int) $id, 'prev' => $r['prev'] ];
			update_option( 'inpulsia_medios_log_' . $token, $log, false );
		}
		unset( $r['prev'] );
		$results[] = [ 'id' => (int) $id ] + $r;
	}
	update_option( 'inpulsia_medios_log_' . $token, $log, false );
	inpulsia_medios_history_update( $token, [ 'changed' => count( $log ) ] );
	inpulsia_medios_flush_elementor();
	return $results;
}

function inpulsia_medios_undo_entries( $token ) {
	return array_reverse( (array) get_option( 'inpulsia_medios_log_' . $token, [] ) );
}

function inpulsia_medios_undo_rows( $entries ) {
	$results = [];
	foreach ( $entries as $item ) {
		$prev     = $item['prev'];
		$restored = null;
		if ( isset( $prev['restore'] ) ) {
			$restored = inpulsia_medios_backup_restore( $prev['restore'] );
			unset( $prev['restore'] );
		}
		$r = $prev ? inpulsia_medios_apply_row( $item['id'], $prev, true ) : [ 'status' => 'ok', 'msg' => '' ];
		unset( $r['prev'] );
		if ( is_wp_error( $restored ) ) {
			$extra       = $r['status'] === 'ok' ? $r['msg'] : '';
			$r['status'] = 'error';
			$r['msg']    = trim( 'Archivo: ' . $restored->get_error_message() . ' ' . $extra );
		} elseif ( $restored ) {
			$r['status'] = 'ok';
			$r['msg']    = trim( 'Imagen original restaurada. ' . ( $r['msg'] === 'Sin cambios.' ? '' : $r['msg'] ) );
		}
		$results[] = [ 'id' => $item['id'] ] + $r;
	}
	inpulsia_medios_flush_elementor();
	return $results;
}

// Renames the file, its sub-sizes, original image and WebP/AVIF copies, then rewrites URLs and adds redirects.
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

	$main_old         = basename( $file );
	$map              = [ $main_old => $swap( $main_old, '' ) ];
	if ( ! empty( $meta['original_image'] ) ) {
		$map[ $meta['original_image'] ] = $swap( $meta['original_image'], '' );
	}
	foreach ( $meta['sizes'] ?? [] as $s ) {
		if ( ! isset( $map[ $s['file'] ] ) ) {
			$map[ $s['file'] ] = $swap( $s['file'], '-' . $s['width'] . 'x' . $s['height'] );
		}
	}

	// Copies made by optimizers: "foto.jpg.webp" (ShortPixel, EWWW, Imagify) or "foto.webp".
	foreach ( $map as $old => $new ) {
		foreach ( [ 'webp', 'avif' ] as $ext ) {
			$candidates = [
				$old . '.' . $ext                                => $new . '.' . $ext,
				pathinfo( $old, PATHINFO_FILENAME ) . '.' . $ext => pathinfo( $new, PATHINFO_FILENAME ) . '.' . $ext,
			];
			foreach ( $candidates as $so => $sn ) {
				if ( ! isset( $map[ $so ] ) && file_exists( $dir . '/' . $so ) ) {
					$map[ $so ] = $sn;
				}
			}
		}
	}

	inpulsia_medios_orphans_release( $id, $dir, array_values( $map ) );
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

	// Keep the old names working during the grace period (hard link: no extra disk space).
	$kept = [];
	foreach ( $moved as $old => $new ) {
		if ( isset( $map[ $old ] ) && in_array( $old, $map, true ) ) {
			continue;
		}
		if ( @link( $dir . '/' . $new, $dir . '/' . $old ) || @copy( $dir . '/' . $new, $dir . '/' . $old ) ) {
			inpulsia_medios_fix_perms( $dir . '/' . $old );
			$kept[] = $old;
		}
	}
	inpulsia_medios_orphans_add( $id, $dir, $kept );

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

	$rel = inpulsia_medios_rel_dir( $id );
	inpulsia_medios_redirects_add( $rel, $moved );
	return inpulsia_medios_replace_urls( $rel, $old_stem, $map, $id );
}

// Replaces strings inside plain, JSON or (nested) serialized values without breaking serialization.
function inpulsia_medios_replace_in_value( $value, $pairs ) {
	if ( is_string( $value ) ) {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( trim( $value ), [ 'allowed_classes' => false ] );
			if ( $data !== false || trim( $value ) === 'b:0;' ) {
				return serialize( inpulsia_medios_replace_in_value( $data, $pairs ) );
			}
		}
		return strtr( $value, $pairs );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = inpulsia_medios_replace_in_value( $v, $pairs );
		}
	}
	return $value;
}

// Rewrites file references in posts, post/term meta and options. Returns how many items changed.
function inpulsia_medios_replace_urls( $rel, $old_stem, $map, $exclude_id = 0 ) {
	global $wpdb;

	$prefix = '/' . ( $rel === '' ? '' : $rel . '/' );
	$esc    = function ( $s ) {
		return str_replace( '/', '\/', $s );
	};
	$pairs  = [];
	foreach ( $map as $o => $n ) {
		$pairs[ $prefix . $o ]          = $prefix . $n;
		$pairs[ $esc( $prefix . $o ) ] = $esc( $prefix . $n );
	}
	$like_a  = '%' . $wpdb->esc_like( $prefix . $old_stem ) . '%';
	$like_b  = '%' . $wpdb->esc_like( $esc( $prefix . $old_stem ) ) . '%';
	$touched = 0;

	$posts = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
		 WHERE post_type NOT IN ('revision','attachment')
		 AND (post_content LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s)",
		$like_a,
		$like_b,
		$like_a
	) );
	foreach ( $posts as $p ) {
		$content = strtr( $p->post_content, $pairs );
		$excerpt = strtr( $p->post_excerpt, $pairs );
		if ( $content !== $p->post_content || $excerpt !== $p->post_excerpt ) {
			$wpdb->update( $wpdb->posts, [ 'post_content' => $content, 'post_excerpt' => $excerpt ], [ 'ID' => $p->ID ] );
			clean_post_cache( $p->ID );
			$touched++;
		}
	}

	$metas = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
		 WHERE post_id <> %d AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata')
		 AND (meta_value LIKE %s OR meta_value LIKE %s)",
		$exclude_id,
		$like_a,
		$like_b
	) );
	foreach ( $metas as $m ) {
		$value = inpulsia_medios_replace_in_value( $m->meta_value, $pairs );
		if ( $value !== $m->meta_value ) {
			$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $value ], [ 'meta_id' => $m->meta_id ] );
			wp_cache_delete( $m->post_id, 'post_meta' );
			if ( strpos( $m->meta_key, '_elementor' ) === 0 ) {
				// Backgrounds live in Elementor's generated CSS and element cache: both must be rebuilt.
				delete_post_meta( $m->post_id, '_elementor_css' );
				delete_post_meta( $m->post_id, '_elementor_element_cache' );
				$GLOBALS['inpulsia_medios_elementor_dirty'] = true;
			}
			$touched++;
		}
	}

	$terms = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_id, term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_value LIKE %s OR meta_value LIKE %s",
		$like_a,
		$like_b
	) );
	foreach ( $terms as $t ) {
		$value = inpulsia_medios_replace_in_value( $t->meta_value, $pairs );
		if ( $value !== $t->meta_value ) {
			$wpdb->update( $wpdb->termmeta, [ 'meta_value' => $value ], [ 'meta_id' => $t->meta_id ] );
			wp_cache_delete( $t->term_id, 'term_meta' );
			$touched++;
		}
	}

	$options = $wpdb->get_results( $wpdb->prepare(
		"SELECT option_id, option_name, option_value FROM {$wpdb->options}
		 WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
		 AND (option_value LIKE %s OR option_value LIKE %s)",
		$wpdb->esc_like( '_transient' ) . '%',
		$wpdb->esc_like( '_site_transient' ) . '%',
		$wpdb->esc_like( 'inpulsia_medios_' ) . '%',
		$like_a,
		$like_b
	) );
	foreach ( $options as $o ) {
		$value = inpulsia_medios_replace_in_value( $o->option_value, $pairs );
		if ( $value !== $o->option_value ) {
			$wpdb->update( $wpdb->options, [ 'option_value' => $value ], [ 'option_id' => $o->option_id ] );
			wp_cache_delete( $o->option_name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$touched++;
		}
	}

	return $touched;
}

// Gutenberg and the classic editor hard-code alt in each <img class="wp-image-ID">.
function inpulsia_medios_sync_alt( $id, $alt ) {
	global $wpdb;
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return 0;
	}

	$posts   = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_content FROM {$wpdb->posts}
		 WHERE post_type NOT IN ('revision','attachment') AND post_content LIKE %s",
		'%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%'
	) );
	$pattern = '/(^|\s)wp-image-' . (int) $id . '(\s|$)/';
	$count   = 0;

	foreach ( $posts as $p ) {
		$html    = new WP_HTML_Tag_Processor( $p->post_content );
		$changed = false;
		while ( $html->next_tag( 'img' ) ) {
			$class = (string) $html->get_attribute( 'class' );
			if ( preg_match( $pattern, $class ) && $html->get_attribute( 'alt' ) !== $alt ) {
				$html->set_attribute( 'alt', $alt );
				$changed = true;
			}
		}
		if ( $changed ) {
			$wpdb->update( $wpdb->posts, [ 'post_content' => $html->get_updated_html() ], [ 'ID' => $p->ID ] );
			clean_post_cache( $p->ID );
			$count++;
		}
	}
	return $count;
}

function inpulsia_medios_flush_elementor() {
	if ( empty( $GLOBALS['inpulsia_medios_elementor_dirty'] ) ) {
		return;
	}
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	$GLOBALS['inpulsia_medios_elementor_dirty'] = false;
}

// Purges page caches of common plugins and hosts. Returns the names purged.
function inpulsia_medios_purge_caches() {
	$purged = [];
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		$purged[] = 'WP Rocket';
	}
	if ( defined( 'LSCWP_V' ) ) {
		do_action( 'litespeed_purge_all' );
		$purged[] = 'LiteSpeed Cache';
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$purged[] = 'W3 Total Cache';
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
		$purged[] = 'WP Super Cache';
	}
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
		$purged[] = 'SiteGround Optimizer';
	}
	if ( class_exists( 'WpFastestCache' ) ) {
		do_action( 'wpfc_clear_all_cache' );
		$purged[] = 'WP Fastest Cache';
	}
	if ( class_exists( '\Breeze_PurgeCache' ) ) {
		do_action( 'breeze_clear_all_cache' );
		$purged[] = 'Breeze';
	}
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
		$purged[] = 'Elementor (CSS regenerado)';
	}
	if ( has_action( 'rt_nginx_helper_purge_all' ) ) {
		do_action( 'rt_nginx_helper_purge_all' );
		$purged[] = 'Nginx Helper';
	}
	if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
		autoptimizeCache::clearall();
		$purged[] = 'Autoptimize';
	}
	if ( has_action( 'wphb_clear_page_cache' ) ) {
		do_action( 'wphb_clear_page_cache' );
		$purged[] = 'Hummingbird';
	}
	if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
		Cache_Enabler::clear_complete_cache();
		$purged[] = 'Cache Enabler';
	}
	if ( class_exists( 'WpeCommon' ) ) {
		foreach ( [ 'purge_memcached', 'clear_maxcdn_cache', 'purge_varnish_cache' ] as $m ) {
			if ( method_exists( 'WpeCommon', $m ) ) {
				call_user_func( [ 'WpeCommon', $m ] );
			}
		}
		$purged[] = 'WP Engine';
	}
	if ( isset( $GLOBALS['kinsta_cache'] ) && isset( $GLOBALS['kinsta_cache']->kinsta_cache_purge ) && method_exists( $GLOBALS['kinsta_cache']->kinsta_cache_purge, 'purge_complete_caches' ) ) {
		$GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches();
		$purged[] = 'Kinsta';
	}
	if ( class_exists( '\CF\WordPress\Hooks' ) && method_exists( '\CF\WordPress\Hooks', 'purgeCacheEverything' ) ) {
		( new \CF\WordPress\Hooks() )->purgeCacheEverything();
		$purged[] = 'Cloudflare';
		$GLOBALS['inpulsia_medios_cloudflare_purged'] = true;
	}
	wp_cache_flush();
	return $purged;
}

function inpulsia_medios_history() {
	$h = get_option( 'inpulsia_medios_history', [] );
	return is_array( $h ) ? $h : [];
}

function inpulsia_medios_history_add( $entry ) {
	if ( inpulsia_medios_history_find( $entry['token'] ) ) {
		return;
	}
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

function inpulsia_medios_new_token() {
	return strtolower( wp_generate_password( 12, false ) );
}
