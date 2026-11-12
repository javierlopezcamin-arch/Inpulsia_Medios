<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Inpulsia_Medios_Updater {
	const REPO      = 'javierlopezcamin-arch/Inpulsia_Medios';
	const SLUG      = 'inpulsia-medios';
	const TRANSIENT = 'inpulsia_medios_release';

	private $basename;

	public function __construct( $file ) {
		$this->basename = plugin_basename( $file );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check' ] );
		add_filter( 'plugins_api', [ $this, 'info' ], 20, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'purge' ] );
		add_filter( 'plugin_action_links_' . $this->basename, [ $this, 'action_links' ] );
		add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'force_check' ] );
	}

	private function release() {
		$cached = get_transient( self::TRANSIENT );
		if ( $cached !== false ) {
			return $cached ?: null;
		}

		$release = null;
		$res     = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', [
			'timeout' => 10,
			'headers' => [ 'Accept' => 'application/vnd.github+json' ],
		] );
		if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! empty( $data['tag_name'] ) ) {
				$package = $data['zipball_url'];
				foreach ( $data['assets'] ?? [] as $asset ) {
					if ( substr( $asset['name'], -4 ) === '.zip' ) {
						$package = $asset['browser_download_url'];
						break;
					}
				}
				$release = [
					'version' => ltrim( $data['tag_name'], 'vV' ),
					'package' => $package,
					'notes'   => (string) ( $data['body'] ?? '' ),
					'url'     => $data['html_url'],
					'date'    => $data['published_at'] ?? '',
				];
			}
		}

		set_transient( self::TRANSIENT, $release ?: [], $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	public function check( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}
		$release = $this->release();
		if ( ! $release ) {
			return $transient;
		}
		$item = (object) [
			'id'          => self::REPO,
			'slug'        => self::SLUG,
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'package'     => $release['package'],
			'url'         => $release['url'],
		];
		if ( version_compare( $release['version'], INPULSIA_MEDIOS_VERSION, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
		} else {
			$transient->no_update[ $this->basename ] = $item;
		}
		return $transient;
	}

	public function info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}
		$release = $this->release();
		if ( ! $release ) {
			return $result;
		}
		$header = get_file_data( WP_PLUGIN_DIR . '/' . $this->basename, [ 'requires' => 'Requires at least', 'requires_php' => 'Requires PHP' ] );
		return (object) [
			'name'          => 'Inpulsia Medios',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://inpulsia.es">Inpulsia</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $header['requires'],
			'requires_php'  => $header['requires_php'],
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['date'],
			'download_link' => $release['package'],
			'sections'      => [
				'description' => __( '<p>Edición masiva de los medios de WordPress: nombre de archivo, título, texto alternativo, leyenda y descripción desde CSV, Excel, una tabla editable o WP-CLI, con sugerencias de IA.</p>', 'inpulsia-medios' )
					. __( '<p>Optimiza imágenes (JPG o WebP, ancho máximo y compresión) manteniendo las transparentes. Al renombrar o convertir actualiza las URLs en todo el sitio, crea redirecciones 301, vacía cachés y permite deshacer desde el historial.</p>', 'inpulsia-medios' ),
				'changelog'   => $this->notes_html( $release['notes'] ),
			],
		];
	}

	// Release notes are "- line" bullets generated from commit subjects.
	private function notes_html( $notes ) {
		$items = array_filter( array_map( function ( $line ) {
			return trim( ltrim( trim( $line ), '-*' ) );
		}, explode( "\n", (string) $notes ) ) );
		if ( ! $items ) {
			return __( '<p>Sin notas de versión.</p>', 'inpulsia-medios' );
		}
		return '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
	}

	// GitHub zipballs unpack to "owner-repo-hash/"; WordPress needs the plugin's own folder name.
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( ( $hook_extra['plugin'] ?? '' ) !== $this->basename ) {
			return $source;
		}
		global $wp_filesystem;
		$target = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $target ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ) {
			return $target;
		}
		return new WP_Error( 'inpulsia_medios_folder', __( 'No se pudo preparar la carpeta del plugin.', 'inpulsia-medios' ) );
	}

	public function purge() {
		delete_transient( self::TRANSIENT );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'upload.php?page=inpulsia-medios' ) ) . '">Abrir</a>' );
		if ( current_user_can( 'update_plugins' ) ) {
			$url     = wp_nonce_url( admin_url( 'plugins.php?inpulsia_medios_check=1' ), 'inpulsia_medios_check' );
			$links[] = '<a href="' . esc_url( $url ) . '">Buscar actualizaciones</a>';
		}
		return $links;
	}

	public function row_meta( $meta, $file ) {
		if ( $file !== $this->basename ) {
			return $meta;
		}
		$url    = admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . self::SLUG . '&TB_iframe=true&width=600&height=550' );
		$meta[] = '<a href="' . esc_url( $url ) . '" class="thickbox open-plugin-details-modal">Ver detalles</a>';
		return $meta;
	}

	public function force_check() {
		if ( empty( $_GET['inpulsia_medios_check'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'inpulsia_medios_check' );
		$this->purge();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
