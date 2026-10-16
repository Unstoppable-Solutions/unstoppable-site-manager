<?php
/**
 * Plugin ZIPs you add on Plugins > Add plugin (an upload or a web address),
 * held briefly so client sites can download them from this dashboard.
 *
 * Nothing is written to the file system: the ZIP is kept in this plugin's own
 * database table (in 512 KB pieces) and served only through
 * /wp-json/usmd/v1/package/<token>, where the token is random, 32 characters
 * long and expires after 6 hours. Expired packages are deleted.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Packages {

	const OPT       = 'usmd_packages';
	const TTL       = 21600; // 6 hours.
	const MAX_BYTES = 52428800; // 50 MB.
	const CHUNK     = 524288; // 512 KB per database row.

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

	/** This plugin's table for package contents. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'usmd_files';
	}

	private static function all() {
		$p = get_option( self::OPT );
		return is_array( $p ) ? $p : array();
	}

	public static function url( $token ) {
		return rest_url( 'usmd/v1/package/' . $token );
	}

	/** Delete one package's contents. */
	private static function delete_rows( $token ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'token' => (string) $token ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
	}

	/** Delete expired packages. */
	public static function cleanup() {
		$all  = self::all();
		$keep = array();
		foreach ( $all as $token => $p ) {
			if ( isset( $p['expires'], $p['size'] ) && time() < (int) $p['expires'] ) {
				$keep[ $token ] = $p;
				continue;
			}
			self::delete_rows( $token );
		}
		if ( count( $keep ) !== count( $all ) ) {
			update_option( self::OPT, $keep, false );
		}
	}

	/**
	 * Copy a ZIP into the database table.
	 *
	 * @return int|WP_Error Bytes stored.
	 */
	private static function put( $token, $path ) {
		global $wpdb;
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading a temporary file in pieces.
		if ( ! $fh ) {
			return new WP_Error( 'usmd_store', __( 'The ZIP could not be read.', 'unstoppable-site-manager' ) );
		}
		$seq  = 0;
		$size = 0;
		while ( ! feof( $fh ) ) {
			$data = fread( $fh, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $data || '' === $data ) {
				break;
			}
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
				self::table(),
				array(
					'token' => $token,
					'seq'   => $seq,
					'data'  => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ZIP data stored as text.
				),
				array( '%s', '%d', '%s' )
			);
			if ( ! $ok ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				self::delete_rows( $token );
				return new WP_Error( 'usmd_store', __( 'The ZIP could not be saved in the database.', 'unstoppable-site-manager' ) );
			}
			$size += strlen( $data );
			++$seq;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $size;
	}

	/**
	 * Store a checked ZIP and register its token.
	 *
	 * @return array|WP_Error token, name, url
	 */
	private static function add( $path, $label, $filename, $src = '' ) {
		$token = bin2hex( random_bytes( 16 ) );
		$size  = self::put( $token, $path );
		if ( is_wp_error( $size ) ) {
			return $size;
		}
		$all           = self::all();
		$all[ $token ] = array(
			'name'      => $label,
			'filename'  => $filename,
			'size'      => $size,
			'expires'   => time() + self::TTL,
			'connector' => self::is_connector_name( $label ),
			'src'       => $src,
		);
		update_option( self::OPT, $all, false );
		return array(
			'token' => $token,
			'name'  => $label,
			'url'   => self::url( $token ),
		);
	}

	/**
	 * Store an uploaded ZIP from $_FILES (read from PHP's temporary upload file, never moved).
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
		$label = $plugin ? $plugin : preg_replace( '/\.zip$/i', '', $name );
		return self::add( $file['tmp_name'], $label, $name );
	}

	/**
	 * Download a plugin ZIP from a web address once and hold it here like an upload,
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
			if ( ! empty( $p['src'] ) && $p['src'] === $url ) {
				return array(
					'token' => $token,
					'name'  => $p['name'],
					'url'   => self::url( $token ),
				);
			}
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 120 ); // WordPress's temporary folder; deleted below.
		if ( is_wp_error( $tmp ) ) {
			/* translators: %s: error message. */
			return new WP_Error( 'usmd_url', sprintf( __( 'Could not download the plugin: %s', 'unstoppable-site-manager' ), $tmp->get_error_message() ) );
		}
		$plugin = filesize( $tmp ) > self::MAX_BYTES ? null : self::plugin_name_in_zip( $tmp );
		if ( null === $plugin || false === $plugin ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'usmd_url',
				null === $plugin
					? __( 'That ZIP is larger than 50 MB.', 'unstoppable-site-manager' )
					: __( 'That address does not lead to a WordPress plugin ZIP.', 'unstoppable-site-manager' )
			);
		}
		$name  = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$name  = preg_match( '/\.zip$/i', $name ) ? $name : 'plugin.zip';
		$label = $plugin ? $plugin : preg_replace( '/\.zip$/i', '', $name );
		$r     = self::add( $tmp, $label, $name, $url );
		wp_delete_file( $tmp );
		return $r;
	}

	/** Is this plugin name our own connector (current or former name)? */
	public static function is_connector_name( $name ) {
		return in_array( strtolower( trim( (string) $name ) ), array( 'fm connector', 'unstoppable site connector' ), true );
	}

	/** Was this package the connector? */
	public static function is_connector( $token ) {
		$all = self::all();
		return ! empty( $all[ $token ]['connector'] );
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

	/** Stream a package to a client site, one database row at a time. */
	public static function serve( WP_REST_Request $request ) {
		global $wpdb;
		$token = (string) $request['token'];
		$all   = self::all();
		if ( empty( $all[ $token ]['size'] ) || time() > (int) $all[ $token ]['expires'] ) {
			return new WP_Error( 'usmd_package', 'Package not found or expired.', array( 'status' => 404 ) );
		}
		$p   = $all[ $token ];
		$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(seq) FROM %i WHERE token = %s', self::table(), $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $p['filename'] ) . '"' );
		header( 'Content-Length: ' . (int) $p['size'] );
		header( 'X-Content-Type-Options: nosniff' );
		for ( $seq = 0; $seq <= $max; $seq++ ) {
			$data = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM %i WHERE token = %s AND seq = %d', self::table(), $token, $seq ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
			echo base64_decode( (string) $data ); // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ZIP download.
			flush();
		}
		exit;
	}

	/**
	 * Before 0.15.0 packages and a connector copy were kept in uploads/usmd-packages
	 * and uploads/usmd-connector. Delete those files and folders.
	 */
	public static function remove_legacy_files() {
		$u = wp_upload_dir( null, false );
		foreach ( array( 'usmd-packages', 'usmd-connector' ) as $folder ) {
			$dir = trailingslashit( $u['basedir'] ) . $folder;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( (array) glob( $dir . '/{,*/}{,.}*', GLOB_BRACE ) as $f ) {
				if ( is_file( $f ) ) {
					wp_delete_file( $f );
				}
			}
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
					continue;
				}
			}
			$wp_filesystem->rmdir( $dir, true );
		}
		delete_option( 'usmd_connector_zip' );
		delete_option( self::OPT ); // Their entries pointed at the deleted files.
		$bulk = get_option( 'usmd_bulk' );
		if ( is_array( $bulk ) ) {
			unset( $bulk['file'], $bulk['version'] );
			update_option( 'usmd_bulk', $bulk, false );
		}
	}
}
