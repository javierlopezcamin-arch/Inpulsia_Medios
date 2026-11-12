<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'inpulsia_medios_menu' );
add_action( 'admin_enqueue_scripts', 'inpulsia_medios_assets' );
add_action( 'wp_ajax_inpulsia_medios_preview', 'inpulsia_medios_ajax_preview' );
add_action( 'wp_ajax_inpulsia_medios_stage', 'inpulsia_medios_ajax_stage' );
add_action( 'wp_ajax_inpulsia_medios_apply', 'inpulsia_medios_ajax_apply' );
add_action( 'wp_ajax_inpulsia_medios_undo', 'inpulsia_medios_ajax_undo' );
add_action( 'admin_post_inpulsia_medios_redirects', 'inpulsia_medios_redirects_action' );

function inpulsia_medios_menu() {
	add_media_page( 'Inpulsia Medios', 'Inpulsia Medios', 'upload_files', 'inpulsia-medios', 'inpulsia_medios_page' );
}

function inpulsia_medios_tabs() {
	$tabs = [
		'importar'      => 'Importar',
		'editar'        => 'Editar',
		'exportar'      => 'Exportar',
		'historial'     => 'Historial',
		'redirecciones' => 'Redirecciones',
	];
	if ( current_user_can( 'manage_options' ) ) {
		$tabs['ajustes'] = 'Ajustes';
	}
	return $tabs;
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
	$tabs = inpulsia_medios_tabs();
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

function inpulsia_medios_preview_markup() {
	?>
	<div id="inpulsia-medios-preview" hidden>
		<h2>Vista previa</h2>
		<div id="inpulsia-medios-summary" class="inpulsia-medios-summary"></div>
		<p><label><input type="checkbox" id="inpulsia-medios-only-warn" /> Mostrar solo filas con avisos</label></p>
		<table class="wp-list-table widefat striped" id="inpulsia-medios-table">
			<thead><tr><th class="col-id">ID</th><th class="col-thumb"></th><th>Cambios</th><th class="col-warn">Avisos</th></tr></thead>
			<tbody></tbody>
		</table>
		<p class="inpulsia-medios-actions">
			<button id="inpulsia-medios-apply" class="button button-primary button-hero">Aplicar cambios</button>
			<button id="inpulsia-medios-cancel" class="button">Cancelar</button>
		</p>
	</div>
	<?php
	inpulsia_medios_run_markup( 'Procesando' );
}

function inpulsia_medios_run_markup( $title ) {
	?>
	<div id="inpulsia-medios-run" hidden>
		<h2 id="inpulsia-medios-run-title"><?php echo esc_html( $title ); ?></h2>
		<div class="inpulsia-medios-progress"><div></div></div>
		<p id="inpulsia-medios-run-status"></p>
		<div id="inpulsia-medios-log"></div>
	</div>
	<?php
}

function inpulsia_medios_tab_importar() {
	?>
	<div class="inpulsia-medios-card">
		<h2>Subir CSV o Excel</h2>
		<p>Columnas: <code>ID</code> (obligatoria), <code>filename</code>, <code>title</code>, <code>alt</code>, <code>caption</code>, <code>description</code>. Las demás se ignoran.
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=inpulsia_medios_template' ), 'inpulsia_medios_template' ) ); ?>">Descargar plantilla</a>
			· <a href="<?php echo esc_url( admin_url( 'upload.php?page=inpulsia-medios&tab=exportar' ) ); ?>">Exportar medios actuales</a></p>
		<ul class="inpulsia-medios-help">
			<li>Celda vacía = no cambiar. Escribe <code><?php echo esc_html( INPULSIA_MEDIOS_CLEAR ); ?></code> para vaciar un campo.</li>
			<li>El nombre de archivo se normaliza para SEO (minúsculas, guiones, sin tildes) y conserva la extensión original.</li>
			<li>Al renombrar se actualizan las URLs en todo el sitio, se renombran las copias WebP/AVIF y se crean redirecciones 301 desde las URLs antiguas.</li>
			<li>Al cambiar el alt también se actualiza en las imágenes ya insertadas en entradas y páginas.</li>
		</ul>
		<form id="inpulsia-medios-form">
			<input type="file" id="inpulsia-medios-file" accept=".csv,text/csv,.xlsx" required />
			<button type="submit" class="button button-primary">Ver vista previa</button>
			<span class="spinner"></span>
		</form>
	</div>
	<?php
	inpulsia_medios_preview_markup();
}

function inpulsia_medios_tab_editar() {
	$paged   = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$missing = ! empty( $_GET['missing_alt'] );

	$args = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'image',
		'posts_per_page' => 20,
		'paged'          => $paged,
		's'              => $search,
	];
	if ( $missing ) {
		$args['meta_query'] = [
			'relation' => 'OR',
			[ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
			[ 'key' => '_wp_attachment_image_alt', 'value' => '' ],
		];
	}
	$query  = new WP_Query( $args );
	$has_ai = inpulsia_medios_api_key() !== '';
	?>
	<form method="get" class="inpulsia-medios-filters">
		<input type="hidden" name="page" value="inpulsia-medios" />
		<input type="hidden" name="tab" value="editar" />
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar medios" />
		<label><input type="checkbox" name="missing_alt" value="1" <?php checked( $missing ); ?> /> Solo sin alt</label>
		<button class="button">Filtrar</button>
		<span class="inpulsia-medios-count"><?php echo (int) $query->found_posts; ?> imágenes</span>
	</form>

	<div id="inpulsia-medios-grid-wrap">
		<p class="inpulsia-medios-actions">
			<button type="button" id="inpulsia-medios-review" class="button button-primary">Revisar cambios</button>
			<?php if ( $has_ai ) : ?>
				<button type="button" id="inpulsia-medios-suggest-selected" class="button">Sugerir con IA para seleccionadas</button>
			<?php elseif ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'upload.php?page=inpulsia-medios&tab=ajustes' ) ); ?>">Configura la IA en Ajustes para sugerir textos</a>
			<?php endif; ?>
			<span id="inpulsia-medios-dirty"></span>
		</p>
		<table class="wp-list-table widefat striped inpulsia-medios-grid">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="inpulsia-medios-check-all" /></td>
					<th class="col-thumb"></th>
					<th>Nombre de archivo</th>
					<th>Título</th>
					<th>Alt</th>
					<th>Leyenda</th>
					<th>Descripción</th>
					<?php if ( $has_ai ) : ?><th class="col-ai"></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $query->posts as $post ) :
				$cur = inpulsia_medios_current( $post->ID );
				$ext = pathinfo( (string) get_attached_file( $post->ID ), PATHINFO_EXTENSION );
				?>
				<tr data-id="<?php echo (int) $post->ID; ?>">
					<th class="check-column"><input type="checkbox" class="inpulsia-medios-row-check" /></th>
					<td class="col-thumb">
						<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" target="_blank" rel="noopener"><?php echo wp_get_attachment_image( $post->ID, 'thumbnail' ); ?></a>
						<small>#<?php echo (int) $post->ID; ?></small>
					</td>
					<?php foreach ( [ 'filename', 'title', 'alt', 'caption', 'description' ] as $field ) : ?>
						<td>
							<?php if ( $field === 'description' || $field === 'caption' ) : ?>
								<textarea rows="2" data-field="<?php echo esc_attr( $field ); ?>" data-orig="<?php echo esc_attr( $cur[ $field ] ); ?>"><?php echo esc_textarea( $cur[ $field ] ); ?></textarea>
							<?php else : ?>
								<input type="text" data-field="<?php echo esc_attr( $field ); ?>" data-orig="<?php echo esc_attr( $cur[ $field ] ); ?>" value="<?php echo esc_attr( $cur[ $field ] ); ?>" />
							<?php endif; ?>
							<?php if ( $field === 'filename' && $ext ) : ?><small class="muted">.<?php echo esc_html( $ext ); ?></small><?php endif; ?>
							<?php if ( $field === 'alt' ) : ?><small class="inpulsia-medios-chars"></small><?php endif; ?>
						</td>
					<?php endforeach; ?>
					<?php if ( $has_ai ) : ?>
						<td class="col-ai"><button type="button" class="button inpulsia-medios-suggest" title="Sugerir alt, nombre y título con IA">IA</button></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $query->posts ) : ?>
				<tr><td colspan="8">No hay imágenes con estos filtros.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( [
			'base'    => add_query_arg( 'paged', '%#%' ),
			'format'  => '',
			'current' => $paged,
			'total'   => max( 1, $query->max_num_pages ),
		] ) . '</div></div>';
		?>
	</div>
	<?php
	inpulsia_medios_preview_markup();
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
				<thead><tr><th>Fecha</th><th>Origen</th><th>Usuario</th><th>Filas</th><th>Medios cambiados</th><th>Estado</th><th></th></tr></thead>
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
	<?php
	inpulsia_medios_run_markup( 'Deshaciendo' );
}

