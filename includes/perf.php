<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 1.9: daily check of optimized images, and a page analysis that measures, in a real browser,
 * which images are much bigger than shown and which image is the LCP of each page.
 */

add_action( 'init', 'inpulsia_medios_schedule_health' );
add_action( 'inpulsia_medios_health_check', 'inpulsia_medios_health_run' );
add_action( 'wp_enqueue_scripts', 'inpulsia_medios_perf_beacon' );
add_action( 'wp_ajax_inpulsia_medios_perf_report', 'inpulsia_medios_ajax_perf_report' );
add_filter( 'wp_get_attachment_image_attributes', 'inpulsia_medios_lcp_attributes', 20, 2 );
add_filter( 'wp_content_img_tag', 'inpulsia_medios_lcp_content_tag', 20, 3 );

/* ---------- 1. Daily check of recently changed images ---------- */

function inpulsia_medios_schedule_health() {
	if ( ! wp_next_scheduled( 'inpulsia_medios_health_check' ) ) {
		wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'inpulsia_medios_health_check' );
	}
}

// Images touched by Inpulsia in the last $days days (optimizations, renames, restores), newest first.
function inpulsia_medios_recent_ids( $days = 30, $limit = 40 ) {
	$ids  = [];
	$from = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	foreach ( inpulsia_medios_history() as $e ) {
		if ( $e['date'] < $from || $e['status'] === 'deshecho' ) {
			continue;
		}
		foreach ( array_reverse( (array) get_option( 'inpulsia_medios_log_' . $e['token'], [] ) ) as $item ) {
			$ids[ (int) $item['id'] ] = true;
		}
	}
	$ids = array_filter( array_keys( $ids ), function ( $id ) {
		return get_post_type( $id ) === 'attachment';
	} );
	return array_slice( array_values( $ids ), 0, $limit );
}

/**
 * Requests each recently changed image (main file and medium size) as a visitor would.
 * Stores { time, checked, failed: [ { id, url, code } ] }.
 */
function inpulsia_medios_health_run() {
	$failed  = [];
	$checked = 0;
	foreach ( inpulsia_medios_recent_ids() as $id ) {
		$urls = array_unique( array_filter( [ wp_get_attachment_url( $id ), wp_get_attachment_image_url( $id, 'medium' ) ] ) );
		foreach ( $urls as $url ) {
			$checked++;
			$file = get_attached_file( $id );
			$code = 0;
			if ( $url === wp_get_attachment_url( $id ) && ( ! $file || ! file_exists( $file ) ) ) {
				$code = 404;
			} else {
				$res = wp_remote_head( $url, [ 'timeout' => 8, 'redirection' => 2, 'sslverify' => false ] );
				if ( ! is_wp_error( $res ) ) {
					$code = (int) wp_remote_retrieve_response_code( $res );
					$code = $code >= 400 ? $code : 0;
				}
			}
			if ( $code ) {
				$failed[] = [ 'id' => $id, 'url' => $url, 'code' => $code ];
			}
		}
	}
	$result = [ 'time' => time(), 'checked' => $checked, 'failed' => $failed ];
	update_option( 'inpulsia_medios_health', $result, false );
	delete_transient( 'inpulsia_medios_lost_count' );
	return $result;
}

function inpulsia_medios_health_result() {
	$h = get_option( 'inpulsia_medios_health' );
	return is_array( $h ) ? $h : null;
}

/* ---------- 2. In-browser measurement ---------- */

/*
 * Runs only for users who can edit media, and only when the page is opened by the analysis
 * (?inpulsia_perf=1) or the user browses the site normally while logged in.
 */
function inpulsia_medios_perf_beacon() {
	if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) || is_admin() ) {
		return;
	}
	$id = (int) get_queried_object_id();
	if ( ! $id || ! is_singular() && ! is_front_page() ) {
		return;
	}
	wp_enqueue_script( 'inpulsia-medios-perf', INPULSIA_MEDIOS_URL . 'assets/perf-beacon.js', [], INPULSIA_MEDIOS_VERSION, true );
	wp_localize_script( 'inpulsia-medios-perf', 'inpulsiaMediosPerf', [
		'ajax'  => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'inpulsia_medios_perf' ),
		'page'  => $id,
		'url'   => get_permalink( $id ),
	] );
}

