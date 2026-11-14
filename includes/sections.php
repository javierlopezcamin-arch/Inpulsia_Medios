<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 2.0: four sections, one job each.
 *   Resumen   — what needs attention, one button per problem.
 *   Textos    — names, titles and alt texts (the old Editar).
 *   Archivos  — heavy, broken and unused files (old optimize panel, Limpieza and Rendimiento).
 *   Historial — what changed and how to undo it.
 * Ajustes keeps the settings and, folded away, Importar, Exportar and Redirecciones.
 */

// Old tab slugs still work: they open the section (and block) that replaced them.
function inpulsia_medios_tab_aliases() {
	return [
		'limpieza'      => [ 'archivos', 'im-rotas' ],
		'rendimiento'   => [ 'archivos', 'im-pesadas' ],
		'importar'      => [ 'ajustes', 'im-tool-importar' ],
		'exportar'      => [ 'ajustes', 'im-tool-exportar' ],
		'redirecciones' => [ 'ajustes', 'im-tool-redirecciones' ],
	];
}

/* ---------- One list of pending work ---------- */

/**
 * Everything that needs attention, most serious first. The same numbers feed the Resumen,
 * the tab badges and each section, so a problem is always counted once.
 * Each item: key, level (bad|warn|info), count, title, text, cta, url, tab.
 */
function inpulsia_medios_pending( $fresh = false ) {
	static $items = null;
	if ( $items !== null && ! $fresh ) {
		return $items;
	}
	$items = [];
	$add   = function ( $key, $level, $count, $title, $text, $cta, $url, $tab ) use ( &$items ) {
		if ( $count > 0 ) {
			$items[] = compact( 'key', 'level', 'count', 'title', 'text', 'cta', 'url', 'tab' );
		}
	};

	$lost = inpulsia_medios_lost_count();
	$add( 'lost', 'bad', $lost, __( 'Imágenes rotas', 'inpulsia-medios' ), __( 'Están en la biblioteca pero su archivo ya no está en el servidor: la web las muestra rotas.', 'inpulsia-medios' ), __( 'Recuperar', 'inpulsia-medios' ), inpulsia_medios_url( 'archivos' ) . '#im-rotas', 'archivos' );

	$broken = get_transient( 'inpulsia_medios_broken' );
	$add( 'broken', 'bad', $broken ? count( $broken['items'] ) : 0, __( 'Enlaces a imágenes que no existen', 'inpulsia-medios' ), __( 'Páginas que muestran una imagen que ya no está.', 'inpulsia-medios' ), __( 'Reparar', 'inpulsia-medios' ), inpulsia_medios_url( 'archivos' ) . '#im-rotas', 'archivos' );

	$health = function_exists( 'inpulsia_medios_health_result' ) ? inpulsia_medios_health_result() : null;
	$lost_ids = $lost ? wp_list_pluck( inpulsia_medios_lost_files(), 'id' ) : [];
	$no_load  = $health ? count( array_unique( array_diff( wp_list_pluck( $health['failed'], 'id' ), $lost_ids ) ) ) : 0;
	$add( 'health', 'bad', $no_load, __( 'Imágenes cambiadas que no cargan', 'inpulsia-medios' ), __( 'El archivo existe, pero la web no lo sirve (permisos o reglas del servidor).', 'inpulsia-medios' ), __( 'Ver cuáles', 'inpulsia-medios' ), inpulsia_medios_url( 'archivos' ) . '#im-rotas', 'archivos' );

	$add( 'errors', 'warn', count( inpulsia_medios_recent_errors() ), __( 'Optimizaciones fallidas esta semana', 'inpulsia-medios' ), __( 'Se mantuvo la imagen original; conviene ver el motivo.', 'inpulsia-medios' ), __( 'Ver fallos', 'inpulsia-medios' ), inpulsia_medios_url( 'archivos' ) . '#im-errors', 'archivos' );

	$add( 'heavy', 'warn', inpulsia_medios_count( [ 'weight' => '300k' ] ), __( 'Imágenes pesadas', 'inpulsia-medios' ), __( 'Pesan más de 300 KB y hacen la web más lenta.', 'inpulsia-medios' ), __( 'Optimizar', 'inpulsia-medios' ), inpulsia_medios_url( 'archivos' ) . '#im-pesadas', 'archivos' );

	if ( function_exists( 'inpulsia_medios_perf_problem_count' ) ) {
		$add( 'speed', 'warn', inpulsia_medios_perf_problem_count(), __( 'Problemas de velocidad en páginas', 'inpulsia-medios' ), __( 'Imagen principal que carga tarde o imágenes más grandes de lo que se ven.', 'inpulsia-medios' ), __( 'Revisar', 'inpulsia-medios' ), inpulsia_medios_url( 'archivos' ) . '#im-velocidad', 'archivos' );
	}

	$add( 'alt', 'warn', inpulsia_medios_count( [ 'missing_alt' => true ] ), __( 'Sin texto alternativo', 'inpulsia-medios' ), __( 'Google no sabe qué muestran y los lectores de pantalla tampoco.', 'inpulsia-medios' ), __( 'Completar', 'inpulsia-medios' ), inpulsia_medios_url( 'editar', [ 'missing_alt' => 1, 'do' => 'focus' ] ), 'editar' );

	$add( 'names', 'info', inpulsia_medios_count( [ 'names' => 'bad' ] ), __( 'Nombres de archivo poco claros', 'inpulsia-medios' ), __( 'IMG_2041.jpg y similares no dicen nada a Google.', 'inpulsia-medios' ), __( 'Renombrar', 'inpulsia-medios' ), inpulsia_medios_url( 'editar', [ 'names' => 'bad', 'do' => 'focus' ] ), 'editar' );

	if ( function_exists( 'inpulsia_medios_unused_ids' ) ) {
		$add( 'unused', 'info', count( inpulsia_medios_unused_ids() ), __( 'Imágenes sin uso', 'inpulsia-medios' ), __( 'No aparecen en ninguna página. Ocupan espacio y copias.', 'inpulsia-medios' ), __( 'Limpiar', 'inpulsia-medios' ), inpulsia_medios_url( 'archivos' ) . '#im-sinuso', 'archivos' );
	}
	return $items;
}

