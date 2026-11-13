<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Optimizes new uploads in the background (WP-Cron) so the upload itself stays fast.
add_action( 'add_attachment', 'inpulsia_medios_auto_schedule' );
add_action( 'inpulsia_medios_auto_optimize', 'inpulsia_medios_auto_run', 10, 2 );

function inpulsia_medios_auto_enabled() {
	$s = get_option( 'inpulsia_medios_opt', [] );
	return is_array( $s ) && ! empty( $s['auto'] );
}

function inpulsia_medios_auto_schedule( $id ) {
	if ( ! inpulsia_medios_auto_enabled() || strpos( (string) get_post_mime_type( $id ), 'image/' ) !== 0 ) {
		return;
	}
	wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'inpulsia_medios_auto_optimize', [ (int) $id, 0 ] );
}

function inpulsia_medios_auto_run( $id, $attempt = 0 ) {
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'attachment' || ! function_exists( 'inpulsia_medios_optimize' ) ) {
		return;
	}
	// Sub-sizes may still be generating: try again a little later.
	// WordPress saves the metadata after each size, so wait until the upload is a couple of minutes old too.
	$young = time() - (int) get_post_time( 'U', true, $post ) < 90;
	if ( ( $young || ! wp_get_attachment_metadata( $id ) ) && $attempt < 3 ) {
		wp_schedule_single_event( time() + 60, 'inpulsia_medios_auto_optimize', [ (int) $id, $attempt + 1 ] );
		return;
	}
	$previous = get_current_user_id();
	wp_set_current_user( (int) $post->post_author );
	if ( ! current_user_can( 'edit_post', $id ) ) {
		wp_set_current_user( $previous );
		return;
	}
	$opts             = inpulsia_medios_opt_settings();
	$opts['keep_old'] = true; // The editor may still hold the original URL while the post is open.
	$opts['ai']       = $opts['ai'] && function_exists( 'inpulsia_medios_api_key' ) && inpulsia_medios_api_key() !== '';
	$token            = inpulsia_medios_daily_token( 'auto', 'Optimización automática al subir' /* Stored in Spanish, translated when shown. */ );
	inpulsia_medios_optimize_ids( $token, [ $id ], $opts );
	wp_set_current_user( $previous );
}
