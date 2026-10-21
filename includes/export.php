<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_inpulsia_medios_export', 'inpulsia_medios_export' );
add_action( 'admin_post_inpulsia_medios_template', 'inpulsia_medios_template' );

function inpulsia_medios_template() {
	check_admin_referer( 'inpulsia_medios_template' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( 'No tienes permisos.' );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="plantilla-inpulsia-medios.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, [ 'ID', 'filename', 'title', 'alt', 'caption', 'description' ], ';' );
	fputcsv( $out, [ '123', 'reforma-cocina-santander', 'Reforma de cocina en Santander', 'Cocina reformada con encimera de madera y muebles blancos', '', INPULSIA_MEDIOS_CLEAR ], ';' );
	fclose( $out );
	exit;
}

function inpulsia_medios_export() {
	check_admin_referer( 'inpulsia_medios_export' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( 'No tienes permisos para exportar medios.' );
	}
	global $wpdb;

	$type    = ( $_GET['type'] ?? 'image' ) === 'all' ? 'all' : 'image';
	$missing = ! empty( $_GET['missing_alt'] );
	$usage   = ! empty( $_GET['usage'] );
	$date    = function ( $key ) {
		$v = sanitize_text_field( $_GET[ $key ] ?? '' );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	};
	$from = $date( 'from' );
	$to   = $date( 'to' );

	$args = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	];
	if ( $type === 'image' || $missing ) {
		$args['post_mime_type'] = 'image';
	}
	if ( $missing ) {
		$args['meta_query'] = [
			'relation' => 'OR',
			[ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
			[ 'key' => '_wp_attachment_image_alt', 'value' => '' ],
		];
	}
	if ( $from || $to ) {
		$args['date_query'] = [ array_filter( [ 'after' => $from, 'before' => $to, 'inclusive' => true ] ) ];
	}
	$ids = get_posts( $args );

	$featured = [];
	$gallery  = [];
	if ( $usage ) {
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" ) as $r ) {
			$featured[ (int) $r->meta_value ][] = (int) $r->post_id;
		}
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> ''" ) as $r ) {
			foreach ( array_map( 'intval', explode( ',', $r->meta_value ) ) as $img ) {
				$gallery[ $img ][] = (int) $r->post_id;
			}
		}
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="medios-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );

	$header = [ 'ID', 'filename', 'title', 'alt', 'caption', 'description', 'url' ];
	if ( $usage ) {
		$header[] = 'usado_en';
	}
	fputcsv( $out, $header, ';' );

	foreach ( $ids as $id ) {
		$cur  = inpulsia_medios_current( $id );
		$line = [ $id, $cur['filename'], $cur['title'], $cur['alt'], $cur['caption'], $cur['description'], wp_get_attachment_url( $id ) ];
		if ( $usage ) {
			$line[] = implode( ' | ', inpulsia_medios_usage( $id, $cur['filename'], $featured[ $id ] ?? [], $gallery[ $id ] ?? [] ) );
		}
		fputcsv( $out, $line, ';' );
	}
	fclose( $out );
	exit;
}

function inpulsia_medios_usage( $id, $stem, $featured, $gallery ) {
	global $wpdb;
	$uses  = [];
	$label = function ( $pid, $prefix ) {
		$p = get_post( $pid );
		if ( ! $p || in_array( $p->post_status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
			return null;
		}
		$pto = get_post_type_object( $p->post_type );
		return $prefix . ': ' . ( $p->post_title !== '' ? $p->post_title : '#' . $p->ID ) . ' (' . ( $pto ? $pto->labels->singular_name : $p->post_type ) . ')';
	};

	foreach ( $featured as $pid ) {
		$uses[] = $label( $pid, 'Destacada' );
	}
	foreach ( $gallery as $pid ) {
		$uses[] = $label( $pid, 'Galería' );
	}

	$rel    = dirname( (string) get_post_meta( $id, '_wp_attached_file', true ) );
	$prefix = '/' . ( $rel === '.' ? '' : $rel . '/' ) . $stem;
	$like   = '%' . $wpdb->esc_like( $prefix ) . '%';
	$like_e = '%' . $wpdb->esc_like( str_replace( '/', '\/', $prefix ) ) . '%';

	$in_content = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_content LIKE %s LIMIT 10",
		$like
	) );
	foreach ( $in_content as $pid ) {
		$uses[] = $label( $pid, 'Contenido' );
	}

	$in_elementor = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND (meta_value LIKE %s OR meta_value LIKE %s) LIMIT 10",
		$like,
		$like_e
	) );
	foreach ( $in_elementor as $pid ) {
		$uses[] = $label( $pid, 'Elementor' );
	}

	$parent = wp_get_post_parent_id( $id );
	if ( $parent && ! $uses ) {
		$uses[] = $label( $parent, 'Subida en' );
	}

	return array_values( array_unique( array_filter( $uses ) ) );
}
