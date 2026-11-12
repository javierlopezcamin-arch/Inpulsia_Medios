<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Old files stay on disk this long after a rename or optimization, so cached pages and CDNs never point to a missing file.
define( 'INPULSIA_MEDIOS_GRACE_DAYS', 15 );

add_action( 'init', 'inpulsia_medios_schedule_cleanup' );
add_action( 'inpulsia_medios_cleanup', 'inpulsia_medios_orphans_cleanup' );
add_action( 'admin_init', 'inpulsia_medios_repair_permissions_once' );

function inpulsia_medios_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'inpulsia_medios_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'inpulsia_medios_cleanup' );
	}
}

// Same permissions WordPress gives to uploads, so the web server can read files we create.
function inpulsia_medios_fix_perms( $path ) {
	if ( ! file_exists( $path ) ) {
		return;
	}
	$stat  = @stat( dirname( $path ) );
	$perms = $stat ? ( $stat['mode'] & 0000666 ) : 0644;
	@chmod( $path, $perms ?: 0644 );
}

// Main, original and sub-size files listed in the attachment metadata (no optimizer copies).
function inpulsia_medios_meta_files( $id ) {
	$file = get_attached_file( $id );
	if ( ! $file ) {
		return [ '', [] ];
	}
	$meta  = wp_get_attachment_metadata( $id );
	$names = [ basename( $file ) ];
	if ( ! empty( $meta['original_image'] ) ) {
		$names[] = $meta['original_image'];
	}
	foreach ( $meta['sizes'] ?? [] as $s ) {
		$names[] = $s['file'];
	}
	return [ dirname( $file ), array_values( array_unique( $names ) ) ];
}

function inpulsia_medios_orphans() {
	$o = get_option( 'inpulsia_medios_orphans', [] );
	return is_array( $o ) ? $o : [];
}

function inpulsia_medios_orphans_add( $id, $dir, $names ) {
	$names = array_values( array_filter( $names, function ( $n ) use ( $dir ) {
		return file_exists( $dir . '/' . $n );
	} ) );
	if ( ! $names ) {
		return;
	}
	$list   = inpulsia_medios_orphans();
	$list[] = [ 'id' => (int) $id, 'dir' => $dir, 'files' => $names, 'until' => time() + INPULSIA_MEDIOS_GRACE_DAYS * DAY_IN_SECONDS ];
	update_option( 'inpulsia_medios_orphans', $list, false );
}

// Deletes kept old files of this attachment that are about to be reused (e.g. renaming back) and forgets them.
function inpulsia_medios_orphans_release( $id, $dir, $names ) {
	$list    = inpulsia_medios_orphans();
	$changed = false;
	foreach ( $list as $k => $entry ) {
		if ( (int) $entry['id'] !== (int) $id || $entry['dir'] !== $dir ) {
			continue;
		}
		$keep = array_diff( $entry['files'], $names );
		foreach ( array_intersect( $entry['files'], $names ) as $f ) {
			@unlink( $dir . '/' . $f );
		}
		if ( count( $keep ) !== count( $entry['files'] ) ) {
			$changed = true;
			if ( $keep ) {
				$list[ $k ]['files'] = array_values( $keep );
			} else {
				unset( $list[ $k ] );
			}
		}
	}
	if ( $changed ) {
		update_option( 'inpulsia_medios_orphans', array_values( $list ), false );
	}
}

// Removes expired old files (or all of them with $all), never one the attachment uses again.
function inpulsia_medios_orphans_cleanup( $all = false ) {
	$list    = inpulsia_medios_orphans();
	$deleted = 0;
	foreach ( $list as $k => $entry ) {
		if ( ! $all && $entry['until'] > time() ) {
			continue;
		}
		list( , $owned ) = inpulsia_medios_meta_files( (int) $entry['id'] );
		foreach ( $entry['files'] as $f ) {
			if ( ! in_array( $f, $owned, true ) && @unlink( $entry['dir'] . '/' . $f ) ) {
				$deleted++;
			}
		}
		unset( $list[ $k ] );
	}
	update_option( 'inpulsia_medios_orphans', array_values( $list ), false );
	return $deleted;
}