function inpulsia_medios_perf_data() {
	$d = get_option( 'inpulsia_medios_perf', [] );
	return is_array( $d ) ? $d : [];
}

// Receives the measurements of one page view (desktop or mobile) and keeps the latest per page and device.
function inpulsia_medios_ajax_perf_report() {
	check_ajax_referer( 'inpulsia_medios_perf', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error();
	}
	$page   = absint( $_POST['page'] ?? 0 );
	$device = sanitize_key( $_POST['device'] ?? '' ) === 'mobile' ? 'mobile' : 'desktop';
	$raw    = json_decode( (string) wp_unslash( $_POST['data'] ?? '' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is cast below.
	if ( ! $page || ! get_post( $page ) || ! is_array( $raw ) ) {
		wp_send_json_error();
	}
	$img = function ( $i ) {
		$src = esc_url_raw( (string) ( $i['src'] ?? '' ) );
		$id  = absint( $i['id'] ?? 0 );
		if ( ! $id && $src && function_exists( 'inpulsia_medios_url_to_attachment' ) ) {
			$id = inpulsia_medios_url_to_attachment( $src );
		}
		return [
			'id'      => $id,
			'src'     => $src,
			'natural' => absint( $i['natural'] ?? 0 ),
			'shown'   => absint( $i['shown'] ?? 0 ),
			'bytes'   => absint( $i['bytes'] ?? 0 ),
			'lazy'    => ! empty( $i['lazy'] ),
			'fp'      => sanitize_key( $i['fp'] ?? '' ),
			'bg'      => ! empty( $i['bg'] ),
		];
	};
	$over = [];
	foreach ( array_slice( (array) ( $raw['over'] ?? [] ), 0, 30 ) as $i ) {
		$over[] = $img( (array) $i );
	}
	$data                     = inpulsia_medios_perf_data();
	$data[ $page ]            = $data[ $page ] ?? [];
	$data[ $page ][ $device ] = [
		'time' => time(),
		'lcp'  => ! empty( $raw['lcp'] ) ? $img( (array) $raw['lcp'] ) + [ 'ms' => absint( $raw['lcp']['ms'] ?? 0 ) ] : null,
		'over' => $over,
	];
	update_option( 'inpulsia_medios_perf', array_slice( $data, -200, null, true ), false );
	wp_send_json_success();
}

/* ---------- 3. LCP fixes on the front end ---------- */

// Pages whose LCP image gets loading="eager" and fetchpriority="high". [ page_id => attachment_id ].
function inpulsia_medios_lcp_fixes() {
	$f = get_option( 'inpulsia_medios_lcp_fix', [] );
	return is_array( $f ) ? $f : [];
}

function inpulsia_medios_lcp_target() {
	if ( is_admin() ) {
		return 0;
	}
	$page = (int) get_queried_object_id();
	return $page ? (int) ( inpulsia_medios_lcp_fixes()[ $page ] ?? 0 ) : 0;
}

function inpulsia_medios_lcp_attributes( $attr, $attachment ) {
	$target = inpulsia_medios_lcp_target();
	if ( $target && (int) $attachment->ID === $target ) {
		$attr['loading']       = 'eager';
		$attr['fetchpriority'] = 'high';
		$attr['decoding']      = 'async';
	} elseif ( $target && ( $attr['fetchpriority'] ?? '' ) === 'high' ) {
		unset( $attr['fetchpriority'] ); // Only one image should compete for priority.
	}
	return $attr;
}

function inpulsia_medios_lcp_content_tag( $tag, $context, $attachment_id ) {
	$target = inpulsia_medios_lcp_target();
	if ( ! $target || (int) $attachment_id !== $target ) {
		return $tag;
	}
	$tag = preg_replace( '/\s(loading|fetchpriority)="[^"]*"/', '', $tag );
	return str_replace( '<img ', '<img loading="eager" fetchpriority="high" ', $tag );
}

/* ---------- Reading the results ---------- */

// Pages worth analysing: the home page, published pages and the latest posts.
function inpulsia_medios_perf_pages( $limit = 20 ) {
	$ids = [];
	if ( get_option( 'page_on_front' ) ) {
		$ids[] = (int) get_option( 'page_on_front' );
	}
	$ids = array_merge( $ids, get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => $limit, 'orderby' => 'menu_order date', 'fields' => 'ids' ] ) );
	$ids = array_merge( $ids, get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 5, 'fields' => 'ids' ] ) );
	return array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, $limit );
}