// Numbers on the tabs: only what is worth acting on (bad + warn), the same figures as the Resumen.
function inpulsia_medios_section_badges() {
	$out = [];
	foreach ( inpulsia_medios_pending() as $p ) {
		if ( $p['level'] === 'info' ) {
			continue;
		}
		$out[ $p['tab'] ] = ( $out[ $p['tab'] ] ?? [ 0, 'warn' ] );
		$out[ $p['tab'] ][0] += $p['count'];
		if ( $p['level'] === 'bad' ) {
			$out[ $p['tab'] ][1] = 'bad';
		}
	}
	return $out;
}

/* ---------- Resumen ---------- */

function inpulsia_medios_tab_resumen() {
	update_user_meta( get_current_user_id(), 'inpulsia_medios_welcomed', 1 );
	$s       = inpulsia_medios_stats();
	$score   = inpulsia_medios_score( $s );
	$tone    = $score >= 85 ? 'ok' : ( $score >= 60 ? 'warn' : 'bad' );
	$pending = inpulsia_medios_pending();
	$labels  = [ 'bad' => __( 'Urgente', 'inpulsia-medios' ), 'warn' => __( 'Recomendado', 'inpulsia-medios' ), 'info' => __( 'Cuando puedas', 'inpulsia-medios' ) ];
	?>
	<div class="im-guide im-resumen">
		<?php inpulsia_medios_alerts_markup(); ?>

		<section class="im-health">
			<div class="im-score is-<?php echo esc_attr( $tone ); ?>" style="--im-score:<?php echo (int) $score; ?>" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Salud de la biblioteca: %d de 100', 'inpulsia-medios' ), $score ) ); ?>">
				<span><?php echo (int) $score; ?></span><small><?php esc_html_e( 'de 100', 'inpulsia-medios' ); ?></small>
			</div>
			<div class="im-health-text">
				<h2><?php echo esc_html( $pending ? __( 'Esto es lo que queda por hacer', 'inpulsia-medios' ) : __( 'Todo en orden', 'inpulsia-medios' ) ); ?></h2>
				<p><?php echo esc_html( sprintf( _n( '%s imagen en la biblioteca.', '%s imágenes en la biblioteca.', $s['total'], 'inpulsia-medios' ), number_format_i18n( $s['total'] ) ) ); ?>
					<?php echo esc_html( $pending ? __( 'Empieza por arriba: lo urgente rompe la web, lo recomendado la hace más lenta o peor posicionada.', 'inpulsia-medios' ) : __( 'No hay nada pendiente. Revisa de vez en cuando las imágenes nuevas.', 'inpulsia-medios' ) ); ?></p>
			</div>
		</section>
		<?php inpulsia_medios_hidden_notice( $s['hidden'] ); ?>

		<?php if ( $pending ) : ?>
			<ol class="im-todo">
				<?php foreach ( $pending as $p ) : ?>
					<li class="is-<?php echo esc_attr( $p['level'] ); ?>">
						<span class="im-todo-count"><?php echo esc_html( number_format_i18n( $p['count'] ) ); ?></span>
						<div class="im-todo-text">
							<span class="im-todo-level"><?php echo esc_html( $labels[ $p['level'] ] ); ?></span>
							<h3><?php echo esc_html( $p['title'] ); ?></h3>
							<p><?php echo esc_html( $p['text'] ); ?></p>
						</div>
						<a class="button <?php echo $p['level'] === 'info' ? '' : 'button-primary'; ?>" href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['cta'] ); ?></a>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

		<details class="inpulsia-medios-card im-faq">
			<summary><strong><?php esc_html_e( 'Cómo funciona y preguntas frecuentes', 'inpulsia-medios' ); ?></strong></summary>
			<p><?php esc_html_e( 'Textos: escribe nombres, títulos y textos alternativos; se guardan solos. Archivos: optimiza, recupera y limpia imágenes, viendo antes qué se hará. Historial: cualquier cambio se puede deshacer.', 'inpulsia-medios' ); ?></p>
			<p><strong><?php esc_html_e( '¿Puedo romper la web?', 'inpulsia-medios' ); ?></strong> <?php esc_html_e( 'Las páginas que usan una imagen se actualizan solas, las direcciones antiguas redirigen a las nuevas y todo se puede deshacer desde el Historial.', 'inpulsia-medios' ); ?></p>
			<p><strong><?php esc_html_e( '¿Se pierde calidad?', 'inpulsia-medios' ); ?></strong> <?php esc_html_e( 'Con la calidad 82 no se aprecia. Si una imagen no pesa al menos un 5 % menos, se deja como estaba. Los logos transparentes se mantienen en PNG.', 'inpulsia-medios' ); ?></p>
			<p><strong><?php esc_html_e( '¿Y la IA?', 'inpulsia-medios' ); ?></strong> <?php esc_html_e( 'Funciona con proveedores gratuitos, con Claude o sin ninguno (entonces propone a partir de la página donde se usa la imagen). Se configura en Ajustes.', 'inpulsia-medios' ); ?></p>
		</details>
	</div>
	<?php
}

