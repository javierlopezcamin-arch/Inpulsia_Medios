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
add_action( 'wp_ajax_inpulsia_medios_autosave', 'inpulsia_medios_ajax_autosave' );
add_action( 'wp_ajax_inpulsia_medios_opt_stage', 'inpulsia_medios_ajax_opt_stage' );
add_action( 'wp_ajax_inpulsia_medios_opt_apply', 'inpulsia_medios_ajax_opt_apply' );
add_action( 'admin_post_inpulsia_medios_backups', 'inpulsia_medios_backups_action' );
add_action( 'admin_post_inpulsia_medios_redirects', 'inpulsia_medios_redirects_action' );

function inpulsia_medios_menu() {
	$hook = add_media_page( 'Inpulsia Medios', 'Inpulsia Medios', 'upload_files', 'inpulsia-medios', 'inpulsia_medios_page' );
	add_action( 'load-' . $hook, 'inpulsia_medios_help_tabs' );
}

function inpulsia_medios_tabs() {
	$tabs = [
		'inicio'        => 'Inicio',
		'editar'        => 'Editar',
		'importar'      => 'Importar',
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
		'site'     => get_bloginfo( 'name' ),
	] );
}

function inpulsia_medios_page() {
	$tabs = inpulsia_medios_tabs();
	$tab  = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
	$tab  = isset( $tabs[ $tab ] ) ? $tab : 'inicio';
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

function inpulsia_medios_editar_params() {
	$usage  = sanitize_key( $_REQUEST['usage'] ?? '' );
	$format = sanitize_key( $_REQUEST['format'] ?? '' );
	$weight = sanitize_key( $_REQUEST['weight'] ?? '' );
	$names  = sanitize_key( $_REQUEST['names'] ?? '' );
	$lang   = sanitize_key( $_REQUEST['lang'] ?? '' );
	return [
		's'           => sanitize_text_field( wp_unslash( $_REQUEST['s'] ?? '' ) ),
		'missing_alt' => ! empty( $_REQUEST['missing_alt'] ),
		'usage'       => in_array( $usage, [ 'used', 'unused' ], true ) ? $usage : '',
		'format'      => isset( inpulsia_medios_formats()[ $format ] ) ? $format : '',
		'weight'      => isset( inpulsia_medios_weights()[ $weight ] ) ? $weight : '',
		'names'       => $names === 'bad' ? 'bad' : '',
		'lang'        => $lang === 'all' && inpulsia_medios_multilang() ? 'all' : '',
	];
}

function inpulsia_medios_editar_args( $params ) {
	$params = wp_parse_args( $params, [ 's' => '', 'missing_alt' => false, 'usage' => '', 'format' => '', 'weight' => '', 'names' => '', 'lang' => '' ] );
	$args   = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => $params['format'] ? inpulsia_medios_formats()[ $params['format'] ][0] : 'image',
		's'              => $params['s'],
	];
	$meta = [];
	if ( $params['missing_alt'] ) {
		$meta[] = [
			'relation' => 'OR',
			[ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
			[ 'key' => '_wp_attachment_image_alt', 'value' => '' ],
		];
	}
	if ( $params['weight'] ) {
		inpulsia_medios_sizes_sync();
		$meta['peso'] = [
			'key'     => INPULSIA_MEDIOS_BYTES_KEY,
			'value'   => inpulsia_medios_weights()[ $params['weight'] ][0],
			'compare' => '>',
			'type'    => 'NUMERIC',
		];
		$args['orderby'] = [ 'peso' => 'DESC' ];
	}
	if ( $meta ) {
		$args['meta_query'] = [ 'relation' => 'AND' ] + $meta;
	}
	$only = null;
	if ( $params['names'] ) {
		$only = inpulsia_medios_bad_name_ids();
	}
	if ( $params['usage'] ) {
		$used = inpulsia_medios_used_ids();
		if ( $params['usage'] === 'used' ) {
			$only = $only === null ? $used : array_values( array_intersect( $only, $used ) );
		} else {
			$args['post__not_in'] = $used;
		}
	}
	if ( $only !== null ) {
		$args['post__in'] = $only ?: [ 0 ];
	}
	if ( $params['lang'] ) {
		$args = inpulsia_medios_all_langs_args( $args );
	}
	return $args;
}

function inpulsia_medios_tab_editar() {
	if ( ! empty( $_GET['refresh_usage'] ) ) {
		inpulsia_medios_used_ids( true );
	}
	$params = inpulsia_medios_editar_params();
	$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$query  = new WP_Query( inpulsia_medios_editar_args( $params ) + [ 'posts_per_page' => 20, 'paged' => $paged ] );
	$has_ai = inpulsia_medios_api_key() !== '';
	$opt    = inpulsia_medios_opt_settings();
	$labels = inpulsia_medios_field_labels();
	$others = array_values( array_filter( [
		defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ) ? 'ShortPixel' : '',
		defined( 'IMAGIFY_VERSION' ) ? 'Imagify' : '',
		defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ? 'EWWW' : '',
		class_exists( 'WP_Smush' ) ? 'Smush' : '',
		defined( 'OPTIMOLE_VERSION' ) ? 'Optimole' : '',
	] ) );
	?>
	<form method="get" class="inpulsia-medios-filters im-sticky">
		<input type="hidden" name="page" value="inpulsia-medios" />
		<input type="hidden" name="tab" value="editar" />
		<input type="search" name="s" value="<?php echo esc_attr( $params['s'] ); ?>" placeholder="Buscar medios" aria-label="Buscar medios" />
		<select name="usage" aria-label="Uso">
			<option value="">Cualquier uso</option>
			<option value="used" <?php selected( $params['usage'], 'used' ); ?>>En uso en la web</option>
			<option value="unused" <?php selected( $params['usage'], 'unused' ); ?>>Sin uso detectado</option>
		</select>
		<select name="format" aria-label="Formato">
			<option value="">Todos los formatos</option>
			<?php foreach ( inpulsia_medios_formats() as $key => $f ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $params['format'], $key ); ?>><?php echo esc_html( $f[1] ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="weight" aria-label="Peso">
			<option value="">Cualquier peso</option>
			<?php foreach ( inpulsia_medios_weights() as $key => $w ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $params['weight'], $key ); ?>><?php echo esc_html( $w[1] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( inpulsia_medios_multilang() ) : ?>
			<select name="lang" aria-label="Idioma">
				<option value="">Idioma activo</option>
				<option value="all" <?php selected( $params['lang'], 'all' ); ?>>Todos los idiomas</option>
			</select>
		<?php endif; ?>
		<label><input type="checkbox" name="missing_alt" value="1" <?php checked( $params['missing_alt'] ); ?> /> Sin alt</label>
		<label><input type="checkbox" name="names" value="bad" <?php checked( $params['names'], 'bad' ); ?> /> Nombre poco amigable</label>
		<button class="button">Filtrar</button>
		<?php if ( array_filter( $params ) ) : ?>
			<a href="<?php echo esc_url( inpulsia_medios_url( 'editar' ) ); ?>">Quitar filtros</a>
		<?php endif; ?>
		<span class="inpulsia-medios-count"><?php echo (int) $query->found_posts; ?> imágenes</span>
		<?php if ( $params['usage'] ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'refresh_usage', 1 ) ); ?>" class="inpulsia-medios-refresh">Recalcular uso</a>
		<?php endif; ?>
	</form>

	<?php
	if ( ! array_filter( $params ) && $paged === 1 ) {
		global $wpdb;
		$stored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash' AND post_mime_type LIKE 'image/%'" );
		inpulsia_medios_hidden_notice( $stored - (int) $query->found_posts );
	}
	?>
	<div id="inpulsia-medios-grid-wrap" data-do="<?php echo esc_attr( sanitize_key( $_GET['do'] ?? '' ) ); ?>">
		<div class="im-tools">
		<details class="inpulsia-medios-card inpulsia-medios-opt" <?php echo $params['usage'] || $params['format'] || $params['weight'] ? 'open' : ''; ?>>
			<summary><span class="dashicons dashicons-performance" aria-hidden="true"></span> <strong>Optimizar imágenes</strong> <span class="muted">convertir, reducir peso, nombres amigables y alt</span></summary>
			<?php if ( $others ) : ?>
				<div class="notice notice-warning inline"><p>Esta web ya usa <?php echo esc_html( implode( ', ', $others ) ); ?>. Optimizar también aquí puede comprimir dos veces: revisa su configuración antes.</p></div>
			<?php endif; ?>
			<div class="inpulsia-medios-opt-fields">
				<label>Formato
					<select id="im-opt-format">
						<option value="jpg" <?php selected( $opt['format'], 'jpg' ); ?>>JPG</option>
						<option value="webp" <?php selected( $opt['format'], 'webp' ); ?> <?php disabled( ! wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ); ?>>WebP (≈30 % menos peso)</option>
					</select>
				</label>
				<label>Ancho máximo <input type="number" id="im-opt-width" min="320" max="8000" step="10" value="<?php echo (int) $opt['max_width']; ?>" /> px</label>
				<label>Calidad <input type="number" id="im-opt-quality" min="40" max="100" value="<?php echo (int) $opt['quality']; ?>" /></label>
				<label><input type="checkbox" id="im-opt-backup" <?php checked( $opt['backup'] ); ?> /> Copia de seguridad (permite deshacer)</label>
				<label title="Si no se marca, la imagen anterior se conserva <?php echo (int) INPULSIA_MEDIOS_GRACE_DAYS; ?> días para que ninguna caché muestre una imagen rota.">
					<input type="checkbox" id="im-opt-delete-old" <?php checked( ! $opt['keep_old'] ); ?> /> Borrar del servidor la imagen anterior al sustituirla
				</label>
				<label title="<?php echo $has_ai ? '' : esc_attr( 'Configura la clave de Claude en Ajustes' ); ?>">
					<input type="checkbox" id="im-opt-ai" <?php checked( $opt['ai'] && $has_ai ); ?> <?php disabled( ! $has_ai ); ?> /> Nombre y alt con IA si faltan o son de cámara
				</label>
			</div>
			<p class="description">Los PNG con transparencia (logos, iconos) se mantienen en PNG. Los GIF animados no se tocan. Las URLs antiguas redirigen a las nuevas.</p>
			<p>
				<button type="button" class="button button-primary" id="inpulsia-medios-opt-all" data-total="<?php echo (int) $query->found_posts; ?>">Optimizar las <?php echo (int) $query->found_posts; ?> del filtro</button>
			</p>
		</details>

		<details class="inpulsia-medios-card im-template">
			<summary><span class="dashicons dashicons-editor-code" aria-hidden="true"></span> <strong>Rellenar con plantilla</strong> <span class="muted">el mismo patrón para muchas imágenes, sin IA</span></summary>
			<div class="inpulsia-medios-opt-fields">
				<label>Campo
					<select id="im-tpl-field">
						<?php foreach ( $labels as $f => $l ) : ?>
							<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $f, 'alt' ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="im-tpl-input">Plantilla <input type="text" id="im-tpl" value="{pagina} - {web}" /></label>
				<label><input type="checkbox" id="im-tpl-empty" checked /> Solo donde esté vacío o tenga nombre de cámara</label>
				<button type="button" class="button button-primary" id="im-tpl-apply">Aplicar</button>
			</div>
			<p class="description">Variables:
				<?php foreach ( [ '{pagina}' => 'título de la página donde se usa', '{titulo}' => 'título de la imagen', '{web}' => 'nombre de la web', '{nombre}' => 'nombre de archivo actual', '{alt}' => 'alt actual', '{n}' => 'número 1, 2, 3…' ] as $var => $help ) : ?>
					<button type="button" class="button-link im-var" data-var="<?php echo esc_attr( $var ); ?>" title="<?php echo esc_attr( $help ); ?>"><code><?php echo esc_html( $var ); ?></code></button>
				<?php endforeach; ?>
				· Se aplica a las seleccionadas, o a toda la página si no hay ninguna. Después revisa y aplica.
			</p>
		</details>
		</div>

		<div class="im-toolbar">
			<label class="im-autosave"><input type="checkbox" id="im-autosave" /> Guardar automáticamente alt, título, leyenda y descripción</label>
			<span class="muted im-keys">Intro: fila siguiente · Pega una columna de Excel para rellenar hacia abajo</span>
			<span id="inpulsia-medios-dirty"></span>
			<button type="button" id="inpulsia-medios-review" class="button button-primary">Revisar cambios</button>
			<?php if ( ! $has_ai && current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'upload.php?page=inpulsia-medios&tab=ajustes' ) ); ?>">Configura la IA en Ajustes</a>
			<?php endif; ?>
		</div>

		<div class="inpulsia-medios-scroll">
		<table class="wp-list-table widefat striped inpulsia-medios-grid">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="inpulsia-medios-check-all" aria-label="Seleccionar todas las imágenes de la página" /></td>
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
				$cur   = inpulsia_medios_current( $post->ID );
				$path  = (string) get_attached_file( $post->ID );
				$ext   = pathinfo( $path, PATHINFO_EXTENSION );
				$meta  = wp_get_attachment_metadata( $post->ID );
				$where = inpulsia_medios_where_used( $post->ID );
				$lang  = inpulsia_medios_lang( $post->ID );
				$large = wp_get_attachment_image_url( $post->ID, 'medium_large' );
				?>
				<tr data-id="<?php echo (int) $post->ID; ?>" data-page="<?php echo esc_attr( $where ? $where[0]['title'] : '' ); ?>">
					<th class="check-column"><input type="checkbox" class="inpulsia-medios-row-check" aria-label="<?php echo esc_attr( sprintf( 'Seleccionar imagen %d', $post->ID ) ); ?>" /></th>
					<td class="col-thumb">
						<a class="im-thumb" href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" target="_blank" rel="noopener"><?php echo wp_get_attachment_image( $post->ID, 'thumbnail' ); ?>
							<?php if ( $large ) : ?><span class="im-zoom" aria-hidden="true"><img src="<?php echo esc_url( $large ); ?>" alt="" loading="lazy" /></span><?php endif; ?>
						</a>
						<small>#<?php echo (int) $post->ID; ?> · <?php echo esc_html( strtoupper( $ext ) ); ?><?php echo $lang ? ' · <span class="im-lang">' . esc_html( strtoupper( $lang ) ) . '</span>' : ''; ?></small>
						<small><?php echo (int) ( $meta['width'] ?? 0 ); ?>×<?php echo (int) ( $meta['height'] ?? 0 ); ?> · <?php echo esc_html( file_exists( $path ) ? size_format( filesize( $path ), 0 ) : '—' ); ?></small>
						<?php if ( $where ) : ?>
							<small class="im-where" title="<?php echo esc_attr( implode( "\n", wp_list_pluck( $where, 'title' ) ) ); ?>">
								<a href="<?php echo esc_url( $where[0]['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_html_excerpt( $where[0]['title'], 22, '…' ) ); ?></a><?php echo count( $where ) > 1 ? ' +' . ( count( $where ) - 1 ) : ''; ?>
							</small>
						<?php else : ?>
							<small class="muted">Sin uso detectado</small>
						<?php endif; ?>
					</td>
					<?php foreach ( [ 'filename', 'title', 'alt', 'caption', 'description' ] as $field ) : ?>
						<td>
							<?php if ( $field === 'description' || $field === 'caption' ) : ?>
								<textarea rows="2" aria-label="<?php echo esc_attr( $labels[ $field ] . ' #' . $post->ID ); ?>" data-field="<?php echo esc_attr( $field ); ?>" data-orig="<?php echo esc_attr( $cur[ $field ] ); ?>"><?php echo esc_textarea( $cur[ $field ] ); ?></textarea>
							<?php else : ?>
								<input type="text" aria-label="<?php echo esc_attr( $labels[ $field ] . ' #' . $post->ID ); ?>" data-field="<?php echo esc_attr( $field ); ?>" data-orig="<?php echo esc_attr( $cur[ $field ] ); ?>" value="<?php echo esc_attr( $cur[ $field ] ); ?>" />
							<?php endif; ?>
							<?php if ( $field === 'filename' && $ext ) : ?><small class="muted">.<?php echo esc_html( $ext ); ?></small><?php endif; ?>
							<?php if ( $field === 'alt' ) : ?><small class="inpulsia-medios-chars"></small><?php endif; ?>
							<small class="im-hint" data-for="<?php echo esc_attr( $field ); ?>"></small>
						</td>
					<?php endforeach; ?>
					<?php if ( $has_ai ) : ?>
						<td class="col-ai"><button type="button" class="button inpulsia-medios-suggest" title="Sugerir alt, nombre y título con IA" aria-label="<?php echo esc_attr( sprintf( 'Sugerir textos con IA para la imagen %d', $post->ID ) ); ?>">IA</button></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $query->posts ) : ?>
				<tr><td colspan="8">No hay imágenes con estos filtros.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		</div>
		<?php
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( [
			'base'    => add_query_arg( 'paged', '%#%' ),
			'format'  => '',
			'current' => $paged,
			'total'   => max( 1, $query->max_num_pages ),
		] ) ) . '</div></div>';
		?>

		<div class="im-actionbar" hidden role="region" aria-label="Acciones con las imágenes seleccionadas">
			<strong id="im-selected-count"></strong>
			<?php if ( $has_ai ) : ?>
				<button type="button" id="inpulsia-medios-suggest-selected" class="button button-primary">Sugerir con IA</button>
			<?php endif; ?>
			<button type="button" class="button" id="inpulsia-medios-opt-selected">Optimizar</button>
			<button type="button" class="button" id="im-copy-down" title="Copia el campo en el que estabas escribiendo a todas las seleccionadas">Copiar campo a seleccionadas</button>
			<button type="button" class="button-link" id="im-clear-selection">Quitar selección</button>
		</div>
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
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
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
	inpulsia_medios_backups_card();
}

