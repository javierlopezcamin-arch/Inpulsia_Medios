<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Media → Library (list view): SEO column, row link and bulk actions.
add_filter( 'manage_media_columns', 'inpulsia_medios_media_columns' );
add_action( 'manage_media_custom_column', 'inpulsia_medios_media_column', 10, 2 );
add_filter( 'media_row_actions', 'inpulsia_medios_media_row_actions', 10, 2 );
add_filter( 'bulk_actions-upload', 'inpulsia_medios_media_bulk_actions' );
add_filter( 'handle_bulk_actions-upload', 'inpulsia_medios_media_bulk_handle', 10, 3 );
add_action( 'admin_head-upload.php', 'inpulsia_medios_media_css' );

function inpulsia_medios_media_columns( $cols ) {
	$cols['inpulsia_seo'] = __( 'SEO de imagen', 'inpulsia-medios' );
	return $cols;
}

function inpulsia_medios_media_checks( $id ) {
	$bytes = (int) get_post_meta( $id, INPULSIA_MEDIOS_BYTES_KEY, true );
	if ( ! $bytes ) {
		inpulsia_medios_store_size( $id );
		$bytes = (int) get_post_meta( $id, INPULSIA_MEDIOS_BYTES_KEY, true );
	}
	return [
		'alt'    => [ (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) !== '', __( 'Alt', 'inpulsia-medios' ), __( 'Falta el texto alternativo', 'inpulsia-medios' ) ],
		'weight' => [ $bytes <= MB_IN_BYTES, size_format( $bytes, 0 ), __( 'Pesa más de 1 MB', 'inpulsia-medios' ) ],
		'name'   => [ ! inpulsia_medios_is_bad_name( pathinfo( (string) get_post_meta( $id, '_wp_attached_file', true ), PATHINFO_FILENAME ) ), __( 'Nombre', 'inpulsia-medios' ), __( 'Nombre de archivo poco amigable', 'inpulsia-medios' ) ],
	];
}

function inpulsia_medios_media_column( $col, $id ) {
	if ( $col !== 'inpulsia_seo' || strpos( (string) get_post_mime_type( $id ), 'image/' ) !== 0 ) {
		return;
	}
	foreach ( inpulsia_medios_media_checks( $id ) as $c ) {
		printf(
			'<span class="im-badge %s" title="%s">%s %s</span> ',
			$c[0] ? 'is-ok' : 'is-warn',
			esc_attr( $c[0] ? __( 'Correcto', 'inpulsia-medios' ) : $c[2] ),
			$c[0] ? '✓' : '!',
			esc_html( $c[1] )
		);
	}
}

function inpulsia_medios_media_row_actions( $actions, $post ) {
	if ( strpos( (string) $post->post_mime_type, 'image/' ) === 0 ) {
		$actions['inpulsia'] = '<a href="' . esc_url( inpulsia_medios_url( 'editar', [ 'ids' => $post->ID, 'lang' => 'all' ] ) ) . '">Editar en Inpulsia</a>';
	}
	return $actions;
}

function inpulsia_medios_media_bulk_actions( $actions ) {
	$actions['inpulsia_edit']     = __( 'Editar textos en Inpulsia Medios', 'inpulsia-medios' );
	$actions['inpulsia_optimize'] = __( 'Optimizar con Inpulsia Medios', 'inpulsia-medios' );
	return $actions;
}

function inpulsia_medios_media_bulk_handle( $redirect, $action, $ids ) {
	if ( ! in_array( $action, [ 'inpulsia_edit', 'inpulsia_optimize' ], true ) || ! $ids ) {
		return $redirect;
	}
	$args = [ 'ids' => implode( ',', array_map( 'absint', $ids ) ), 'lang' => 'all' ];
	if ( $action === 'inpulsia_optimize' ) {
		$args['do'] = 'optimize';
	}
	return inpulsia_medios_url( 'editar', $args );
}

function inpulsia_medios_media_css() {
	echo '<style>.column-inpulsia_seo{width:150px}.im-badge{display:inline-block;padding:1px 6px;margin:0 2px 3px 0;border-radius:9px;font-size:11px;line-height:1.6;white-space:nowrap}.im-badge.is-ok{background:#edfaef;color:#00701a}.im-badge.is-warn{background:#fcf0f1;color:#b32d2e;font-weight:600}</style>';
}
