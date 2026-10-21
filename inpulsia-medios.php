<?php
/**
 * Plugin Name: Inpulsia Medios
 * Plugin URI: https://inpulsia.es
 * Description: Actualización masiva de medios (nombre de archivo, alt y descripción) mediante CSV con indicaciones SEO.
 * Version: 1.0.0
 * Author: Inpulsia
 * Author URI: https://inpulsia.es
 * License: GPL-2.0+
 * Text Domain: inpulsia-medios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INPULSIA_MEDIOS_VERSION', '1.0.0' );
define( 'INPULSIA_MEDIOS_PATH', plugin_dir_path( __FILE__ ) );
define( 'INPULSIA_MEDIOS_URL', plugin_dir_url( __FILE__ ) );

add_action( 'admin_menu', 'inpulsia_medios_menu' );
add_action( 'admin_enqueue_scripts', 'inpulsia_medios_assets' );
add_action( 'wp_ajax_inpulsia_medios_process', 'inpulsia_medios_process_ajax' );
add_action( 'wp_ajax_inpulsia_medios_export', 'inpulsia_medios_export_ajax' );

function inpulsia_medios_menu() {
	add_media_page(
		'Inpulsia Medios',
		'Inpulsia Medios',
		'upload_files',
		'inpulsia-medios',
		'inpulsia_medios_page'
	);
}

function inpulsia_medios_assets( $hook ) {
	if ( $hook !== 'media_page_inpulsia-medios' ) {
		return;
	}
	wp_enqueue_style(
		'inpulsia-medios',
		INPULSIA_MEDIOS_URL . 'assets/style.css',
		[],
		INPULSIA_MEDIOS_VERSION
	);
	wp_enqueue_script(
		'inpulsia-medios',
		INPULSIA_MEDIOS_URL . 'assets/script.js',
		[ 'jquery' ],
		INPULSIA_MEDIOS_VERSION,
		true
	);
	wp_localize_script( 'inpulsia-medios', 'inpulsiaMedios', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'inpulsia_medios_nonce' ),
	] );
}

function inpulsia_medios_page() {
	?>
	<div class="wrap inpulsia-medios-wrap">
		<h1>Inpulsia Medios</h1>
		<p class="description">Actualiza nombre de archivo, texto alternativo y descripción de tus medios de forma masiva mediante un archivo CSV.</p>

		<div class="inpulsia-medios-cards">
			<!-- Export card -->
			<div class="inpulsia-medios-card">
				<h2>1. Exportar medios actuales</h2>
				<p>Descarga un CSV con todos los medios de tu biblioteca para editarlo.</p>
				<button id="inpulsia-medios-export" class="button button-secondary">Exportar CSV</button>
			</div>

			<!-- Import card -->
			<div class="inpulsia-medios-card">
				<h2>2. Subir CSV modificado</h2>
				<p>El CSV debe tener las columnas: <code>ID</code>, <code>filename</code>, <code>alt</code>, <code>description</code></p>
				<p class="description">Deja en blanco las celdas que no quieras cambiar. El <code>ID</code> es obligatorio.</p>
				<form id="inpulsia-medios-form" enctype="multipart/form-data">
					<input type="file" id="inpulsia-medios-file" accept=".csv" required />
					<button type="submit" class="button button-primary">Procesar CSV</button>
				</form>
			</div>
		</div>

		<!-- Preview table -->
		<div id="inpulsia-medios-preview" style="display:none;">
			<h2>Vista previa de cambios</h2>
			<p class="description">Revisa los cambios antes de aplicarlos. Los valores que cambiarán aparecen resaltados.</p>
			<table class="wp-list-table widefat fixed striped" id="inpulsia-medios-table">
				<thead>
					<tr>
						<th>ID</th>
						<th>Miniatura</th>
						<th>Nombre archivo actual → nuevo</th>
						<th>Alt actual → nuevo</th>
						<th>Descripción actual → nueva</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
			<p>
				<button id="inpulsia-medios-apply" class="button button-primary button-hero">Aplicar cambios</button>
				<button id="inpulsia-medios-cancel" class="button">Cancelar</button>
			</p>
		</div>

		<!-- Results -->
		<div id="inpulsia-medios-results" style="display:none;">
			<h2>Resultados</h2>
			<div id="inpulsia-medios-log"></div>
		</div>
	</div>
	<?php
}

function inpulsia_medios_export_ajax() {
	check_ajax_referer( 'inpulsia_medios_nonce', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'Sin permisos.' );
	}

	$args = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'post_mime_type' => 'image',
	];
	$attachments = get_posts( $args );
	$rows = [];
	$rows[] = [ 'ID', 'filename', 'alt', 'description', 'url' ];

	foreach ( $attachments as $att ) {
		$file = get_attached_file( $att->ID );
		$rows[] = [
			$att->ID,
			$file ? basename( $file ) : '',
			get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
			$att->post_content,
			wp_get_attachment_url( $att->ID ),
		];
	}

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="medios-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // BOM for Excel
	foreach ( $rows as $row ) {
		fputcsv( $out, $row, ';' );
	}
	fclose( $out );
	wp_die();
}

function inpulsia_medios_process_ajax() {
	check_ajax_referer( 'inpulsia_medios_nonce', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'Sin permisos.' );
	}

	$action_type = sanitize_text_field( $_POST['action_type'] ?? 'preview' );

	if ( $action_type === 'preview' ) {
		inpulsia_medios_preview();
	} else {
		inpulsia_medios_apply();
	}
}

function inpulsia_medios_parse_csv( $file_path ) {
	$rows = [];
	$handle = fopen( $file_path, 'r' );
	if ( ! $handle ) {
		return [];
	}

	// Detect delimiter
	$first_line = fgets( $handle );
	rewind( $handle );
	$delimiter = ( substr_count( $first_line, ';' ) >= substr_count( $first_line, ',' ) ) ? ';' : ',';

	// Remove BOM
	$bom = fread( $handle, 3 );
	if ( $bom !== chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ) {
		rewind( $handle );
	}

	$headers = fgetcsv( $handle, 0, $delimiter );
	if ( ! $headers ) {
		fclose( $handle );
		return [];
	}
	$headers = array_map( 'strtolower', array_map( 'trim', $headers ) );

	while ( ( $data = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
		$row = [];
		foreach ( $headers as $i => $h ) {
			$row[ $h ] = isset( $data[ $i ] ) ? trim( $data[ $i ] ) : '';
		}
		if ( ! empty( $row['id'] ) ) {
			$rows[] = $row;
		}
	}
	fclose( $handle );
	return $rows;
}

function inpulsia_medios_preview() {
	if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( 'Error al subir el archivo.' );
	}

	$file = $_FILES['csv_file']['tmp_name'];
	$rows = inpulsia_medios_parse_csv( $file );

	if ( empty( $rows ) ) {
		wp_send_json_error( 'CSV vacío o sin columna ID.' );
	}

	$preview = [];
	foreach ( $rows as $row ) {
		$id  = intval( $row['id'] );
		$att = get_post( $id );
		if ( ! $att || $att->post_type !== 'attachment' ) {
			continue;
		}

		$current_file = get_attached_file( $id );
		$current_name = $current_file ? basename( $current_file ) : '';
		$current_alt  = get_post_meta( $id, '_wp_attachment_image_alt', true );
		$current_desc = $att->post_content;
		$thumb        = wp_get_attachment_image_url( $id, 'thumbnail' );

		$new_name = $row['filename'] ?? '';
		$new_alt  = $row['alt'] ?? '';
		$new_desc = $row['description'] ?? '';

		$preview[] = [
			'id'           => $id,
			'thumb'        => $thumb ?: '',
			'current_name' => $current_name,
			'new_name'     => $new_name,
			'current_alt'  => $current_alt,
			'new_alt'      => $new_alt,
			'current_desc' => $current_desc,
			'new_desc'     => $new_desc,
		];
	}

	// Store CSV in transient for apply step
	set_transient( 'inpulsia_medios_csv_' . get_current_user_id(), $rows, 300 );

	wp_send_json_success( $preview );
}

function inpulsia_medios_apply() {
	$rows = get_transient( 'inpulsia_medios_csv_' . get_current_user_id() );
	if ( ! $rows ) {
		wp_send_json_error( 'Sesión expirada. Vuelve a subir el CSV.' );
	}
	delete_transient( 'inpulsia_medios_csv_' . get_current_user_id() );

	$results = [];
	foreach ( $rows as $row ) {
		$id  = intval( $row['id'] );
		$att = get_post( $id );
		if ( ! $att || $att->post_type !== 'attachment' ) {
			$results[] = [ 'id' => $id, 'status' => 'error', 'msg' => 'Medio no encontrado.' ];
			continue;
		}

		$changes = [];

		// Alt text
		$new_alt = $row['alt'] ?? '';
		if ( $new_alt !== '' ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $new_alt ) );
			$changes[] = 'alt';
		}

		// Description
		$new_desc = $row['description'] ?? '';
		if ( $new_desc !== '' ) {
			wp_update_post( [
				'ID'           => $id,
				'post_content' => sanitize_textarea_field( $new_desc ),
			] );
			$changes[] = 'descripción';
		}

		// Rename file
		$new_name = $row['filename'] ?? '';
		if ( $new_name !== '' ) {
			$renamed = inpulsia_medios_rename_file( $id, $new_name );
			if ( is_wp_error( $renamed ) ) {
				$results[] = [ 'id' => $id, 'status' => 'partial', 'msg' => 'Alt/desc OK. Renombrar falló: ' . $renamed->get_error_message(), 'changes' => $changes ];
				continue;
			}
			$changes[] = 'nombre';
		}

		if ( empty( $changes ) ) {
			$results[] = [ 'id' => $id, 'status' => 'skip', 'msg' => 'Sin cambios.' ];
		} else {
			$results[] = [ 'id' => $id, 'status' => 'ok', 'msg' => 'Actualizado: ' . implode( ', ', $changes ) . '.' ];
		}
	}

	wp_send_json_success( $results );
}

function inpulsia_medios_rename_file( $id, $new_filename ) {
	$old_path = get_attached_file( $id );
	if ( ! $old_path || ! file_exists( $old_path ) ) {
		return new WP_Error( 'not_found', 'Archivo no encontrado en disco.' );
	}

	$dir       = dirname( $old_path );
	$old_ext   = pathinfo( $old_path, PATHINFO_EXTENSION );
	$new_base  = pathinfo( $new_filename, PATHINFO_FILENAME );
	$new_ext   = pathinfo( $new_filename, PATHINFO_EXTENSION );

	// Keep original extension if not provided
	if ( ! $new_ext ) {
		$new_ext = $old_ext;
	}

	$new_base = sanitize_file_name( $new_base );
	$new_file = $new_base . '.' . $new_ext;
	$new_path = $dir . '/' . $new_file;

	// Avoid overwriting
	if ( $new_path !== $old_path && file_exists( $new_path ) ) {
		return new WP_Error( 'exists', 'Ya existe un archivo con ese nombre.' );
	}

	if ( $new_path === $old_path ) {
		return true;
	}

	if ( ! rename( $old_path, $new_path ) ) {
		return new WP_Error( 'rename_failed', 'No se pudo renombrar el archivo.' );
	}

	// Update attachment
	update_attached_file( $id, $new_path );

	// Update post title and slug
	wp_update_post( [
		'ID'         => $id,
		'post_title' => $new_base,
		'post_name'  => sanitize_title( $new_base ),
	] );

	// Update metadata (thumbnails etc)
	$metadata = wp_get_attachment_metadata( $id );
	if ( $metadata && isset( $metadata['file'] ) ) {
		$upload_dir = wp_get_upload_dir();
		$rel_dir    = dirname( $metadata['file'] );
		$metadata['file'] = $rel_dir . '/' . $new_file;

		// Rename thumbnails
		if ( ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $info ) {
				$old_thumb = $dir . '/' . $info['file'];
				$thumb_ext = pathinfo( $info['file'], PATHINFO_EXTENSION );
				$new_thumb_name = $new_base . '-' . $info['width'] . 'x' . $info['height'] . '.' . $thumb_ext;
				$new_thumb_path = $dir . '/' . $new_thumb_name;
				if ( file_exists( $old_thumb ) && ! file_exists( $new_thumb_path ) ) {
					rename( $old_thumb, $new_thumb_path );
				}
				$metadata['sizes'][ $size ]['file'] = $new_thumb_name;
			}
		}
		wp_update_attachment_metadata( $id, $metadata );
	}

	// Update GUID
	global $wpdb;
	$new_url = str_replace( basename( wp_get_attachment_url( $id ) ), $new_file, wp_get_attachment_url( $id ) );
	$wpdb->update( $wpdb->posts, [ 'guid' => esc_url( $new_url ) ], [ 'ID' => $id ] );

	return true;
}
