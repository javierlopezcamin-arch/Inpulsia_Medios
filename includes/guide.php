<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_notices', 'inpulsia_medios_welcome_notice' );
add_action( 'wp_ajax_inpulsia_medios_dismiss_welcome', 'inpulsia_medios_dismiss_welcome' );

function inpulsia_medios_url( $tab = 'inicio', $args = [] ) {
	return add_query_arg( array_merge( [ 'page' => 'inpulsia-medios', 'tab' => $tab ], $args ), admin_url( 'upload.php' ) );
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
		defined( 'ICL_SITEPRESS_VERSION' ) ? 'WPML (muestra solo las imágenes del idioma activo)' : '',
		function_exists( 'pll_languages_list' ) ? 'Polylang (muestra solo las imágenes del idioma activo)' : '',
		defined( 'NJFB_VERSION' ) ? 'FileBird (muestra solo la carpeta seleccionada)' : '',
		defined( 'RML_VERSION' ) ? 'Real Media Library (muestra solo la carpeta seleccionada)' : '',
		defined( 'HAPPYFILES_VERSION' ) ? 'HappyFiles (muestra solo la carpeta seleccionada)' : '',
		class_exists( 'MaxGalleriaMediaLib' ) ? 'Media Library Folders (muestra solo la carpeta seleccionada)' : '',
	] ) );
}

function inpulsia_medios_hidden_notice( $hidden ) {
	if ( $hidden < 1 ) {
		return;
	}
	$causes = inpulsia_medios_library_filters();
	?>
	<div class="notice notice-info inline im-hidden-notice">
		<p><strong><?php echo esc_html( 1 === (int) $hidden ? 'Hay 1 imagen más en la base de datos que no aparece aquí.' : 'Hay ' . number_format_i18n( $hidden ) . ' imágenes más en la base de datos que no aparecen aquí.' ); ?></strong>
		<?php if ( $causes ) : ?>
			Las filtra <?php echo esc_html( implode( '; ', $causes ) ); ?>.
			<?php if ( inpulsia_medios_multilang() ) : ?>
				<a href="<?php echo esc_url( inpulsia_medios_url( 'editar', [ 'lang' => 'all' ] ) ); ?>">Ver las de todos los idiomas</a> o cambia el idioma en la barra superior.
			<?php else : ?>
				Cambia la carpeta en la barra superior de WordPress para verlas y trabajar con ellas.
			<?php endif; ?>
		<?php else : ?>
			Otro plugin está filtrando la biblioteca de medios (por idioma, carpetas o permisos). Las cifras de esta página son las de las imágenes visibles.
		<?php endif; ?>
		</p>
	</div>
	<?php
}

