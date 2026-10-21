<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'inpulsia_medios_menu' );
add_action( 'admin_enqueue_scripts', 'inpulsia_medios_assets' );
add_action( 'wp_ajax_inpulsia_medios_preview', 'inpulsia_medios_ajax_preview' );
add_action( 'wp_ajax_inpulsia_medios_apply', 'inpulsia_medios_ajax_apply' );
add_action( 'wp_ajax_inpulsia_medios_undo', 'inpulsia_medios_ajax_undo' );

function inpulsia_medios_menu() {
	add_media_page( 'Inpulsia Medios', 'Inpulsia Medios', 'upload_files', 'inpulsia-medios', 'inpulsia_medios_page' );
}

function inpulsia_medios_assets( $hook ) {
	if ( $hook !== 'media_page_inpulsia-medios' ) {
		return;
	}
	wp_enqueue_style( 'inpulsia-medios', INPULSIA_MEDIOS_URL . 'assets/style.css', [], INPULSIA_MEDIOS_VERSION );
	wp_enqueue_script( 'inpulsia-medios', INPULSIA_MEDIOS_URL . 'assets/script.js', [ 'jquery' ], INPULSIA_MEDIOS_VERSION, true );
	wp_localize_script( 'inpulsia-medios', 'inpulsiaMedios', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'inpulsia_medios_nonce' ),
		'labels'   => inpulsia_medios_field_labels(),
		'clear'    => INPULSIA_MEDIOS_CLEAR,
		'edit_url' => admin_url( 'post.php?action=edit&post=' ),
		'history'  => admin_url( 'upload.php?page=inpulsia-medios&tab=historial' ),
	] );
}

function inpulsia_medios_page() {
	$tabs = [ 'importar' => 'Importar', 'exportar' => 'Exportar', 'historial' => 'Historial' ];
	$tab  = isset( $_GET['tab'], $tabs[ $_GET['tab'] ] ) ? $_GET['tab'] : 'importar';
	?>
	<div class="wrap inpulsia-medios-wrap">
		<h1>Inpulsia Medios <span class="inpulsia-medios-version">v<?php echo esc_html( INPULSIA_MEDIOS_VERSION ); ?></span></h1>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, admin_url( 'upload.php?page=inpulsia-medios' ) ) ); ?>"
					class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php call_user_func( 'inpulsia_medios_tab_' . $tab ); ?>
	</div>
	<?php
}

function inpulsia_medios_tab_importar() {
	?>
	<div class="inpulsia-medios-card">
		<h2>Subir CSV</h2>
		<p>Columnas: <code>ID</code> (obligatoria), <code>filename</code>, <code>title</code>, <code>alt</code>, <code>caption</code>, <code>description</code>. Las demás se ignoran.
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=inpulsia_medios_template' ), 'inpulsia_medios_template' ) ); ?>">Descargar plantilla</a>
			· <a href="<?php echo esc_url( admin_url( 'upload.php?page=inpulsia-medios&tab=exportar' ) ); ?>">Exportar medios actuales</a></p>
		<ul class="inpulsia-medios-help">
			<li>Celda vacía = no cambiar. Escribe <code><?php echo esc_html( INPULSIA_MEDIOS_CLEAR ); ?></code> para vaciar un campo.</li>
			<li>El nombre de archivo se normaliza para SEO (minúsculas, guiones, sin tildes) y conserva la extensión original.</li>
			<li>Al renombrar se actualizan las URLs en entradas, páginas y Elementor.</li>
		</ul>
		<form id="inpulsia-medios-form">
			<input type="file" id="inpulsia-medios-file" accept=".csv,text/csv" required />
			<button type="submit" class="button button-primary">Ver vista previa</button>
			<span class="spinner"></span>
		</form>
	</div>

	<div id="inpulsia-medios-preview" hidden>
		<h2>Vista previa</h2>
		<div id="inpulsia-medios-summary" class="inpulsia-medios-summary"></div>
		<p>
			<label><input type="checkbox" id="inpulsia-medios-only-warn" /> Mostrar solo filas con avisos</label>
		</p>
		<table class="wp-list-table widefat striped" id="inpulsia-medios-table">
			<thead><tr><th class="col-id">ID</th><th class="col-thumb"></th><th>Cambios</th><th class="col-warn">Avisos</th></tr></thead>
			<tbody></tbody>
		</table>
		<p class="inpulsia-medios-actions">
			<button id="inpulsia-medios-apply" class="button button-primary button-hero">Aplicar cambios</button>
			<button id="inpulsia-medios-cancel" class="button">Cancelar</button>
		</p>
	</div>

	<div id="inpulsia-medios-run" hidden>
		<h2 id="inpulsia-medios-run-title">Procesando</h2>
		<div class="inpulsia-medios-progress"><div></div></div>
		<p id="inpulsia-medios-run-status"></p>
		<div id="inpulsia-medios-log"></div>
	</div>
	<?php
}