function inpulsia_medios_tab_redirecciones() {
	$rows  = inpulsia_medios_redirects_list();
	$total = inpulsia_medios_redirects_count();
	$url   = admin_url( 'admin-post.php' );
	?>
	<div class="inpulsia-medios-card">
		<h2>Redirecciones 301 de archivos renombrados</h2>
		<p class="description">Cuando alguien (o Google Imágenes) pide la URL antigua de un archivo renombrado, se le redirige a la nueva. Funciona en servidores donde las peticiones a archivos que no existen llegan a WordPress, que es lo habitual en Apache y en la mayoría de configuraciones de Nginx.</p>
		<?php if ( isset( $_GET['deleted'] ) ) : ?>
			<div class="notice notice-success inline"><p>Redirecciones eliminadas.</p></div>
		<?php endif; ?>
		<?php if ( ! $rows ) : ?>
			<p>No hay redirecciones.</p>
		<?php else : ?>
			<p><?php echo (int) $total; ?> redirecciones<?php echo $total > count( $rows ) ? ' (se muestran las ' . count( $rows ) . ' más recientes)' : ''; ?>.</p>
			<table class="wp-list-table widefat striped">
				<thead><tr><th>Origen</th><th>Destino</th><th>Visitas</th><th>Creada</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r->source ); ?></code></td>
						<td><code><?php echo esc_html( $r->target ); ?></code></td>
						<td><?php echo (int) $r->hits; ?></td>
						<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $r->created ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( $url ); ?>">
								<input type="hidden" name="action" value="inpulsia_medios_redirects" />
								<input type="hidden" name="delete" value="<?php echo (int) $r->id; ?>" />
								<?php wp_nonce_field( 'inpulsia_medios_redirects' ); ?>
								<button class="button-link button-link-delete">Eliminar</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( $url ); ?>" onsubmit="return confirm('¿Eliminar todas las redirecciones?');">
				<input type="hidden" name="action" value="inpulsia_medios_redirects" />
				<input type="hidden" name="delete" value="all" />
				<?php wp_nonce_field( 'inpulsia_medios_redirects' ); ?>
				<p><button class="button">Eliminar todas</button></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