/* ---------- Archivos ---------- */

function inpulsia_medios_card_optimize() {
	$o     = inpulsia_medios_opt_settings();
	$heavy = inpulsia_medios_count( [ 'weight' => '300k' ] );
	$big   = inpulsia_medios_count( [ 'weight' => '1m' ] );
	$fmt   = inpulsia_medios_count( [ 'format' => 'heavy' ] );
	$others = array_values( array_filter( [
		defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ) ? 'ShortPixel' : '',
		defined( 'IMAGIFY_VERSION' ) ? 'Imagify' : '',
		defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ? 'EWWW' : '',
		class_exists( 'WP_Smush' ) ? 'Smush' : '',
		defined( 'OPTIMOLE_VERSION' ) ? 'Optimole' : '',
	] ) );
	?>
	<div class="inpulsia-medios-card im-clean-card im-opt-card">
		<h2><span class="dashicons dashicons-performance" aria-hidden="true"></span> <?php esc_html_e( 'Imágenes pesadas', 'inpulsia-medios' ); ?></h2>
		<p class="im-clean-figure"><strong><?php echo esc_html( number_format_i18n( $heavy ) ); ?></strong> <?php esc_html_e( 'pesan más de 300 KB', 'inpulsia-medios' ); ?>
			<?php if ( $big ) : ?><span class="muted">· <?php echo esc_html( sprintf( __( '%d de ellas más de 1 MB', 'inpulsia-medios' ), $big ) ); ?></span><?php endif; ?></p>
		<p><?php esc_html_e( 'Se convierten, se reducen si son enormes y se comprimen sin que se note. Antes verás qué se hará con cada una.', 'inpulsia-medios' ); ?></p>
		<p class="description"><?php echo esc_html( sprintf( __( 'Se usará: %1$s, máximo %2$d px, calidad %3$d, %4$s.', 'inpulsia-medios' ), strtoupper( $o['format'] ), $o['max_width'], $o['quality'], $o['backup'] ? __( 'con copia para deshacer', 'inpulsia-medios' ) : __( 'sin copia', 'inpulsia-medios' ) ) ); ?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?><a href="<?php echo esc_url( inpulsia_medios_url( 'ajustes' ) . '#im-opt-settings' ); ?>"><?php esc_html_e( 'Cambiar', 'inpulsia-medios' ); ?></a><?php endif; ?></p>
		<?php if ( $others ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( __( 'Esta web ya usa %s: optimizar también aquí puede comprimir dos veces.', 'inpulsia-medios' ), implode( ', ', $others ) ) ); ?></p></div>
		<?php endif; ?>
		<p>
			<button type="button" class="button button-primary im-opt-run" data-total="<?php echo (int) $heavy; ?>" data-params="<?php echo esc_attr( wp_json_encode( [ 'weight' => '300k' ] ) ); ?>" <?php disabled( ! $heavy ); ?>><?php echo esc_html( sprintf( __( 'Optimizar las %d', 'inpulsia-medios' ), $heavy ) ); ?></button>
			<?php if ( $fmt ) : ?>
				<button type="button" class="button im-opt-run" data-total="<?php echo (int) $fmt; ?>" data-params="<?php echo esc_attr( wp_json_encode( [ 'format' => 'heavy' ] ) ); ?>"><?php echo esc_html( sprintf( __( 'Convertir las %d en PNG o GIF', 'inpulsia-medios' ), $fmt ) ); ?></button>
			<?php endif; ?>
		</p>
		<p class="description"><?php esc_html_e( 'Para optimizar otras, márcalas en Textos y elige «Optimizar» en la barra de selección.', 'inpulsia-medios' ); ?></p>
	</div>
	<?php
}

