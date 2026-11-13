<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Backend helpers added in 1.8: diagnostics, alerts, badges, preferences, saved filters, background jobs and per-image undo.

add_action( 'wp_ajax_inpulsia_medios_pref', 'inpulsia_medios_ajax_pref' );
add_action( 'wp_ajax_inpulsia_medios_filter_save', 'inpulsia_medios_ajax_filter_save' );
add_action( 'wp_ajax_inpulsia_medios_filter_delete', 'inpulsia_medios_ajax_filter_delete' );
add_action( 'wp_ajax_inpulsia_medios_bg_start', 'inpulsia_medios_ajax_bg_start' );
add_action( 'wp_ajax_inpulsia_medios_bg_status', 'inpulsia_medios_ajax_bg_status' );
add_action( 'wp_ajax_inpulsia_medios_bg_cancel', 'inpulsia_medios_ajax_bg_cancel' );
add_action( 'wp_ajax_inpulsia_medios_undo_one', 'inpulsia_medios_ajax_undo_one' );
add_action( 'admin_post_inpulsia_medios_opt_defaults', 'inpulsia_medios_opt_defaults_action' );
add_action( 'admin_post_inpulsia_medios_opt_save', 'inpulsia_medios_opt_save_action' );

/* ---------- Diagnostics ---------- */

function inpulsia_medios_bytes_ini( $v ) {
	return function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) $v ) : (int) $v;
}

/**
 * Server checks that predict problems before an optimization starts.
 * Each item: [ 'label', 'value', 'status' => ok|warn|bad, 'help' ].
 */
function inpulsia_medios_diagnostics() {
	$upload = wp_get_upload_dir();
	$out    = [];

	$editor = _wp_image_editor_choose( [ 'mime_type' => 'image/jpeg' ] );
	$out[]  = [
		'label'  => __( 'Motor de imágenes', 'inpulsia-medios' ),
		'value'  => $editor ? str_replace( 'WP_Image_Editor_', '', $editor ) : __( 'ninguno', 'inpulsia-medios' ),
		'status' => $editor ? 'ok' : 'bad',
		'help'   => __( 'Sin Imagick ni GD no se puede optimizar: pide al hosting que active una de las dos.', 'inpulsia-medios' ),
	];

	$webp  = wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
	$out[] = [
		'label'  => __( 'Conversión a WebP', 'inpulsia-medios' ),
		'value'  => $webp ? __( 'disponible', 'inpulsia-medios' ) : __( 'no disponible', 'inpulsia-medios' ),
		'status' => $webp ? 'ok' : 'warn',
		'help'   => __( 'El servidor no sabe crear WebP: se usará JPG.', 'inpulsia-medios' ),
	];

	$mem   = inpulsia_medios_bytes_ini( ini_get( 'memory_limit' ) );
	$out[] = [
		'label'  => __( 'Memoria de PHP', 'inpulsia-medios' ),
		'value'  => $mem < 0 ? __( 'sin límite', 'inpulsia-medios' ) : size_format( $mem ),
		'status' => $mem < 0 || $mem >= 256 * MB_IN_BYTES ? 'ok' : ( $mem >= 128 * MB_IN_BYTES ? 'warn' : 'bad' ),
		'help'   => __( 'Con menos de 256 MB las fotos grandes pueden fallar al convertirse.', 'inpulsia-medios' ),
	];

	$time  = (int) ini_get( 'max_execution_time' );
	$out[] = [
		'label'  => __( 'Tiempo máximo por petición', 'inpulsia-medios' ),
		'value'  => $time ? sprintf( __( '%d s', 'inpulsia-medios' ), $time ) : __( 'sin límite', 'inpulsia-medios' ),
		'status' => ! $time || $time >= 60 ? 'ok' : 'warn',
		'help'   => __( 'Con menos de 60 segundos los lotes se reintentan más a menudo.', 'inpulsia-medios' ),
	];

	$writable = wp_is_writable( $upload['basedir'] );
	$out[]    = [
		'label'  => __( 'Permisos de uploads', 'inpulsia-medios' ),
		'value'  => $writable ? __( 'escritura correcta', 'inpulsia-medios' ) : __( 'sin permiso de escritura', 'inpulsia-medios' ),
		'status' => $writable ? 'ok' : 'bad',
		'help'   => __( 'WordPress no puede escribir en uploads: no se puede optimizar ni renombrar.', 'inpulsia-medios' ),
	];

	$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $upload['basedir'] ) : false;
	if ( $free !== false ) {
		$out[] = [
			'label'  => __( 'Espacio libre en disco', 'inpulsia-medios' ),
			'value'  => size_format( $free, 1 ),
			'status' => $free >= GB_IN_BYTES ? 'ok' : ( $free >= 200 * MB_IN_BYTES ? 'warn' : 'bad' ),
			'help'   => __( 'Las copias de seguridad necesitan espacio: libera disco o borra copias antiguas.', 'inpulsia-medios' ),
		];
	}

	$cron  = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	$out[] = [
		'label'  => __( 'Tareas programadas (WP-Cron)', 'inpulsia-medios' ),
		'value'  => $cron ? __( 'activas', 'inpulsia-medios' ) : __( 'desactivadas en wp-config.php', 'inpulsia-medios' ),
		'status' => $cron ? 'ok' : 'warn',
		'help'   => __( 'Si no hay un cron real en el servidor, no funcionarán la optimización al subir, el proceso en segundo plano ni la limpieza diaria.', 'inpulsia-medios' ),
	];

	return $out;
}