function inpulsia_medios_backups_card() {
	list( $count, $bytes ) = inpulsia_medios_backups_stats();
	list( $old_count )     = inpulsia_medios_backups_stats( 30 );
	list( $orphans, $orphan_bytes ) = inpulsia_medios_orphans_stats();
	$url                   = admin_url( 'admin-post.php' );
	?>
	<div class="inpulsia-medios-card">
		<h2>Copias de seguridad de imágenes optimizadas</h2>
		<?php if ( isset( $_GET['orphans_deleted'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php echo (int) $_GET['orphans_deleted']; ?> archivos antiguos eliminados.</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['backups_deleted'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php echo (int) $_GET['backups_deleted']; ?> copias eliminadas.</p></div>
		<?php endif; ?>
		<p><?php echo (int) $count; ?> copias, <?php echo esc_html( $bytes ? size_format( $bytes, 1 ) : '0 MB' ); ?> en <code>uploads/inpulsia-medios-backups</code>. Sin copia, una optimización ya no se puede deshacer.</p>
		<?php if ( $orphans ) : ?>
			<p><?php echo (int) $orphans; ?> archivos antiguos (<?php echo esc_html( size_format( $orphan_bytes, 1 ) ); ?>) se conservan durante <?php echo (int) INPULSIA_MEDIOS_GRACE_DAYS; ?> días tras renombrar u optimizar, para que las cachés y los enlaces externos no muestren imágenes rotas. Se borran solos.</p>
			<form method="post" action="<?php echo esc_url( $url ); ?>" onsubmit="return confirm('Borra los archivos antiguos ya. Hazlo solo si has vaciado todas las cachés (también la del hosting y Cloudflare). ¿Seguir?');">
				<input type="hidden" name="action" value="inpulsia_medios_backups" />
				<input type="hidden" name="orphans" value="1" />
				<?php wp_nonce_field( 'inpulsia_medios_backups' ); ?>
				<p><button class="button">Borrar ya los archivos antiguos</button></p>
			</form>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
			<input type="hidden" name="action" value="inpulsia_medios_backups" />
			<input type="hidden" name="days" value="30" />
			<?php wp_nonce_field( 'inpulsia_medios_backups' ); ?>
			<button class="button" <?php disabled( ! $old_count ); ?>>Borrar las de más de 30 días (<?php echo (int) $old_count; ?>)</button>
		</form>
		<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" onsubmit="return confirm('Se borrarán todas las copias y no se podrá deshacer ninguna optimización. ¿Seguir?');">
			<input type="hidden" name="action" value="inpulsia_medios_backups" />
			<input type="hidden" name="days" value="0" />
			<?php wp_nonce_field( 'inpulsia_medios_backups' ); ?>
			<button class="button button-link-delete" <?php disabled( ! $count ); ?>>Borrar todas</button>
		</form>
	</div>
	<?php
}

function inpulsia_medios_backups_action() {
	check_admin_referer( 'inpulsia_medios_backups' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Solo los administradores pueden borrar copias.' );
	}
	if ( ! empty( $_POST['orphans'] ) ) {
		$n = inpulsia_medios_orphans_cleanup( true );
		wp_safe_redirect( admin_url( 'upload.php?page=inpulsia-medios&tab=ajustes&orphans_deleted=' . $n ) );
		exit;
	}
	$n = inpulsia_medios_backups_delete( absint( $_POST['days'] ?? 30 ) );
	wp_safe_redirect( admin_url( 'upload.php?page=inpulsia-medios&tab=ajustes&backups_deleted=' . $n ) );
	exit;
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
	$upload = $_FILES['csv_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below, tmp_name is set by PHP.
	if ( ! is_array( $upload ) || ( $upload['error'] ?? -1 ) !== UPLOAD_ERR_OK || ! is_uploaded_file( $upload['tmp_name'] ?? '' ) ) {
		wp_send_json_error( 'Error al subir el archivo.' );
	}
	$name = sanitize_file_name( $upload['name'] ?? 'archivo.csv' );
	$rows = inpulsia_medios_parse_file( $upload['tmp_name'], $name );
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
	$raw  = json_decode( wp_unslash( $_POST['rows'] ?? '' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is cast or sanitized when applied.
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
	$notes = $done && $purged ? inpulsia_medios_done_notes() : [];

	wp_send_json_success( compact( 'results', 'next', 'done', 'purged', 'notes' ) + [ 'total' => count( $rows ) ] );
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

function inpulsia_medios_ajax_opt_stage() {
	inpulsia_medios_check_ajax();
	$opts = inpulsia_medios_opt_sanitize( [
		'format'    => sanitize_key( $_POST['format'] ?? 'jpg' ),
		'max_width' => absint( $_POST['max_width'] ?? 2560 ),
		'quality'   => absint( $_POST['quality'] ?? 82 ),
		'backup'    => ! empty( $_POST['backup'] ) && $_POST['backup'] !== 'false',
		'ai'        => ! empty( $_POST['ai'] ) && $_POST['ai'] !== 'false' && inpulsia_medios_api_key() !== '',
		'keep_old'  => empty( $_POST['delete_old'] ) || $_POST['delete_old'] === 'false',
	] );
	update_option( 'inpulsia_medios_opt', $opts, false );

	if ( sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) ) === 'filter' ) {
		$ids = get_posts( inpulsia_medios_editar_args( inpulsia_medios_editar_params() ) + [ 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ] );
	} else {
		$ids = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) );
	}
	if ( ! $ids ) {
		wp_send_json_error( 'No hay imágenes seleccionadas.' );
	}

	$items = [];
	$valid = [];
	$bytes = 0;
	foreach ( $ids as $id ) {
		if ( ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}
		$plan = inpulsia_medios_opt_plan( $id, $opts );
		$item = [
			'id'    => $id,
			'edit'  => get_edit_post_link( $id, 'raw' ),
			'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail', true ),
			'name'  => basename( (string) get_attached_file( $id ) ) . ' · ' . size_format( $plan['bytes'], 1 ),
		];
		if ( isset( $plan['error'] ) ) {
			$item['skip'] = $plan['error'];
		} else {
			$item['lines']    = $plan['lines'];
			$item['warnings'] = $plan['warnings'];
			$valid[]          = $id;
			$bytes           += $plan['bytes'];
		}
		if ( count( $items ) < 300 ) {
			$items[] = $item;
		}
	}

	$token = inpulsia_medios_new_token();
	set_transient( 'inpulsia_medios_opt_' . $token, [ 'user' => get_current_user_id(), 'ids' => $valid, 'opts' => $opts ], DAY_IN_SECONDS );
	wp_send_json_success( [
		'token'  => $token,
		'items'  => $items,
		'total'  => count( $valid ),
		'listed' => count( $ids ),
		'bytes'  => size_format( $bytes, 1 ),
		'mode'   => 'optimize',
	] );
}

function inpulsia_medios_ajax_opt_apply() {
	inpulsia_medios_check_ajax();
	$token  = sanitize_key( $_POST['token'] ?? '' );
	$offset = absint( $_POST['offset'] ?? 0 );
	$data   = get_transient( 'inpulsia_medios_opt_' . $token );
	if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
		wp_send_json_error( 'La sesión ha caducado. Vuelve a lanzar la optimización.' );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 );
	}
	wp_raise_memory_limit( 'image' );

	$ids = $data['ids'];
	if ( $offset === 0 ) {
		inpulsia_medios_history_add( [
			'token'   => $token,
			'date'    => current_time( 'mysql' ),
			'user'    => wp_get_current_user()->display_name,
			'file'    => 'Optimización (' . strtoupper( $data['opts']['format'] ) . ', ' . $data['opts']['max_width'] . ' px)',
			'rows'    => count( $ids ),
			'changed' => 0,
			'status'  => 'incompleto',
		] );
	}

	$batch   = $data['opts']['ai'] ? 2 : 3;
	$results = inpulsia_medios_optimize_ids( $token, array_slice( $ids, $offset, $batch ), $data['opts'] );
	$next    = min( $offset + $batch, count( $ids ) );
	$done    = $next >= count( $ids );
	$purged  = [];
	if ( $done ) {
		inpulsia_medios_history_update( $token, [ 'status' => 'aplicado' ] );
		delete_transient( 'inpulsia_medios_opt_' . $token );
		$purged = inpulsia_medios_purge_caches();
	}
	$notes = $done ? inpulsia_medios_done_notes( $data['opts']['keep_old'] ) : [];
	wp_send_json_success( compact( 'results', 'next', 'done', 'purged', 'notes' ) + [ 'total' => count( $ids ) ] );
}

// Saves one text field right away (never the file name). All autosaves of a user and day share one Historial entry.
function inpulsia_medios_ajax_autosave() {
	inpulsia_medios_check_ajax();
	$id    = absint( $_POST['id'] ?? 0 );
	$field = sanitize_key( $_POST['field'] ?? '' );
	$value = (string) wp_unslash( $_POST['value'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in apply_row.
	if ( ! in_array( $field, [ 'title', 'alt', 'caption', 'description' ], true ) ) {
		wp_send_json_error( 'Este campo no se guarda automáticamente.' );
	}
	$token = inpulsia_medios_autosave_token();
	list( $r ) = inpulsia_medios_apply_rows( $token, [ [ 'id' => $id, $field => $value === '' ? INPULSIA_MEDIOS_CLEAR : $value ] ] );
	if ( $r['status'] === 'error' ) {
		wp_send_json_error( $r['msg'] );
	}
	$entry = inpulsia_medios_history_find( $token );
	inpulsia_medios_history_update( $token, [ 'rows' => (int) $entry['changed'] ] );
	wp_send_json_success( [ 'value' => inpulsia_medios_current( $id )[ $field ] ] );
}

function inpulsia_medios_autosave_token() {
	$user  = get_current_user_id();
	$today = current_time( 'Y-m-d' );
	$saved = get_user_meta( $user, 'inpulsia_medios_autosave', true );
	$entry = is_array( $saved ) && $saved['day'] === $today ? inpulsia_medios_history_find( $saved['token'] ) : null;
	if ( $entry && $entry['status'] !== 'deshecho' ) {
		return $saved['token'];
	}
	$token = inpulsia_medios_new_token();
	inpulsia_medios_history_add( [
		'token'   => $token,
		'date'    => current_time( 'mysql' ),
		'user'    => wp_get_current_user()->display_name,
		'file'    => 'Guardado automático en tabla',
		'rows'    => 0,
		'changed' => 0,
		'status'  => 'aplicado',
	] );
	update_user_meta( $user, 'inpulsia_medios_autosave', [ 'day' => $today, 'token' => $token ] );
	return $token;
}