function inpulsia_medios_tab_archivos() {
	if ( ! empty( $_GET['refresh_usage'] ) ) {
		inpulsia_medios_used_ids( true );
	}
	if ( ! empty( $_GET['scan'] ) ) {
		inpulsia_medios_broken_scan();
	}
	$counts = [];
	foreach ( inpulsia_medios_pending() as $p ) {
		$counts[ $p['key'] ] = $p['count'];
	}
	$rotas = ( $counts['lost'] ?? 0 ) + ( $counts['broken'] ?? 0 ) + ( $counts['health'] ?? 0 );
	$nav   = [
		'im-pesadas' => [ __( 'Pesadas y lentas', 'inpulsia-medios' ), ( $counts['heavy'] ?? 0 ) + ( $counts['speed'] ?? 0 ) ],
		'im-rotas'   => [ __( 'Rotas', 'inpulsia-medios' ), $rotas ],
		'im-sinuso'  => [ __( 'Sin uso o duplicadas', 'inpulsia-medios' ), $counts['unused'] ?? 0 ],
	];
	?>
	<div id="im-clean" class="im-archivos">
		<nav class="im-subnav im-blocks" aria-label="<?php esc_attr_e( 'Bloques de Archivos', 'inpulsia-medios' ); ?>">
			<?php foreach ( $nav as $anchor => $n ) : ?>
				<a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $n[0] ); ?><?php if ( $n[1] ) : ?> <span class="im-badge <?php echo $anchor === 'im-rotas' ? 'is-bad' : ''; ?>"><?php echo esc_html( number_format_i18n( $n[1] ) ); ?></span><?php endif; ?></a>
			<?php endforeach; ?>
		</nav>
		<p class="description"><?php esc_html_e( 'Antes de cambiar o borrar nada verás la lista completa, y todo se puede deshacer desde el Historial.', 'inpulsia-medios' ); ?></p>

		<section id="im-pesadas" class="im-block">
			<h2 class="im-section-title"><?php esc_html_e( 'Pesadas y lentas', 'inpulsia-medios' ); ?></h2>
			<div class="im-block-grid">
				<?php inpulsia_medios_card_optimize(); ?>
				<?php inpulsia_medios_card_analysis(); ?>
			</div>
			<div id="im-velocidad">
				<?php inpulsia_medios_card_lcp(); ?>
				<?php inpulsia_medios_card_oversized(); ?>
			</div>
		</section>

		<section id="im-rotas" class="im-block">
			<h2 class="im-section-title"><?php esc_html_e( 'Rotas', 'inpulsia-medios' ); ?></h2>
			<?php if ( ! inpulsia_medios_card_lost() ) : ?>
				<p class="im-ok-line"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Todas las imágenes de la biblioteca tienen su archivo.', 'inpulsia-medios' ); ?></p>
			<?php endif; ?>
			<div class="im-block-grid">
				<?php inpulsia_medios_card_broken(); ?>
				<?php inpulsia_medios_card_health(); ?>
			</div>
			<?php inpulsia_medios_card_errors(); ?>
		</section>

		<section id="im-sinuso" class="im-block">
			<h2 class="im-section-title"><?php esc_html_e( 'Sin uso o duplicadas', 'inpulsia-medios' ); ?></h2>
			<div class="im-block-grid">
				<?php inpulsia_medios_card_unused(); ?>
				<?php inpulsia_medios_card_dupes(); ?>
			</div>
		</section>
	</div>
	<?php
	inpulsia_medios_preview_markup();
}

