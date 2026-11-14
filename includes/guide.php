<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_notices', 'inpulsia_medios_welcome_notice' );
add_action( 'wp_ajax_inpulsia_medios_dismiss_welcome', 'inpulsia_medios_dismiss_welcome' );

function inpulsia_medios_url( $tab = 'inicio', $args = [] ) {
	$alias = function_exists( 'inpulsia_medios_tab_aliases' ) ? ( inpulsia_medios_tab_aliases()[ $tab ] ?? null ) : null;
	$url   = add_query_arg( array_merge( [ 'page' => 'inpulsia-medios', 'tab' => $alias ? $alias[0] : $tab ], $args ), admin_url( 'upload.php' ) );
	return $alias ? $url . '#' . $alias[1] : $url;
}

// Same query as the Editar tab, so every number matches what the user sees after clicking it.
function inpulsia_medios_count( $params ) {
	$q = new WP_Query( inpulsia_medios_editar_args( $params ) + [
		'posts_per_page' => 1,
		'fields'         => 'ids',
	] );
	return (int) $q->found_posts;
}

function inpulsia_medios_stats() {
	global $wpdb;
	$visible = inpulsia_medios_count( [] );
	$stored  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash' AND post_mime_type LIKE 'image/%'" );
	return [
		'total'     => $visible,
		'hidden'    => max( 0, $stored - $visible ),
		'missing'   => inpulsia_medios_count( [ 'missing_alt' => true ] ),
		'used'      => inpulsia_medios_count( [ 'usage' => 'used' ] ),
		'png'       => inpulsia_medios_count( [ 'format' => 'heavy' ] ),
		'big'       => inpulsia_medios_count( [ 'weight' => '1m' ] ),
		'names'     => inpulsia_medios_count( [ 'names' => 'bad' ] ),
		'redirects' => inpulsia_medios_redirects_count(),
	];
}

// Plugins known to filter the media library by language or folder.
function inpulsia_medios_library_filters() {
	return array_values( array_filter( [
		defined( 'ICL_SITEPRESS_VERSION' ) ? __( 'WPML (muestra solo las imágenes del idioma activo)', 'inpulsia-medios' ) : '',
		function_exists( 'pll_languages_list' ) ? __( 'Polylang (muestra solo las imágenes del idioma activo)', 'inpulsia-medios' ) : '',
		defined( 'NJFB_VERSION' ) ? __( 'FileBird (muestra solo la carpeta seleccionada)', 'inpulsia-medios' ) : '',
		defined( 'RML_VERSION' ) ? __( 'Real Media Library (muestra solo la carpeta seleccionada)', 'inpulsia-medios' ) : '',
		defined( 'HAPPYFILES_VERSION' ) ? __( 'HappyFiles (muestra solo la carpeta seleccionada)', 'inpulsia-medios' ) : '',
		class_exists( 'MaxGalleriaMediaLib' ) ? __( 'Media Library Folders (muestra solo la carpeta seleccionada)', 'inpulsia-medios' ) : '',
	] ) );
}

function inpulsia_medios_hidden_notice( $hidden ) {
	if ( $hidden < 1 ) {
		return;
	}
	$causes = inpulsia_medios_library_filters();
	?>
	<div class="notice notice-info inline im-hidden-notice">
		<p><strong><?php echo esc_html( 1 === (int) $hidden ? __( 'Hay 1 imagen más en la base de datos que no aparece aquí.', 'inpulsia-medios' ) : sprintf( __( 'Hay %s imágenes más en la base de datos que no aparecen aquí.', 'inpulsia-medios' ), number_format_i18n( $hidden ) ) ); ?></strong>
		<?php if ( $causes ) : ?>
			<?php esc_html_e( 'Las filtra', 'inpulsia-medios' ); ?> <?php echo esc_html( implode( '; ', $causes ) ); ?>.
			<?php if ( inpulsia_medios_multilang() ) : ?>
				<a href="<?php echo esc_url( inpulsia_medios_url( 'editar', [ 'lang' => 'all' ] ) ); ?>"><?php esc_html_e( 'Ver las de todos los idiomas', 'inpulsia-medios' ); ?></a> <?php esc_html_e( 'o cambia el idioma en la barra superior.', 'inpulsia-medios' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Cambia la carpeta en la barra superior de WordPress para verlas y trabajar con ellas.', 'inpulsia-medios' ); ?>
			<?php endif; ?>
		<?php else : ?>
			<?php esc_html_e( 'Otro plugin está filtrando la biblioteca de medios (por idioma, carpetas o permisos). Las cifras de esta página son las de las imágenes visibles.', 'inpulsia-medios' ); ?>
		<?php endif; ?>
		</p>
	</div>
	<?php
}

function inpulsia_medios_stat_card( $number, $label, $cta, $url, $tone = '' ) {
	?>
	<div class="im-stat <?php echo $tone ? 'is-' . esc_attr( $tone ) : ''; ?>">
		<span class="im-stat-number"><?php echo esc_html( number_format_i18n( $number ) ); ?></span>
		<span class="im-stat-label"><?php echo esc_html( $label ); ?></span>
		<?php if ( $cta ) : ?>
			<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $cta ); ?> →</a>
		<?php endif; ?>
	</div>
	<?php
}