/**
 * Turns the raw measurements into two lists.
 * oversized: [ id, src, natural, shown, bytes, waste, pages[] ] — images served more than twice as wide as shown.
 * lcp: [ page, device, id, src, ms, lazy, fp, bg, problem ] — one per page and device.
 */
function inpulsia_medios_perf_issues() {
	$over = [];
	$lcp  = [];
	foreach ( inpulsia_medios_perf_data() as $page => $devices ) {
		if ( ! get_post( $page ) ) {
			continue;
		}
		foreach ( $devices as $device => $d ) {
			foreach ( $d['over'] as $i ) {
				$key = $i['id'] ? 'id' . $i['id'] : $i['src'];
				if ( ! isset( $over[ $key ] ) || $i['shown'] > $over[ $key ]['shown'] ) {
					$ratio        = $i['natural'] ? min( 1, ( $i['shown'] / $i['natural'] ) ** 2 ) : 1;
					$pages        = isset( $over[ $key ] ) ? $over[ $key ]['pages'] : [];
					$over[ $key ] = $i + [ 'waste' => (int) ( $i['bytes'] * ( 1 - $ratio ) ), 'pages' => $pages ];
				}
				$over[ $key ]['pages'][ $page ] = true;
			}
			if ( ! empty( $d['lcp'] ) ) {
				$l       = $d['lcp'];
				$problem = [];
				if ( $l['lazy'] ) {
					$problem[] = __( 'tiene carga diferida (lazy): el navegador la pide tarde', 'inpulsia-medios' );
				}
				if ( ! $l['bg'] && $l['fp'] !== 'high' ) {
					$problem[] = __( 'no tiene prioridad alta (fetchpriority)', 'inpulsia-medios' );
				}
				if ( $l['bg'] ) {
					$problem[] = __( 'es un fondo CSS: el navegador la descubre tarde', 'inpulsia-medios' );
				}
				if ( $l['bytes'] > 200 * KB_IN_BYTES ) {
					$problem[] = sprintf( __( 'pesa %s', 'inpulsia-medios' ), size_format( $l['bytes'] ) );
				}
				$lcp[] = $l + [ 'page' => (int) $page, 'device' => $device, 'problem' => $problem ];
			}
		}
	}
	usort( $over, function ( $a, $b ) {
		return $b['waste'] <=> $a['waste'];
	} );
	$over = array_values( array_filter( $over, function ( $i ) {
		return $i['waste'] > 20 * KB_IN_BYTES;
	} ) );
	return [ 'oversized' => $over, 'lcp' => $lcp ];
}

function inpulsia_medios_perf_problem_count() {
	$i = inpulsia_medios_perf_issues();
	$n = count( $i['oversized'] );
	foreach ( $i['lcp'] as $l ) {
		$n += $l['problem'] ? 1 : 0;
	}
	return $n;
}

/* ---------- Admin: Rendimiento tab ---------- */

add_action( 'wp_ajax_inpulsia_medios_health_now', 'inpulsia_medios_ajax_health_now' );
add_action( 'wp_ajax_inpulsia_medios_lcp_fix', 'inpulsia_medios_ajax_lcp_fix' );

function inpulsia_medios_ajax_health_now() {
	inpulsia_medios_check_ajax();
	wp_send_json_success( inpulsia_medios_health_run() );
}

