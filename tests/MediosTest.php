<?php

class MediosTest extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
	}

	public function tear_down() {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	private function make_image( $name = 'foto-original.jpg', $w = 1200, $h = 800 ) {
		$tmp = trailingslashit( get_temp_dir() ) . $name;
		$im  = imagecreatetruecolor( $w, $h );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 120, 160, 200 ) );
		imagejpeg( $im, $tmp );
		$id = self::factory()->attachment->create_upload_object( $tmp );
		@unlink( $tmp );
		return $id;
	}

	private function rel_url( $id, $file ) {
		$rel = inpulsia_medios_rel_dir( $id );
		return '/' . ( $rel === '' ? '' : $rel . '/' ) . $file;
	}

	public function test_rename_moves_main_file_and_sizes() {
		$id   = $this->make_image();
		$old  = get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $meta['sizes'] );

		$r = inpulsia_medios_rename( $id, 'reforma-cocina' );

		$this->assertNotWPError( $r );
		$this->assertFileExists( $old, 'El archivo antiguo se conserva durante el periodo de gracia.' );
		$this->assertContains( basename( $old ), inpulsia_medios_orphans()[0]['files'] );
		$this->assertSame( 'reforma-cocina', inpulsia_medios_stem( $id ) );
		$this->assertFileExists( get_attached_file( $id ) );
		$dir = dirname( get_attached_file( $id ) );
		foreach ( wp_get_attachment_metadata( $id )['sizes'] as $size ) {
			$this->assertStringStartsWith( 'reforma-cocina-', $size['file'] );
			$this->assertFileExists( $dir . '/' . $size['file'] );
		}
	}

	public function test_rename_keeps_scaled_and_original_image_in_sync() {
		$id   = $this->make_image( 'grande.jpg', 3000, 2000 );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertArrayHasKey( 'original_image', $meta );

		inpulsia_medios_rename( $id, 'panoramica' );

		$meta = wp_get_attachment_metadata( $id );
		$dir  = dirname( get_attached_file( $id ) );
		$this->assertSame( 'panoramica.jpg', $meta['original_image'] );
		$this->assertSame( 'panoramica-scaled.jpg', basename( get_attached_file( $id ) ) );
		$this->assertFileExists( $dir . '/panoramica.jpg' );
	}

	public function test_rename_moves_webp_and_avif_copies() {
		$id  = $this->make_image();
		$dir = dirname( get_attached_file( $id ) );
		$old = basename( get_attached_file( $id ) );
		file_put_contents( $dir . '/' . $old . '.webp', 'webp' );
		file_put_contents( $dir . '/' . pathinfo( $old, PATHINFO_FILENAME ) . '.avif', 'avif' );

		inpulsia_medios_rename( $id, 'con-webp' );

		$this->assertFileExists( $dir . '/con-webp.jpg.webp' );
		$this->assertFileExists( $dir . '/con-webp.avif' );
		$this->assertFileExists( $dir . '/' . $old . '.webp', 'Se conserva durante el periodo de gracia.' );
	}

	public function test_rename_refuses_to_overwrite_existing_file() {
		$a = $this->make_image( 'uno.jpg' );
		$this->make_image( 'ocupado.jpg' );
		$dir = dirname( get_attached_file( $a ) );
		if ( ! file_exists( $dir . '/ocupado.jpg' ) ) {
			$this->markTestSkipped( 'Las subidas acabaron en carpetas distintas.' );
		}

		$r = inpulsia_medios_rename( $a, 'ocupado' );

		$this->assertWPError( $r );
		$this->assertSame( 'uno', inpulsia_medios_stem( $a ) );
	}

	public function test_rename_rewrites_urls_everywhere() {
		$id      = $this->make_image( 'vieja.jpg' );
		$old_rel = $this->rel_url( $id, 'vieja.jpg' );
		$old_url = wp_get_attachment_url( $id );

		$post = self::factory()->post->create( [ 'post_content' => '<img src="' . $old_url . '" />' ] );
		update_post_meta( $post, 'acf_gallery', [ 'img' => $old_url, 'nested' => [ 'url' => $old_url ] ] );
		update_post_meta( $post, '_elementor_data', wp_slash( wp_json_encode( [ [ 'settings' => [ 'image' => [ 'url' => $old_url, 'id' => $id ] ] ] ] ) ) );
		update_option( 'theme_mods_demo', [ 'logo' => $old_url ] );
		$term = self::factory()->term->create();
		update_term_meta( $term, 'thumb', $old_url );

		$count = inpulsia_medios_rename( $id, 'nueva' );

		$new_rel = $this->rel_url( $id, 'nueva.jpg' );
		$this->assertGreaterThanOrEqual( 4, $count );
		$this->assertStringContainsString( $new_rel, get_post_field( 'post_content', $post ) );
		$this->assertStringNotContainsString( $old_rel, get_post_field( 'post_content', $post ) );

		$acf = get_post_meta( $post, 'acf_gallery', true );
		$this->assertIsArray( $acf );
		$this->assertStringContainsString( $new_rel, $acf['nested']['url'] );

		$elementor = json_decode( get_post_meta( $post, '_elementor_data', true ), true );
		$this->assertStringContainsString( $new_rel, $elementor[0]['settings']['image']['url'] );

		$this->assertStringContainsString( $new_rel, get_option( 'theme_mods_demo' )['logo'] );
		$this->assertStringContainsString( $new_rel, get_term_meta( $term, 'thumb', true ) );
	}

	public function test_rename_creates_redirect_and_undo_does_not_loop() {
		global $wpdb;
		$table = inpulsia_medios_redirects_table();
		$id    = $this->make_image( 'antes.jpg' );
		$base  = inpulsia_medios_uploads_path();
		$old   = $base . $this->rel_url( $id, 'antes.jpg' );
		$new   = $base . $this->rel_url( $id, 'despues.jpg' );

		inpulsia_medios_rename( $id, 'despues' );
		$this->assertSame( $new, $wpdb->get_var( $wpdb->prepare( "SELECT target FROM {$table} WHERE source = %s", $old ) ) );

		inpulsia_medios_rename( $id, 'antes' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT target FROM {$table} WHERE source = %s", $old ) ) );
		$this->assertSame( $old, $wpdb->get_var( $wpdb->prepare( "SELECT target FROM {$table} WHERE source = %s", $new ) ) );
	}

	public function test_alt_change_updates_inserted_images() {
		$id    = $this->make_image();
		$other = $id * 10 + 1;
		$html  = '<!-- wp:image --><figure><img src="a.jpg" alt="viejo" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->'
			. '<p><img src="b.jpg" alt="otro" class="wp-image-' . $other . '"></p>';
		$post  = self::factory()->post->create( [ 'post_content' => $html ] );

		$r = inpulsia_medios_apply_row( $id, [ 'alt' => 'Cocina con isla de madera' ] );

		$this->assertSame( 'ok', $r['status'] );
		$content = get_post_field( 'post_content', $post );
		$this->assertStringContainsString( 'alt="Cocina con isla de madera"', $content );
		$this->assertStringContainsString( 'alt="otro"', $content );
		$this->assertSame( 'Cocina con isla de madera', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	public function test_apply_and_undo_restore_every_field() {
		$id = $this->make_image( 'original.jpg' );
		update_post_meta( $id, '_wp_attachment_image_alt', 'alt viejo' );
		wp_update_post( [ 'ID' => $id, 'post_title' => 'Título viejo', 'post_excerpt' => '' ] );

		$token = inpulsia_medios_new_token();
		inpulsia_medios_history_add( [ 'token' => $token, 'date' => current_time( 'mysql' ), 'user' => 'test', 'file' => 't.csv', 'rows' => 1, 'changed' => 0, 'status' => 'incompleto' ] );
		inpulsia_medios_apply_rows( $token, [ [
			'id'       => $id,
			'filename' => 'Nombre Nuevo.JPG',
			'title'    => 'Título nuevo',
			'alt'      => INPULSIA_MEDIOS_CLEAR,
			'caption'  => 'Leyenda',
		] ] );

		$cur = inpulsia_medios_current( $id );
		$this->assertSame( 'nombre-nuevo', $cur['filename'] );
		$this->assertSame( 'Título nuevo', $cur['title'] );
		$this->assertSame( '', $cur['alt'] );
		$this->assertSame( 'Leyenda', $cur['caption'] );

		inpulsia_medios_undo_rows( inpulsia_medios_undo_entries( $token ) );

		$cur = inpulsia_medios_current( $id );
		$this->assertSame( 'original', $cur['filename'] );
		$this->assertSame( 'Título viejo', $cur['title'] );
		$this->assertSame( 'alt viejo', $cur['alt'] );
		$this->assertSame( '', $cur['caption'] );
	}

	public function test_replace_in_value_keeps_serialization_valid() {
		$pairs = [ '/2026/01/a.jpg' => '/2026/01/una-imagen-mucho-mas-larga.jpg' ];
		$value = serialize( [ 'x' => 'https://e.org/wp-content/uploads/2026/01/a.jpg', 'n' => 3, 'd' => serialize( [ '/2026/01/a.jpg' ] ) ] );

		$out  = inpulsia_medios_replace_in_value( $value, $pairs );
		$data = unserialize( $out );

		$this->assertSame( 'https://e.org/wp-content/uploads/2026/01/una-imagen-mucho-mas-larga.jpg', $data['x'] );
		$this->assertSame( 3, $data['n'] );
		$this->assertSame( [ '/2026/01/una-imagen-mucho-mas-larga.jpg' ], unserialize( $data['d'] ) );
	}

	public function test_preview_reports_warnings_and_errors() {
		$a = $this->make_image( 'a.jpg' );
		$b = $this->make_image( 'b.jpg' );

		list( $items, $valid ) = inpulsia_medios_build_preview( [
			[ 'id' => $a, 'filename' => 'mismo', 'alt' => str_repeat( 'x', 130 ) ],
			[ 'id' => $b, 'filename' => 'mismo', 'alt' => 'Mismo' ],
			[ 'id' => $a, 'alt' => 'repetido' ],
			[ 'id' => 999999, 'alt' => 'no existe' ],
		] );

		$this->assertCount( 2, $valid );
		$warnings = wp_list_pluck( array_filter( $items, function ( $i ) {
			return isset( $i['warnings'] );
		} ), 'warnings' );
		$all      = implode( ' ', array_merge( ...array_values( $warnings ) ) );
		$this->assertStringContainsString( '130 caracteres', $all );
		$this->assertStringContainsString( 'Nombre repetido', $all );
		$this->assertStringContainsString( 'repite el nombre de archivo', $all );
		$errors = wp_list_pluck( array_filter( $items, function ( $i ) {
			return isset( $i['error'] );
		} ), 'error' );
		$this->assertCount( 2, $errors );
	}

	public function test_users_without_permission_cannot_edit() {
		$id = $this->make_image();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$r = inpulsia_medios_apply_row( $id, [ 'alt' => 'hack' ] );

		$this->assertSame( 'error', $r['status'] );
		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	public function test_csv_parser_handles_excel_exports() {
		$file = wp_tempnam( 'medios.csv' );
		$csv  = "ID,Nombre,Título,Texto alternativo,Descripción\n12,mi-foto,Título,\"Alt, con coma\",Línea\n,sin,id,,\n";
		file_put_contents( $file, mb_convert_encoding( $csv, 'Windows-1252', 'UTF-8' ) );

		$rows = inpulsia_medios_parse_file( $file, 'medios.csv' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '12', $rows[0]['id'] );
		$this->assertSame( 'mi-foto', $rows[0]['filename'] );
		$this->assertSame( 'Título', $rows[0]['title'] );
		$this->assertSame( 'Alt, con coma', $rows[0]['alt'] );
		$this->assertSame( 'Línea', $rows[0]['description'] );
		unlink( $file );
	}

	public function test_csv_parser_with_bom_and_semicolons() {
		$file = wp_tempnam( 'bom.csv' );
		file_put_contents( $file, "\xEF\xBB\xBFID;alt\n7;Hola; adiós\n" );

		$rows = inpulsia_medios_parse_file( $file, 'bom.csv' );

		$this->assertSame( '7', $rows[0]['id'] );
		$this->assertSame( 'Hola', $rows[0]['alt'] );
		unlink( $file );
	}

	public function test_xlsx_parser_reads_shared_and_inline_strings() {
		$file = wp_tempnam( 'medios.xlsx' );
		$zip  = new ZipArchive();
		$zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>ID</t></si><si><t>alt</t></si><si><r><t>Cocina </t></r><r><t>blanca</t></r></si></sst>' );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
			. '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="inlineStr"><is><t>title</t></is></c></row>'
			. '<row r="2"><c r="A2"><v>42</v></c><c r="B2" t="s"><v>2</v></c><c r="D2" t="inlineStr"><is><t>Título</t></is></c></row>'
			. '</sheetData></worksheet>' );
		$zip->close();

		$rows = inpulsia_medios_parse_file( $file, 'medios.xlsx' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '42', $rows[0]['id'] );
		$this->assertSame( 'Cocina blanca', $rows[0]['alt'] );
		$this->assertSame( 'Título', $rows[0]['title'] );
		unlink( $file );
	}

	public function test_export_writes_current_values() {
		$id = $this->make_image( 'exportada.jpg' );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Alt exportado' );

		$out = fopen( 'php://memory', 'w+' );
		$n   = inpulsia_medios_export_write( $out, [ 'usage' => true ] );
		rewind( $out );
		$csv = stream_get_contents( $out );

		$this->assertGreaterThanOrEqual( 1, $n );
		$this->assertStringContainsString( 'usado_en', $csv );
		$this->assertStringContainsString( 'exportada;', $csv );
		$this->assertStringContainsString( 'Alt exportado', $csv );
	}
}
