<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Text changes for every image in the current filter, not only the visible page.
add_action( 'wp_ajax_inpulsia_medios_tpl_stage', 'inpulsia_medios_ajax_tpl_stage' );
add_action( 'wp_ajax_inpulsia_medios_ai_stage', 'inpulsia_medios_ajax_ai_stage' );
add_action( 'wp_ajax_inpulsia_medios_ai_apply', 'inpulsia_medios_ajax_ai_apply' );

function inpulsia_medios_per_page_options() {
	return [ 20, 50, 100, 200, 500 ];
}

function inpulsia_medios_per_page() {
	$user = get_current_user_id();
	$req  = absint( $_GET['per_page'] ?? 0 );
	if ( in_array( $req, inpulsia_medios_per_page_options(), true ) ) {
		update_user_meta( $user, 'inpulsia_medios_per_page', $req );
		return $req;
	}
	$saved = (int) get_user_meta( $user, 'inpulsia_medios_per_page', true );
	return in_array( $saved, inpulsia_medios_per_page_options(), true ) ? $saved : 20;
}

function inpulsia_medios_filter_ids() {
	return array_map( 'intval', get_posts( inpulsia_medios_editar_args( inpulsia_medios_editar_params() ) + [
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	] ) );
}

// Same rules as the template in the table (assets/script.js), run on the server.
function inpulsia_medios_template_value( $id, $tpl, $field, $n ) {
	$cur   = inpulsia_medios_current( $id );
	$where = inpulsia_medios_where_used( $id, 1 );
	$page  = $where ? $where[0]['title'] : $cur['title'];
	$vars  = [
		'pagina'  => $page,
		'keyword' => $where && $where[0]['keyword'] !== '' ? $where[0]['keyword'] : $page,
		'titulo'  => $cur['title'],
		'web'     => get_bloginfo( 'name' ),
		'nombre'  => $cur['filename'],
		'alt'     => $cur['alt'],
		'n'       => (string) $n,
	];
	if ( preg_match( '/\{(encabezado|categoria|ciudad)\}/', $tpl ) && function_exists( 'inpulsia_medios_image_context' ) ) {
		$c                  = inpulsia_medios_image_context( $id );
		$vars['encabezado'] = $c['heading'] !== '' ? $c['heading'] : $page;
		$vars['categoria']  = $c['category'];
		$vars['ciudad']     = $c['city'];
	}
	$v = preg_replace_callback( '/\{(\w+)\}/', function ( $m ) use ( $vars ) {
		return $vars[ $m[1] ] ?? $m[0];
	}, $tpl );
	$v = trim( preg_replace( [ '/\s+-\s+-\s+/', '/\s{2,}/' ], [ ' - ', ' ' ], $v ), " -\t\n" );
	return $field === 'filename' ? sanitize_title( $v ) : $v;
}

function inpulsia_medios_ajax_tpl_stage() {
	inpulsia_medios_check_ajax();
	$field = sanitize_key( $_POST['field'] ?? '' );
	$tpl   = sanitize_text_field( wp_unslash( $_POST['tpl'] ?? '' ) );
	$empty = ! empty( $_POST['only_empty'] ) && $_POST['only_empty'] !== 'false';
	if ( ! in_array( $field, inpulsia_medios_fields(), true ) || trim( $tpl ) === '' ) {
		wp_send_json_error( __( 'Acción no válida.', 'inpulsia-medios' ) );
	}
	$rows = [];
	$n    = 0;
	foreach ( inpulsia_medios_filter_ids() as $id ) {
		$cur = inpulsia_medios_current( $id )[ $field ];
		if ( $empty && $cur !== '' && ! ( in_array( $field, [ 'filename', 'title' ], true ) && inpulsia_medios_is_camera_name( $cur ) ) ) {
			continue;
		}
		$n++;
		$v = inpulsia_medios_template_value( $id, $tpl, $field, $n );
		if ( $v !== '' ) {
			$rows[] = [ 'id' => $id, $field => $v ];
		}
	}
	if ( ! $rows ) {
		wp_send_json_error( __( 'No había ningún campo que rellenar con esas condiciones.', 'inpulsia-medios' ) );
	}
	wp_send_json_success( inpulsia_medios_stage( $rows, 'Plantilla: ' . $tpl ) );
}

