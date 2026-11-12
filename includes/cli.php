<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Actualización masiva de medios desde la línea de comandos.
 */
class Inpulsia_Medios_CLI {

	private function require_user() {
		if ( get_current_user_id() ) {
			return;
		}
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! $admins ) {
			WP_CLI::error( __( 'No hay administradores. Indica un usuario con --user=<id|login>.', 'inpulsia-medios' ) );
		}
		wp_set_current_user( (int) $admins[0] );
	}

	/**
	 * Importa un CSV o Excel y aplica los cambios.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Ruta al archivo .csv o .xlsx.
	 *
	 * [--dry-run]
	 * : Muestra los cambios y avisos sin aplicar nada.
	 *
	 * [--yes]
	 * : No pide confirmación.
	 *
	 * ## EXAMPLES
	 *
	 *     wp inpulsia-medios import medios.csv --dry-run
	 *     wp inpulsia-medios import medios.xlsx --yes --user=admin
	 */
	public function import( $args, $assoc ) {
		$this->require_user();
		$file = $args[0];
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( __( 'No se puede leer %s.', 'inpulsia-medios' ), $file ) );
		}
		$rows = inpulsia_medios_parse_file( $file );
		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
		}
		if ( ! $rows ) {
			WP_CLI::error( __( 'El archivo está vacío o no tiene columna ID.', 'inpulsia-medios' ) );
		}

		list( $items, $valid ) = inpulsia_medios_build_preview( $rows );
		$table                 = [];
		foreach ( $items as $item ) {
			$changes = [];
			foreach ( $item['changes'] ?? [] as $f => $v ) {
				$changes[] = $f . ': ' . ( $v === '' ? __( '(vacío)', 'inpulsia-medios' ) : $v );
			}
			$table[] = [
				'ID'      => $item['id'],
				'Cambios' => isset( $item['error'] ) ? '' : ( $changes ? implode( ' · ', $changes ) : 'sin cambios' ),
				'Avisos'  => isset( $item['error'] ) ? $item['error'] : implode( ' ', $item['warnings'] ),
			];
		}
		WP_CLI\Utils\format_items( 'table', $table, [ 'ID', 'Cambios', 'Avisos' ] );
		WP_CLI::log( sprintf( __( '%d medios con cambios.', 'inpulsia-medios' ), count( $valid ) ) );

		if ( ! $valid || isset( $assoc['dry-run'] ) ) {
			return;
		}
		WP_CLI::confirm( __( '¿Aplicar los cambios?', 'inpulsia-medios' ), $assoc );

		$token = inpulsia_medios_new_token();
		inpulsia_medios_history_add( [
			'token'   => $token,
			'date'    => current_time( 'mysql' ),
			'user'    => 'WP-CLI (' . wp_get_current_user()->user_login . ')',
			'file'    => basename( $file ),
			'rows'    => count( $valid ),
			'changed' => 0,
			'status'  => 'incompleto',
		] );

		$bar    = WP_CLI\Utils\make_progress_bar( 'Aplicando', count( $valid ) );
		$counts = [];
		foreach ( array_chunk( $valid, INPULSIA_MEDIOS_BATCH ) as $chunk ) {
			foreach ( inpulsia_medios_apply_rows( $token, $chunk ) as $r ) {
				$counts[ $r['status'] ] = ( $counts[ $r['status'] ] ?? 0 ) + 1;
				if ( $r['status'] === 'error' || $r['status'] === 'partial' ) {
					WP_CLI::warning( "ID {$r['id']}: {$r['msg']}" );
				}
				$bar->tick();
			}
		}
		$bar->finish();
		inpulsia_medios_history_update( $token, [ 'status' => 'aplicado' ] );
		$purged = inpulsia_medios_purge_caches();

		WP_CLI::success( sprintf(
			'%d correctos, %d parciales, %d errores. Para deshacer: wp inpulsia-medios undo %s',
			$counts['ok'] ?? 0,
			$counts['partial'] ?? 0,
			$counts['error'] ?? 0,
			$token
		) );
		if ( $purged ) {
			WP_CLI::log( sprintf( __( 'Cachés vaciadas: %s.', 'inpulsia-medios' ), implode( ', ', $purged ) ) );
		}
	}

	/**
	 * Exporta la biblioteca a CSV.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Ruta del CSV a generar.
	 *
	 * [--all]
	 * : Incluye todos los medios, no solo imágenes.
	 *
	 * [--missing-alt]
	 * : Solo imágenes sin texto alternativo.
	 *
	 * [--usage]
	 * : Añade la columna usado_en.
	 *
	 * [--from=<fecha>]
	 * : Subidas desde esta fecha (AAAA-MM-DD).
	 *
	 * [--to=<fecha>]
	 * : Subidas hasta esta fecha (AAAA-MM-DD).
	 */
	public function export( $args, $assoc ) {
		$out = fopen( $args[0], 'w' );
		if ( ! $out ) {
			WP_CLI::error( sprintf( __( 'No se puede escribir %s.', 'inpulsia-medios' ), $args[0] ) );
		}
		$n = inpulsia_medios_export_write( $out, [
			'type'        => isset( $assoc['all'] ) ? 'all' : 'image',
			'missing_alt' => isset( $assoc['missing-alt'] ),
			'usage'       => isset( $assoc['usage'] ),
			'from'        => $assoc['from'] ?? '',
			'to'          => $assoc['to'] ?? '',
		] );
		fclose( $out );
		WP_CLI::success( sprintf( __( '%1$d medios exportados a %2$s.', 'inpulsia-medios' ), $n, $args[0] ) );
	}

	/**
	 * Lista las últimas importaciones.
	 *
	 * @subcommand history
	 */
	public function history() {
		$rows = array_map( function ( $e ) {
			return [
				'Token'   => $e['token'],
				'Fecha'   => $e['date'],
				'Origen'  => $e['file'],
				'Usuario' => $e['user'],
				'Cambios' => $e['changed'],
				'Estado'  => $e['status'],
			];
		}, inpulsia_medios_history() );
		WP_CLI\Utils\format_items( 'table', $rows, [ 'Token', __( 'Fecha', 'inpulsia-medios' ), __( 'Origen', 'inpulsia-medios' ), __( 'Usuario', 'inpulsia-medios' ), 'Cambios', __( 'Estado', 'inpulsia-medios' ) ] );
	}

	/**
	 * Deshace una importación.
	 *
	 * ## OPTIONS
	 *
	 * <token>
	 * : Token de la importación (ver `wp inpulsia-medios history`).
	 *
	 * [--yes]
	 * : No pide confirmación.
	 */
	public function undo( $args, $assoc ) {
		$this->require_user();
		$entry = inpulsia_medios_history_find( sanitize_key( $args[0] ) );
		if ( ! $entry || $entry['status'] === 'deshecho' ) {
			WP_CLI::error( __( 'Esta importación no existe o ya se deshizo.', 'inpulsia-medios' ) );
		}
		WP_CLI::confirm( sprintf( __( '¿Deshacer %1$d cambios de «%2$s»?', 'inpulsia-medios' ), $entry['changed'], $entry['file'] ), $assoc );
		$results = inpulsia_medios_undo_rows( inpulsia_medios_undo_entries( $entry['token'] ) );
		inpulsia_medios_history_update( $entry['token'], [ 'status' => 'deshecho' ] );
		inpulsia_medios_purge_caches();
		foreach ( $results as $r ) {
			if ( $r['status'] === 'error' || $r['status'] === 'partial' ) {
				WP_CLI::warning( "ID {$r['id']}: {$r['msg']}" );
			}
		}
		WP_CLI::success( sprintf( __( '%d medios restaurados.', 'inpulsia-medios' ), count( $results ) ) );
	}
}

WP_CLI::add_command( 'inpulsia-medios', 'Inpulsia_Medios_CLI' );
