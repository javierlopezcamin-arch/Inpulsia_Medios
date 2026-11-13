<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_inpulsia_medios_clean_stage', 'inpulsia_medios_ajax_clean_stage' );
add_action( 'wp_ajax_inpulsia_medios_clean_apply', 'inpulsia_medios_ajax_clean_apply' );

function inpulsia_medios_clean_labels() {
	return [
		'unused' => 'Limpieza: imágenes sin uso',
		'dupes'  => 'Limpieza: duplicados',
		'broken' => 'Limpieza: imágenes rotas',
		'lost'   => 'Limpieza: recuperar archivos perdidos',
	];
}

function inpulsia_medios_tab_limpieza() {
	if ( ! empty( $_GET['refresh_usage'] ) ) {
		inpulsia_medios_used_ids( true );
	}
	$unused = inpulsia_medios_unused_ids();
	$ubytes = 0;
	foreach ( $unused as $id ) {
		$ubytes += (int) get_post_meta( $id, INPULSIA_MEDIOS_BYTES_KEY, true );
	}
	$groups = inpulsia_medios_duplicate_groups();
	$dupes  = 0;
	$dbytes = 0;
	foreach ( $groups as $g ) {
		foreach ( $g['dupes'] as $id ) {
			$dupes++;
			$dbytes += (int) get_post_meta( $id, INPULSIA_MEDIOS_BYTES_KEY, true );
		}
	}
	if ( ! empty( $_GET['scan'] ) ) {
		inpulsia_medios_broken_scan();
	}
	$broken = get_transient( 'inpulsia_medios_broken' );
	$fixable = $broken ? count( array_filter( $broken['items'], function ( $b ) {
		return $b['fix'] !== '';
	} ) ) : 0;
	$lost       = inpulsia_medios_lost_files();
	$recoverable = count( array_filter( wp_list_pluck( $lost, 'backup' ) ) );
	$errors     = array_reverse( (array) get_option( 'inpulsia_medios_errors', [] ) );
	?>
	<div id="im-clean">
		<?php if ( $lost ) : ?>
			<div class="inpulsia-medios-card im-clean-card im-clean-alert">
				<h2><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> <?php esc_html_e( 'Imágenes sin archivo en el servidor', 'inpulsia-medios' ); ?></h2>
				<p class="im-clean-figure"><strong><?php echo esc_html( number_format_i18n( count( $lost ) ) ); ?></strong> <?php esc_html_e( 'imágenes', 'inpulsia-medios' ); ?> · <?php echo esc_html( sprintf( __( '%d se pueden recuperar de una copia', 'inpulsia-medios' ), $recoverable ) ); ?></p>
				<p><?php esc_html_e( 'La biblioteca las tiene, pero el archivo ya no está en uploads, así que la web las muestra rotas.', 'inpulsia-medios' ); ?></p>
				<div class="inpulsia-medios-scroll">
				<table class="wp-list-table widefat striped">
					<thead><tr><th>ID</th><th><?php esc_html_e( 'Archivo que falta', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Copia de seguridad', 'inpulsia-medios' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $lost, 0, 50 ) as $l ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $l['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo (int) $l['id']; ?></a></td>
							<td><code><?php echo esc_html( $l['file'] ); ?></code></td>
							<td><?php echo $l['backup'] ? esc_html__( 'Sí', 'inpulsia-medios' ) : '<span class="muted">' . esc_html__( 'No: vuelve a subirla o cámbiala en las páginas', 'inpulsia-medios' ) . '</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p><button type="button" class="button button-primary im-clean-stage" data-kind="lost" <?php disabled( ! $recoverable ); ?>><?php echo esc_html( sprintf( __( 'Recuperar las %d con copia', 'inpulsia-medios' ), $recoverable ) ); ?></button></p>
			</div>
		<?php endif; ?>
		<p class="description im-clean-intro"><?php esc_html_e( 'Antes de borrar o cambiar nada verás la lista completa. Las imágenes borradas se guardan en una copia y se pueden recuperar desde el Historial.', 'inpulsia-medios' ); ?></p>

		<div class="inpulsia-medios-card im-clean-card">
			<h2><span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e( 'Imágenes sin uso', 'inpulsia-medios' ); ?></h2>
			<p class="im-clean-figure"><strong><?php echo esc_html( number_format_i18n( count( $unused ) ) ); ?></strong> <?php esc_html_e( 'imágenes', 'inpulsia-medios' ); ?> · <?php echo esc_html( size_format( $ubytes, 1 ) ?: '0 B' ); ?></p>
			<p><?php esc_html_e( 'No aparecen en ninguna página, entrada, producto, plantilla de Elementor ni ajuste del tema. Se excluyen las subidas en los últimos 7 días.', 'inpulsia-medios' ); ?></p>
			<p class="description"><?php esc_html_e( 'Si alguna imagen se usa desde código del tema, un CSS propio o una web externa, aquí no se detecta: revisa la lista antes de confirmar.', 'inpulsia-medios' ); ?></p>
			<p>
				<button type="button" class="button button-primary im-clean-stage" data-kind="unused" <?php disabled( ! $unused ); ?>><?php esc_html_e( 'Revisar y mandar a la papelera', 'inpulsia-medios' ); ?></button>
				<a class="button" href="<?php echo esc_url( inpulsia_medios_url( 'editar', [ 'usage' => 'unused' ] ) ); ?>"><?php esc_html_e( 'Verlas en Editar', 'inpulsia-medios' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'refresh_usage', 1 ) ); ?>"><?php esc_html_e( 'Recalcular uso', 'inpulsia-medios' ); ?></a>
			</p>
		</div>

		<div class="inpulsia-medios-card im-clean-card">
			<h2><span class="dashicons dashicons-images-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Imágenes duplicadas', 'inpulsia-medios' ); ?></h2>
			<p class="im-clean-figure"><strong><?php echo esc_html( number_format_i18n( $dupes ) ); ?></strong> <?php esc_html_e( 'copias sobrantes', 'inpulsia-medios' ); ?> · <?php echo esc_html( size_format( $dbytes, 1 ) ?: '0 B' ); ?></p>
			<p><?php esc_html_e( 'La misma foto subida varias veces. Se conserva la que se usa (o la más antigua); las páginas que usan las copias pasan a usar esa y las direcciones antiguas redirigen a ella.', 'inpulsia-medios' ); ?></p>
			<?php if ( $groups ) : ?>
				<div class="im-dupe-groups">
					<?php foreach ( array_slice( $groups, 0, 8 ) as $g ) : ?>
						<div class="im-dupe-group">
							<span class="im-dupe-keep" title="<?php esc_attr_e( 'Se conserva', 'inpulsia-medios' ); ?>"><?php echo wp_get_attachment_image( $g['keep'], [ 48, 48 ] ); ?></span>
							<span class="muted">← <?php echo esc_html( sprintf( _n( '%d copia', '%d copias', count( $g['dupes'] ), 'inpulsia-medios' ), count( $g['dupes'] ) ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<p><button type="button" class="button button-primary im-clean-stage" data-kind="dupes" <?php disabled( ! $dupes ); ?>><?php esc_html_e( 'Revisar y unificar', 'inpulsia-medios' ); ?></button></p>
		</div>

		<div class="inpulsia-medios-card im-clean-card">
			<h2><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e( 'Imágenes rotas', 'inpulsia-medios' ); ?></h2>
			<p><?php esc_html_e( 'Páginas y plantillas de Elementor que enlazan a imágenes que ya no existen en el servidor. Cuando se encuentra la misma imagen con otro nombre, tamaño o formato, se propone el cambio.', 'inpulsia-medios' ); ?></p>
			<?php if ( ! $broken ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'scan', 1 ) ); ?>"><?php esc_html_e( 'Analizar la web', 'inpulsia-medios' ); ?></a></p>
			<?php else : ?>
				<p class="im-clean-figure"><strong><?php echo esc_html( number_format_i18n( count( $broken['items'] ) ) ); ?></strong> <?php esc_html_e( 'enlaces rotos', 'inpulsia-medios' ); ?> · <?php echo esc_html( sprintf( __( '%d con arreglo propuesto', 'inpulsia-medios' ), $fixable ) ); ?>
					<span class="muted">· <?php echo esc_html( sprintf( __( 'analizado hace %s', 'inpulsia-medios' ), human_time_diff( $broken['time'] ) ) ); ?></span>
					<a href="<?php echo esc_url( add_query_arg( 'scan', 1 ) ); ?>"><?php esc_html_e( 'Volver a analizar', 'inpulsia-medios' ); ?></a></p>
				<?php if ( $broken['items'] ) : ?>
					<div class="inpulsia-medios-scroll">
					<table class="wp-list-table widefat striped im-broken">
						<thead><tr><th><?php esc_html_e( 'Página', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Imagen que falta', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Propuesta', 'inpulsia-medios' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( array_slice( $broken['items'], 0, 50 ) as $b ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $b['post'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $b['post'] ) ?: '#' . $b['post'] ); ?></a></td>
								<td><code><?php echo esc_html( $b['path'] ); ?></code></td>
								<td><?php echo $b['fix'] !== '' ? '<code>' . esc_html( $b['fix'] ) . '</code>' : '<span class="muted">' . esc_html__( 'Sin propuesta: cámbiala a mano', 'inpulsia-medios' ) . '</span>'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<p><button type="button" class="button button-primary im-clean-stage" data-kind="broken" <?php disabled( ! $fixable ); ?>><?php echo esc_html( sprintf( __( 'Reparar las %d con propuesta', 'inpulsia-medios' ), $fixable ) ); ?></button></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php if ( $errors ) : ?>
			<div class="inpulsia-medios-card im-clean-card" id="im-errors">
				<h2><span class="dashicons dashicons-list-view" aria-hidden="true"></span> <?php esc_html_e( 'Optimizaciones que fallaron', 'inpulsia-medios' ); ?></h2>
				<p><?php esc_html_e( 'En todas ellas se mantuvo la imagen original.', 'inpulsia-medios' ); ?></p>
				<div class="inpulsia-medios-scroll">
				<table class="wp-list-table widefat striped">
					<tbody>
					<?php foreach ( array_slice( $errors, 0, 30 ) as $e ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'd/m/Y H:i', $e['time'] ) ); ?></td>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $e['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $e['id'] ) ?: '#' . $e['id'] ); ?></a></td>
							<td><?php echo esc_html( $e['msg'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
	inpulsia_medios_preview_markup();
}

function inpulsia_medios_ajax_clean_stage() {
	inpulsia_medios_check_ajax();
	$kind  = sanitize_key( $_POST['kind'] ?? '' );
	$jobs  = [];
	$items = [];
	$item  = function ( $id, $lines, $warnings = [] ) {
		return [
			'id'       => $id,
			'edit'     => get_edit_post_link( $id, 'raw' ),
			'thumb'    => (string) wp_get_attachment_image_url( $id, 'thumbnail', true ),
			'name'     => basename( (string) get_attached_file( $id ) ) . ' · ' . size_format( (int) get_post_meta( $id, INPULSIA_MEDIOS_BYTES_KEY, true ), 1 ),
			'lines'    => $lines,
			'warnings' => $warnings,
		];
	};
	if ( $kind === 'unused' ) {
		foreach ( inpulsia_medios_unused_ids() as $id ) {
			$jobs[]  = [ 'id' => $id ];
			$parent  = wp_get_post_parent_id( $id );
			$items[] = $item( $id, [ __( 'Se borra (con copia para recuperarla)', 'inpulsia-medios' ) ], $parent ? [ sprintf( __( 'Se subió en «%s»: comprueba que no la necesitas.', 'inpulsia-medios' ), get_the_title( $parent ) ) ] : [] );
		}
	} elseif ( $kind === 'dupes' ) {
		foreach ( inpulsia_medios_duplicate_groups() as $g ) {
			foreach ( $g['dupes'] as $id ) {
				$jobs[]  = [ 'id' => $id, 'keep' => $g['keep'] ];
				$items[] = $item( $id, [ sprintf( __( 'Igual que %1$s (ID %2$d), que se conserva', 'inpulsia-medios' ), basename( (string) get_attached_file( $g['keep'] ) ), $g['keep'] ) ] );
			}
		}
	} elseif ( $kind === 'broken' ) {
		$broken = get_transient( 'inpulsia_medios_broken' );
		foreach ( $broken ? $broken['items'] : [] as $b ) {
			if ( $b['fix'] === '' ) {
				continue;
			}
			$jobs[]  = $b + [ 'id' => $b['post'] ];
			$items[] = [ 'id' => $b['post'], 'edit' => get_edit_post_link( $b['post'], 'raw' ), 'thumb' => '', 'name' => get_the_title( $b['post'] ), 'lines' => [ $b['path'] . ' → ' . $b['fix'] ], 'warnings' => [] ];
		}
	} elseif ( $kind === 'lost' ) {
		foreach ( inpulsia_medios_lost_files() as $l ) {
			if ( $l['backup'] === '' ) {
				continue;
			}
			$jobs[]  = [ 'id' => $l['id'], 'backup' => $l['backup'] ];
			$items[] = [ 'id' => $l['id'], 'edit' => get_edit_post_link( $l['id'], 'raw' ), 'thumb' => '', 'name' => $l['file'], 'lines' => [ __( 'Se recupera el archivo desde la copia de seguridad', 'inpulsia-medios' ) ], 'warnings' => [] ];
		}
	} else {
		wp_send_json_error( __( 'Acción no válida.', 'inpulsia-medios' ) );
	}
	if ( ! $jobs ) {
		wp_send_json_error( __( 'No hay nada que limpiar.', 'inpulsia-medios' ) );
	}
	$token = inpulsia_medios_new_token();
	set_transient( 'inpulsia_medios_clean_' . $token, [ 'user' => get_current_user_id(), 'kind' => $kind, 'jobs' => $jobs ], DAY_IN_SECONDS );
	$labels = [
		'unused' => __( 'Mandar %d imágenes a la papelera', 'inpulsia-medios' ),
		'dupes'  => __( 'Unificar %d duplicados', 'inpulsia-medios' ),
		'broken' => __( 'Reparar %d enlaces', 'inpulsia-medios' ),
		'lost'   => __( 'Recuperar %d imágenes', 'inpulsia-medios' ),
	];
	wp_send_json_success( [
		'token'  => $token,
		'items'  => array_slice( $items, 0, 300 ),
		'total'  => count( $jobs ),
		'listed' => count( $jobs ),
		'mode'   => 'optimize',
		'action' => 'inpulsia_medios_clean_apply',
		'label'  => sprintf( $labels[ $kind ], count( $jobs ) ),
		'summary' => sprintf( _n( '%d elemento', '%d elementos', count( $jobs ), 'inpulsia-medios' ), count( $jobs ) ),
		'title'  => __( 'Limpiando', 'inpulsia-medios' ),
	] );
}

function inpulsia_medios_ajax_clean_apply() {
	inpulsia_medios_check_ajax();
	$token  = sanitize_key( $_POST['token'] ?? '' );
	$offset = absint( $_POST['offset'] ?? 0 );
	$data   = get_transient( 'inpulsia_medios_clean_' . $token );
	if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
		wp_send_json_error( __( 'La sesión ha caducado. Vuelve a cargar los cambios.', 'inpulsia-medios' ) );
	}
	$jobs = $data['jobs'];
	if ( $offset === 0 ) {
		inpulsia_medios_history_add( [
			'token'   => $token,
			'date'    => current_time( 'mysql' ),
			'user'    => wp_get_current_user()->display_name,
			'file'    => inpulsia_medios_clean_labels()[ $data['kind'] ],
			'rows'    => count( $jobs ),
			'changed' => 0,
			'status'  => 'incompleto',
		] );
	}
	$log     = get_option( 'inpulsia_medios_log_' . $token, [] );
	$results = [];
	foreach ( array_slice( $jobs, $offset, 5 ) as $job ) {
		if ( $data['kind'] === 'lost' ) {
			$ok = inpulsia_medios_backup_restore( $job['backup'] );
			$r  = is_wp_error( $ok ) ? [ 'status' => 'error', 'msg' => $ok->get_error_message() ] : [ 'status' => 'ok', 'msg' => __( 'Imagen recuperada.', 'inpulsia-medios' ) ];
		} elseif ( $data['kind'] === 'broken' ) {
			$ok = inpulsia_medios_broken_repair( $job['post'], $job['path'], $job['fix'] );
			$r  = $ok ? [ 'status' => 'ok', 'msg' => $job['path'] . ' → ' . $job['fix'] ] : [ 'status' => 'skip', 'msg' => __( 'Sin cambios.', 'inpulsia-medios' ) ];
		} else {
			$r = $data['kind'] === 'dupes' ? inpulsia_medios_dedupe( $job['id'], $job['keep'] ) : inpulsia_medios_trash_image( $job['id'] );
		}
		if ( ! empty( $r['prev'] ) ) {
			$log[] = [ 'id' => $job['id'], 'prev' => $r['prev'] ];
			update_option( 'inpulsia_medios_log_' . $token, $log, false );
		}
		unset( $r['prev'] );
		$results[] = [ 'id' => $job['id'] ] + $r;
	}
	inpulsia_medios_history_update( $token, [ 'changed' => count( $log ) ] );
	inpulsia_medios_flush_elementor();
	$next   = min( $offset + 5, count( $jobs ) );
	$done   = $next >= count( $jobs );
	$purged = [];
	if ( $done ) {
		inpulsia_medios_history_update( $token, [ 'status' => 'aplicado' ] );
		delete_transient( 'inpulsia_medios_clean_' . $token );
		if ( $data['kind'] === 'broken' ) {
			delete_transient( 'inpulsia_medios_broken' );
		}
		inpulsia_medios_used_ids( true );
		$purged = inpulsia_medios_purge_caches();
	}
	wp_send_json_success( compact( 'results', 'next', 'done', 'purged' ) + [ 'total' => count( $jobs ), 'notes' => [] ] );
}
