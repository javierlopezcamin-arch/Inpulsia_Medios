<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function inpulsia_medios_fields() {
	return [ 'filename', 'title', 'alt', 'caption', 'description' ];
}

function inpulsia_medios_field_labels() {
	return [
		'filename'    => __( 'Nombre', 'inpulsia-medios' ),
		'title'       => __( 'Título', 'inpulsia-medios' ),
		'alt'         => __( 'Alt', 'inpulsia-medios' ),
		'caption'     => __( 'Leyenda', 'inpulsia-medios' ),
		'description' => __( 'Descripción', 'inpulsia-medios' ),
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
			$items[] = [ 'id' => $row['id'] ?? '', 'error' => __( 'No existe un medio con este ID.', 'inpulsia-medios' ) ];
			continue;
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			$items[] = [ 'id' => $id, 'error' => __( 'No tienes permisos para editar este medio.', 'inpulsia-medios' ) ];
			continue;
		}
		if ( isset( $seen[ $id ] ) ) {
			$items[] = [ 'id' => $id, 'error' => __( 'ID repetido: se usa solo la primera fila.', 'inpulsia-medios' ) ];
			continue;
		}
		$seen[ $id ] = true;

		list( $cur, $changes ) = inpulsia_medios_changes( $id, $row );
		$warn      = [];
		$final_alt = $changes['alt'] ?? $cur['alt'];
		$final_fn  = $changes['filename'] ?? $cur['filename'];

		if ( wp_attachment_is_image( $id ) && $final_alt === '' ) {
			$warn[] = __( 'Sin texto alternativo.', 'inpulsia-medios' );
		}
		if ( mb_strlen( $final_alt ) > 125 ) {
			$warn[] = sprintf( __( 'Alt de %d caracteres (recomendado ≤ 125).', 'inpulsia-medios' ), mb_strlen( $final_alt ) );
		}
		if ( $final_alt !== '' && sanitize_title( $final_alt ) === sanitize_title( $final_fn ) ) {
			$warn[] = __( 'El alt repite el nombre de archivo.', 'inpulsia-medios' );
		}
		if ( isset( $changes['filename'] ) ) {
			$stems[ $final_fn ][] = $id;
			$file                 = get_attached_file( $id );
			if ( $file && file_exists( dirname( $file ) . '/' . $final_fn . '.' . pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
				$warn[] = __( 'Ya existe un archivo con ese nombre en la carpeta: no se renombrará.', 'inpulsia-medios' );
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
				$items[ 'id' . $id ]['warnings'][] = sprintf( __( 'Nombre repetido (IDs %s).', 'inpulsia-medios' ), implode( ', ', $ids ) );
			}
		}
	}

	return [ array_values( $items ), $valid ];
}

function inpulsia_medios_apply_row( $id, $row, $undo = false ) {
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		return [ 'status' => 'error', 'msg' => __( 'Medio no encontrado.', 'inpulsia-medios' ) ];
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return [ 'status' => 'error', 'msg' => __( 'No tienes permisos para editar este medio.', 'inpulsia-medios' ) ];
	}

	list( $cur, $changes ) = inpulsia_medios_changes( $id, $row, $undo );
	if ( ! $changes ) {
		return [ 'status' => 'skip', 'msg' => __( 'Sin cambios.', 'inpulsia-medios' ) ];
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
		$done[] = $labels['alt'] . ( $synced ? ' ' . sprintf( __( '(%d en contenido)', 'inpulsia-medios' ), $synced ) : '' );
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
			$done[] = $labels['filename'] . ( $r > 0 ? ' ' . sprintf( __( '(%d contenidos actualizados)', 'inpulsia-medios' ), $r ) : '' );
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
		if ( isset( $prev['undelete'] ) ) {
			$back      = inpulsia_medios_undelete( $prev['undelete'] );
			$results[] = [ 'id' => $item['id'] ] + ( is_wp_error( $back ) ? [ 'status' => 'error', 'msg' => $back->get_error_message() ] : [ 'status' => 'ok', 'msg' => __( 'Imagen recuperada.', 'inpulsia-medios' ) ] );
			continue;
		}
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
			$r['msg']    = trim( __( 'Imagen original restaurada.', 'inpulsia-medios' ) . ' ' . ( $r['msg'] === __( 'Sin cambios.', 'inpulsia-medios' ) ? '' : $r['msg'] ) );
		}
		$results[] = [ 'id' => $item['id'] ] + $r;
	}
	inpulsia_medios_flush_elementor();
	return $results;
}

