<?php
/**
 * Plugin ZIPs you drop into the dashboard, hosted briefly so client sites can
 * download them. Each upload gets a random 32-character token; client sites
 * fetch it from /wp-json/usmd/v1/package/<token>. Tokens expire after 6 hours
 * and the files are deleted.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Packages {

	const OPT       = 'usmd_packages';
	const TTL       = 21600; // 6 hours.
	const MAX_BYTES = 52428800; // 50 MB.

	public static function register() {
		register_rest_route(
			'usmd/v1',
			'/package/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve' ),
				'permission_callback' => '__return_true', // The unguessable, expiring token is the credential.
				'args'                => array( 'token' => array( 'sanitize_callback' => 'sanitize_key' ) ),
			)
		);
	}

	private static function base() {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['basedir'] ) . 'usmd-packages';
	}

	private static function all() {
		$p = get_option( self::OPT );
		return is_array( $p ) ? $p : array();
	}

	public static function url( $token ) {
		return rest_url( 'usmd/v1/package/' . $token );
	}

	/** Delete expired packages. */
	/** Remove a package's now-empty folder. */
	private static function remove_dir( $dir ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
				return;
			}
		}
		if ( 0 === strpos( $dir, self::base() ) && $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->rmdir( $dir );
		}
	}

	public static function cleanup() {
		$all  = self::all();
		$keep = array();
		foreach ( $all as $token => $p ) {
			if ( time() < (int) $p['expires'] && file_exists( $p['path'] ) ) {
				$keep[ $token ] = $p;
				continue;
			}
			if ( ! empty( $p['path'] ) && 0 === strpos( $p['path'], self::base() ) ) {
				wp_delete_file( $p['path'] );
				self::remove_dir( dirname( $p['path'] ) );
			}
		}
		if ( count( $keep ) !== count( $all ) ) {
			update_option( self::OPT, $keep, false );
		}
	}

	/**
	 * Store an uploaded ZIP from $_FILES.
	 *
	 * @return array|WP_Error token, name, url
	 */
	public static function store_upload( array $file ) {
		self::cleanup();
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'usmd_upload', __( 'The file did not upload. It may be larger than the server allows.', 'unstoppable-site-manager' ) );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'usmd_upload', __( 'That ZIP is larger than 50 MB.', 'unstoppable-site-manager' ) );
		}

		$name  = sanitize_file_name( (string) $file['name'] );
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $name, array( 'zip' => 'application/zip' ) );
		if ( 'zip' !== $check['ext'] ) {
			return new WP_Error( 'usmd_upload', __( 'Only plugin .zip files can be uploaded.', 'unstoppable-site-manager' ) );
		}
		$plugin = self::plugin_name_in_zip( $file['tmp_name'] );
		if ( false === $plugin ) {
			return new WP_Error( 'usmd_upload', __( 'That ZIP does not contain a WordPress plugin.', 'unstoppable-site-manager' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$token  = bin2hex( random_bytes( 16 ) );
		$dir    = self::base() . '/' . $token;
		$filter = static function ( $u ) use ( $dir, $token ) {
			$u['path']   = $dir;
			$u['url']    = '';
			$u['subdir'] = '/usmd-packages/' . $token;
			return $u;
		};
		self::protect_base();
		add_filter( 'upload_dir', $filter );
		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array( 'zip' => 'application/zip' ),
			)
		);
		remove_filter( 'upload_dir', $filter );
		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'usmd_upload', $moved['error'] );
		}

		$label = $plugin ? $plugin : preg_replace( '/\.zip$/i', '', $name );
		$all   = self::all();
		$all[ $token ] = array(
			'path'      => $moved['file'],
			'name'      => $label,
			'expires'   => time() + self::TTL,
			'connector' => self::is_connector_name( $plugin ),
		);
		update_option( self::OPT, $all, false );

		// A new connector pushed to sites also becomes the copy offered for download on Add Site.
		if ( USMD_Connector_Zip::NAME === $plugin ) {
			$h = self::zip_headers( $moved['file'] );
			if ( $h && $h['version'] ) {
				USMD_Connector_Zip::keep( $moved['file'], $h['version'] );
			}
		}

		return array(
			'token' => $token,
			'name'  => $label,
			'url'   => self::url( $token ),
		);
	}

	/**
	 * Download a plugin ZIP from a web address once and host it here like an upload,
	 * so client sites fetch it from this dashboard. Reuses the copy while it is kept.
	 *
	 * @return array|WP_Error { token, name, url }
	 */
	public static function store_from_url( $url ) {
		self::cleanup();
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'usmd_url', __( 'Enter the full web address of a plugin .zip file.', 'unstoppable-site-manager' ) );
		}
		foreach ( self::all() as $token => $p ) {
			if ( isset( $p['src'] ) && $p['src'] === $url && file_exists( $p['path'] ) ) {
				return array(
					'token' => $token,
					'name'  => $p['name'],
					'url'   => self::url( $token ),
				);
			}
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			/* translators: %s: error message. */
			return new WP_Error( 'usmd_url', sprintf( __( 'Could not download the plugin: %s', 'unstoppable-site-manager' ), $tmp->get_error_message() ) );
		}
		if ( filesize( $tmp ) > self::MAX_BYTES ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'usmd_url', __( 'That ZIP is larger than 50 MB.', 'unstoppable-site-manager' ) );
		}
		$plugin = self::plugin_name_in_zip( $tmp );
		if ( false === $plugin ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'usmd_url', __( 'That address does not lead to a WordPress plugin ZIP.', 'unstoppable-site-manager' ) );
		}
		$token = bin2hex( random_bytes( 16 ) );
		$dir   = self::base() . '/' . $token;
		self::protect_base();
		wp_mkdir_p( $dir );
		$name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$name = preg_match( '/\.zip$/i', $name ) ? $name : 'plugin.zip';
		$dest = $dir . '/' . $name;
		$ok   = copy( $tmp, $dest );
		wp_delete_file( $tmp );
		if ( ! $ok ) {
			return new WP_Error( 'usmd_url', __( 'The plugin could not be saved. Check that wp-content/uploads is writable.', 'unstoppable-site-manager' ) );
		}
		$label         = $plugin ? $plugin : preg_replace( '/\.zip$/i', '', $name );
		$all           = self::all();
		$all[ $token ] = array(
			'path'      => $dest,
			'name'      => $label,
			'expires'   => time() + self::TTL,
			'connector' => self::is_connector_name( $plugin ),
			'src'       => $url,
		);
		update_option( self::OPT, $all, false );
		return array(
			'token' => $token,
			'name'  => $label,
			'url'   => self::url( $token ),
		);
	}

	/** Is this plugin name our own connector (current or future name)? */
	public static function is_connector_name( $name ) {
		return in_array( strtolower( trim( (string) $name ) ), array( 'fm connector', 'unstoppable site connector' ), true );
	}

	/** Was this uploaded package the connector? */
	public static function is_connector( $token ) {
		$all = self::all();
		return ! empty( $all[ $token ]['connector'] );
	}

	/** Deny direct web access to the folder; packages are only served through the token route. */
	private static function protect_base() {
		$base = self::base();
		wp_mkdir_p( $base );
		if ( ! file_exists( $base . '/index.php' ) ) {
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			file_put_contents( $base . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Returns the plugin's name from its header, '' if it looks like a plugin
	 * but the name couldn't be read, or false if it is not a plugin.
	 */
	private static function plugin_name_in_zip( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}
		$h = self::zip_headers( $path );
		return $h ? $h['name'] : false;
	}

	/**
	 * The main plugin file's Name and Version headers from a ZIP.
	 *
	 * @return array|false array( name, version ), or false if it is not a plugin.
	 */
	public static function zip_headers( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return false;
		}
		$found = false;
		for ( $i = 0; $i < min( $zip->numFiles, 3000 ); $i++ ) {
			$entry = $zip->getNameIndex( $i );
			// Main plugin files sit at the top level or one folder down.
			if ( ! preg_match( '#^([^/]+/)?[^/]+\.php$#i', $entry ) ) {
				continue;
			}
			$head = $zip->getFromIndex( $i, 8192 );
			if ( $head && preg_match( '/^[ \t\/*#@]*Plugin Name:(.*)$/mi', $head, $m ) ) {
				$found = array(
					'name'    => trim( wp_strip_all_tags( $m[1] ) ),
					'version' => preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $head, $v ) ? trim( wp_strip_all_tags( $v[1] ) ) : '',
				);
				break;
			}
		}
		$zip->close();
		return $found;
	}

	public static function serve( WP_REST_Request $request ) {
		$token = (string) $request['token'];
		$all   = self::all();
		if ( empty( $all[ $token ] ) || time() > (int) $all[ $token ]['expires'] || ! is_readable( $all[ $token ]['path'] ) ) {
			return new WP_Error( 'usmd_package', 'Package not found or expired.', array( 'status' => 404 ) );
		}
		$path = $all[ $token ]['path'];
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
