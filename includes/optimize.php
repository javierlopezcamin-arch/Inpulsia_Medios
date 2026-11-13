<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function inpulsia_medios_opt_settings() {
	$s = get_option( 'inpulsia_medios_opt', [] );
	$s = wp_parse_args( is_array( $s ) ? $s : [], [
		'format'    => 'jpg',
		'max_width' => 2560,
		'quality'   => 82,
		'backup'    => true,
		'ai'        => false,
		'keep_old'  => true,
		'auto'      => false,
	] );
	return inpulsia_medios_opt_sanitize( $s );
}

function inpulsia_medios_opt_sanitize( $s ) {
	return [
		'format'    => ( $s['format'] ?? '' ) === 'webp' ? 'webp' : 'jpg',
		'max_width' => min( 8000, max( 320, (int) ( $s['max_width'] ?? 2560 ) ) ),
		'quality'   => min( 100, max( 40, (int) ( $s['quality'] ?? 82 ) ) ),
		'backup'    => ! empty( $s['backup'] ),
		'ai'        => ! empty( $s['ai'] ),
		'keep_old'  => ! isset( $s['keep_old'] ) || ! empty( $s['keep_old'] ),
		'auto'      => ! empty( $s['auto'] ),
	];
}

function inpulsia_medios_opt_mimes() {
	return [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
}

// Every file on disk that belongs to the attachment (main, original, sizes and optimizer copies).
function inpulsia_medios_attachment_files( $id ) {
	$file = get_attached_file( $id );
	if ( ! $file ) {
		return [ '', [] ];
	}
	$dir   = dirname( $file );
	$meta  = wp_get_attachment_metadata( $id );
	$names = [ basename( $file ) ];
	if ( ! empty( $meta['original_image'] ) ) {
		$names[] = $meta['original_image'];
	}
	foreach ( $meta['sizes'] ?? [] as $s ) {
		$names[] = $s['file'];
	}
	foreach ( $names as $n ) {
		foreach ( [ 'webp', 'avif' ] as $ext ) {
			$names[] = $n . '.' . $ext;
			$names[] = pathinfo( $n, PATHINFO_FILENAME ) . '.' . $ext;
		}
	}
	$names = array_values( array_unique( array_filter( $names, function ( $n ) use ( $dir ) {
		return file_exists( $dir . '/' . $n );
	} ) ) );
	return [ $dir, $names ];
}

function inpulsia_medios_is_camera_name( $stem ) {
	return (bool) preg_match( '/^(img|dsc[nf]?|dcim|pxl|mvimg|photo|foto|pic|p)[-_ .]*\d|^(whatsapp|captura|screenshot|screen ?shot|imagen?|untitled|sin[-_ ]titulo)\b|^[\d_ -]+$|^[a-f0-9]{8}-[a-f0-9]{4}|^[a-z0-9]{24,}$|copia|copy|final|-v\d+$/i', $stem );
}

function inpulsia_medios_is_animated_gif( $path ) {
	return substr_count( (string) file_get_contents( $path ), "\x21\xF9\x04" ) > 1;
}

// True when at least one pixel is (partly) transparent. Borders are scanned fully, the interior sampled.
function inpulsia_medios_has_alpha( $path, $mime ) {
	if ( $mime === 'image/jpeg' ) {
		return false;
	}
	$im = @imagecreatefromstring( (string) file_get_contents( $path ) );
	if ( ! $im ) {
		return true;
	}
	if ( ! imageistruecolor( $im ) ) {
		imagepalettetotruecolor( $im );
	}
	$w    = imagesx( $im );
	$h    = imagesy( $im );
	$test = function ( $x, $y ) use ( $im ) {
		return ( ( imagecolorat( $im, $x, $y ) >> 24 ) & 0x7F ) > 0;
	};
	for ( $x = 0; $x < $w; $x++ ) {
		if ( $test( $x, 0 ) || $test( $x, $h - 1 ) ) {
			return true;
		}
	}
	for ( $y = 0; $y < $h; $y++ ) {
		if ( $test( 0, $y ) || $test( $w - 1, $y ) ) {
			return true;
		}
	}
	$step = max( 1, (int) floor( sqrt( $w * $h / 250000 ) ) );
	for ( $y = 0; $y < $h; $y += $step ) {
		for ( $x = 0; $x < $w; $x += $step ) {
			if ( $test( $x, $y ) ) {
				return true;
			}
		}
	}
	return false;
}

// What would be done, without touching anything (for the preview).
function inpulsia_medios_opt_plan( $id, $opts ) {
	$file = get_attached_file( $id );
	$mime = get_post_mime_type( $id );
	$meta = wp_get_attachment_metadata( $id );
	$plan = [ 'lines' => [], 'warnings' => [], 'bytes' => $file && file_exists( $file ) ? filesize( $file ) : 0 ];
	$to   = $opts['format'] === 'webp' ? 'WebP' : 'JPG';
	$fmt  = strtoupper( str_replace( [ 'image/', 'jpeg' ], [ '', 'jpg' ], (string) $mime ) );

	if ( ! $file || ! file_exists( $file ) ) {
		$plan['error'] = __( 'Archivo no encontrado en el servidor.', 'inpulsia-medios' );
		return $plan;
	}
	if ( ! in_array( $mime, inpulsia_medios_opt_mimes(), true ) ) {
		$plan['error'] = sprintf( __( 'Formato no compatible (%s).', 'inpulsia-medios' ), $fmt );
		return $plan;
	}
	if ( $mime === 'image/gif' && inpulsia_medios_is_animated_gif( $file ) ) {
		$plan['error'] = __( 'GIF animado: se deja como está.', 'inpulsia-medios' );
		return $plan;
	}

	$width  = (int) ( $meta['width'] ?? 0 );
	$height = (int) ( $meta['height'] ?? 0 );
	if ( ! empty( $meta['original_image'] ) && ( $size = @getimagesize( dirname( $file ) . '/' . $meta['original_image'] ) ) ) {
		list( $width, $height ) = $size;
	}

	if ( $fmt !== $to ) {
		$plan['lines'][] = $mime === 'image/jpeg' ? sprintf( __( 'Formato: %1$s → %2$s', 'inpulsia-medios' ), $fmt, $to ) : sprintf( __( 'Formato: %1$s → %2$s (si no tiene transparencia)', 'inpulsia-medios' ), $fmt, $to );
	}
	if ( $width > $opts['max_width'] ) {
		$plan['lines'][] = sprintf( __( 'Tamaño: %d×%d → %d×%d', 'inpulsia-medios' ), $width, $height, $opts['max_width'], round( $height * $opts['max_width'] / $width ) );
	}
	$plan['lines'][] = sprintf( __( 'Compresión: calidad %d y sin metadatos EXIF', 'inpulsia-medios' ), $opts['quality'] );

	$stem = inpulsia_medios_stem( $id );
	if ( $opts['ai'] ) {
		if ( inpulsia_medios_is_camera_name( $stem ) ) {
			$plan['lines'][] = sprintf( __( 'Nombre: «%s» → propuesto por IA', 'inpulsia-medios' ), $stem );
		}
		if ( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) === '' ) {
			$plan['lines'][] = __( 'Alt: vacío → propuesto por IA', 'inpulsia-medios' );
		}
	}
	if ( ! $opts['backup'] ) {
		$plan['warnings'][] = __( 'Sin copia de seguridad: no se podrá deshacer.', 'inpulsia-medios' );
	}
	if ( ! $opts['keep_old'] ) {
		$plan['lines'][] = __( 'La imagen anterior se borra del servidor al momento', 'inpulsia-medios' );
	}
	return $plan;
}

