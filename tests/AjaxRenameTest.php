<?php
/**
 * @group ajax
 */
class AjaxRenameTest extends WP_Ajax_UnitTestCase {
	public function tear_down() {
		$this->remove_added_uploads();
		foreach ( (array) glob( wp_get_upload_dir()['path'] . '/pared-de-obra*' ) as $f ) {
			@unlink( $f );
		}
		parent::tear_down();
	}

	public function test_autosave_renames_from_the_table() {
		$this->_setRole( 'administrator' );
		foreach ( (array) glob( wp_get_upload_dir()['path'] . '/pared-de-obra*' ) as $f ) {
			@unlink( $f );
		}
		$tmp = trailingslashit( get_temp_dir() ) . 'IMG_0544.jpg';
		$im  = imagecreatetruecolor( 400, 200 );
		imagejpeg( $im, $tmp );
		$id = self::factory()->attachment->create_upload_object( $tmp );
		$_POST = [ 'nonce' => wp_create_nonce( 'inpulsia_medios_nonce' ), 'id' => $id, 'field' => 'filename', 'value' => 'pared de obra' ];
		try {
			$this->_handleAjax( 'inpulsia_medios_autosave' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}
		$r = json_decode( $this->_last_response, true );
		$this->assertTrue( $r['success'] ?? false, $this->_last_response );
		$this->assertSame( 'pared-de-obra', $r['data']['value'] );
	}
}
