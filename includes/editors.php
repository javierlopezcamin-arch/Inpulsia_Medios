<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Inpulsia panel inside the block editor (image block) and Elementor (image widget).
add_action( 'enqueue_block_editor_assets', 'inpulsia_medios_block_editor_assets' );
add_action( 'elementor/editor/after_enqueue_scripts', 'inpulsia_medios_elementor_assets' );
add_action( 'wp_ajax_inpulsia_medios_image_info', 'inpulsia_medios_ajax_image_info' );
add_action( 'wp_ajax_inpulsia_medios_opt_one', 'inpulsia_medios_ajax_opt_one' );

function inpulsia_medios_editor_config() {
	return [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'inpulsia_medios_nonce' ),
		'has_ai'   => function_exists( 'inpulsia_medios_api_key' ) && inpulsia_medios_api_key() !== '',
		'i18n'     => determine_locale() === 'es_ES' ? [] : inpulsia_medios_editor_i18n(),
	];
}

function inpulsia_medios_editor_i18n() {
	return [
		'Inpulsia Medios'                     => __( 'Inpulsia Medios', 'inpulsia-medios' ),
		'Peso'                                => __( 'Peso', 'inpulsia-medios' ),
		'Nombre de archivo'                   => __( 'Nombre de archivo', 'inpulsia-medios' ),
		'Pesa más de 300 KB: conviene optimizarla.' => __( 'Pesa más de 300 KB: conviene optimizarla.', 'inpulsia-medios' ),
		'Nombre poco amigable para Google.'   => __( 'Nombre poco amigable para Google.', 'inpulsia-medios' ),
		'Falta el texto alternativo.'         => __( 'Falta el texto alternativo.', 'inpulsia-medios' ),
		'Todo correcto.'                      => __( 'Todo correcto.', 'inpulsia-medios' ),
		'Optimizar'                           => __( 'Optimizar', 'inpulsia-medios' ),
		'Sugerir alt con IA'         => __( 'Sugerir alt con IA', 'inpulsia-medios' ),
		'Usar el alt de la biblioteca'        => __( 'Usar el alt de la biblioteca', 'inpulsia-medios' ),
		'Guardar este alt en la biblioteca'   => __( 'Guardar este alt en la biblioteca', 'inpulsia-medios' ),
		'Alt en la biblioteca'                => __( 'Alt en la biblioteca', 'inpulsia-medios' ),
		'Guardado.'                           => __( 'Guardado.', 'inpulsia-medios' ),
		'Trabajando…'                         => __( 'Trabajando…', 'inpulsia-medios' ),
		'Error de conexión con el servidor.'  => __( 'Error de conexión con el servidor.', 'inpulsia-medios' ),
		'Abrir en Inpulsia Medios'            => __( 'Abrir en Inpulsia Medios', 'inpulsia-medios' ),
		'Guarda la página para conservar el cambio.' => __( 'Guarda la página para conservar el cambio.', 'inpulsia-medios' ),
	];
}

function inpulsia_medios_block_editor_assets() {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	wp_enqueue_script( 'inpulsia-medios-blocks', INPULSIA_MEDIOS_URL . 'assets/editor-blocks.js', [ 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components' ], INPULSIA_MEDIOS_VERSION, true );
	wp_localize_script( 'inpulsia-medios-blocks', 'inpulsiaMediosEditor', inpulsia_medios_editor_config() );
}

function inpulsia_medios_elementor_assets() {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	wp_enqueue_script( 'inpulsia-medios-elementor', INPULSIA_MEDIOS_URL . 'assets/editor-elementor.js', [ 'jquery' ], INPULSIA_MEDIOS_VERSION, true );
	wp_localize_script( 'inpulsia-medios-elementor', 'inpulsiaMediosEditor', inpulsia_medios_editor_config() );
	wp_add_inline_style( 'elementor-editor', inpulsia_medios_editor_css() );
}

function inpulsia_medios_editor_css() {
	return '.im-ed{margin:10px 20px 0;padding:10px 12px;border:1px solid #d5d8dc;border-radius:6px;background:#fff;font-size:12px;line-height:1.5}.im-ed h4{margin:0 0 6px;font-size:12px;font-weight:600}.im-ed .im-ed-row{display:flex;justify-content:space-between;gap:8px}.im-ed .is-warn{color:#b32d2e}.im-ed .is-ok{color:#00701a}.im-ed button{margin:6px 6px 0 0}.im-ed .im-ed-msg{margin-top:6px;color:#50575e}';
}

function inpulsia_medios_image_info( $id ) {
	$file  = (string) get_attached_file( $id );
	$bytes = file_exists( $file ) ? (int) filesize( $file ) : 0;
	$stem  = pathinfo( $file, PATHINFO_FILENAME );
	return [
		'id'       => $id,
		'name'     => basename( $file ),
		'bytes'    => $bytes,
		'size'     => size_format( $bytes, 1 ),
		'heavy'    => $bytes > 300 * KB_IN_BYTES,
		'bad_name' => inpulsia_medios_is_bad_name( $stem ),
		'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'url'      => (string) wp_get_attachment_url( $id ),
		'edit'     => inpulsia_medios_url( 'editar', [ 'ids' => $id, 'lang' => 'all' ] ),
	];
}

function inpulsia_medios_ajax_image_info() {
	inpulsia_medios_check_ajax();
	$id = absint( $_POST['id'] ?? 0 );
	if ( get_post_type( $id ) !== 'attachment' || ! current_user_can( 'edit_post', $id ) ) {
		wp_send_json_error( __( 'Medio no encontrado.', 'inpulsia-medios' ) );
	}
	wp_send_json_success( inpulsia_medios_image_info( $id ) );
}

// Optimizes one image from the editor with the saved options; logged in Historial like any other change.
function inpulsia_medios_ajax_opt_one() {
	inpulsia_medios_check_ajax();
	$id = absint( $_POST['id'] ?? 0 );
	if ( get_post_type( $id ) !== 'attachment' || ! current_user_can( 'edit_post', $id ) ) {
		wp_send_json_error( __( 'Medio no encontrado.', 'inpulsia-medios' ) );
	}
	wp_raise_memory_limit( 'image' );
	$opts             = inpulsia_medios_opt_settings();
	$opts['keep_old'] = true; // The open editor still holds the old URL until the page is saved.
	$token            = inpulsia_medios_daily_token( 'editor', 'Optimización desde el editor' );
	list( $r )        = inpulsia_medios_optimize_ids( $token, [ $id ], $opts );
	inpulsia_medios_purge_caches();
	if ( $r['status'] === 'error' ) {
		wp_send_json_error( $r['msg'] );
	}
	wp_send_json_success( [ 'msg' => $r['msg'], 'info' => inpulsia_medios_image_info( $id ) ] );
}