/**
 * Converts, resizes and recompresses one attachment. Optional AI name and alt.
 * Returns [ status, msg, before, after, prev ] where prev feeds the undo log.
 */
/**
 * One optimization per image at a time. A retried request or the upload cron working on the same image
 * would otherwise delete the files the other one just wrote (menamarprojects.com, 1.7.0).
 */
function inpulsia_medios_lock( $id ) {
	global $wpdb;
	$name = 'inpulsia_medios_lock_' . (int) $id;
	$now  = time();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $name, $now - 10 * MINUTE_IN_SECONDS ) );
	$ok = (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now ) );
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return $ok;
}

function inpulsia_medios_unlock( $id ) {
	global $wpdb;
	$wpdb->delete( $wpdb->options, [ 'option_name' => 'inpulsia_medios_lock_' . (int) $id ] );
	wp_cache_delete( 'inpulsia_medios_lock_' . (int) $id, 'options' );
}

// Failed optimizations, shown in Limpieza so nobody has to find them in the server logs.
function inpulsia_medios_errors_add( $id, $msg ) {
	$list   = (array) get_option( 'inpulsia_medios_errors', [] );
	$list[] = [ 'id' => (int) $id, 'time' => time(), 'msg' => (string) $msg ];
	update_option( 'inpulsia_medios_errors', array_slice( $list, -100 ), false );
}

