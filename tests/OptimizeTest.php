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
		$this->assertFileDoesNotExist( $old_file );

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
}
