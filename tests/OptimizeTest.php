<?php

class OptimizeTest extends WP_UnitTestCase {

	private $opts = [ 'format' => 'jpg', 'max_width' => 2560, 'quality' => 82, 'backup' => true, 'ai' => false ];

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		$this->remove_added_uploads();
		inpulsia_medios_backups_delete( 0 );
		parent::tear_down();
	}

	// Noisy, photo-like content: PNG is heavy and JPG/WebP compress it well.
	private function upload( $name, $w, $h, $transparent = false ) {
		$im = imagecreatetruecolor( $w, $h );
		imagealphablending( $im, false );
		imagesavealpha( $im, true );
		mt_srand( 42 );
		for ( $y = 0; $y < $h; $y += 3 ) {
			for ( $x = 0; $x < $w; $x += 3 ) {
				$c = imagecolorallocatealpha( $im, ( intdiv( $x, 12 ) + mt_rand( 0, 60 ) ) % 256, ( intdiv( $y, 6 ) + mt_rand( 0, 60 ) ) % 256, mt_rand( 80, 140 ), 0 );
				imagefilledrectangle( $im, $x, $y, $x + 2, $y + 2, $c );
			}
		}
		if ( $transparent ) {
			imagefilledrectangle( $im, 0, 0, $w - 1, (int) ( $h / 4 ), imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
		}
		$tmp = trailingslashit( get_temp_dir() ) . $name;
		$ext = pathinfo( $name, PATHINFO_EXTENSION );
		$ext === 'png' ? imagepng( $im, $tmp ) : imagejpeg( $im, $tmp, 100 );
		$id = self::factory()->attachment->create_upload_object( $tmp );
		@unlink( $tmp );
		return $id;
	}

	public function test_opaque_png_becomes_resized_jpg_and_urls_follow() {
		$id      = $this->upload( 'IMG_0001.png', 3000, 1500 );
		$old_url = wp_get_attachment_url( $id );
		$post    = self::factory()->post->create( [ 'post_content' => '<img class="wp-image-' . $id . '" src="' . $old_url . '">' ] );

		$r = inpulsia_medios_optimize( $id, $this->opts );

		$this->assertSame( 'ok', $r['status'], $r['msg'] );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
		$this->assertSame( 'jpg', pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) );
		$this->assertSame( 2560, (int) wp_get_attachment_metadata( $id )['width'] );
		$this->assertLessThan( $r['before'], $r['after'] );
		$this->assertStringContainsString( wp_get_attachment_url( $id ), get_post_field( 'post_content', $post ) );
		$this->assertArrayHasKey( 'restore', $r['prev'] );
		$this->assertSame( [], glob( dirname( get_attached_file( $id ) ) . '/.inpulsia-*' ) );
	}

	public function test_history_entries_are_not_duplicated_on_retry() {
		$entry = [ 'token' => 'reintento', 'date' => current_time( 'mysql' ), 'user' => 't', 'file' => 'x', 'rows' => 1, 'changed' => 0, 'status' => 'incompleto' ];
		inpulsia_medios_history_add( $entry );
		inpulsia_medios_history_add( $entry );

		$this->assertCount( 1, wp_list_filter( inpulsia_medios_history(), [ 'token' => 'reintento' ] ) );
	}

	public function test_transparent_png_stays_png() {
		$id = $this->upload( 'logo.png', 600, 300, true );

		$r = inpulsia_medios_optimize( $id, $this->opts );

		$this->assertNotSame( 'error', $r['status'], $r['msg'] );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$this->assertSame( 'png', pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) );
	}

	public function test_webp_format() {
		if ( ! wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) {
			$this->markTestSkipped( 'El editor de imágenes no soporta WebP.' );
		}
		$id = $this->upload( 'foto.jpg', 1200, 800 );

		$r = inpulsia_medios_optimize( $id, [ 'format' => 'webp' ] + $this->opts );

		$this->assertSame( 'ok', $r['status'], $r['msg'] );
		$this->assertSame( 'image/webp', get_post_mime_type( $id ) );
	}

	public function test_undo_restores_original_files_and_urls() {
		$id       = $this->upload( 'original.png', 1000, 600 );
		$old_file = get_attached_file( $id );
		$old_url  = wp_get_attachment_url( $id );
		$post     = self::factory()->post->create( [ 'post_content' => '<img src="' . $old_url . '">' ] );

		$token = inpulsia_medios_new_token();
		inpulsia_medios_history_add( [ 'token' => $token, 'date' => current_time( 'mysql' ), 'user' => 't', 'file' => 'opt', 'rows' => 1, 'changed' => 0, 'status' => 'incompleto' ] );
		inpulsia_medios_optimize_ids( $token, [ $id ], $this->opts );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
		$this->assertFileExists( $old_file, 'El original se conserva durante el periodo de gracia.' );

		$results = inpulsia_medios_undo_rows( inpulsia_medios_undo_entries( $token ) );

		$this->assertSame( 'ok', $results[0]['status'], $results[0]['msg'] );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$this->assertSame( $old_file, get_attached_file( $id ) );
		$this->assertFileExists( $old_file );
		$this->assertStringContainsString( $old_url, get_post_field( 'post_content', $post ) );
		list( $count ) = inpulsia_medios_backups_stats();
		$this->assertSame( 0, $count );
	}

	public function test_used_ids_detects_every_kind_of_reference() {
		$in_content  = $this->upload( 'a.jpg', 200, 200 );
		$featured    = $this->upload( 'b.jpg', 200, 200 );
		$in_elem     = $this->upload( 'c.jpg', 200, 200 );
		$unused      = $this->upload( 'd.jpg', 200, 200 );
		$thumb_url   = wp_get_attachment_image_url( $in_elem, 'thumbnail' );
		$post        = self::factory()->post->create( [ 'post_content' => '<img class="wp-image-' . $in_content . '" src="x.jpg">' ] );
		set_post_thumbnail( $post, $featured );
		update_post_meta( $post, '_elementor_data', wp_slash( wp_json_encode( [ [ 'url' => $thumb_url ] ] ) ) );

		$used = inpulsia_medios_used_ids( true );

		$this->assertContains( $in_content, $used );
		$this->assertContains( $featured, $used );
		$this->assertContains( $in_elem, $used );
		$this->assertNotContains( $unused, $used );
	}

	public function test_used_ids_ignore_deleted_images_and_count_translations() {
		$img  = $this->upload( 'traducida.jpg', 200, 200 );
		$copy = self::factory()->attachment->create_object( [ 'file' => get_post_meta( $img, '_wp_attached_file', true ), 'post_mime_type' => 'image/jpeg' ] );
		$post = self::factory()->post->create( [ 'post_content' => '<img src="' . wp_get_attachment_url( $img ) . '"> <img class="wp-image-999999" src="x.jpg">' ] );
		set_post_thumbnail( $post, $img );
		update_post_meta( $post, '_thumbnail_id', 888888 );

		$used = inpulsia_medios_used_ids( true );

		$this->assertContains( $img, $used );
		$this->assertContains( $copy, $used );
		$this->assertNotContains( 999999, $used );
		$this->assertNotContains( 888888, $used );
	}

	public function test_old_files_are_removed_after_the_grace_period() {
		$id  = $this->upload( 'antigua.png', 900, 600 );
		$old = get_attached_file( $id );
		inpulsia_medios_optimize( $id, $this->opts );
		$new = get_attached_file( $id );
		$this->assertFileExists( $old );

		$list = inpulsia_medios_orphans();
		foreach ( $list as &$e ) {
			$e['until'] = time() - 1;
		}
		update_option( 'inpulsia_medios_orphans', $list, false );
		inpulsia_medios_orphans_cleanup();

		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $new );
		$this->assertSame( [], inpulsia_medios_orphans() );
	}

	public function test_stale_optimizer_copies_are_removed() {
		$id      = $this->upload( 'con-copia.jpg', 900, 600 );
		$sidecar = get_attached_file( $id ) . '.webp';
		file_put_contents( $sidecar, 'webp viejo' );

		$r = inpulsia_medios_optimize( $id, [ 'quality' => 60 ] + $this->opts );

		$this->assertSame( 'ok', $r['status'], $r['msg'] );
		$this->assertFileDoesNotExist( $sidecar );
	}

	public function test_permissions_are_made_readable() {
		$file = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'privada.jpg';
		file_put_contents( $file, 'x' );
		chmod( $file, 0600 );

		inpulsia_medios_fix_perms( $file );
		clearstatcache();

		$this->assertNotSame( 0, fileperms( $file ) & 0044, 'El servidor web debe poder leer el archivo.' );
		unlink( $file );
	}

	public function test_elementor_caches_are_cleared_when_an_image_changes() {
		$id   = $this->upload( 'fondo.jpg', 900, 600 );
		$post = self::factory()->post->create();
		update_post_meta( $post, '_elementor_data', wp_slash( wp_json_encode( [ [ 'settings' => [ 'background_image' => [ 'url' => wp_get_attachment_url( $id ), 'id' => $id ] ] ] ] ) ) );
		update_post_meta( $post, '_elementor_css', [ 'status' => 'file' ] );
		update_post_meta( $post, '_elementor_element_cache', [ 'html' => 'viejo' ] );

		inpulsia_medios_rename( $id, 'fondo-nuevo' );

		$this->assertSame( '', get_post_meta( $post, '_elementor_css', true ) );
		$this->assertSame( '', get_post_meta( $post, '_elementor_element_cache', true ) );
		$this->assertStringContainsString( 'fondo-nuevo', get_post_meta( $post, '_elementor_data', true ) );
	}

	private function ids_for( $params ) {
		return get_posts( inpulsia_medios_editar_args( $params ) + [ 'posts_per_page' => -1, 'fields' => 'ids' ] );
	}

	public function test_weight_filter_finds_heavy_images_heaviest_first() {
		$small  = $this->upload( 'pequena.jpg', 300, 200 );
		$big    = $this->upload( 'grande.jpg', 300, 200 );
		$bigger = $this->upload( 'enorme.jpg', 300, 200 );
		delete_post_meta( $small, INPULSIA_MEDIOS_BYTES_KEY );
		update_post_meta( $big, INPULSIA_MEDIOS_BYTES_KEY, 2 * MB_IN_BYTES );
		update_post_meta( $bigger, INPULSIA_MEDIOS_BYTES_KEY, 5 * MB_IN_BYTES );

		$ids = $this->ids_for( [ 'weight' => '1m' ] );

		$this->assertSame( [ $bigger, $big ], array_map( 'intval', $ids ) );
		$this->assertSame( filesize( get_attached_file( $small ) ), (int) get_post_meta( $small, INPULSIA_MEDIOS_BYTES_KEY, true ), 'El índice se completa solo.' );
	}

	public function test_format_filter_for_heavy_formats() {
		$png = $this->upload( 'grafico.png', 300, 200 );
		$jpg = $this->upload( 'foto2.jpg', 300, 200 );

		$ids = array_map( 'intval', $this->ids_for( [ 'format' => 'heavy' ] ) );

		$this->assertContains( $png, $ids );
		$this->assertNotContains( $jpg, $ids );
	}

	public function test_size_index_follows_optimization() {
		$id = $this->upload( 'indice.png', 1600, 1000 );
		inpulsia_medios_optimize( $id, $this->opts );

		$this->assertSame( filesize( get_attached_file( $id ) ), (int) get_post_meta( $id, INPULSIA_MEDIOS_BYTES_KEY, true ) );
	}

	public function test_delete_old_option_cleans_the_server_at_once() {
		$id  = $this->upload( 'borrar.png', 1000, 600 );
		$old = get_attached_file( $id );

		$r = inpulsia_medios_optimize( $id, [ 'keep_old' => false ] + $this->opts );

		$this->assertSame( 'ok', $r['status'], $r['msg'] );
		$this->assertFileDoesNotExist( $old );
		$this->assertSame( [], inpulsia_medios_orphans() );
		$this->assertArrayHasKey( 'restore', $r['prev'], 'La copia de seguridad sigue permitiendo deshacer.' );
	}

	public function test_camera_names() {
		foreach ( [ 'IMG_2041', 'DSC0099', 'PXL_20260101_123', 'WhatsApp Image 2026', '20260101_101010', 'foto-final-v2', 'Captura de pantalla' ] as $n ) {
			$this->assertTrue( inpulsia_medios_is_camera_name( $n ), $n );
		}
		foreach ( [ 'reforma-cocina-santander', 'logo-inpulsia', 'equipo' ] as $n ) {
			$this->assertFalse( inpulsia_medios_is_camera_name( $n ), $n );
		}
	}

	public function test_flat_graphic_is_not_made_heavier() {
		$im = imagecreatetruecolor( 1200, 800 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 30, 90, 160 ) );
		imagefilledrectangle( $im, 100, 100, 600, 400, imagecolorallocate( $im, 250, 250, 250 ) );
		$tmp = trailingslashit( get_temp_dir() ) . 'grafico.png';
		imagepng( $im, $tmp, 9 );
		$id = self::factory()->attachment->create_upload_object( $tmp );
		@unlink( $tmp );

		$r = inpulsia_medios_optimize( $id, $this->opts );

		$this->assertSame( 'skip', $r['status'], $r['msg'] );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
	}

	public function test_animated_gif_is_skipped() {
		$id   = self::factory()->attachment->create_object( [ 'file' => 'anim.gif', 'post_mime_type' => 'image/gif' ] );
		$path = wp_get_upload_dir()['basedir'] . '/anim.gif';
		file_put_contents( $path, "GIF89a\x21\xF9\x04frame1\x21\xF9\x04frame2" );
		update_attached_file( $id, $path );

		$r = inpulsia_medios_optimize( $id, $this->opts );

		$this->assertSame( 'skip', $r['status'] );
		$this->assertStringContainsString( 'animado', $r['msg'] );
		@unlink( $path );
	}

	public function test_bad_names_are_detected_and_filtered() {
		$this->assertTrue( inpulsia_medios_is_bad_name( 'IMG_2041' ) );
		$this->assertTrue( inpulsia_medios_is_bad_name( 'Foto Cocina' ) );
		$this->assertTrue( inpulsia_medios_is_bad_name( 'cocina_reformada' ) );
		$this->assertTrue( inpulsia_medios_is_bad_name( 'baño-nuevo' ) );
		$this->assertFalse( inpulsia_medios_is_bad_name( 'cocina-reformada-santander' ) );
		$this->assertFalse( inpulsia_medios_is_bad_name( 'cocina-reformada-santander-scaled' ) );

		$bad  = $this->upload( 'DSC_0042.jpg', 40, 40 );
		$good = $this->upload( 'reforma-bano-santander.jpg', 40, 40 );
		$ids  = $this->ids_for( [ 'names' => 'bad' ] );
		$this->assertContains( $bad, $ids );
		$this->assertNotContains( $good, $ids );
	}

	public function test_translations_follow_renamed_and_optimized_file() {
		$id  = $this->upload( 'IMG_0100.png', 600, 300 );
		$tr  = self::factory()->attachment->create( [ 'post_mime_type' => 'image/png', 'file' => get_post_meta( $id, '_wp_attached_file', true ) ] );
		update_post_meta( $tr, '_wp_attached_file', get_post_meta( $id, '_wp_attached_file', true ) );
		$this->assertSame( [ $tr ], inpulsia_medios_siblings( $id ) );

		$this->assertNotWPError( inpulsia_medios_rename( $id, 'piscina-exterior' ) );
		$this->assertSame( get_post_meta( $id, '_wp_attached_file', true ), get_post_meta( $tr, '_wp_attached_file', true ) );

		$r = inpulsia_medios_optimize( $id, $this->opts + [ 'keep_old' => true ] );
		$this->assertSame( 'ok', $r['status'], $r['msg'] );
		$this->assertSame( get_attached_file( $id ), get_attached_file( $tr ) );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $tr ) );
		$this->assertFileExists( get_attached_file( $tr ) );
	}

	public function test_autosave_groups_edits_in_one_undoable_entry() {
		$id    = $this->upload( 'casa.jpg', 40, 40 );
		$token = inpulsia_medios_autosave_token();
		inpulsia_medios_apply_rows( $token, [ [ 'id' => $id, 'alt' => 'Casa con jardín' ] ] );
		inpulsia_medios_apply_rows( inpulsia_medios_autosave_token(), [ [ 'id' => $id, 'alt' => 'Casa blanca con jardín' ] ] );
		$this->assertSame( $token, inpulsia_medios_autosave_token() );
		$this->assertSame( 2, inpulsia_medios_history_find( $token )['changed'] );

		inpulsia_medios_undo_rows( inpulsia_medios_undo_entries( $token ) );
		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		inpulsia_medios_history_update( $token, [ 'status' => 'deshecho' ] );
		$this->assertNotSame( $token, inpulsia_medios_autosave_token() );
	}

	public function test_health_score() {
		$this->assertSame( 100, inpulsia_medios_score( [ 'total' => 0 ] ) );
		$this->assertSame( 100, inpulsia_medios_score( [ 'total' => 10, 'missing' => 0, 'big' => 0, 'names' => 0, 'png' => 0 ] ) );
		$this->assertSame( 60, inpulsia_medios_score( [ 'total' => 10, 'missing' => 10, 'big' => 0, 'names' => 0, 'png' => 0 ] ) );
		$this->assertSame( 0, inpulsia_medios_score( [ 'total' => 10, 'missing' => 10, 'big' => 10, 'names' => 10, 'png' => 10 ] ) );
	}

	public function test_usage_index_updates_when_a_post_is_saved() {
		$id = $this->upload( 'nueva.jpg', 40, 40 );
		inpulsia_medios_used_ids( true );
		$this->assertNotContains( $id, inpulsia_medios_used_ids() );

		$post = self::factory()->post->create( [ 'post_content' => '<img class="wp-image-' . $id . '" src="x.jpg">' ] );
		inpulsia_medios_usage_flush();
		$this->assertContains( $id, inpulsia_medios_used_ids() );

		wp_update_post( [ 'ID' => $post, 'post_content' => 'sin imagen' ] );
		inpulsia_medios_usage_flush();
		$this->assertNotContains( $id, inpulsia_medios_used_ids() );
	}

	public function test_new_uploads_are_optimized_in_background_when_enabled() {
		update_option( 'inpulsia_medios_opt', [ 'auto' => true, 'backup' => true ] );
		$id = $this->upload( 'subida.png', 800, 400 );
		$this->assertNotFalse( wp_next_scheduled( 'inpulsia_medios_auto_optimize', [ $id, 0 ] ) );

		wp_set_current_user( 0 );
		inpulsia_medios_auto_run( $id );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
		$this->assertSame( 0, get_current_user_id() );
		$h = inpulsia_medios_history()[0];
		$this->assertSame( 'Optimización automática al subir', $h['file'] );
		$this->assertSame( 1, $h['changed'] );

		update_option( 'inpulsia_medios_opt', [ 'auto' => false ] );
		$off = $this->upload( 'manual.png', 40, 40 );
		$this->assertFalse( wp_next_scheduled( 'inpulsia_medios_auto_optimize', [ $off, 0 ] ) );
	}

	public function test_focus_keyword_from_seo_plugins_reaches_where_used() {
		$id   = $this->upload( 'jardin.jpg', 40, 40 );
		$post = self::factory()->post->create( [ 'post_content' => '<img class="wp-image-' . $id . '" src="' . wp_get_attachment_url( $id ) . '">' ] );
		update_post_meta( $post, 'rank_math_focus_keyword', 'jardinería en Girona,poda' );
		$this->assertSame( 'jardinería en Girona', inpulsia_medios_where_used( $id )[0]['keyword'] );
		delete_post_meta( $post, 'rank_math_focus_keyword' );
		update_post_meta( $post, '_yoast_wpseo_focuskw', 'mantenimiento de jardines' );
		$this->assertSame( 'mantenimiento de jardines', inpulsia_medios_focus_keyword( $post ) );
	}

	public function test_library_bulk_action_opens_editar_with_those_images() {
		$a   = $this->upload( 'a.jpg', 40, 40 );
		$b   = $this->upload( 'b.jpg', 40, 40 );
		$this->upload( 'c.jpg', 40, 40 );
		$url = inpulsia_medios_media_bulk_handle( 'x', 'inpulsia_optimize', [ $a, $b ] );
		$this->assertStringContainsString( 'do=optimize', $url );
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertEqualsCanonicalizing( [ $a, $b ], $this->ids_for( [ 'ids' => $q['ids'] ] ) );
		$this->assertSame( 'x', inpulsia_medios_media_bulk_handle( 'x', 'delete', [ $a ] ) );
	}

	public function test_english_and_catalan_translations_load() {
		$check = function ( $locale ) {
			$filter = function () use ( $locale ) {
				return $locale;
			};
			add_filter( 'determine_locale', $filter, 99 );
			unload_textdomain( 'inpulsia-medios' );
			inpulsia_medios_load_textdomain();
			$text = __( 'Optimizar imágenes', 'inpulsia-medios' );
			remove_filter( 'determine_locale', $filter, 99 );
			unload_textdomain( 'inpulsia-medios' );
			return $text;
		};
		$this->assertSame( 'Optimize images', $check( 'en_US' ) );
		$this->assertSame( 'Optimize images', $check( 'en_GB' ) );
		$this->assertSame( 'Optimitza les imatges', $check( 'ca' ) );
		$this->assertSame( 'Optimitza les imatges', $check( 'ca_ES' ) );
		$this->assertSame( 'Optimizar imágenes', $check( 'es_ES' ) );
		$this->assertArrayHasKey( '%s seleccionadas', inpulsia_medios_js_i18n() );
	}
}