function inpulsia_medios_done_notes() {
	$notes = [];
	if ( get_transient( 'inpulsia_medios_cloudflare' ) && empty( $GLOBALS['inpulsia_medios_cloudflare_purged'] ) ) {
		$notes[] = 'La web pasa por Cloudflare: vacía su caché desde el panel de Cloudflare para que las visitas vean ya las imágenes nuevas.';
	}
	$notes[] = 'Los archivos antiguos se conservan ' . INPULSIA_MEDIOS_GRACE_DAYS . ' días para que ninguna caché muestre una imagen rota; después se borran solos.';
	return $notes;
}

function inpulsia_medios_orphans_stats() {
	$count = 0;
	$bytes = 0;
	foreach ( inpulsia_medios_orphans() as $entry ) {
		foreach ( $entry['files'] as $f ) {
			if ( file_exists( $entry['dir'] . '/' . $f ) ) {
				$count++;
				$bytes += (int) filesize( $entry['dir'] . '/' . $f );
			}
		}
	}
	return [ $count, $bytes ];
}

/**
 * Requests the image as a visitor would. Repairs permissions on a 401/403.
 * Returns '' when it loads (or the check is not possible) and a warning otherwise.
 */
function inpulsia_medios_check_public( $id ) {
	$url   = wp_get_attachment_url( $id );
	$check = function () use ( $url ) {
		return wp_remote_head( $url, [ 'timeout' => 6, 'redirection' => 0, 'sslverify' => false ] );
	};
	$res = $check();
	if ( is_wp_error( $res ) ) {
		return '';
	}
	$headers = wp_remote_retrieve_headers( $res );
	if ( ! empty( $headers['cf-ray'] ) || stripos( (string) ( $headers['server'] ?? '' ), 'cloudflare' ) !== false ) {
		set_transient( 'inpulsia_medios_cloudflare', 1, WEEK_IN_SECONDS );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( in_array( $code, [ 401, 403 ], true ) ) {
		list( $dir, $names ) = inpulsia_medios_meta_files( $id );
		foreach ( $names as $n ) {
			inpulsia_medios_fix_perms( $dir . '/' . $n );
		}
		$res  = $check();
		$code = is_wp_error( $res ) ? 200 : (int) wp_remote_retrieve_response_code( $res );
	}
	if ( $code >= 400 ) {
		return sprintf( 'Atención: la imagen nueva responde con error %d al abrirla desde la web; revisa los permisos de uploads o las reglas del servidor para .%s.', $code, pathinfo( $url, PATHINFO_EXTENSION ) );
	}
	return '';
}

// 1.3.4 fixes the permissions of images optimized by earlier versions.
function inpulsia_medios_repair_permissions_once() {
	if ( get_option( 'inpulsia_medios_perms_repaired' ) || ! current_user_can( 'upload_files' ) ) {
		return;
	}
	$ids = [];
	foreach ( inpulsia_medios_history() as $entry ) {
		foreach ( (array) get_option( 'inpulsia_medios_log_' . $entry['token'], [] ) as $item ) {
			$ids[ (int) $item['id'] ] = true;
		}
	}
	$backups = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'inpulsia-medios-backups';
	foreach ( (array) glob( $backups . '/*', GLOB_ONLYDIR ) as $d ) {
		$ids[ (int) basename( $d ) ] = true;
	}
	foreach ( array_keys( $ids ) as $id ) {
		list( $dir, $names ) = inpulsia_medios_meta_files( $id );
		foreach ( $names as $n ) {
			inpulsia_medios_fix_perms( $dir . '/' . $n );
		}
	}
	update_option( 'inpulsia_medios_perms_repaired', 1, false );
}