function inpulsia_medios_optimize( $id, $opts ) {
	if ( ! inpulsia_medios_lock( $id ) ) {
		return [ 'status' => 'skip', 'msg' => __( 'Esta imagen ya se está procesando en otra pestaña o en segundo plano; no se toca.', 'inpulsia-medios' ) ];
	}
	try {
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
		$r = inpulsia_medios_optimize_locked( $id, $opts );
	} finally {
		inpulsia_medios_unlock( $id );
	}
	if ( $r['status'] === 'error' ) {
		inpulsia_medios_errors_add( $id, $r['msg'] );
	}
	return $r;
}

function inpulsia_medios_optimize_locked( $id, $opts ) {
	global $wpdb;
	$opts = inpulsia_medios_opt_sanitize( $opts );
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		return [ 'status' => 'error', 'msg' => __( 'Medio no encontrado.', 'inpulsia-medios' ) ];
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return [ 'status' => 'error', 'msg' => __( 'No tienes permisos para editar este medio.', 'inpulsia-medios' ) ];
	}
	$plan = inpulsia_medios_opt_plan( $id, $opts );
	if ( isset( $plan['error'] ) ) {
		return [ 'status' => 'skip', 'msg' => $plan['error'] ];
	}

	$file     = get_attached_file( $id );
	$dir      = dirname( $file );
	$mime     = get_post_mime_type( $id );
	$meta     = wp_get_attachment_metadata( $id ) ?: [];
	$old_stem = inpulsia_medios_stem( $id );
	$before   = filesize( $file );
	$prev     = [];
	$notes    = [];

	// 1. AI name and alt, before the file changes.
	$new_stem = $old_stem;
	if ( $opts['ai'] && function_exists( 'inpulsia_medios_suggest' ) ) {
		$cur_alt  = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$want_alt = $cur_alt === '';
		$want_fn  = inpulsia_medios_is_camera_name( $old_stem );
		if ( $want_alt || $want_fn ) {
			$s = inpulsia_medios_suggest( $id );
			if ( is_wp_error( $s ) ) {
				$notes[] = 'IA: ' . $s->get_error_message();
			} else {
				$row = [];
				if ( $want_alt && $s['alt'] !== '' ) {
					$row['alt'] = $s['alt'];
				}
				if ( $want_fn && $s['filename'] !== '' ) {
					$new_stem = $s['filename'];
					if ( $post->post_title === $old_stem || inpulsia_medios_is_camera_name( $post->post_title ) ) {
						$row['title'] = $s['title'];
					}
				}
				if ( $row ) {
					$r    = inpulsia_medios_apply_row( $id, $row );
					$prev = $r['prev'] ?? [];
					if ( isset( $row['alt'] ) ) {
						$notes[] = sprintf( __( 'alt «%s»', 'inpulsia-medios' ), $row['alt'] );
					}
				}
			}
		}
	}

	// 2. Encode the new image from the largest available source.
	$source = $file;
	if ( ! empty( $meta['original_image'] ) && file_exists( $dir . '/' . $meta['original_image'] ) ) {
		$source = $dir . '/' . $meta['original_image'];
	}
	$alpha  = inpulsia_medios_has_alpha( $source, $mime );
	$target = $alpha ? $mime : ( $opts['format'] === 'webp' ? 'image/webp' : 'image/jpeg' );
	if ( ! wp_image_editor_supports( [ 'mime_type' => $target ] ) ) {
		$target = $alpha ? $mime : 'image/jpeg';
	}
	$ext = [ 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/png' => 'png', 'image/gif' => 'gif' ][ $target ];

	$editor = wp_get_image_editor( $source );
	if ( is_wp_error( $editor ) ) {
		return [ 'status' => 'error', 'msg' => sprintf( __( 'No se pudo abrir la imagen: %s', 'inpulsia-medios' ), $editor->get_error_message() ), 'prev' => $prev ];
	}
	if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
		$editor->maybe_exif_rotate();
	}
	$size    = $editor->get_size();
	$resized = false;
	if ( $size['width'] > $opts['max_width'] ) {
		$editor->resize( $opts['max_width'], null, false );
		$resized = true;
	}
	$editor->set_quality( $opts['quality'] );
	$tmp   = trailingslashit( get_temp_dir() ) . 'inpulsia-' . $id . '-' . wp_generate_password( 6, false ) . '.' . $ext;
	$saved = $editor->save( $tmp, $target );
	if ( is_wp_error( $saved ) ) {
		return [ 'status' => 'error', 'msg' => sprintf( __( 'No se pudo guardar la imagen: %s', 'inpulsia-medios' ), $saved->get_error_message() ), 'prev' => $prev ];
	}
	$tmp = $saved['path'];

	// Never replace a file with one that is not clearly lighter (flat graphics are often smaller as PNG).
	if ( filesize( $tmp ) >= $before * 0.95 ) {
		@unlink( $tmp );
		if ( $new_stem !== $old_stem ) {
			$r = inpulsia_medios_rename( $id, $new_stem, $opts['keep_old'] );
			if ( ! is_wp_error( $r ) ) {
				$prev['filename'] = $old_stem;
				$notes[]          = sprintf( __( 'renombrada a «%s»', 'inpulsia-medios' ), $new_stem );
			}
		}
		return [
			'status' => $notes ? 'ok' : 'skip',
			'msg'    => ( $alpha ? sprintf( __( 'Tiene transparencia: se mantiene en %s y ya estaba optimizada', 'inpulsia-medios' ), strtoupper( str_replace( 'image/', '', $mime ) ) ) : ( $target === $mime ? __( 'Ya estaba optimizada', 'inpulsia-medios' ) : sprintf( __( 'Se mantiene en %s: convertirla no reduce el peso', 'inpulsia-medios' ), strtoupper( str_replace( [ 'image/', 'jpeg' ], [ '', 'jpg' ], $mime ) ) ) ) ) . ( $notes ? '; ' . implode( ', ', $notes ) : '' ) . '.',
			'before' => $before,
			'after'  => $before,
			'prev'   => $prev,
		];
	}

	// 3. Check the destination name before touching anything.
	inpulsia_medios_orphans_release( $id, $dir, [ $new_stem . '.' . $ext ] );
	list( , $own ) = inpulsia_medios_attachment_files( $id );
	$new_name      = $new_stem . '.' . $ext;
	if ( file_exists( $dir . '/' . $new_name ) && ! in_array( $new_name, $own, true ) ) {
		$new_name = wp_unique_filename( $dir, $new_name );
	}

	// 4. Backup, then swap the files.
	$backup = null;
	if ( $opts['backup'] ) {
		$backup = inpulsia_medios_backup_create( $id );
		if ( is_wp_error( $backup ) ) {
			@unlink( $tmp );
			return [ 'status' => 'error', 'msg' => $backup->get_error_message(), 'prev' => $prev ];
		}
	}

	$old_main  = basename( $file );
	$old_meta  = $meta;
	$old_files = $own;

	// Put the new file in place under a temporary name first, so a failure never leaves the attachment without a file.
	$staged = $dir . '/.inpulsia-' . wp_generate_password( 8, false ) . '.' . $ext;
	$moved = @rename( $tmp, $staged ) || @copy( $tmp, $staged );
	@unlink( $tmp );
	if ( ! $moved || ! file_exists( $staged ) || ! filesize( $staged ) ) {
		@unlink( $staged );
		return [ 'status' => 'error', 'msg' => __( 'No se pudo escribir la imagen nueva en uploads (permisos o espacio); el original no se ha tocado.', 'inpulsia-medios' ), 'prev' => $prev ];
	}
	// Optimizer copies (photo.jpg.webp, photo.jpg.avif) would serve the old picture: remove them now.
	// Only exact copies of a file of this image, never anything else that happens to share the name.
	list( , $old_meta_files ) = inpulsia_medios_meta_files( $id );
	foreach ( array_diff( $old_files, $old_meta_files ) as $f ) {
		if ( preg_match( '/^(.+)\.(webp|avif)$/i', $f, $m ) && in_array( $m[1], $old_meta_files, true ) ) {
			@unlink( $dir . '/' . $f );
		}
	}
	if ( ! @rename( $staged, $dir . '/' . $new_name ) ) {
		$new_name = basename( $staged );
	}
	inpulsia_medios_fix_perms( $dir . '/' . $new_name );

	$siblings = inpulsia_medios_siblings( $id );
	update_attached_file( $id, $dir . '/' . $new_name );
	$wpdb->update( $wpdb->posts, [ 'post_mime_type' => $target ], [ 'ID' => $id ] );
	clean_post_cache( $id );
	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
	$new_meta = wp_generate_attachment_metadata( $id, $dir . '/' . $new_name );
	wp_update_attachment_metadata( $id, $new_meta );
	$new_main = basename( get_attached_file( $id ) );
	list( , $new_meta_files ) = inpulsia_medios_meta_files( $id );

	// Check every new file is really on disk before the old ones are let go; otherwise go back to the original.
	clearstatcache();
	$missing = [];
	foreach ( $new_meta_files as $f ) {
		if ( ! file_exists( $dir . '/' . $f ) || ! filesize( $dir . '/' . $f ) ) {
			$missing[] = $f;
		}
	}
	if ( $missing ) {
		foreach ( array_diff( $new_meta_files, $old_meta_files ) as $f ) {
			@unlink( $dir . '/' . $f );
		}
		if ( $backup && ! file_exists( $file ) ) {
			inpulsia_medios_backup_restore( $backup );
		} else {
			update_attached_file( $id, $file );
			wp_update_attachment_metadata( $id, $old_meta );
			$wpdb->update( $wpdb->posts, [ 'post_mime_type' => $mime, 'guid' => wp_get_attachment_url( $id ) ], [ 'ID' => $id ] );
			clean_post_cache( $id );
			inpulsia_medios_siblings_sync( $id, $siblings );
		}
		return [ 'status' => 'error', 'msg' => sprintf( __( 'La imagen nueva no se ha generado bien (falta %s); se mantiene la original sin cambios.', 'inpulsia-medios' ), implode( ', ', array_slice( $missing, 0, 3 ) ) ), 'prev' => $prev ];
	}
	foreach ( $new_meta_files as $f ) {
		inpulsia_medios_fix_perms( $dir . '/' . $f );
	}
	// Old files stay reachable during the grace period (the daily cleanup removes them) unless asked to delete them now.
	if ( $opts['keep_old'] ) {
		inpulsia_medios_orphans_add( $id, $dir, array_diff( $old_meta_files, $new_meta_files ) );
	} else {
		foreach ( array_diff( $old_meta_files, $new_meta_files ) as $f ) {
			@unlink( $dir . '/' . $f );
		}
	}
	$wpdb->update( $wpdb->posts, [ 'guid' => wp_get_attachment_url( $id ) ], [ 'ID' => $id ] );
	clean_post_cache( $id );
	inpulsia_medios_siblings_sync( $id, $siblings );

	// 5. Point every old URL at the new files.
	$map = [ $old_main => $new_main ];
	if ( ! empty( $old_meta['original_image'] ) ) {
		$map[ $old_meta['original_image'] ] = $new_meta['original_image'] ?? $new_main;
	}
	foreach ( $old_meta['sizes'] ?? [] as $name => $s ) {
		$map[ $s['file'] ] = $new_meta['sizes'][ $name ]['file'] ?? $new_main;
	}
	foreach ( $old_files as $f ) {
		if ( ! isset( $map[ $f ] ) ) {
			$map[ $f ] = $new_main;
		}
	}
	$map = array_filter( $map, function ( $new, $old ) {
		return $new !== $old;
	}, ARRAY_FILTER_USE_BOTH );

	$rel     = inpulsia_medios_rel_dir( $id );
	$updated = $map ? inpulsia_medios_replace_urls( $rel, $old_stem, $map, $id ) : 0;
	inpulsia_medios_redirects_add( $rel, $map );
	$GLOBALS['inpulsia_medios_changed'] = true;

	$after = filesize( get_attached_file( $id ) );
	$public = inpulsia_medios_check_public( $id );
	if ( $public !== '' ) {
		$notes[] = $public;
	}
	if ( $backup ) {
		$prev['restore'] = $backup;
	}
	$fmt_old = strtoupper( str_replace( [ 'image/', 'jpeg' ], [ '', 'jpg' ], $mime ) );
	$fmt_new = strtoupper( $ext );
	$msg     = sprintf(
		'%s → %s%s: %s → %s (−%d %%)',
		$fmt_old,
		$fmt_new,
		$alpha && $target === $mime ? ' (transparente, se mantiene)' : '',
		size_format( $before, 1 ),
		size_format( $after, 1 ),
		$before ? max( 0, round( ( 1 - $after / $before ) * 100 ) ) : 0
	);
	if ( $resized ) {
		$msg .= ', redimensionada a ' . $opts['max_width'] . ' px';
	}
	if ( $new_stem !== $old_stem ) {
		$notes[] = sprintf( __( 'renombrada a «%s»', 'inpulsia-medios' ), pathinfo( $new_main, PATHINFO_FILENAME ) );
	}
	if ( $updated ) {
		$notes[] = $updated . ' contenidos actualizados';
	}
	if ( $notes ) {
		$msg .= '; ' . implode( ', ', $notes );
	}

	return [
		'status' => 'ok',
		'msg'    => $msg . '.',
		'before' => $before,
		'after'  => $after,
		'prev'   => $prev,
	];
}