// Images of the filter that need AI: no alt, or a camera/unfriendly name.
function inpulsia_medios_ajax_ai_stage() {
	inpulsia_medios_check_ajax();
	$jobs  = [];
	$items = [];
	foreach ( inpulsia_medios_filter_ids() as $id ) {
		$cur   = inpulsia_medios_current( $id );
		$lines = [];
		if ( $cur['alt'] === '' ) {
			$lines[] = __( 'Alt: vacío → propuesto por IA', 'inpulsia-medios' );
		}
		if ( inpulsia_medios_is_bad_name( $cur['filename'] ) ) {
			$lines[] = sprintf( __( 'Nombre: «%s» → propuesto por IA', 'inpulsia-medios' ), $cur['filename'] );
		}
		if ( ! $lines ) {
			continue;
		}
		$jobs[] = $id;
		if ( count( $items ) < 300 ) {
			$items[] = [ 'id' => $id, 'edit' => get_edit_post_link( $id, 'raw' ), 'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail', true ), 'name' => basename( (string) get_attached_file( $id ) ), 'lines' => $lines, 'warnings' => [] ];
		}
	}
	if ( ! $jobs ) {
		wp_send_json_error( __( 'Todas las imágenes del filtro tienen alt y nombre claros.', 'inpulsia-medios' ) );
	}
	$token = inpulsia_medios_new_token();
	set_transient( 'inpulsia_medios_ai_' . $token, [ 'user' => get_current_user_id(), 'ids' => $jobs ], DAY_IN_SECONDS );
	wp_send_json_success( [
		'token'   => $token,
		'items'   => $items,
		'total'   => count( $jobs ),
		'listed'  => count( $jobs ),
		'mode'    => 'optimize',
		'action'  => 'inpulsia_medios_ai_apply',
		'label'   => sprintf( __( 'Completar %d imágenes con IA', 'inpulsia-medios' ), count( $jobs ) ),
		'summary' => inpulsia_medios_ai_ready() ? sprintf( __( '%d imágenes · una consulta de IA por imagen; si se agota la cuota gratuita, el resto queda en cola', 'inpulsia-medios' ), count( $jobs ) ) : sprintf( __( '%d imágenes · sin IA configurada: se propone a partir de la foto y la página donde se usa', 'inpulsia-medios' ), count( $jobs ) ),
		'title'   => __( 'Completando con IA', 'inpulsia-medios' ),
	] );
}

function inpulsia_medios_ajax_ai_apply() {
	inpulsia_medios_check_ajax();
	$token  = sanitize_key( $_POST['token'] ?? '' );
	$offset = absint( $_POST['offset'] ?? 0 );
	$data   = get_transient( 'inpulsia_medios_ai_' . $token );
	if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
		wp_send_json_error( __( 'La sesión ha caducado. Vuelve a cargar los cambios.', 'inpulsia-medios' ) );
	}
	$ids = $data['ids'];
	if ( $offset === 0 ) {
		inpulsia_medios_history_add( [
			'token'   => $token,
			'date'    => current_time( 'mysql' ),
			'user'    => wp_get_current_user()->display_name,
			'file'    => 'Completar con IA',
			'rows'    => count( $ids ),
			'changed' => 0,
			'status'  => 'incompleto',
		] );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 );
	}
	$rows   = [];
	$fail   = [];
	$queued = 0;
	$next   = min( $offset + 2, count( $ids ) );
	foreach ( array_slice( $ids, $offset, 2 ) as $i => $id ) {
		$s = inpulsia_medios_suggest( $id );
		if ( is_wp_error( $s ) && $s->get_error_code() === 'quota' ) {
			// Free quota gone: the rest waits in the queue and is completed automatically when it resets.
			$rest   = array_slice( $ids, $offset + $i );
			$queued = count( $rest );
			inpulsia_medios_ai_queue_add( $rest, get_current_user_id() );
			$fail[] = [ 'id' => $id, 'status' => 'skip', 'msg' => sprintf( __( 'Cuota gratuita agotada: %d imágenes quedan en cola y se completarán solas.', 'inpulsia-medios' ), $queued ) ];
			$next   = count( $ids );
			break;
		}
		if ( is_wp_error( $s ) ) {
			$fail[] = [ 'id' => $id, 'status' => 'error', 'msg' => $s->get_error_message() ];
			continue;
		}
		$row = inpulsia_medios_ai_row( $id, $s );
		if ( $row ) {
			$rows[] = $row;
		}
	}
	$results = array_merge( $fail, $rows ? inpulsia_medios_apply_rows( $token, $rows ) : [] );
	$done    = $next >= count( $ids );
	$purged  = [];
	if ( $done ) {
		inpulsia_medios_history_update( $token, [ 'status' => 'aplicado' ] );
		delete_transient( 'inpulsia_medios_ai_' . $token );
		$purged = inpulsia_medios_purge_caches();
	}
	$notes = $queued ? [ sprintf( __( 'Se ha agotado la cuota gratuita de IA. %d imágenes quedan en cola y se completarán solas cuando se renueve (cada hora se comprueba).', 'inpulsia-medios' ), $queued ) ] : [];
	wp_send_json_success( compact( 'results', 'next', 'done', 'purged', 'notes' ) + [ 'total' => count( $ids ) ] );
}