function inpulsia_medios_tab_exportar() {
	?>
	<div class="inpulsia-medios-card">
		<h2>Exportar biblioteca a CSV</h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="inpulsia_medios_export" />
			<?php wp_nonce_field( 'inpulsia_medios_export' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Tipo</th>
					<td>
						<select name="type">
							<option value="image">Solo imágenes</option>
							<option value="all">Todos los medios</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Filtro</th>
					<td><label><input type="checkbox" name="missing_alt" value="1" /> Solo imágenes sin texto alternativo</label></td>
				</tr>
				<tr>
					<th scope="row">Subidas entre</th>
					<td><input type="date" name="from" /> y <input type="date" name="to" /></td>
				</tr>
				<tr>
					<th scope="row">Contexto</th>
					<td>
						<label><input type="checkbox" name="usage" value="1" checked /> Incluir columna <code>usado_en</code></label>
						<p class="description">Indica en qué páginas, entradas, productos o plantillas de Elementor aparece cada imagen. En bibliotecas muy grandes la exportación tarda más.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Descargar CSV', 'primary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

function inpulsia_medios_tab_historial() {
	$history = inpulsia_medios_history();
	?>
	<div class="inpulsia-medios-card">
		<h2>Últimas importaciones</h2>
		<p class="description">Se guardan las 20 últimas. Deshacer restaura los valores anteriores, incluidos los nombres de archivo y sus URLs.</p>
		<?php if ( ! $history ) : ?>
			<p>Todavía no hay importaciones.</p>
		<?php else : ?>
			<table class="wp-list-table widefat striped">
				<thead><tr><th>Fecha</th><th>Archivo</th><th>Usuario</th><th>Filas</th><th>Medios cambiados</th><th>Estado</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $history as $e ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $e['date'] ) ); ?></td>
						<td><?php echo esc_html( $e['file'] ); ?></td>
						<td><?php echo esc_html( $e['user'] ); ?></td>
						<td><?php echo (int) $e['rows']; ?></td>
						<td><?php echo (int) $e['changed']; ?></td>
						<td><span class="inpulsia-medios-status status-<?php echo esc_attr( sanitize_title( $e['status'] ) ); ?>"><?php echo esc_html( $e['status'] ); ?></span></td>
						<td>
							<?php if ( $e['status'] !== 'deshecho' && $e['changed'] > 0 ) : ?>
								<button class="button inpulsia-medios-undo" data-token="<?php echo esc_attr( $e['token'] ); ?>">Deshacer</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<div id="inpulsia-medios-run" hidden>
		<h2 id="inpulsia-medios-run-title">Deshaciendo</h2>
		<div class="inpulsia-medios-progress"><div></div></div>
		<p id="inpulsia-medios-run-status"></p>
		<div id="inpulsia-medios-log"></div>
	</div>
	<?php
}

function inpulsia_medios_check_ajax() {
	check_ajax_referer( 'inpulsia_medios_nonce', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'No tienes permisos para editar medios.' );
	}
}

function inpulsia_medios_parse_csv( $path ) {
	$content = file_get_contents( $path );
	if ( $content === false || $content === '' ) {
		return [];
	}
	if ( strpos( $content, "\xEF\xBB\xBF" ) === 0 ) {
		$content = substr( $content, 3 );
	}
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
		$content = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
	}

	$first     = strtok( $content, "\n" );
	$delimiter = substr_count( $first, ';' ) >= substr_count( $first, ',' ) ? ';' : ',';

	$handle = fopen( 'php://temp', 'r+' );
	fwrite( $handle, $content );
	rewind( $handle );

	$aliases = [
		'nombre'            => 'filename',
		'archivo'           => 'filename',
		'nombre de archivo' => 'filename',
		'titulo'            => 'title',
		'texto alternativo' => 'alt',
		'leyenda'           => 'caption',
		'descripcion'       => 'description',
	];

	$headers = fgetcsv( $handle, 0, $delimiter );
	if ( ! $headers ) {
		fclose( $handle );
		return [];
	}
	$headers = array_map( function ( $h ) use ( $aliases ) {
		$h = strtolower( remove_accents( trim( (string) $h ) ) );
		return $aliases[ $h ] ?? $h;
	}, $headers );

	$rows = [];
	while ( ( $data = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
		$row = [];
		foreach ( $headers as $i => $h ) {
			$row[ $h ] = isset( $data[ $i ] ) ? trim( $data[ $i ] ) : '';
		}
		if ( isset( $row['id'] ) && $row['id'] !== '' ) {
			$rows[] = $row;
		}
	}
	fclose( $handle );
	return $rows;
}

