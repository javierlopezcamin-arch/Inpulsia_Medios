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
		$plan['error'] = 'Archivo no encontrado en el servidor.';
		return $plan;
	}
	if ( ! in_array( $mime, inpulsia_medios_opt_mimes(), true ) ) {
		$plan['error'] = 'Formato no compatible (' . $fmt . ').';
		return $plan;
	}
	if ( $mime === 'image/gif' && inpulsia_medios_is_animated_gif( $file ) ) {
		$plan['error'] = 'GIF animado: se deja como está.';
		return $plan;
	}

	$width  = (int) ( $meta['width'] ?? 0 );
	$height = (int) ( $meta['height'] ?? 0 );
	if ( ! empty( $meta['original_image'] ) && ( $size = @getimagesize( dirname( $file ) . '/' . $meta['original_image'] ) ) ) {
		list( $width, $height ) = $size;
	}

	if ( $fmt !== $to ) {
		$plan['lines'][] = $mime === 'image/jpeg' ? "Formato: {$fmt} → {$to}" : "Formato: {$fmt} → {$to} (si no tiene transparencia)";
	}
	if ( $width > $opts['max_width'] ) {
		$plan['lines'][] = sprintf( 'Tamaño: %d×%d → %d×%d', $width, $height, $opts['max_width'], round( $height * $opts['max_width'] / $width ) );
	}
	$plan['lines'][] = 'Compresión: calidad ' . $opts['quality'] . ' y sin metadatos EXIF';

	$stem = inpulsia_medios_stem( $id );
	if ( $opts['ai'] ) {
		if ( inpulsia_medios_is_camera_name( $stem ) ) {
			$plan['lines'][] = 'Nombre: «' . $stem . '» → propuesto por IA';
		}
		if ( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) === '' ) {
			$plan['lines'][] = 'Alt: vacío → propuesto por IA';
		}
	}
	if ( ! $opts['backup'] ) {
		$plan['warnings'][] = 'Sin copia de seguridad: no se podrá deshacer.';
	}
	return $plan;
}

/**
 * Converts, resizes and recompresses one attachment. Optional AI name and alt.
 * Returns [ status, msg, before, after, prev ] where prev feeds the undo log.
 */
function inpulsia_medios_optimize( $id, $opts ) {
	global $wpdb;
	$opts = inpulsia_medios_opt_sanitize( $opts );
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		return [ 'status' => 'error', 'msg' => 'Medio no encontrado.' ];
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return [ 'status' => 'error', 'msg' => 'No tienes permisos para editar este medio.' ];
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
						$notes[] = 'alt «' . $row['alt'] . '»';
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
		return [ 'status' => 'error', 'msg' => 'No se pudo abrir la imagen: ' . $editor->get_error_message(), 'prev' => $prev ];
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
		return [ 'status' => 'error', 'msg' => 'No se pudo guardar la imagen: ' . $saved->get_error_message(), 'prev' => $prev ];
	}
	$tmp = $saved['path'];

	// Never replace a file with one that is not clearly lighter (flat graphics are often smaller as PNG).
	if ( filesize( $tmp ) >= $before * 0.95 ) {
		@unlink( $tmp );
		if ( $new_stem !== $old_stem ) {
			$r = inpulsia_medios_rename( $id, $new_stem );
			if ( ! is_wp_error( $r ) ) {
				$prev['filename'] = $old_stem;
				$notes[]          = 'renombrada a «' . $new_stem . '»';
			}
		}
		return [
			'status' => $notes ? 'ok' : 'skip',
			'msg'    => ( $alpha ? 'Tiene transparencia: se mantiene en ' . strtoupper( str_replace( 'image/', '', $mime ) ) . ' y ya estaba optimizada' : ( $target === $mime ? 'Ya estaba optimizada' : 'Se mantiene en ' . strtoupper( str_replace( [ 'image/', 'jpeg' ], [ '', 'jpg' ], $mime ) ) . ': convertirla no reduce el peso' ) ) . ( $notes ? '; ' . implode( ', ', $notes ) : '' ) . '.',
			'before' => $before,
			'after'  => $before,
			'prev'   => $prev,
		];
	}

	// 3. Check the destination name before touching anything.
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
		return [ 'status' => 'error', 'msg' => 'No se pudo escribir la imagen nueva en uploads (permisos o espacio); el original no se ha tocado.', 'prev' => $prev ];
	}
	foreach ( $old_files as $f ) {
		@unlink( $dir . '/' . $f );
	}
	if ( ! @rename( $staged, $dir . '/' . $new_name ) ) {
		$new_name = basename( $staged );
	}

	update_attached_file( $id, $dir . '/' . $new_name );
	$wpdb->update( $wpdb->posts, [ 'post_mime_type' => $target ], [ 'ID' => $id ] );
	clean_post_cache( $id );
	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
	$new_meta = wp_generate_attachment_metadata( $id, $dir . '/' . $new_name );
	wp_update_attachment_metadata( $id, $new_meta );
	$new_main = basename( get_attached_file( $id ) );
	$wpdb->update( $wpdb->posts, [ 'guid' => wp_get_attachment_url( $id ) ], [ 'ID' => $id ] );
	clean_post_cache( $id );

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
		$notes[] = 'renombrada a «' . pathinfo( $new_main, PATHINFO_FILENAME ) . '»';
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
		return new WP_Error( 'backup', 'No hay archivos que copiar.' );
	}
	$key  = $id . '-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 4, false ) );
	$dest = inpulsia_medios_backup_dir() . '/' . $key;
	if ( ! wp_mkdir_p( $dest ) ) {
		return new WP_Error( 'backup', 'No se pudo crear la copia de seguridad (permisos de uploads).' );
	}
	foreach ( $files as $f ) {
		if ( ! @copy( $src . '/' . $f, $dest . '/' . $f ) ) {
			inpulsia_medios_rmdir( $dest );
			return new WP_Error( 'backup', 'No se pudo crear la copia de seguridad (¿espacio en disco?).' );
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
		return new WP_Error( 'no_backup', 'La copia de seguridad ya no existe.' );
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
	foreach ( $data['files'] as $f ) {
		if ( ! @copy( $path . '/' . $f, $dir . '/' . $f ) ) {
			return new WP_Error( 'restore', 'No se pudo restaurar ' . $f . '.' );
		}
	}

	update_post_meta( $id, '_wp_attached_file', $data['file'] );
	wp_update_attachment_metadata( $id, $old_meta );
	$wpdb->update( $wpdb->posts, [ 'post_mime_type' => $data['mime'] ], [ 'ID' => $id ] );
	clean_post_cache( $id );
	$wpdb->update( $wpdb->posts, [ 'guid' => wp_get_attachment_url( $id ) ], [ 'ID' => $id ] );
	clean_post_cache( $id );

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