function inpulsia_medios_redirects_action() {
	check_admin_referer( 'inpulsia_medios_redirects' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Solo los administradores pueden eliminar redirecciones.' );
	}
	global $wpdb;
	$table  = inpulsia_medios_redirects_table();
	$delete = sanitize_text_field( wp_unslash( $_POST['delete'] ?? '' ) );
	if ( $delete === 'all' ) {
		$wpdb->query( "DELETE FROM {$table}" );
	} elseif ( absint( $delete ) ) {
		$wpdb->delete( $table, [ 'id' => absint( $delete ) ] );
	}
	wp_safe_redirect( admin_url( 'upload.php?page=inpulsia-medios&tab=redirecciones&deleted=1' ) );
	exit;
}

function inpulsia_medios_tab_ajustes() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s         = inpulsia_medios_settings();
	$from_conf = defined( 'INPULSIA_MEDIOS_CLAUDE_KEY' ) && INPULSIA_MEDIOS_CLAUDE_KEY;
	?>
	<div class="inpulsia-medios-card">
		<h2>Sugerencias con IA</h2>
		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success inline"><p>Ajustes guardados.</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="inpulsia_medios_settings" />
			<?php wp_nonce_field( 'inpulsia_medios_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="im-key">Clave de la API de Claude</label></th>
					<td>
						<?php if ( $from_conf ) : ?>
							<p>Se usa la clave definida en <code>wp-config.php</code> (<code>INPULSIA_MEDIOS_CLAUDE_KEY</code>).</p>
						<?php else : ?>
							<input type="password" id="im-key" name="api_key" class="regular-text" autocomplete="off"
								placeholder="<?php echo $s['api_key'] ? esc_attr( 'Guardada (…' . substr( $s['api_key'], -4 ) . ')' ) : 'sk-ant-…'; ?>" />
							<?php if ( $s['api_key'] ) : ?>
								<label><input type="checkbox" name="delete_key" value="1" /> Borrar la clave guardada</label>
							<?php endif; ?>
							<p class="description">Déjalo en blanco para mantener la actual. Es más seguro definirla en <code>wp-config.php</code>: <code>define( 'INPULSIA_MEDIOS_CLAUDE_KEY', 'sk-ant-…' );</code></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="im-model">Modelo</label></th>
					<td>
						<select id="im-model" name="model">
							<?php foreach ( inpulsia_medios_ai_models() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="im-context">Contexto SEO de la web</label></th>
					<td>
						<textarea id="im-context" name="context" rows="5" class="large-text" placeholder="Ej.: Reformas integrales en Santander. Palabras clave: reformas de cocina, baños, Cantabria."><?php echo esc_textarea( $s['context'] ); ?></textarea>
						<p class="description">Negocio, ubicación y palabras clave. Se envía en cada sugerencia junto con la imagen y las páginas donde aparece.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar ajustes' ); ?>
		</form>
	</div>
	<?php
}

function inpulsia_medios_check_ajax() {
	check_ajax_referer( 'inpulsia_medios_nonce', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'No tienes permisos para editar medios.' );
	}
}