function inpulsia_medios_ajax_lcp_fix() {
	inpulsia_medios_check_ajax();
	$page = absint( $_POST['page'] ?? 0 );
	$id   = absint( $_POST['id'] ?? 0 );
	if ( ! $page || ! current_user_can( 'edit_post', $page ) ) {
		wp_send_json_error( __( 'No tienes permisos para editar esta página.', 'inpulsia-medios' ) );
	}
	$fixes = inpulsia_medios_lcp_fixes();
	if ( $id && get_post_type( $id ) === 'attachment' ) {
		$fixes[ $page ] = $id;
	} else {
		unset( $fixes[ $page ] );
	}
	update_option( 'inpulsia_medios_lcp_fix', $fixes );
	if ( function_exists( 'inpulsia_medios_purge_caches' ) ) {
		inpulsia_medios_purge_caches();
	}
	wp_send_json_success();
}

function inpulsia_medios_tab_rendimiento() {
	$health = inpulsia_medios_health_result();
	$issues = inpulsia_medios_perf_issues();
	$pages  = inpulsia_medios_perf_pages();
	$fixes  = inpulsia_medios_lcp_fixes();
	$urls   = array_map( function ( $id ) {
		return add_query_arg( 'inpulsia_perf', 1, get_permalink( $id ) );
	}, $pages );
	$data   = inpulsia_medios_perf_data();
	$last   = $data ? max( array_map( function ( $d ) {
		return max( wp_list_pluck( $d, 'time' ) );
	}, $data ) ) : 0;
	?>
	<div id="im-perf">
		<div class="inpulsia-medios-card">
			<h2><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Comprobación de las imágenes cambiadas', 'inpulsia-medios' ); ?></h2>
			<p><?php esc_html_e( 'Cada día se abren, como lo haría un visitante, las imágenes optimizadas o renombradas en los últimos 30 días. Si alguna no carga, se avisa en Inicio.', 'inpulsia-medios' ); ?></p>
			<?php if ( $health ) : ?>
				<p class="im-clean-figure">
					<?php if ( $health['failed'] ) : ?>
						<strong class="im-text-bad"><?php echo esc_html( sprintf( _n( '%d imagen no carga', '%d imágenes no cargan', count( $health['failed'] ), 'inpulsia-medios' ), count( $health['failed'] ) ) ); ?></strong>
					<?php else : ?>
						<strong class="im-text-ok"><?php esc_html_e( 'Todas cargan bien', 'inpulsia-medios' ); ?></strong>
					<?php endif; ?>
					<span class="muted">· <?php echo esc_html( sprintf( __( '%1$d comprobadas hace %2$s', 'inpulsia-medios' ), $health['checked'], human_time_diff( $health['time'] ) ) ); ?></span>
				</p>
				<?php if ( $health['failed'] ) : ?>
					<table class="wp-list-table widefat striped">
						<thead><tr><th>ID</th><th><?php esc_html_e( 'Dirección', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Respuesta', 'inpulsia-medios' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $health['failed'] as $f ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $f['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo (int) $f['id']; ?></a></td>
								<td><code><?php echo esc_html( $f['url'] ); ?></code></td>
								<td><?php echo (int) $f['code']; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p><a class="button" href="<?php echo esc_url( inpulsia_medios_url( 'limpieza' ) ); ?>"><?php esc_html_e( 'Ir a Limpieza para recuperarlas', 'inpulsia-medios' ); ?></a></p>
				<?php endif; ?>
			<?php else : ?>
				<p class="muted"><?php esc_html_e( 'Todavía no se ha hecho ninguna comprobación.', 'inpulsia-medios' ); ?></p>
			<?php endif; ?>
			<p><button type="button" class="button" id="im-health-now"><?php esc_html_e( 'Comprobar ahora', 'inpulsia-medios' ); ?></button></p>
		</div>

		<div class="inpulsia-medios-card">
			<h2><span class="dashicons dashicons-dashboard" aria-hidden="true"></span> <?php esc_html_e( 'Análisis de páginas', 'inpulsia-medios' ); ?></h2>
			<p><?php esc_html_e( 'Se abren las páginas principales en este navegador, en tamaño escritorio y móvil, y se mide qué imagen tarda más en aparecer (la que más pesa en Core Web Vitals) y qué imágenes se sirven mucho más grandes de lo que se ven.', 'inpulsia-medios' ); ?></p>
			<p class="description"><?php esc_html_e( 'También se mide al navegar por la web con tu sesión iniciada, una vez al día por página.', 'inpulsia-medios' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="im-perf-run" data-urls="<?php echo esc_attr( wp_json_encode( array_values( $urls ) ) ); ?>"><?php echo esc_html( sprintf( __( 'Analizar %d páginas', 'inpulsia-medios' ), count( $urls ) ) ); ?></button>
				<?php if ( $last ) : ?><span class="muted"><?php echo esc_html( sprintf( __( 'Último análisis hace %s', 'inpulsia-medios' ), human_time_diff( $last ) ) ); ?></span><?php endif; ?>
			</p>
			<div id="im-perf-progress" hidden>
				<div class="inpulsia-medios-progress"><div></div></div>
				<p class="im-perf-status" aria-live="polite"></p>
				<div class="im-perf-frames" aria-hidden="true"></div>
			</div>
		</div>

		<div class="inpulsia-medios-card">
			<h2><span class="dashicons dashicons-star-filled" aria-hidden="true"></span> <?php esc_html_e( 'Imagen principal de cada página (LCP)', 'inpulsia-medios' ); ?></h2>
			<p><?php esc_html_e( 'Es la imagen grande que se ve al entrar. Google mide cuánto tarda en aparecer: debe cargarse la primera, sin carga diferida y con prioridad alta.', 'inpulsia-medios' ); ?></p>
			<?php if ( ! $issues['lcp'] ) : ?>
				<p class="muted"><?php esc_html_e( 'Sin datos todavía: pulsa «Analizar páginas».', 'inpulsia-medios' ); ?></p>
			<?php else : ?>
				<div class="inpulsia-medios-scroll">
				<table class="wp-list-table widefat striped im-lcp">
					<thead><tr><th><?php esc_html_e( 'Página', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Dispositivo', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Imagen', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Aparece en', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Problemas', 'inpulsia-medios' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $issues['lcp'] as $l ) : ?>
						<?php $fixed = ( $fixes[ $l['page'] ] ?? 0 ) === $l['id'] && $l['id']; ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_permalink( $l['page'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $l['page'] ) ?: '#' . $l['page'] ); ?></a></td>
							<td><?php echo esc_html( $l['device'] === 'mobile' ? __( 'Móvil', 'inpulsia-medios' ) : __( 'Escritorio', 'inpulsia-medios' ) ); ?></td>
							<td class="im-lcp-img"><?php echo $l['id'] ? wp_get_attachment_image( $l['id'], [ 48, 48 ] ) : ''; ?> <code><?php echo esc_html( wp_basename( (string) wp_parse_url( $l['src'], PHP_URL_PATH ) ) ); ?></code></td>
							<td><?php echo $l['ms'] ? esc_html( number_format_i18n( $l['ms'] / 1000, 1 ) . ' s' ) : '—'; ?></td>
							<td>
								<?php if ( $fixed ) : ?>
									<span class="im-text-ok"><?php esc_html_e( 'Arreglada: carga la primera con prioridad alta', 'inpulsia-medios' ); ?></span>
								<?php elseif ( $l['problem'] ) : ?>
									<?php echo esc_html( ucfirst( implode( '; ', $l['problem'] ) ) ); ?>.
								<?php else : ?>
									<span class="im-text-ok"><?php esc_html_e( 'Correcta', 'inpulsia-medios' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $fixed ) : ?>
									<button type="button" class="button-link im-lcp-fix" data-page="<?php echo (int) $l['page']; ?>" data-id="0"><?php esc_html_e( 'Quitar arreglo', 'inpulsia-medios' ); ?></button>
								<?php elseif ( $l['id'] && ( $l['lazy'] || $l['fp'] !== 'high' ) ) : ?>
									<button type="button" class="button button-small im-lcp-fix" data-page="<?php echo (int) $l['page']; ?>" data-id="<?php echo (int) $l['id']; ?>"><?php esc_html_e( 'Arreglar', 'inpulsia-medios' ); ?></button>
								<?php elseif ( $l['bg'] ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $l['page'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Cambiar el fondo por una imagen', 'inpulsia-medios' ); ?></a>
								<?php endif; ?>
								<?php if ( $l['id'] && $l['bytes'] > 200 * KB_IN_BYTES ) : ?>
									<a href="<?php echo esc_url( inpulsia_medios_url( 'editar', [ 'ids' => $l['id'], 'do' => 'optimize' ] ) ); ?>"><?php esc_html_e( 'Optimizar', 'inpulsia-medios' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="description"><?php esc_html_e( '«Arreglar» quita la carga diferida y da prioridad alta a esa imagen solo en esa página. Si usas un plugin de caché, se vacía solo; si no, vacíala tú.', 'inpulsia-medios' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="inpulsia-medios-card">
			<h2><span class="dashicons dashicons-image-crop" aria-hidden="true"></span> <?php esc_html_e( 'Imágenes más grandes de lo que se ven', 'inpulsia-medios' ); ?></h2>
			<p><?php esc_html_e( 'El navegador descarga la imagen entera aunque se muestre pequeña. Aquí salen las que tienen más del doble de los píxeles necesarios, ordenadas por lo que se desperdicia.', 'inpulsia-medios' ); ?></p>
			<?php if ( ! $issues['oversized'] ) : ?>
				<p class="muted"><?php echo esc_html( $data ? __( 'Ninguna: todas se sirven a un tamaño adecuado.', 'inpulsia-medios' ) : __( 'Sin datos todavía: pulsa «Analizar páginas».', 'inpulsia-medios' ) ); ?></p>
			<?php else : ?>
				<div class="inpulsia-medios-scroll">
				<table class="wp-list-table widefat striped im-oversized">
					<thead><tr><th><?php esc_html_e( 'Imagen', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Archivo / se ve a', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Se desperdicia', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Páginas', 'inpulsia-medios' ); ?></th><th><?php esc_html_e( 'Qué hacer', 'inpulsia-medios' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $issues['oversized'], 0, 50 ) as $o ) : ?>
						<tr>
							<td class="im-lcp-img"><?php echo $o['id'] ? wp_get_attachment_image( $o['id'], [ 48, 48 ] ) : ''; ?> <code><?php echo esc_html( wp_basename( (string) wp_parse_url( $o['src'], PHP_URL_PATH ) ) ); ?></code></td>
							<td><?php echo esc_html( sprintf( '%1$d px / %2$d px', $o['natural'], $o['shown'] ) ); ?></td>
							<td><?php echo esc_html( $o['bytes'] ? sprintf( __( '≈ %s', 'inpulsia-medios' ), size_format( $o['waste'] ) ) : '—' ); ?></td>
							<td>
								<?php foreach ( array_slice( array_keys( $o['pages'] ), 0, 3 ) as $p ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $p ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_html_excerpt( get_the_title( $p ), 24, '…' ) ); ?></a><br>
								<?php endforeach; ?>
							</td>
							<td>
								<?php if ( $o['id'] && $o['natural'] > 2560 ) : ?>
									<a class="button button-small" href="<?php echo esc_url( inpulsia_medios_url( 'editar', [ 'ids' => $o['id'], 'do' => 'optimize' ] ) ); ?>"><?php esc_html_e( 'Optimizar (reduce a 2560 px)', 'inpulsia-medios' ); ?></a>
								<?php else : ?>
									<?php echo esc_html( sprintf( __( 'En la página, elige un tamaño de unos %d px (Mediano o Grande) en vez de «Completo».', 'inpulsia-medios' ), (int) ( ceil( $o['shown'] / 100 ) * 100 ) ) ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