// Contextual "Ayuda" tab in the top-right corner of every plugin screen.
function inpulsia_medios_help_tabs() {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}
	$help = [
		'inicio'        => [ __( 'Cómo funciona', 'inpulsia-medios' ), __( '<p>Inpulsia Medios mejora las imágenes de la biblioteca: menos peso, nombres de archivo claros y texto alternativo. Todo se revisa en una vista previa antes de aplicarse y se puede deshacer desde <strong>Historial</strong>.</p>', 'inpulsia-medios' ) ],
		'editar'        => [ __( 'Textos', 'inpulsia-medios' ), __( '<p>Filtra las imágenes (por ejemplo, <em>En uso en la web</em>), escribe los cambios directamente en la tabla o pulsa <strong>IA</strong> para que se propongan, y se guarda solo.</p><p>Todo se guarda solo al salir de cada campo. Para reducir el peso de las imágenes, ve a <strong>Archivos</strong>.</p>', 'inpulsia-medios' ) ],
		'importar'      => [ __( 'Importar un Excel o CSV', 'inpulsia-medios' ), __( '<p>Sube un archivo con las columnas <code>ID</code>, <code>filename</code>, <code>title</code>, <code>alt</code>, <code>caption</code> y <code>description</code>. Las celdas vacías no cambian nada; escribe <code>[vaciar]</code> para borrar un campo.</p><p>La forma más cómoda es exportar primero desde <strong>Exportar</strong>, editar ese archivo y subirlo aquí.</p>', 'inpulsia-medios' ) ],
		'exportar'      => [ __( 'Exportar', 'inpulsia-medios' ), __( '<p>Descarga un CSV con los datos actuales de las imágenes. La columna <code>usado_en</code> dice en qué páginas aparece cada una, útil para escribir textos alternativos con contexto.</p>', 'inpulsia-medios' ) ],
		'historial'     => [ __( 'Deshacer cambios', 'inpulsia-medios' ), __( '<p>Cada importación, edición u optimización queda aquí. <strong>Deshacer</strong> devuelve nombres, textos y, si había copia de seguridad, los archivos originales.</p>', 'inpulsia-medios' ) ],
		'redirecciones' => [ __( 'Redirecciones', 'inpulsia-medios' ), __( '<p>Cuando un archivo cambia de nombre o de formato, su dirección antigua redirige a la nueva para que no haya enlaces rotos ni se pierda el posicionamiento en Google Imágenes.</p>', 'inpulsia-medios' ) ],
		'ajustes'       => [ __( 'Ajustes', 'inpulsia-medios' ), '<p>La clave de Claude activa las sugerencias con IA. El contexto SEO (negocio, zona, palabras clave) hace que las propuestas encajen con tu web.</p>' ],
	];
	$current = sanitize_key( wp_unslash( $_GET['tab'] ?? 'inicio' ) );
	if ( isset( $help[ $current ] ) ) {
		$help = [ $current => $help[ $current ] ] + $help;
	}
	foreach ( $help as $id => $h ) {
		$screen->add_help_tab( [ 'id' => 'im-' . $id, 'title' => $h[0], 'content' => $h[1] ] );
	}
	$screen->set_help_sidebar( '<p><strong>Inpulsia Medios</strong></p><p><a href="' . esc_url( inpulsia_medios_url() ) . '">' . esc_html__( 'Guía de inicio', 'inpulsia-medios' ) . '</a></p><p><a href="https://inpulsia.es" target="_blank" rel="noopener">inpulsia.es</a></p>' );
}

function inpulsia_medios_welcome_notice() {
	if ( ! current_user_can( 'upload_files' ) || get_user_meta( get_current_user_id(), 'inpulsia_medios_welcomed', true ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'dashboard', 'upload' ], true ) ) {
		return;
	}
	?>
	<div class="notice notice-info is-dismissible inpulsia-medios-welcome" data-nonce="<?php echo esc_attr( wp_create_nonce( 'inpulsia_medios_welcome' ) ); ?>">
		<p><strong><?php esc_html_e( 'Inpulsia Medios está listo.', 'inpulsia-medios' ); ?></strong> <?php esc_html_e( 'Optimiza las imágenes de la web y completa sus nombres y textos alternativos en unos minutos.', 'inpulsia-medios' ); ?>
			<a class="button button-primary" style="margin-left:8px" href="<?php echo esc_url( inpulsia_medios_url() ); ?>"><?php esc_html_e( 'Ver la guía de inicio', 'inpulsia-medios' ); ?></a></p>
	</div>
	<script>
		document.addEventListener( 'click', function ( e ) {
			var n = e.target.closest( '.inpulsia-medios-welcome' );
			if ( ! n || ! ( e.target.classList.contains( 'notice-dismiss' ) || e.target.classList.contains( 'button-primary' ) ) ) {
				return;
			}
			var body = new FormData();
			body.append( 'action', 'inpulsia_medios_dismiss_welcome' );
			body.append( 'nonce', n.dataset.nonce );
			navigator.sendBeacon ? navigator.sendBeacon( ajaxurl, body ) : fetch( ajaxurl, { method: 'POST', body: body } );
		} );
	</script>
	<?php
}

function inpulsia_medios_dismiss_welcome() {
	check_ajax_referer( 'inpulsia_medios_welcome', 'nonce' );
	update_user_meta( get_current_user_id(), 'inpulsia_medios_welcomed', 1 );
	wp_send_json_success();
}