function inpulsia_medios_stage( $rows, $source ) {
	list( $items, $valid ) = inpulsia_medios_build_preview( $rows );
	$token                 = inpulsia_medios_new_token();
	set_transient( 'inpulsia_medios_rows_' . $token, [
		'user' => get_current_user_id(),
		'file' => $source,
		'rows' => $valid,
	], DAY_IN_SECONDS );
	return [ 'token' => $token, 'items' => $items, 'total' => count( $valid ) ];
}

function inpulsia_medios_ajax_preview() {
	inpulsia_medios_check_ajax();
	if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( 'Error al subir el archivo.' );
	}
	$name = sanitize_file_name( $_FILES['csv_file']['name'] );
	$rows = inpulsia_medios_parse_file( $_FILES['csv_file']['tmp_name'], $name );
	if ( is_wp_error( $rows ) ) {
		wp_send_json_error( $rows->get_error_message() );
	}
	if ( ! $rows ) {
		wp_send_json_error( 'El archivo está vacío o no tiene columna ID.' );
	}
	wp_send_json_success( inpulsia_medios_stage( $rows, $name ) );
}

function inpulsia_medios_ajax_stage() {
	inpulsia_medios_check_ajax();
	$raw  = json_decode( wp_unslash( $_POST['rows'] ?? '' ), true );
	$rows = [];
	foreach ( is_array( $raw ) ? $raw : [] as $r ) {
		$row = [ 'id' => absint( $r['id'] ?? 0 ) ];
		foreach ( inpulsia_medios_fields() as $f ) {
			if ( isset( $r[ $f ] ) ) {
				$row[ $f ] = (string) $r[ $f ];
			}
		}
		$rows[] = $row;
	}
	if ( ! $rows ) {
		wp_send_json_error( 'No hay cambios que revisar.' );
	}
	wp_send_json_success( inpulsia_medios_stage( $rows, 'Edición en tabla' ) );
}

function inpulsia_medios_ajax_apply() {
	inpulsia_medios_check_ajax();

	$token  = sanitize_key( $_POST['token'] ?? '' );
	$offset = absint( $_POST['offset'] ?? 0 );
	$data   = get_transient( 'inpulsia_medios_rows_' . $token );
	if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
		wp_send_json_error( 'La sesión ha caducado. Vuelve a cargar los cambios.' );
	}

	$rows = $data['rows'];
	if ( $offset === 0 ) {
		inpulsia_medios_history_add( [
			'token'   => $token,
			'date'    => current_time( 'mysql' ),
			'user'    => wp_get_current_user()->display_name,
			'file'    => $data['file'],
			'rows'    => count( $rows ),
			'changed' => 0,
			'status'  => 'incompleto',
		] );
	}

	$results = inpulsia_medios_apply_rows( $token, array_slice( $rows, $offset, INPULSIA_MEDIOS_BATCH ) );
	$next    = min( $offset + INPULSIA_MEDIOS_BATCH, count( $rows ) );
	$done    = $next >= count( $rows );
	$purged  = [];
	if ( $done ) {
		inpulsia_medios_history_update( $token, [ 'status' => 'aplicado' ] );
		delete_transient( 'inpulsia_medios_rows_' . $token );
		$entry  = inpulsia_medios_history_find( $token );
		$purged = $entry && $entry['changed'] > 0 ? inpulsia_medios_purge_caches() : [];
	}

	wp_send_json_success( compact( 'results', 'next', 'done', 'purged' ) + [ 'total' => count( $rows ) ] );
}

function inpulsia_medios_ajax_undo() {
	inpulsia_medios_check_ajax();

	$token  = sanitize_key( $_POST['token'] ?? '' );
	$offset = absint( $_POST['offset'] ?? 0 );
	$entry  = inpulsia_medios_history_find( $token );
	if ( ! $entry || $entry['status'] === 'deshecho' ) {
		wp_send_json_error( 'Esta importación no se puede deshacer.' );
	}

	$log     = inpulsia_medios_undo_entries( $token );
	$results = inpulsia_medios_undo_rows( array_slice( $log, $offset, INPULSIA_MEDIOS_BATCH ) );
	$next    = min( $offset + INPULSIA_MEDIOS_BATCH, count( $log ) );
	$done    = $next >= count( $log );
	$purged  = [];
	if ( $done ) {
		inpulsia_medios_history_update( $token, [ 'status' => 'deshecho' ] );
		$purged = inpulsia_medios_purge_caches();
	}

	wp_send_json_success( compact( 'results', 'next', 'done', 'purged' ) + [ 'total' => count( $log ) ] );
}