function inpulsia_medios_backup_dir() {
	$dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'inpulsia-medios-backups';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
	}
	return $dir;
}

function inpulsia_medios_backup_create( $id ) {
	list( $src, $files ) = inpulsia_medios_attachment_files( $id );
	if ( ! $files ) {
		return new WP_Error( 'backup', __( 'No hay archivos que copiar.', 'inpulsia-medios' ) );
	}
	$key  = $id . '-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 4, false ) );
	$dest = inpulsia_medios_backup_dir() . '/' . $key;
	if ( ! wp_mkdir_p( $dest ) ) {
		return new WP_Error( 'backup', __( 'No se pudo crear la copia de seguridad (permisos de uploads).', 'inpulsia-medios' ) );
	}
	foreach ( $files as $f ) {
		if ( ! @copy( $src . '/' . $f, $dest . '/' . $f ) ) {
			inpulsia_medios_rmdir( $dest );
			return new WP_Error( 'backup', __( 'No se pudo crear la copia de seguridad (¿espacio en disco?).', 'inpulsia-medios' ) );
		}
	}
	file_put_contents( $dest . '/backup.json', wp_json_encode( [
		'id'    => $id,
		'file'  => get_post_meta( $id, '_wp_attached_file', true ),
		'meta'  => wp_get_attachment_metadata( $id ),
		'mime'  => get_post_mime_type( $id ),
		'files' => $files,
		'date'  => time(),
	] ) );
	return $key;
}