/* ---------- Ajustes: advanced tools ---------- */

function inpulsia_medios_tools_markup( $open = '' ) {
	$tools = [
		'importar'      => [ __( 'Importar un Excel o CSV', 'inpulsia-medios' ), __( 'Cambiar nombres y textos de muchas imágenes con una hoja de cálculo.', 'inpulsia-medios' ) ],
		'exportar'      => [ __( 'Exportar a Excel o CSV', 'inpulsia-medios' ), __( 'Descargar los datos de las imágenes para revisarlos o pasárselos a alguien.', 'inpulsia-medios' ) ],
		'redirecciones' => [ __( 'Redirecciones', 'inpulsia-medios' ), __( 'Direcciones antiguas de archivos renombrados y a dónde llevan.', 'inpulsia-medios' ) ],
	];
	?>
	<h2 class="im-section-title" id="im-tools"><?php esc_html_e( 'Herramientas avanzadas', 'inpulsia-medios' ); ?></h2>
	<?php foreach ( $tools as $slug => $t ) : ?>
		<details class="im-tool" id="im-tool-<?php echo esc_attr( $slug ); ?>" <?php echo $open === $slug ? 'open' : ''; ?>>
			<summary><strong><?php echo esc_html( $t[0] ); ?></strong> <span class="muted"><?php echo esc_html( $t[1] ); ?></span></summary>
			<?php call_user_func( 'inpulsia_medios_tab_' . $slug ); ?>
		</details>
	<?php endforeach; ?>
	<?php
}
