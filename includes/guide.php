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

function inpulsia_medios_tab_inicio() {
	$s       = inpulsia_medios_stats();
	$has_ai  = inpulsia_medios_api_key() !== '';
	$opt     = inpulsia_medios_opt_settings();
	$history = inpulsia_medios_history();
	update_user_meta( get_current_user_id(), 'inpulsia_medios_welcomed', 1 );
	?>
	<div class="im-guide">
		<?php inpulsia_medios_alerts_markup(); ?>
		<section class="im-hero">
			<h2><?php esc_html_e( 'Imágenes más ligeras, mejor posicionadas y sin romper nada', 'inpulsia-medios' ); ?></h2>
			<p><?php esc_html_e( 'Inpulsia Medios revisa las imágenes de la biblioteca de medios y las deja listas para Google: pesan menos, tienen nombres que se entienden y un texto alternativo que describe lo que se ve. Todo se revisa antes de aplicarse y se puede deshacer.', 'inpulsia-medios' ); ?></p>
		</section>

		<?php
		$score = inpulsia_medios_score( $s );
		$tone  = $score >= 85 ? 'ok' : ( $score >= 60 ? 'warn' : 'bad' );
		$steps = [
			[
				'n'     => $s['big'],
				'title' => __( 'Aligerar las imágenes pesadas', 'inpulsia-medios' ),
				'text'  => __( 'Las de más de 1 MB ralentizan la web. Se convierten y comprimen; antes ves qué se hará con cada una.', 'inpulsia-medios' ),
				'done'  => __( 'No hay imágenes de más de 1 MB.', 'inpulsia-medios' ),
				'cta'   => sprintf( __( 'Optimizar %s', 'inpulsia-medios' ), number_format_i18n( $s['big'] ) ),
				'url'   => inpulsia_medios_url( 'editar', [ 'weight' => '1m', 'do' => 'optimize' ] ),
			],
			[
				'n'     => $s['missing'],
				'title' => __( 'Escribir los textos alternativos que faltan', 'inpulsia-medios' ),
				'text'  => $has_ai ? __( 'La IA mira cada imagen y propone el alt. Lo revisas en la tabla y aplicas.', 'inpulsia-medios' ) : __( 'Escríbelos en la tabla, o usa una plantilla. Con la IA activada se proponen solos.', 'inpulsia-medios' ),
				'done'  => __( 'Todas las imágenes tienen alt.', 'inpulsia-medios' ),
				'cta'   => $has_ai ? __( 'Proponer con IA', 'inpulsia-medios' ) : __( 'Completar', 'inpulsia-medios' ),
				'url'   => inpulsia_medios_url( 'editar', [ 'missing_alt' => 1, 'do' => 'ai' ] ),
			],
			[
				'n'     => $s['names'],
				'title' => __( 'Poner nombres de archivo claros', 'inpulsia-medios' ),
				'text'  => __( 'Cambia IMG_2041.jpg y similares por nombres que describan la imagen. Las URLs antiguas redirigen a las nuevas.', 'inpulsia-medios' ),
				'done'  => __( 'Todos los nombres de archivo son claros.', 'inpulsia-medios' ),
				'cta'   => $has_ai ? __( 'Proponer con IA', 'inpulsia-medios' ) : __( 'Renombrar', 'inpulsia-medios' ),
				'url'   => inpulsia_medios_url( 'editar', [ 'names' => 'bad', 'do' => 'ai' ] ),
			],
		];
		?>
		<section class="im-health">
			<div class="im-score is-<?php echo esc_attr( $tone ); ?>" style="--im-score:<?php echo (int) $score; ?>" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Salud de la biblioteca: %d de 100', 'inpulsia-medios' ), $score ) ); ?>">
				<span><?php echo (int) $score; ?></span><small><?php esc_html_e( 'de 100', 'inpulsia-medios' ); ?></small>
			</div>
			<div class="im-health-text">
				<h2><?php esc_html_e( 'Salud de las imágenes', 'inpulsia-medios' ); ?></h2>
				<p><?php echo esc_html( $score >= 85 ? __( 'La biblioteca está en buena forma. Revisa de vez en cuando las imágenes nuevas.', 'inpulsia-medios' ) : ( $score >= 60 ? __( 'Bien encaminada: completa los pasos del asistente para subir la nota.', 'inpulsia-medios' ) : __( 'Hay margen de mejora: cada paso del asistente sube la nota y mejora el SEO y la velocidad.', 'inpulsia-medios' ) ) ); ?></p>
				<p class="muted"><?php esc_html_e( 'Cuenta el alt (40 %), el peso (30 %), los nombres (20 %) y el formato (10 %).', 'inpulsia-medios' ); ?></p>
			</div>
		</section>

		<h2 class="im-section-title"><?php esc_html_e( 'Asistente rápido', 'inpulsia-medios' ); ?></h2>
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

		<h2 class="im-section-title"><?php esc_html_e( 'Estado de esta web', 'inpulsia-medios' ); ?></h2>
		<div class="im-stats">
			<?php
			inpulsia_medios_stat_card( $s['total'], $s['hidden'] ? __( 'imágenes visibles en la biblioteca', 'inpulsia-medios' ) : __( 'imágenes en la biblioteca', 'inpulsia-medios' ), __( 'Ver todas', 'inpulsia-medios' ), inpulsia_medios_url( 'editar' ) );
			inpulsia_medios_stat_card( $s['used'], __( 'se usan en la web', 'inpulsia-medios' ), __( 'Ver las que se usan', 'inpulsia-medios' ), inpulsia_medios_url( 'editar', [ 'usage' => 'used' ] ) );
			inpulsia_medios_stat_card( $s['missing'], __( 'sin texto alternativo', 'inpulsia-medios' ), $s['missing'] ? __( 'Completarlas', 'inpulsia-medios' ) : '', inpulsia_medios_url( 'editar', [ 'missing_alt' => 1 ] ), $s['missing'] ? 'warn' : 'ok' );
			inpulsia_medios_stat_card( $s['big'], __( 'pesan más de 1 MB', 'inpulsia-medios' ), $s['big'] ? __( 'Verlas y optimizarlas', 'inpulsia-medios' ) : '', inpulsia_medios_url( 'editar', [ 'weight' => '1m' ] ), $s['big'] ? 'warn' : 'ok' );
			inpulsia_medios_stat_card( $s['names'], __( 'con nombre poco amigable', 'inpulsia-medios' ), $s['names'] ? __( 'Verlas y renombrarlas', 'inpulsia-medios' ) : '', inpulsia_medios_url( 'editar', [ 'names' => 'bad' ] ), $s['names'] ? 'warn' : 'ok' );
			inpulsia_medios_stat_card( $s['png'], __( 'en PNG, GIF u otros formatos pesados', 'inpulsia-medios' ), $s['png'] ? __( 'Verlas y optimizarlas', 'inpulsia-medios' ) : '', inpulsia_medios_url( 'editar', [ 'format' => 'heavy' ] ), $s['png'] ? 'warn' : 'ok' );
			?>
		</div>

		<h2 class="im-section-title"><?php esc_html_e( 'Qué puedes hacer', 'inpulsia-medios' ); ?></h2>
		<div class="im-features">
			<div class="im-feature">
				<span class="dashicons dashicons-performance" aria-hidden="true"></span>
				<h3><?php esc_html_e( 'Aligerar las imágenes', 'inpulsia-medios' ); ?></h3>
				<p><?php esc_html_e( 'Convierte a JPG o WebP, reduce las fotos enormes a un tamaño razonable y las comprime sin que se note. Los logos con fondo transparente se respetan.', 'inpulsia-medios' ); ?></p>
			</div>
			<div class="im-feature">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<h3><?php esc_html_e( 'Nombres y textos para Google', 'inpulsia-medios' ); ?></h3>
				<p><?php esc_html_e( 'Cambia', 'inpulsia-medios' ); ?> <code><?php esc_html_e( 'IMG_2041.jpg', 'inpulsia-medios' ); ?></code> <?php esc_html_e( 'por', 'inpulsia-medios' ); ?> <code><?php esc_html_e( 'reforma-cocina-santander.jpg', 'inpulsia-medios' ); ?></code> <?php esc_html_e( 'y añade el texto alternativo. Puedes escribirlos tú o pedir a la IA que los proponga mirando la imagen.', 'inpulsia-medios' ); ?></p>
			</div>
			<div class="im-feature">
				<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
				<h3><?php esc_html_e( 'Cambios en bloque', 'inpulsia-medios' ); ?></h3>
				<p><?php esc_html_e( 'Edita decenas de imágenes en una tabla, o descarga un Excel, rellénalo (o pásaselo al equipo de SEO) y vuelve a subirlo.', 'inpulsia-medios' ); ?></p>
			</div>
			<div class="im-feature">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<h3><?php esc_html_e( 'Sin romper nada', 'inpulsia-medios' ); ?></h3>
				<p><?php esc_html_e( 'Al cambiar un archivo se actualizan las páginas que lo usan, las direcciones antiguas redirigen a las nuevas y cualquier cambio se puede deshacer desde el Historial.', 'inpulsia-medios' ); ?></p>
			</div>
		</div>

		<h2 class="im-section-title"><?php esc_html_e( 'Cómo empezar', 'inpulsia-medios' ); ?></h2>
		<ol class="im-steps">
			<li>
				<strong><?php esc_html_e( 'Elige qué imágenes revisar.', 'inpulsia-medios' ); ?></strong>
				<?php esc_html_e( 'En', 'inpulsia-medios' ); ?> <a href="<?php echo esc_url( inpulsia_medios_url( 'editar', [ 'usage' => 'used' ] ) ); ?>"><?php esc_html_e( 'Editar', 'inpulsia-medios' ); ?></a><?php esc_html_e( ', filtra por', 'inpulsia-medios' ); ?> <em><?php esc_html_e( 'En uso en la web', 'inpulsia-medios' ); ?></em> <?php esc_html_e( 'para centrarte en las que de verdad ven tus visitas.', 'inpulsia-medios' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Optimízalas.', 'inpulsia-medios' ); ?></strong>
				<?php esc_html_e( 'Abre', 'inpulsia-medios' ); ?> <em><?php esc_html_e( 'Optimizar imágenes', 'inpulsia-medios' ); ?></em> <?php esc_html_e( 'y pulsa', 'inpulsia-medios' ); ?> <em><?php esc_html_e( 'Optimizar las del filtro', 'inpulsia-medios' ); ?></em><?php esc_html_e( '. Verás qué se va a hacer con cada una antes de confirmar y, al terminar, cuánto peso has ahorrado.', 'inpulsia-medios' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Completa nombres y textos alternativos.', 'inpulsia-medios' ); ?></strong>
				<?php esc_html_e( 'Escríbelos en la tabla (pulsa Intro para bajar de fila o pega una columna de Excel), usa una plantilla o pulsa', 'inpulsia-medios' ); ?> <em>IA</em><?php esc_html_e( '. Revisa y pulsa', 'inpulsia-medios' ); ?> <em><?php esc_html_e( 'Revisar cambios', 'inpulsia-medios' ); ?></em> <?php esc_html_e( 'y después', 'inpulsia-medios' ); ?> <em><?php esc_html_e( 'Aplicar', 'inpulsia-medios' ); ?></em>.
			</li>
			<li>
				<strong><?php esc_html_e( '¿Algo no te convence?', 'inpulsia-medios' ); ?></strong>
				<?php esc_html_e( 'En', 'inpulsia-medios' ); ?> <a href="<?php echo esc_url( inpulsia_medios_url( 'historial' ) ); ?>"><?php esc_html_e( 'Historial', 'inpulsia-medios' ); ?></a> <?php esc_html_e( 'pulsa', 'inpulsia-medios' ); ?> <em><?php esc_html_e( 'Deshacer', 'inpulsia-medios' ); ?></em> <?php esc_html_e( 'y todo vuelve a como estaba.', 'inpulsia-medios' ); ?>
			</li>
		</ol>

		<div class="im-columns">
			<div class="inpulsia-medios-card">
				<h2><?php esc_html_e( 'Puesta a punto', 'inpulsia-medios' ); ?></h2>
				<ul class="im-checklist">
					<li class="<?php echo $has_ai ? 'is-ok' : 'is-todo'; ?>">
						<?php if ( $has_ai ) : ?>
							<?php esc_html_e( 'Sugerencias con IA activadas.', 'inpulsia-medios' ); ?>
						<?php elseif ( current_user_can( 'manage_options' ) ) : ?>
							<?php esc_html_e( 'Sugerencias con IA desactivadas.', 'inpulsia-medios' ); ?> <a href="<?php echo esc_url( inpulsia_medios_url( 'ajustes' ) ); ?>"><?php esc_html_e( 'Añadir la clave de Claude', 'inpulsia-medios' ); ?></a>.
						<?php else : ?>
							<?php esc_html_e( 'Sugerencias con IA desactivadas: pide a un administrador que las configure.', 'inpulsia-medios' ); ?>
						<?php endif; ?>
					</li>
					<li class="<?php echo $opt['backup'] ? 'is-ok' : 'is-todo'; ?>">
						<?php echo $opt['backup'] ? esc_html__( 'Copia de seguridad activada al optimizar.', 'inpulsia-medios' ) : esc_html__( 'La copia de seguridad está desactivada: las optimizaciones no se podrán deshacer.', 'inpulsia-medios' ); ?>
					</li>
					<li class="<?php echo wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ? 'is-ok' : 'is-info'; ?>">
						<?php echo wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ? esc_html__( 'El servidor puede generar WebP.', 'inpulsia-medios' ) : esc_html__( 'El servidor no genera WebP: se usará JPG.', 'inpulsia-medios' ); ?>
					</li>
					<li class="is-info">
						<?php echo (int) $s['redirects']; ?> <?php esc_html_e( 'redirecciones activas de archivos renombrados.', 'inpulsia-medios' ); ?>
					</li>
					<?php if ( $history ) : ?>
						<li class="is-info"><?php esc_html_e( 'Último cambio:', 'inpulsia-medios' ); ?> <?php echo esc_html( inpulsia_medios_history_label( $history[0]['file'] ) ); ?>, <?php echo esc_html( human_time_diff( strtotime( $history[0]['date'] ), current_time( 'timestamp' ) ) ); ?>.</li>
					<?php endif; ?>
				</ul>
			</div>

			<div class="inpulsia-medios-card">
				<h2><?php esc_html_e( 'Preguntas frecuentes', 'inpulsia-medios' ); ?></h2>
				<details>
					<summary><?php esc_html_e( '¿Puedo romper la web?', 'inpulsia-medios' ); ?></summary>
					<p><?php esc_html_e( 'No debería: antes de aplicar ves una vista previa, las páginas que usan la imagen se actualizan solas y las direcciones antiguas redirigen a las nuevas. Aun así, todo se puede deshacer desde el Historial.', 'inpulsia-medios' ); ?></p>
				</details>
				<details>
					<summary><?php esc_html_e( '¿Se pierde calidad?', 'inpulsia-medios' ); ?></summary>
					<p><?php esc_html_e( 'Con la calidad 82 que viene por defecto, la diferencia no se aprecia a simple vista. Si una imagen no pesa al menos un 5 % menos tras optimizarla, se deja como estaba.', 'inpulsia-medios' ); ?></p>
				</details>
				<details>
					<summary><?php esc_html_e( '¿Qué pasa con los logos y los iconos?', 'inpulsia-medios' ); ?></summary>
					<p><?php esc_html_e( 'Las imágenes con fondo transparente se mantienen en PNG para no perder la transparencia. Los GIF animados no se tocan.', 'inpulsia-medios' ); ?></p>
				</details>
				<details>
					<summary><?php esc_html_e( '¿Qué es el texto alternativo?', 'inpulsia-medios' ); ?></summary>
					<p><?php esc_html_e( 'Una frase que describe la imagen. La leen Google y las personas que usan lectores de pantalla. Mejor concreta y natural, de menos de 125 caracteres: «Cocina reformada con isla de madera en Santander».', 'inpulsia-medios' ); ?></p>
				</details>
				<details>
					<summary><?php esc_html_e( '¿Cuánto cuesta la IA?', 'inpulsia-medios' ); ?></summary>
					<p><?php esc_html_e( 'Cada sugerencia es una consulta a la API de Claude y se cobra en la cuenta de la clave configurada, normalmente céntimos por cada pocas imágenes. Solo se usa cuando pulsas', 'inpulsia-medios' ); ?> <em>IA</em> <?php esc_html_e( 'o activas esa opción al optimizar.', 'inpulsia-medios' ); ?></p>
				</details>
				<details>
					<summary><?php esc_html_e( '¿Y si ya uso ShortPixel, Imagify o similares?', 'inpulsia-medios' ); ?></summary>
					<p><?php esc_html_e( 'El plugin te avisará. Puedes seguir usándolo para nombres, textos y redirecciones, y dejar la compresión al otro plugin para no comprimir dos veces.', 'inpulsia-medios' ); ?></p>
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
		'inicio'        => [ __( 'Cómo funciona', 'inpulsia-medios' ), __( '<p>Inpulsia Medios mejora las imágenes de la biblioteca: menos peso, nombres de archivo claros y texto alternativo. Todo se revisa en una vista previa antes de aplicarse y se puede deshacer desde <strong>Historial</strong>.</p>', 'inpulsia-medios' ) ],
		'editar'        => [ __( 'Editar y optimizar', 'inpulsia-medios' ), __( '<p>Filtra las imágenes (por ejemplo, <em>En uso en la web</em>), escribe los cambios directamente en la tabla o pulsa <strong>IA</strong> para que se propongan, y después <strong>Revisar cambios</strong>.</p><p>Para reducir el peso, abre <strong>Optimizar imágenes</strong> y elige las seleccionadas o todas las del filtro.</p>', 'inpulsia-medios' ) ],
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