// Puts the backed-up files back, rewrites URLs to them and deletes the backup.
function inpulsia_medios_backup_restore( $key ) {
	global $wpdb;
	$key  = sanitize_file_name( $key );
	$path = inpulsia_medios_backup_dir() . '/' . $key;
	$data = is_file( $path . '/backup.json' ) ? json_decode( file_get_contents( $path . '/backup.json' ), true ) : null;
	if ( ! $data ) {
		return new WP_Error( 'no_backup', __( 'La copia de seguridad ya no existe.', 'inpulsia-medios' ) );
	}
	$id = (int) $data['id'];

	$cur_stem                  = inpulsia_medios_stem( $id );
	$cur_meta                  = wp_get_attachment_metadata( $id ) ?: [];
	$cur_main                  = basename( (string) get_attached_file( $id ) );
	list( $cur_dir, $cur_files ) = inpulsia_medios_attachment_files( $id );
	$old_meta                  = $data['meta'];
	$old_main                  = basename( $data['file'] );
	$old_rel                   = dirname( $data['file'] );
	$dir                       = untrailingslashit( wp_get_upload_dir()['basedir'] ) . ( $old_rel === '.' ? '' : '/' . $old_rel );

	foreach ( $cur_files as $f ) {
		@unlink( $cur_dir . '/' . $f );
	}
	inpulsia_medios_orphans_release( $id, $dir, $data['files'] );
	foreach ( $data['files'] as $f ) {
		if ( ! @copy( $path . '/' . $f, $dir . '/' . $f ) ) {
			return new WP_Error( 'restore', sprintf( __( 'No se pudo restaurar %s.', 'inpulsia-medios' ), $f ) );
		}
		inpulsia_medios_fix_perms( $dir . '/' . $f );
	}

	$siblings = inpulsia_medios_siblings( $id );
	update_post_meta( $id, '_wp_attached_file', $data['file'] );
	wp_update_attachment_metadata( $id, $old_meta );
	$wpdb->update( $wpdb->posts, [ 'post_mime_type' => $data['mime'] ], [ 'ID' => $id ] );
	clean_post_cache( $id );
	$wpdb->update( $wpdb->posts, [ 'guid' => wp_get_attachment_url( $id ) ], [ 'ID' => $id ] );
	clean_post_cache( $id );
	inpulsia_medios_siblings_sync( $id, $siblings );

	$map = [ $cur_main => $old_main ];
	if ( ! empty( $cur_meta['original_image'] ) ) {
		$map[ $cur_meta['original_image'] ] = $old_meta['original_image'] ?? $old_main;
	}
	foreach ( $cur_meta['sizes'] ?? [] as $name => $s ) {
		$map[ $s['file'] ] = $old_meta['sizes'][ $name ]['file'] ?? $old_main;
	}
	$map = array_filter( $map, function ( $new, $old ) {
		return $new !== $old;
	}, ARRAY_FILTER_USE_BOTH );

	$rel = inpulsia_medios_rel_dir( $id );
	if ( $map ) {
		inpulsia_medios_replace_urls( $rel, $cur_stem, $map, $id );
		inpulsia_medios_redirects_add( $rel, $map );
	}
	inpulsia_medios_rmdir( $path );
	return true;
}