// Renames the file, its sub-sizes, original image and WebP/AVIF copies, then rewrites URLs and adds redirects.
function inpulsia_medios_rename( $id, $new_stem, $keep_old = true ) {
	global $wpdb;

	$file = get_attached_file( $id );
	if ( ! $file || ! file_exists( $file ) ) {
		return new WP_Error( 'not_found', sprintf( __( 'el archivo %s no está en el servidor, así que no se puede renombrar. Recupéralo en Limpieza → Imágenes sin archivo o vuelve a subirlo.', 'inpulsia-medios' ), (string) get_post_meta( $id, '_wp_attached_file', true ) ) );
	}
	if ( $new_stem === '' ) {
		return new WP_Error( 'empty', __( 'nombre vacío.', 'inpulsia-medios' ) );
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
			return new WP_Error( 'exists', sprintf( __( 'ya existe «%s» en la carpeta.', 'inpulsia-medios' ), $new ) );
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
			return new WP_Error( 'rename_failed', __( 'no se pudo renombrar (permisos del servidor).', 'inpulsia-medios' ) );
		}
		$moved[ $old ] = $new;
	}

	// Keep the old names working during the grace period (hard link: no extra disk space).
	$kept = [];
	foreach ( $keep_old ? $moved : [] as $old => $new ) {
		if ( isset( $map[ $old ] ) && in_array( $old, $map, true ) ) {
			continue;
		}
		if ( @link( $dir . '/' . $new, $dir . '/' . $old ) || @copy( $dir . '/' . $new, $dir . '/' . $old ) ) {
			inpulsia_medios_fix_perms( $dir . '/' . $old );
			$kept[] = $old;
		}
	}
	inpulsia_medios_orphans_add( $id, $dir, $kept );

	$siblings = inpulsia_medios_siblings( $id );
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
	inpulsia_medios_siblings_sync( $id, $siblings );

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
// $new_rel: folder of the new files when it differs from $rel (merging duplicates).
function inpulsia_medios_replace_urls( $rel, $old_stem, $map, $exclude_id = 0, $new_rel = null ) {
	global $wpdb;

	$prefix = '/' . ( $rel === '' ? '' : $rel . '/' );
	$to     = $new_rel === null ? $prefix : '/' . ( $new_rel === '' ? '' : $new_rel . '/' );
	$esc    = function ( $s ) {
		return str_replace( '/', '\/', $s );
	};
	$pairs  = [];
	foreach ( $map as $o => $n ) {
		$pairs[ $prefix . $o ]          = $to . $n;
		$pairs[ $esc( $prefix . $o ) ] = $esc( $to . $n );
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
		$purged[] = __( 'WP Rocket', 'inpulsia-medios' );
	}
	if ( defined( 'LSCWP_V' ) ) {
		do_action( 'litespeed_purge_all' );
		$purged[] = __( 'LiteSpeed Cache', 'inpulsia-medios' );
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$purged[] = __( 'W3 Total Cache', 'inpulsia-medios' );
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
		$purged[] = __( 'WP Super Cache', 'inpulsia-medios' );
	}
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
		$purged[] = __( 'SiteGround Optimizer', 'inpulsia-medios' );
	}
	if ( class_exists( 'WpFastestCache' ) ) {
		do_action( 'wpfc_clear_all_cache' );
		$purged[] = __( 'WP Fastest Cache', 'inpulsia-medios' );
	}
	if ( class_exists( '\Breeze_PurgeCache' ) ) {
		do_action( 'breeze_clear_all_cache' );
		$purged[] = 'Breeze';
	}
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
		$purged[] = __( 'Elementor (CSS regenerado)', 'inpulsia-medios' );
	}
	if ( has_action( 'rt_nginx_helper_purge_all' ) ) {
		do_action( 'rt_nginx_helper_purge_all' );
		$purged[] = __( 'Nginx Helper', 'inpulsia-medios' );
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
		$purged[] = __( 'Cache Enabler', 'inpulsia-medios' );
	}
	if ( class_exists( 'WpeCommon' ) ) {
		foreach ( [ 'purge_memcached', 'clear_maxcdn_cache', 'purge_varnish_cache' ] as $m ) {
			if ( method_exists( 'WpeCommon', $m ) ) {
				call_user_func( [ 'WpeCommon', $m ] );
			}
		}
		$purged[] = __( 'WP Engine', 'inpulsia-medios' );
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

// One Historial entry per kind, user and day (autosave, automatic optimization…), so small changes stay undoable together.
function inpulsia_medios_daily_token( $kind, $label ) {
	$user  = get_current_user_id();
	$key   = 'inpulsia_medios_daily_' . $kind . '_' . $user;
	$today = current_time( 'Y-m-d' );
	$saved = get_option( $key );
	$entry = is_array( $saved ) && $saved['day'] === $today ? inpulsia_medios_history_find( $saved['token'] ) : null;
	if ( $entry && $entry['status'] !== 'deshecho' ) {
		return $saved['token'];
	}
	$token = inpulsia_medios_new_token();
	$who   = wp_get_current_user();
	inpulsia_medios_history_add( [
		'token'   => $token,
		'date'    => current_time( 'mysql' ),
		'user'    => $who->exists() ? $who->display_name : 'WordPress',
		'file'    => $label,
		'rows'    => 0,
		'changed' => 0,
		'status'  => 'aplicado',
	] );
	update_option( $key, [ 'day' => $today, 'token' => $token ], false );
	return $token;
}

// History labels are stored in Spanish; show them in the admin language.
function inpulsia_medios_history_label( $label ) {
	$known = [
		'Edición en tabla'                 => __( 'Edición en tabla', 'inpulsia-medios' ),
		'Guardado automático en tabla'     => __( 'Guardado automático en tabla', 'inpulsia-medios' ),
		'Optimización automática al subir' => __( 'Optimización automática al subir', 'inpulsia-medios' ),
		'Limpieza: imágenes sin uso'       => __( 'Limpieza: imágenes sin uso', 'inpulsia-medios' ),
		'Limpieza: duplicados'             => __( 'Limpieza: duplicados', 'inpulsia-medios' ),
		'Limpieza: imágenes rotas'         => __( 'Limpieza: imágenes rotas', 'inpulsia-medios' ),
		'Limpieza: recuperar archivos perdidos' => __( 'Limpieza: recuperar archivos perdidos', 'inpulsia-medios' ),
		'IA pendiente completada' => __( 'IA pendiente completada', 'inpulsia-medios' ),
		'Optimización desde el editor'     => __( 'Optimización desde el editor', 'inpulsia-medios' ),
		'Completar con IA'                 => __( 'Completar con IA', 'inpulsia-medios' ),
	];
	if ( isset( $known[ $label ] ) ) {
		return $known[ $label ];
	}
	if ( strpos( $label, 'Plantilla: ' ) === 0 ) {
		/* translators: %s: the template text. */
		return sprintf( __( 'Plantilla: %s', 'inpulsia-medios' ), substr( $label, 11 ) );
	}
	if ( preg_match( '/^Optimización \((\w+), (\d+) px\)$/u', $label, $m ) ) {
		/* translators: 1: image format, 2: max width in pixels. */
		return sprintf( __( 'Optimización (%1$s, %2$d px)', 'inpulsia-medios' ), $m[1], $m[2] );
	}
	return $label;
}