function inpulsia_medios_ajax_preview() {
	inpulsia_medios_check_ajax();

	if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( 'Error al subir el archivo.' );
	}
	$rows = inpulsia_medios_parse_csv( $_FILES['csv_file']['tmp_name'] );
	if ( ! $rows ) {
		wp_send_json_error( 'El CSV está vacío o no tiene columna ID.' );
	}

	$items = [];
	$valid = [];
	$stems = [];
	$seen  = [];

	foreach ( $rows as $row ) {
		$id   = absint( $row['id'] );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || $post->post_type !== 'attachment' ) {
			$items[] = [ 'id' => $row['id'], 'error' => 'No existe un medio con este ID.' ];
			continue;
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			$items[] = [ 'id' => $id, 'error' => 'No tienes permisos para editar este medio.' ];
			continue;
		}
		if ( isset( $seen[ $id ] ) ) {
			$items[] = [ 'id' => $id, 'error' => 'ID repetido en el CSV: se usa solo la primera fila.' ];
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

	foreach ( $stems as $stem => $ids ) {
		if ( count( $ids ) > 1 ) {
			foreach ( $ids as $id ) {
				$items[ 'id' . $id ]['warnings'][] = 'Nombre repetido en el CSV (IDs ' . implode( ', ', $ids ) . ').';
			}
		}
	}

	$token = strtolower( wp_generate_password( 12, false ) );
	set_transient( 'inpulsia_medios_rows_' . $token, [
		'user' => get_current_user_id(),
		'file' => sanitize_file_name( $_FILES['csv_file']['name'] ),
		'rows' => $valid,
	], DAY_IN_SECONDS );

	wp_send_json_success( [
		'token' => $token,
		'items' => array_values( $items ),
		'total' => count( $valid ),
	] );
}

function inpulsia_medios_ajax_apply() {
	inpulsia_medios_check_ajax();

	$token  = sanitize_key( $_POST['token'] ?? '' );
	$offset = absint( $_POST['offset'] ?? 0 );
	$data   = get_transient( 'inpulsia_medios_rows_' . $token );
	if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
		wp_send_json_error( 'La sesión ha caducado. Vuelve a subir el CSV.' );
	}

	$rows = $data['rows'];
	if ( $offset === 0 ) {
		$user = wp_get_current_user();
		inpulsia_medios_history_add( [
			'token'   => $token,
			'date'    => current_time( 'mysql' ),
			'user'    => $user->display_name,
			'file'    => $data['file'],
			'rows'    => count( $rows ),
			'changed' => 0,
			'status'  => 'incompleto',
		] );
	}

	$log     = get_option( 'inpulsia_medios_log_' . $token, [] );
	$results = [];
	foreach ( array_slice( $rows, $offset, INPULSIA_MEDIOS_BATCH ) as $row ) {
		$id = absint( $row['id'] );
		$r  = inpulsia_medios_apply_row( $id, $row );
		if ( ! empty( $r['prev'] ) ) {
			$log[] = [ 'id' => $id, 'prev' => $r['prev'] ];
		}
		unset( $r['prev'] );
		$results[] = [ 'id' => $id ] + $r;
	}
	update_option( 'inpulsia_medios_log_' . $token, $log, false );
	inpulsia_medios_flush_elementor();

	$next = min( $offset + INPULSIA_MEDIOS_BATCH, count( $rows ) );
	$done = $next >= count( $rows );
	inpulsia_medios_history_update( $token, [ 'changed' => count( $log ) ] + ( $done ? [ 'status' => 'aplicado' ] : [] ) );
	if ( $done ) {
		delete_transient( 'inpulsia_medios_rows_' . $token );
	}

	wp_send_json_success( [ 'results' => $results, 'next' => $next, 'total' => count( $rows ), 'done' => $done ] );
}

function inpulsia_medios_ajax_undo() {
	inpulsia_medios_check_ajax();

	$token  = sanitize_key( $_POST['token'] ?? '' );
	$offset = absint( $_POST['offset'] ?? 0 );
	$entry  = inpulsia_medios_history_find( $token );
	if ( ! $entry || $entry['status'] === 'deshecho' ) {
		wp_send_json_error( 'Esta importación no se puede deshacer.' );
	}

	$log     = array_reverse( (array) get_option( 'inpulsia_medios_log_' . $token, [] ) );
	$results = [];
	foreach ( array_slice( $log, $offset, INPULSIA_MEDIOS_BATCH ) as $item ) {
		$r = inpulsia_medios_apply_row( $item['id'], $item['prev'], true );
		unset( $r['prev'] );
		$results[] = [ 'id' => $item['id'] ] + $r;
	}
	inpulsia_medios_flush_elementor();

	$next = min( $offset + INPULSIA_MEDIOS_BATCH, count( $log ) );
	$done = $next >= count( $log );
	if ( $done ) {
		inpulsia_medios_history_update( $token, [ 'status' => 'deshecho' ] );
	}

	wp_send_json_success( [ 'results' => $results, 'next' => $next, 'total' => count( $log ), 'done' => $done ] );
}