function inpulsia_medios_tab_inicio() {
	$s       = inpulsia_medios_stats();
	$has_ai  = inpulsia_medios_api_key() !== '';
	$opt     = inpulsia_medios_opt_settings();
	$history = inpulsia_medios_history();
	update_user_meta( get_current_user_id(), 'inpulsia_medios_welcomed', 1 );
	?>
	<div class="im-guide">
		<section class="im-hero">
			<h2>Imágenes más ligeras, mejor posicionadas y sin romper nada</h2>
			<p>Inpulsia Medios revisa las imágenes de la biblioteca de medios y las deja listas para Google: pesan menos, tienen nombres que se entienden y un texto alternativo que describe lo que se ve. Todo se revisa antes de aplicarse y se puede deshacer.</p>
		</section>

		<?php
		$score = inpulsia_medios_score( $s );
		$tone  = $score >= 85 ? 'ok' : ( $score >= 60 ? 'warn' : 'bad' );
		$steps = [
			[
				'n'     => $s['big'],
				'title' => 'Aligerar las imágenes pesadas',
				'text'  => 'Las de más de 1 MB ralentizan la web. Se convierten y comprimen; antes ves qué se hará con cada una.',
				'done'  => 'No hay imágenes de más de 1 MB.',
				'cta'   => 'Optimizar ' . number_format_i18n( $s['big'] ),
				'url'   => inpulsia_medios_url( 'editar', [ 'weight' => '1m', 'do' => 'optimize' ] ),
			],
			[
				'n'     => $s['missing'],
				'title' => 'Escribir los textos alternativos que faltan',
				'text'  => $has_ai ? 'La IA mira cada imagen y propone el alt. Lo revisas en la tabla y aplicas.' : 'Escríbelos en la tabla, o usa una plantilla. Con la IA activada se proponen solos.',
				'done'  => 'Todas las imágenes tienen alt.',
				'cta'   => $has_ai ? 'Proponer con IA' : 'Completar',
				'url'   => inpulsia_medios_url( 'editar', [ 'missing_alt' => 1, 'do' => 'ai' ] ),
			],
			[
				'n'     => $s['names'],
				'title' => 'Poner nombres de archivo claros',
				'text'  => 'Cambia IMG_2041.jpg y similares por nombres que describan la imagen. Las URLs antiguas redirigen a las nuevas.',
				'done'  => 'Todos los nombres de archivo son claros.',
				'cta'   => $has_ai ? 'Proponer con IA' : 'Renombrar',
				'url'   => inpulsia_medios_url( 'editar', [ 'names' => 'bad', 'do' => 'ai' ] ),
			],
		];
		?>
		<section class="im-health">
			<div class="im-score is-<?php echo esc_attr( $tone ); ?>" style="--im-score:<?php echo (int) $score; ?>" role="img" aria-label="<?php echo esc_attr( 'Salud de la biblioteca: ' . $score . ' de 100' ); ?>">
				<span><?php echo (int) $score; ?></span><small>de 100</small>
			</div>
			<div class="im-health-text">
				<h2>Salud de las imágenes</h2>
				<p><?php echo esc_html( $score >= 85 ? 'La biblioteca está en buena forma. Revisa de vez en cuando las imágenes nuevas.' : ( $score >= 60 ? 'Bien encaminada: completa los pasos del asistente para subir la nota.' : 'Hay margen de mejora: cada paso del asistente sube la nota y mejora el SEO y la velocidad.' ) ); ?></p>
				<p class="muted">Cuenta el alt (40 %), el peso (30 %), los nombres (20 %) y el formato (10 %).</p>
			</div>
		</section>

		<h2 class="im-section-title">Asistente rápido</h2>
		<?php inpulsia_medios_hidden_notice( $s['hidden'] ); ?>
		<ol class="im-wizard">
			<?php foreach ( $steps as $i => $st ) : ?>
				<li class="<?php echo $st['n'] ? 'is-todo' : 'is-done'; ?>">
					<span class="im-wizard-n" aria-hidden="true"><?php echo $st['n'] ? (int) $i + 1 : '✓'; ?></span>
					<div>
						<h3><?php echo esc_html( $st['title'] ); ?> <?php if ( $st['n'] ) : ?><span class="pill pill-warn"><?php echo esc_html( number_format_i18n( $st['n'] ) ); ?></span><?php endif; ?></h3>
						<p><?php echo esc_html( $st['n'] ? $st['text'] : $st['done'] ); ?></p>
					</div>
					<?php if ( $st['n'] ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $st['url'] ); ?>"><?php echo esc_html( $st['cta'] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<h2 class="im-section-title">Estado de esta web</h2>
		<div class="im-stats">
			<?php
			inpulsia_medios_stat_card( $s['total'], $s['hidden'] ? 'imágenes visibles en la biblioteca' : 'imágenes en la biblioteca', 'Ver todas', inpulsia_medios_url( 'editar' ) );
			inpulsia_medios_stat_card( $s['used'], 'se usan en la web', 'Ver las que se usan', inpulsia_medios_url( 'editar', [ 'usage' => 'used' ] ) );
			inpulsia_medios_stat_card( $s['missing'], 'sin texto alternativo', $s['missing'] ? 'Completarlas' : '', inpulsia_medios_url( 'editar', [ 'missing_alt' => 1 ] ), $s['missing'] ? 'warn' : 'ok' );
			inpulsia_medios_stat_card( $s['big'], 'pesan más de 1 MB', $s['big'] ? 'Verlas y optimizarlas' : '', inpulsia_medios_url( 'editar', [ 'weight' => '1m' ] ), $s['big'] ? 'warn' : 'ok' );
			inpulsia_medios_stat_card( $s['names'], 'con nombre poco amigable', $s['names'] ? 'Verlas y renombrarlas' : '', inpulsia_medios_url( 'editar', [ 'names' => 'bad' ] ), $s['names'] ? 'warn' : 'ok' );
			inpulsia_medios_stat_card( $s['png'], 'en PNG, GIF u otros formatos pesados', $s['png'] ? 'Verlas y optimizarlas' : '', inpulsia_medios_url( 'editar', [ 'format' => 'heavy' ] ), $s['png'] ? 'warn' : 'ok' );
			?>
		</div>

		<h2 class="im-section-title">Qué puedes hacer</h2>
		<div class="im-features">
			<div class="im-feature">
				<span class="dashicons dashicons-performance" aria-hidden="true"></span>
				<h3>Aligerar las imágenes</h3>
				<p>Convierte a JPG o WebP, reduce las fotos enormes a un tamaño razonable y las comprime sin que se note. Los logos con fondo transparente se respetan.</p>
			</div>
			<div class="im-feature">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<h3>Nombres y textos para Google</h3>
				<p>Cambia <code>IMG_2041.jpg</code> por <code>reforma-cocina-santander.jpg</code> y añade el texto alternativo. Puedes escribirlos tú o pedir a la IA que los proponga mirando la imagen.</p>
			</div>
			<div class="im-feature">
				<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
				<h3>Cambios en bloque</h3>
				<p>Edita decenas de imágenes en una tabla, o descarga un Excel, rellénalo (o pásaselo al equipo de SEO) y vuelve a subirlo.</p>
			</div>
			<div class="im-feature">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<h3>Sin romper nada</h3>
				<p>Al cambiar un archivo se actualizan las páginas que lo usan, las direcciones antiguas redirigen a las nuevas y cualquier cambio se puede deshacer desde el Historial.</p>
			</div>
		</div>

		<h2 class="im-section-title">Cómo empezar</h2>
		<ol class="im-steps">
			<li>
				<strong>Elige qué imágenes revisar.</strong>
				En <a href="<?php echo esc_url( inpulsia_medios_url( 'editar', [ 'usage' => 'used' ] ) ); ?>">Editar</a>, filtra por <em>En uso en la web</em> para centrarte en las que de verdad ven tus visitas.
			</li>
			<li>
				<strong>Optimízalas.</strong>
				Abre <em>Optimizar imágenes</em> y pulsa <em>Optimizar las del filtro</em>. Verás qué se va a hacer con cada una antes de confirmar y, al terminar, cuánto peso has ahorrado.
			</li>
			<li>
				<strong>Completa nombres y textos alternativos.</strong>
				Escríbelos en la tabla (pulsa Intro para bajar de fila o pega una columna de Excel), usa una plantilla o pulsa <em>IA</em>. Revisa y pulsa <em>Revisar cambios</em> y después <em>Aplicar</em>.
			</li>
			<li>
				<strong>¿Algo no te convence?</strong>
				En <a href="<?php echo esc_url( inpulsia_medios_url( 'historial' ) ); ?>">Historial</a> pulsa <em>Deshacer</em> y todo vuelve a como estaba.
			</li>
		</ol>

		<div class="im-columns">
			<div class="inpulsia-medios-card">
				<h2>Puesta a punto</h2>
				<ul class="im-checklist">
					<li class="<?php echo $has_ai ? 'is-ok' : 'is-todo'; ?>">
						<?php if ( $has_ai ) : ?>
							Sugerencias con IA activadas.
						<?php elseif ( current_user_can( 'manage_options' ) ) : ?>
							Sugerencias con IA desactivadas. <a href="<?php echo esc_url( inpulsia_medios_url( 'ajustes' ) ); ?>">Añadir la clave de Claude</a>.
						<?php else : ?>
							Sugerencias con IA desactivadas: pide a un administrador que las configure.
						<?php endif; ?>
					</li>
					<li class="<?php echo $opt['backup'] ? 'is-ok' : 'is-todo'; ?>">
						<?php echo $opt['backup'] ? 'Copia de seguridad activada al optimizar.' : 'La copia de seguridad está desactivada: las optimizaciones no se podrán deshacer.'; ?>
					</li>
					<li class="<?php echo wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ? 'is-ok' : 'is-info'; ?>">
						<?php echo wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ? 'El servidor puede generar WebP.' : 'El servidor no genera WebP: se usará JPG.'; ?>
					</li>
					<li class="is-info">
						<?php echo (int) $s['redirects']; ?> redirecciones activas de archivos renombrados.
					</li>
					<?php if ( $history ) : ?>
						<li class="is-info">Último cambio: <?php echo esc_html( $history[0]['file'] ); ?>, <?php echo esc_html( human_time_diff( strtotime( $history[0]['date'] ), current_time( 'timestamp' ) ) ); ?>.</li>
					<?php endif; ?>
				</ul>
			</div>

			<div class="inpulsia-medios-card">
				<h2>Preguntas frecuentes</h2>
				<details>
					<summary>¿Puedo romper la web?</summary>
					<p>No debería: antes de aplicar ves una vista previa, las páginas que usan la imagen se actualizan solas y las direcciones antiguas redirigen a las nuevas. Aun así, todo se puede deshacer desde el Historial.</p>
				</details>
				<details>
					<summary>¿Se pierde calidad?</summary>
					<p>Con la calidad 82 que viene por defecto, la diferencia no se aprecia a simple vista. Si una imagen no pesa al menos un 5 % menos tras optimizarla, se deja como estaba.</p>
				</details>
				<details>
					<summary>¿Qué pasa con los logos y los iconos?</summary>
					<p>Las imágenes con fondo transparente se mantienen en PNG para no perder la transparencia. Los GIF animados no se tocan.</p>
				</details>
				<details>
					<summary>¿Qué es el texto alternativo?</summary>
					<p>Una frase que describe la imagen. La leen Google y las personas que usan lectores de pantalla. Mejor concreta y natural, de menos de 125 caracteres: «Cocina reformada con isla de madera en Santander».</p>
				</details>
				<details>
					<summary>¿Cuánto cuesta la IA?</summary>
					<p>Cada sugerencia es una consulta a la API de Claude y se cobra en la cuenta de la clave configurada, normalmente céntimos por cada pocas imágenes. Solo se usa cuando pulsas <em>IA</em> o activas esa opción al optimizar.</p>
				</details>
				<details>
					<summary>¿Y si ya uso ShortPixel, Imagify o similares?</summary>
					<p>El plugin te avisará. Puedes seguir usándolo para nombres, textos y redirecciones, y dejar la compresión al otro plugin para no comprimir dos veces.</p>
				</details>
			</div>
		</div>
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
		'inicio'        => [ 'Cómo funciona', '<p>Inpulsia Medios mejora las imágenes de la biblioteca: menos peso, nombres de archivo claros y texto alternativo. Todo se revisa en una vista previa antes de aplicarse y se puede deshacer desde <strong>Historial</strong>.</p>' ],
		'editar'        => [ 'Editar y optimizar', '<p>Filtra las imágenes (por ejemplo, <em>En uso en la web</em>), escribe los cambios directamente en la tabla o pulsa <strong>IA</strong> para que se propongan, y después <strong>Revisar cambios</strong>.</p><p>Para reducir el peso, abre <strong>Optimizar imágenes</strong> y elige las seleccionadas o todas las del filtro.</p>' ],
		'importar'      => [ 'Importar un Excel o CSV', '<p>Sube un archivo con las columnas <code>ID</code>, <code>filename</code>, <code>title</code>, <code>alt</code>, <code>caption</code> y <code>description</code>. Las celdas vacías no cambian nada; escribe <code>[vaciar]</code> para borrar un campo.</p><p>La forma más cómoda es exportar primero desde <strong>Exportar</strong>, editar ese archivo y subirlo aquí.</p>' ],
		'exportar'      => [ 'Exportar', '<p>Descarga un CSV con los datos actuales de las imágenes. La columna <code>usado_en</code> dice en qué páginas aparece cada una, útil para escribir textos alternativos con contexto.</p>' ],
		'historial'     => [ 'Deshacer cambios', '<p>Cada importación, edición u optimización queda aquí. <strong>Deshacer</strong> devuelve nombres, textos y, si había copia de seguridad, los archivos originales.</p>' ],
		'redirecciones' => [ 'Redirecciones', '<p>Cuando un archivo cambia de nombre o de formato, su dirección antigua redirige a la nueva para que no haya enlaces rotos ni se pierda el posicionamiento en Google Imágenes.</p>' ],
		'ajustes'       => [ 'Ajustes', '<p>La clave de Claude activa las sugerencias con IA. El contexto SEO (negocio, zona, palabras clave) hace que las propuestas encajen con tu web.</p>' ],
	];
	$current = sanitize_key( wp_unslash( $_GET['tab'] ?? 'inicio' ) );
	if ( isset( $help[ $current ] ) ) {
		$help = [ $current => $help[ $current ] ] + $help;
	}
	foreach ( $help as $id => $h ) {
		$screen->add_help_tab( [ 'id' => 'im-' . $id, 'title' => $h[0], 'content' => $h[1] ] );
	}
	$screen->set_help_sidebar( '<p><strong>Inpulsia Medios</strong></p><p><a href="' . esc_url( inpulsia_medios_url() ) . '">Guía de inicio</a></p><p><a href="https://inpulsia.es" target="_blank" rel="noopener">inpulsia.es</a></p>' );
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
		<p><strong>Inpulsia Medios está listo.</strong> Optimiza las imágenes de la web y completa sus nombres y textos alternativos en unos minutos.
			<a class="button button-primary" style="margin-left:8px" href="<?php echo esc_url( inpulsia_medios_url() ); ?>">Ver la guía de inicio</a></p>
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