function inpulsia_medios_diagnostics_table( $only_problems = false ) {
	$rows = inpulsia_medios_diagnostics();
	if ( $only_problems ) {
		$rows = array_filter( $rows, function ( $r ) {
			return $r['status'] !== 'ok';
		} );
	}
	if ( ! $rows ) {
		return;
	}
	?>
	<table class="widefat im-diag">
		<tbody>
		<?php foreach ( $rows as $r ) : ?>
			<tr class="is-<?php echo esc_attr( $r['status'] ); ?>">
				<th scope="row"><span class="im-dot" aria-hidden="true"></span> <?php echo esc_html( $r['label'] ); ?></th>
				<td><strong><?php echo esc_html( $r['value'] ); ?></strong><?php echo $r['status'] !== 'ok' ? '<br><span class="muted">' . esc_html( $r['help'] ) . '</span>' : ''; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/* ---------- Alerts and badges ---------- */

// Cached for a few minutes: it checks every image file on disk.
function inpulsia_medios_lost_count() {
	$n = get_transient( 'inpulsia_medios_lost_count' );
	if ( $n === false ) {
		$n = function_exists( 'inpulsia_medios_lost_files' ) ? count( inpulsia_medios_lost_files() ) : 0;
		set_transient( 'inpulsia_medios_lost_count', $n, 10 * MINUTE_IN_SECONDS );
	}
	return (int) $n;
}

function inpulsia_medios_recent_errors( $days = 7 ) {
	return array_filter( (array) get_option( 'inpulsia_medios_errors', [] ), function ( $e ) use ( $days ) {
		return $e['time'] > time() - $days * DAY_IN_SECONDS;
	} );
}

/**
 * Things that need attention, most serious first. Each: [ level => bad|warn|info, text, url, cta ].
 */
function inpulsia_medios_alerts() {
	$out  = [];
	$lost = inpulsia_medios_lost_count();
	if ( $lost ) {
		$out[] = [ 'bad', sprintf( _n( '%d imagen no tiene archivo en el servidor y se ve rota en la web.', '%d imágenes no tienen archivo en el servidor y se ven rotas en la web.', $lost, 'inpulsia-medios' ), $lost ), inpulsia_medios_url( 'limpieza' ), __( 'Revisar y recuperar', 'inpulsia-medios' ) ];
	}
	$health = function_exists( 'inpulsia_medios_health_result' ) ? inpulsia_medios_health_result() : null;
	if ( $health && $health['failed'] ) {
		$out[] = [ 'bad', sprintf( _n( 'La comprobación diaria ha encontrado %d imagen cambiada que no carga.', 'La comprobación diaria ha encontrado %d imágenes cambiadas que no cargan.', count( $health['failed'] ), 'inpulsia-medios' ), count( $health['failed'] ) ), inpulsia_medios_url( 'rendimiento' ), __( 'Ver cuáles', 'inpulsia-medios' ) ];
	}
	$job = inpulsia_medios_bg_job();
	if ( $job && empty( $job['done'] ) ) {
		$out[] = [ 'info', sprintf( __( 'Optimización en segundo plano: %1$d de %2$d imágenes.', 'inpulsia-medios' ), $job['offset'], count( $job['ids'] ) ), '', '' ];
	}
	$errors = count( inpulsia_medios_recent_errors() );
	if ( $errors ) {
		$out[] = [ 'warn', sprintf( _n( '%d optimización ha fallado esta semana (se mantuvo el original).', '%d optimizaciones han fallado esta semana (se mantuvo el original).', $errors, 'inpulsia-medios' ), $errors ), inpulsia_medios_url( 'limpieza' ) . '#im-errors', __( 'Ver fallos', 'inpulsia-medios' ) ];
	}
	foreach ( inpulsia_medios_diagnostics() as $d ) {
		if ( $d['status'] === 'bad' ) {
			$out[] = [ 'bad', $d['label'] . ': ' . $d['value'] . '. ' . $d['help'], current_user_can( 'manage_options' ) ? inpulsia_medios_url( 'ajustes' ) . '#im-diag' : '', __( 'Ver diagnóstico', 'inpulsia-medios' ) ];
		}
	}
	if ( function_exists( 'inpulsia_medios_backups_stats' ) ) {
		list( , $bytes ) = inpulsia_medios_backups_stats();
		if ( $bytes > 500 * MB_IN_BYTES ) {
			$out[] = [ 'info', sprintf( __( 'Las copias de seguridad ocupan %s.', 'inpulsia-medios' ), size_format( $bytes, 1 ) ), current_user_can( 'manage_options' ) ? inpulsia_medios_url( 'ajustes' ) . '#im-backups' : '', __( 'Gestionar copias', 'inpulsia-medios' ) ];
		}
	}
	return $out;
}

function inpulsia_medios_alerts_markup() {
	$alerts = inpulsia_medios_alerts();
	if ( ! $alerts ) {
		return;
	}
	?>
	<div class="im-alerts" role="region" aria-label="<?php esc_attr_e( 'Avisos', 'inpulsia-medios' ); ?>">
		<?php foreach ( $alerts as $a ) : ?>
			<div class="im-alert is-<?php echo esc_attr( $a[0] ); ?>">
				<span class="dashicons <?php echo esc_attr( [ 'bad' => 'dashicons-dismiss', 'warn' => 'dashicons-warning', 'info' => 'dashicons-info' ][ $a[0] ] ); ?>" aria-hidden="true"></span>
				<p><?php echo esc_html( $a[1] ); ?></p>
				<?php if ( $a[2] ) : ?><a class="button" href="<?php echo esc_url( $a[2] ); ?>"><?php echo esc_html( $a[3] ); ?></a><?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

// Pending work per tab, shown as a number next to its name.
function inpulsia_medios_tab_badges() {
	$b = [];
	if ( function_exists( 'inpulsia_medios_count' ) ) {
		$b['editar'] = inpulsia_medios_count( [ 'missing_alt' => true ] );
	}
	$b['limpieza'] = inpulsia_medios_lost_count() + count( inpulsia_medios_recent_errors() );
	if ( function_exists( 'inpulsia_medios_perf_problem_count' ) ) {
		$health            = inpulsia_medios_health_result();
		$b['rendimiento'] = inpulsia_medios_perf_problem_count() + ( $health ? count( $health['failed'] ) : 0 );
	}
	return array_filter( $b );
}

/* ---------- Preferences (per user) ---------- */

function inpulsia_medios_prefs() {
	$p = get_user_meta( get_current_user_id(), 'inpulsia_medios_prefs', true );
	return wp_parse_args( is_array( $p ) ? $p : [], [
		'density' => 'comfortable',
		'theme'   => 'auto',
		'hidden'  => [],
	] );
}

function inpulsia_medios_ajax_pref() {
	inpulsia_medios_check_ajax();
	$p   = inpulsia_medios_prefs();
	$key = sanitize_key( $_POST['key'] ?? '' );
	$val = sanitize_key( $_POST['value'] ?? '' );
	if ( $key === 'density' && in_array( $val, [ 'comfortable', 'compact' ], true ) ) {
		$p['density'] = $val;
	} elseif ( $key === 'theme' && in_array( $val, [ 'auto', 'light', 'dark' ], true ) ) {
		$p['theme'] = $val;
	} elseif ( $key === 'hidden' ) {
		$cols        = array_map( 'sanitize_key', (array) ( $_POST['cols'] ?? [] ) );
		$p['hidden'] = array_values( array_intersect( $cols, [ 'filename', 'title', 'alt', 'caption', 'description' ] ) );
	} else {
		wp_send_json_error( __( 'Preferencia no válida.', 'inpulsia-medios' ) );
	}
	update_user_meta( get_current_user_id(), 'inpulsia_medios_prefs', $p );
	wp_send_json_success( $p );
}

/* ---------- Saved filters ---------- */

function inpulsia_medios_filter_presets() {
	return [
		[ 'name' => __( 'Sin alt', 'inpulsia-medios' ), 'q' => [ 'missing_alt' => 1 ] ],
		[ 'name' => __( 'Más de 300 KB', 'inpulsia-medios' ), 'q' => [ 'weight' => '300k' ] ],
		[ 'name' => __( 'Nombres de cámara', 'inpulsia-medios' ), 'q' => [ 'names' => 'bad' ] ],
		[ 'name' => __( 'En uso sin alt', 'inpulsia-medios' ), 'q' => [ 'usage' => 'used', 'missing_alt' => 1 ] ],
		[ 'name' => __( 'Sin uso', 'inpulsia-medios' ), 'q' => [ 'usage' => 'unused' ] ],
	];
}

function inpulsia_medios_saved_filters() {
	$f = get_user_meta( get_current_user_id(), 'inpulsia_medios_filters', true );
	return is_array( $f ) ? $f : [];
}

function inpulsia_medios_ajax_filter_save() {
	inpulsia_medios_check_ajax();
	$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	parse_str( (string) wp_unslash( $_POST['query'] ?? '' ), $q ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted below.
	$q = array_intersect_key( array_map( 'sanitize_text_field', array_filter( (array) $q, 'is_scalar' ) ), array_flip( [ 's', 'usage', 'format', 'weight', 'names', 'lang', 'missing_alt', 'per_page' ] ) );
	if ( $name === '' || ! array_filter( $q ) ) {
		wp_send_json_error( __( 'Pon un nombre y algún filtro.', 'inpulsia-medios' ) );
	}
	$list   = array_values( array_filter( inpulsia_medios_saved_filters(), function ( $f ) use ( $name ) {
		return $f['name'] !== $name;
	} ) );
	$list[] = [ 'name' => wp_html_excerpt( $name, 40 ), 'q' => array_filter( $q ) ];
	update_user_meta( get_current_user_id(), 'inpulsia_medios_filters', array_slice( $list, -12 ) );
	wp_send_json_success();
}

function inpulsia_medios_ajax_filter_delete() {
	inpulsia_medios_check_ajax();
	$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	update_user_meta( get_current_user_id(), 'inpulsia_medios_filters', array_values( array_filter( inpulsia_medios_saved_filters(), function ( $f ) use ( $name ) {
		return $f['name'] !== $name;
	} ) ) );
	wp_send_json_success();
}

function inpulsia_medios_saved_filters_markup( $params ) {
	$current = array_filter( array_intersect_key( $_GET, array_flip( [ 's', 'usage', 'format', 'weight', 'names', 'lang', 'missing_alt' ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="im-saved-filters" role="navigation" aria-label="<?php esc_attr_e( 'Filtros guardados', 'inpulsia-medios' ); ?>">
		<span class="muted"><?php esc_html_e( 'Atajos:', 'inpulsia-medios' ); ?></span>
		<?php foreach ( inpulsia_medios_filter_presets() as $f ) : ?>
			<a class="im-chip <?php echo $current == $f['q'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( inpulsia_medios_url( 'editar', $f['q'] ) ); ?>"><?php echo esc_html( $f['name'] ); ?></a>
		<?php endforeach; ?>
		<?php foreach ( inpulsia_medios_saved_filters() as $f ) : ?>
			<span class="im-chip is-mine <?php echo $current == $f['q'] ? 'is-active' : ''; ?>">
				<a href="<?php echo esc_url( inpulsia_medios_url( 'editar', $f['q'] ) ); ?>"><?php echo esc_html( $f['name'] ); ?></a>
				<button type="button" class="im-filter-delete" data-name="<?php echo esc_attr( $f['name'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Borrar el filtro %s', 'inpulsia-medios' ), $f['name'] ) ); ?>">×</button>
			</span>
		<?php endforeach; ?>
		<?php if ( $current ) : ?>
			<button type="button" class="button-link im-filter-save"><?php esc_html_e( '+ Guardar este filtro', 'inpulsia-medios' ); ?></button>
		<?php endif; ?>
	</div>
	<?php
}

/* ---------- Background optimization (WP-Cron) ---------- */

add_action( 'inpulsia_medios_bg_run', 'inpulsia_medios_bg_run' );

function inpulsia_medios_bg_job() {
	$j = get_option( 'inpulsia_medios_bg' );
	return is_array( $j ) ? $j : null;
}

// Takes over a staged optimization from the browser, from the image where the browser stopped.
function inpulsia_medios_ajax_bg_start() {
	inpulsia_medios_check_ajax();
	$token  = sanitize_key( $_POST['token'] ?? '' );
	$offset = absint( $_POST['offset'] ?? 0 );
	$data   = get_transient( 'inpulsia_medios_opt_' . $token );
	if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
		wp_send_json_error( __( 'La sesión ha caducado. Vuelve a lanzar la optimización.', 'inpulsia-medios' ) );
	}
	$job = inpulsia_medios_bg_job();
	if ( $job && empty( $job['done'] ) && $job['token'] !== $token ) {
		wp_send_json_error( __( 'Ya hay otra optimización en segundo plano. Espera a que termine.', 'inpulsia-medios' ) );
	}
	if ( $offset === 0 && ! inpulsia_medios_history_find( $token ) ) {
		inpulsia_medios_history_add( [
			'token'   => $token,
			'date'    => current_time( 'mysql' ),
			'user'    => wp_get_current_user()->display_name,
			'file'    => 'Optimización (' . strtoupper( $data['opts']['format'] ) . ', ' . (int) $data['opts']['max_width'] . ' px)',
			'rows'    => count( $data['ids'] ),
			'changed' => 0,
			'status'  => 'incompleto',
		] );
	}
	update_option( 'inpulsia_medios_bg', [
		'token'   => $token,
		'user'    => get_current_user_id(),
		'ids'     => $data['ids'],
		'opts'    => $data['opts'],
		'offset'  => $offset,
		'ok'      => 0,
		'errors'  => 0,
		'started' => time(),
		'done'    => false,
	], false );
	delete_transient( 'inpulsia_medios_opt_' . $token );
	wp_clear_scheduled_hook( 'inpulsia_medios_bg_run' );
	wp_schedule_single_event( time(), 'inpulsia_medios_bg_run' );
	spawn_cron();
	wp_send_json_success( inpulsia_medios_bg_public() );
}

function inpulsia_medios_bg_public() {
	$j = inpulsia_medios_bg_job();
	if ( ! $j ) {
		return null;
	}
	return [
		'offset' => (int) $j['offset'],
		'total'  => count( $j['ids'] ),
		'ok'     => (int) $j['ok'],
		'errors' => (int) $j['errors'],
		'done'   => ! empty( $j['done'] ),
	];
}

function inpulsia_medios_bg_run() {
	$job = inpulsia_medios_bg_job();
	if ( ! $job || ! empty( $job['done'] ) || ! function_exists( 'inpulsia_medios_optimize_ids' ) ) {
		return;
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 );
	}
	wp_raise_memory_limit( 'image' );
	$previous = get_current_user_id();
	wp_set_current_user( (int) $job['user'] );
	$until = time() + 20;
	while ( $job['offset'] < count( $job['ids'] ) && time() < $until ) {
		$batch   = array_slice( $job['ids'], $job['offset'], 2 );
		$results = inpulsia_medios_optimize_ids( $job['token'], $batch, $job['opts'] );
		foreach ( $results as $r ) {
			$job[ $r['status'] === 'error' ? 'errors' : 'ok' ]++;
		}
		$job['offset'] += count( $batch );
		$fresh = inpulsia_medios_bg_job();
		if ( ! $fresh || $fresh['token'] !== $job['token'] ) {
			wp_set_current_user( $previous );
			return; // Cancelled meanwhile.
		}
		update_option( 'inpulsia_medios_bg', $job, false );
	}
	if ( $job['offset'] >= count( $job['ids'] ) ) {
		$job['done'] = true;
		update_option( 'inpulsia_medios_bg', $job, false );
		inpulsia_medios_history_update( $job['token'], [ 'status' => 'aplicado' ] );
		if ( function_exists( 'inpulsia_medios_purge_caches' ) ) {
			inpulsia_medios_purge_caches();
		}
	} else {
		wp_schedule_single_event( time(), 'inpulsia_medios_bg_run' );
	}
	wp_set_current_user( $previous );
}

function inpulsia_medios_ajax_bg_status() {
	inpulsia_medios_check_ajax();
	$job = inpulsia_medios_bg_job();
	// Pages that poll keep the job moving even on sites where WP-Cron only runs on visits.
	if ( $job && empty( $job['done'] ) && ! wp_next_scheduled( 'inpulsia_medios_bg_run' ) ) {
		wp_schedule_single_event( time(), 'inpulsia_medios_bg_run' );
	}
	if ( $job && empty( $job['done'] ) ) {
		spawn_cron();
	}
	wp_send_json_success( inpulsia_medios_bg_public() );
}

function inpulsia_medios_ajax_bg_cancel() {
	inpulsia_medios_check_ajax();
	$job = inpulsia_medios_bg_job();
	if ( $job && ( (int) $job['user'] === get_current_user_id() || current_user_can( 'manage_options' ) ) ) {
		delete_option( 'inpulsia_medios_bg' );
		wp_clear_scheduled_hook( 'inpulsia_medios_bg_run' );
		inpulsia_medios_history_update( $job['token'], [ 'status' => 'incompleto' ] );
	}
	wp_send_json_success();
}

// Persistent bar on every Inpulsia page while a background job runs or just finished.
function inpulsia_medios_bg_markup() {
	$j = inpulsia_medios_bg_public();
	if ( ! $j ) {
		return;
	}
	?>
	<div id="im-bg" class="im-bg <?php echo $j['done'] ? 'is-done' : ''; ?>" data-done="<?php echo $j['done'] ? 1 : 0; ?>" role="status" aria-live="polite">
		<strong class="im-bg-title"><?php echo esc_html( $j['done'] ? __( 'Optimización en segundo plano terminada', 'inpulsia-medios' ) : __( 'Optimizando en segundo plano', 'inpulsia-medios' ) ); ?></strong>
		<div class="inpulsia-medios-progress"><div style="width:<?php echo (int) ( $j['total'] ? $j['offset'] / $j['total'] * 100 : 100 ); ?>%"></div></div>
		<span class="im-bg-text"><?php echo esc_html( sprintf( __( '%1$d de %2$d · %3$d errores', 'inpulsia-medios' ), $j['offset'], $j['total'], $j['errors'] ) ); ?></span>
		<?php if ( $j['done'] ) : ?>
			<a href="<?php echo esc_url( inpulsia_medios_url( 'historial' ) ); ?>"><?php esc_html_e( 'Ver en el Historial', 'inpulsia-medios' ); ?></a>
			<button type="button" class="button-link im-bg-cancel"><?php esc_html_e( 'Ocultar', 'inpulsia-medios' ); ?></button>
		<?php else : ?>
			<span class="muted"><?php esc_html_e( 'Puedes cerrar esta pestaña: sigue en el servidor.', 'inpulsia-medios' ); ?></span>
			<button type="button" class="button-link button-link-delete im-bg-cancel"><?php esc_html_e( 'Detener', 'inpulsia-medios' ); ?></button>
		<?php endif; ?>
	</div>
	<?php
}

/* ---------- Per-image undo ---------- */

function inpulsia_medios_ajax_undo_one() {
	inpulsia_medios_check_ajax();
	$token = sanitize_key( $_POST['token'] ?? '' );
	$id    = absint( $_POST['id'] ?? 0 );
	$log   = (array) get_option( 'inpulsia_medios_log_' . $token, [] );
	$mine  = array_values( array_filter( $log, function ( $e ) use ( $id ) {
		return (int) $e['id'] === $id;
	} ) );
	if ( ! $mine || ! current_user_can( 'edit_post', $id ) ) {
		wp_send_json_error( __( 'No hay nada que deshacer en esta imagen.', 'inpulsia-medios' ) );
	}
	$results = inpulsia_medios_undo_rows( array_reverse( $mine ) );
	$rest    = array_values( array_filter( $log, function ( $e ) use ( $id ) {
		return (int) $e['id'] !== $id;
	} ) );
	update_option( 'inpulsia_medios_log_' . $token, $rest, false );
	inpulsia_medios_history_update( $token, $rest ? [ 'changed' => count( $rest ) ] : [ 'changed' => 0, 'status' => 'deshecho' ] );
	if ( function_exists( 'inpulsia_medios_flush_elementor' ) ) {
		inpulsia_medios_flush_elementor();
	}
	wp_send_json_success( $results[0] ?? [ 'status' => 'ok', 'msg' => '' ] );
}

/* ---------- Optimization settings (Ajustes) ---------- */

function inpulsia_medios_opt_recommended() {
	return [ 'format' => wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ? 'webp' : 'jpg', 'max_width' => 2560, 'quality' => 82, 'backup' => true, 'ai' => false, 'keep_old' => true ];
}

function inpulsia_medios_opt_save_action() {
	check_admin_referer( 'inpulsia_medios_opt_save' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permisos.', 'inpulsia-medios' ) );
	}
	$opts = inpulsia_medios_opt_sanitize( [
		'format'    => sanitize_key( $_POST['format'] ?? 'jpg' ),
		'max_width' => absint( $_POST['max_width'] ?? 2560 ),
		'quality'   => absint( $_POST['quality'] ?? 82 ),
		'backup'    => ! empty( $_POST['backup'] ),
		'ai'        => ! empty( $_POST['ai'] ),
		'keep_old'  => empty( $_POST['delete_old'] ),
		'auto'      => ! empty( $_POST['auto_optimize'] ),
	] );
	update_option( 'inpulsia_medios_opt', $opts, false );
	wp_safe_redirect( inpulsia_medios_url( 'ajustes', [ 'saved' => 1 ] ) . '#im-opt-settings' );
	exit;
}

function inpulsia_medios_opt_defaults_action() {
	check_admin_referer( 'inpulsia_medios_opt_defaults' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permisos.', 'inpulsia-medios' ) );
	}
	$opts = inpulsia_medios_opt_recommended() + [ 'auto' => inpulsia_medios_opt_settings()['auto'] ];
	update_option( 'inpulsia_medios_opt', inpulsia_medios_opt_sanitize( $opts ), false );
	wp_safe_redirect( inpulsia_medios_url( 'ajustes', [ 'saved' => 1 ] ) . '#im-opt-settings' );
	exit;
}

function inpulsia_medios_opt_settings_card() {
	$o   = inpulsia_medios_opt_settings();
	$rec = inpulsia_medios_opt_recommended();
	$tag = function ( $key, $value ) use ( $rec ) {
		return $rec[ $key ] === $value ? ' <span class="im-rec">' . esc_html__( 'recomendado', 'inpulsia-medios' ) . '</span>' : '';
	};
	?>
	<div class="inpulsia-medios-card" id="im-opt-settings">
		<h2><span class="dashicons dashicons-performance" aria-hidden="true"></span> <?php esc_html_e( 'Optimización', 'inpulsia-medios' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Valores por defecto de «Optimizar imágenes» y de la optimización al subir.', 'inpulsia-medios' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="inpulsia_medios_opt_save" />
			<?php wp_nonce_field( 'inpulsia_medios_opt_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="im-s-format"><?php esc_html_e( 'Formato', 'inpulsia-medios' ); ?></label></th>
					<td><select id="im-s-format" name="format">
						<option value="jpg" <?php selected( $o['format'], 'jpg' ); ?>>JPG</option>
						<option value="webp" <?php selected( $o['format'], 'webp' ); ?> <?php disabled( ! wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ); ?>>WebP</option>
					</select><?php echo $tag( 'format', $o['format'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Recomendado: %s.', 'inpulsia-medios' ), strtoupper( $rec['format'] ) ) ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="im-s-width"><?php esc_html_e( 'Ancho máximo', 'inpulsia-medios' ); ?></label></th>
					<td><input type="number" id="im-s-width" name="max_width" min="320" max="8000" step="10" value="<?php echo (int) $o['max_width']; ?>" /> px<?php echo $tag( 'max_width', $o['max_width'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p class="description"><?php esc_html_e( 'Recomendado: 2560 px (lo que usa WordPress).', 'inpulsia-medios' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="im-s-quality"><?php esc_html_e( 'Calidad', 'inpulsia-medios' ); ?></label></th>
					<td><input type="number" id="im-s-quality" name="quality" min="40" max="100" value="<?php echo (int) $o['quality']; ?>" /><?php echo $tag( 'quality', $o['quality'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p class="description"><?php esc_html_e( 'Recomendado: 82. Por debajo de 70 se notan artefactos en fotos.', 'inpulsia-medios' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Seguridad', 'inpulsia-medios' ); ?></th>
					<td>
						<label><input type="checkbox" name="backup" value="1" <?php checked( $o['backup'] ); ?> /> <?php esc_html_e( 'Copia de seguridad antes de optimizar (permite deshacer)', 'inpulsia-medios' ); ?></label><?php echo $tag( 'backup', $o['backup'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><br>
						<label><input type="checkbox" name="delete_old" value="1" <?php checked( ! $o['keep_old'] ); ?> /> <?php esc_html_e( 'Borrar enseguida la imagen anterior (si no, se conserva 15 días)', 'inpulsia-medios' ); ?></label><?php echo $tag( 'keep_old', $o['keep_old'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automático', 'inpulsia-medios' ); ?></th>
					<td>
						<label><input type="checkbox" name="auto_optimize" value="1" <?php checked( $o['auto'] ); ?> /> <?php esc_html_e( 'Optimizar cada imagen que se suba', 'inpulsia-medios' ); ?></label><br>
						<label><input type="checkbox" name="ai" value="1" <?php checked( $o['ai'] ); ?> <?php disabled( inpulsia_medios_api_key() === '' ); ?> /> <?php esc_html_e( 'Nombre y alt con IA si faltan o son de cámara', 'inpulsia-medios' ); ?></label>
					</td>
				</tr>
			</table>
			<p>
				<?php submit_button( __( 'Guardar optimización', 'inpulsia-medios' ), 'primary', 'submit', false ); ?>
				<button type="submit" class="button" form="im-opt-defaults"><?php esc_html_e( 'Restaurar valores recomendados', 'inpulsia-medios' ); ?></button>
			</p>
		</form>
		<form id="im-opt-defaults" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="inpulsia_medios_opt_defaults" />
			<?php wp_nonce_field( 'inpulsia_medios_opt_defaults' ); ?>
		</form>
	</div>
	<?php
}

/* ---------- Keyboard shortcuts help ---------- */

function inpulsia_medios_shortcuts_markup() {
	$keys = [
		[ '?', __( 'Mostrar u ocultar esta ayuda', 'inpulsia-medios' ) ],
		[ 'g i / g e / g l / g h', __( 'Ir a Inicio, Editar, Limpieza o Historial', 'inpulsia-medios' ) ],
		[ '/', __( 'Buscar en Editar', 'inpulsia-medios' ) ],
		[ 'Enter', __( 'En la tabla: bajar a la misma celda de la fila siguiente', 'inpulsia-medios' ) ],
		[ 'Ctrl/⌘ + Enter', __( 'En la tabla: revisar cambios', 'inpulsia-medios' ) ],
		[ 'Esc', __( 'Cerrar la vista previa o esta ayuda', 'inpulsia-medios' ) ],
	];
	?>
	<div id="im-keys" class="im-keys" role="dialog" aria-modal="true" aria-labelledby="im-keys-title" hidden>
		<div class="im-keys-box">
			<h2 id="im-keys-title"><?php esc_html_e( 'Atajos de teclado', 'inpulsia-medios' ); ?></h2>
			<table>
				<?php foreach ( $keys as $k ) : ?>
					<tr><th><kbd><?php echo esc_html( $k[0] ); ?></kbd></th><td><?php echo esc_html( $k[1] ); ?></td></tr>
				<?php endforeach; ?>
			</table>
			<p><button type="button" class="button im-keys-close"><?php esc_html_e( 'Cerrar', 'inpulsia-medios' ); ?></button></p>
		</div>
	</div>
	<?php
}

/* ---------- 1.10: image sheet and "complete what is missing" mode ---------- */

function inpulsia_medios_sheet_markup( $has_ai ) {
	$labels = inpulsia_medios_field_labels();
	?>
	<aside id="im-sheet" class="im-sheet" hidden aria-labelledby="im-sheet-title" role="dialog">
		<header>
			<h2 id="im-sheet-title"><?php esc_html_e( 'Ficha de la imagen', 'inpulsia-medios' ); ?> <span class="im-sheet-id muted"></span></h2>
			<button type="button" class="button-link im-sheet-close" aria-label="<?php esc_attr_e( 'Cerrar ficha', 'inpulsia-medios' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
		</header>
		<div class="im-sheet-body">
			<a class="im-sheet-img" target="_blank" rel="noopener"><img alt="" /></a>
			<p class="im-sheet-info muted"></p>
			<div class="im-sheet-where"></div>
			<?php foreach ( $labels as $f => $label ) : ?>
				<div class="im-sheet-field" data-for="<?php echo esc_attr( $f ); ?>">
					<label for="im-sheet-<?php echo esc_attr( $f ); ?>"><?php echo esc_html( $label ); ?> <span class="im-sheet-state" aria-live="polite"></span></label>
					<?php if ( in_array( $f, [ 'caption', 'description' ], true ) ) : ?>
						<textarea id="im-sheet-<?php echo esc_attr( $f ); ?>" rows="3"></textarea>
					<?php else : ?>
						<input type="text" id="im-sheet-<?php echo esc_attr( $f ); ?>" />
					<?php endif; ?>
					<small class="im-sheet-hint"></small>
					<?php if ( $has_ai ) : ?><span class="im-sheet-ai" hidden></span><?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<footer>
			<button type="button" class="button im-sheet-prev"><?php esc_html_e( '← Anterior', 'inpulsia-medios' ); ?></button>
			<?php if ( $has_ai ) : ?><button type="button" class="button im-sheet-suggest"><?php esc_html_e( 'Sugerir con IA', 'inpulsia-medios' ); ?></button><?php endif; ?>
			<button type="button" class="button button-primary im-sheet-next"><?php esc_html_e( 'Siguiente →', 'inpulsia-medios' ); ?></button>
		</footer>
	</aside>

	<div id="im-focus" class="im-keys" role="dialog" aria-modal="true" aria-labelledby="im-focus-title" hidden>
		<div class="im-keys-box im-focus-box">
			<p class="im-focus-count muted"></p>
			<h2 id="im-focus-title"></h2>
			<img class="im-focus-img" alt="" />
			<p class="im-focus-where muted"></p>
			<input type="text" class="im-focus-input large-text" />
			<small class="im-focus-hint"></small>
			<p class="im-focus-actions">
				<button type="button" class="button button-primary im-focus-save"><?php esc_html_e( 'Guardar y siguiente', 'inpulsia-medios' ); ?> <kbd>Intro</kbd></button>
				<button type="button" class="button im-focus-skip"><?php esc_html_e( 'Saltar', 'inpulsia-medios' ); ?></button>
				<?php if ( $has_ai ) : ?><button type="button" class="button im-focus-ai"><?php esc_html_e( 'Sugerir con IA', 'inpulsia-medios' ); ?></button><?php endif; ?>
				<button type="button" class="button-link im-focus-close"><?php esc_html_e( 'Terminar', 'inpulsia-medios' ); ?> <kbd>Esc</kbd></button>
			</p>
		</div>
	</div>
	<?php
}