function inpulsia_medios_rmdir( $dir ) {
	foreach ( array_diff( (array) @scandir( $dir ), [ '.', '..' ] ) as $f ) {
		is_dir( $dir . '/' . $f ) ? inpulsia_medios_rmdir( $dir . '/' . $f ) : @unlink( $dir . '/' . $f );
	}
	@rmdir( $dir );
}

// [ count, bytes ] of stored backups, optionally only those older than $days.
function inpulsia_medios_backups_stats( $days = 0 ) {
	$count = 0;
	$bytes = 0;
	$limit = time() - $days * DAY_IN_SECONDS;
	foreach ( (array) glob( inpulsia_medios_backup_dir() . '/*', GLOB_ONLYDIR ) as $d ) {
		if ( $days && filemtime( $d ) > $limit ) {
			continue;
		}
		$count++;
		foreach ( (array) glob( $d . '/*' ) as $f ) {
			$bytes += (int) @filesize( $f );
		}
	}
	return [ $count, $bytes ];
}

function inpulsia_medios_backups_delete( $days = 0 ) {
	$deleted = 0;
	$limit   = time() - $days * DAY_IN_SECONDS;
	foreach ( (array) glob( inpulsia_medios_backup_dir() . '/*', GLOB_ONLYDIR ) as $d ) {
		if ( $days && filemtime( $d ) > $limit ) {
			continue;
		}
		inpulsia_medios_rmdir( $d );
		$deleted++;
	}
	return $deleted;
}
