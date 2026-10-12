<?php
/**
 * Keeps a copy of the Unstoppable Site Connector ZIP on this dashboard, so it
 * can always be downloaded from Add Site (also before the connector is on
 * WordPress.org, or when a client site can't reach the directory).
 *
 * The copy is stored in uploads/usmd-connector/ (direct web access denied) and
 * served only to administrators through admin-post.php. Uploading it on Add
 * Site, or installing a newer connector ZIP on sites from Plugins > Add plugin,
 * keeps it current.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Connector_Zip {

	const OPT       = 'usmd_connector_zip';
	const NAME      = 'Unstoppable Site Connector';
	const MAX_BYTES = 10485760; // 10 MB; the connector is well under 1 MB.

	public static function hooks() {
		add_action( 'admin_post_usmd_connector_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_usmd_connector_download', array( __CLASS__, 'handle_download' ) );
	}

	private static function dir() {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['basedir'] ) . 'usmd-connector';
	}

	/** Deny direct web access; files are only served through handle_download(). */
	private static function protect() {
		$dir = self::dir();
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/** @return array|null { file, version, time } when a copy is stored and readable. */
	public static function info() {
		$i = get_option( self::OPT );
		return ( is_array( $i ) && ! empty( $i['file'] ) && is_readable( $i['file'] ) ) ? $i : null;
	}

	public static function download_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=usmd_connector_download' ), 'usmd_connector_download' );
	}

	/**
	 * Store a connector ZIP if it is newer than the copy we have (or we have none).
	 *
	 * @param string $src     Path to a checked connector ZIP.
	 * @param string $version Its version, from the plugin header.
	 * @param bool   $move    Move instead of copy (an upload's temporary file).
	 * @return bool Whether it was stored.
	 */
	public static function keep( $src, $version, $move = false ) {
		$version = preg_replace( '/[^0-9A-Za-z.\-]/', '', (string) $version );
		if ( '' === $version || ! is_readable( $src ) ) {
			return false;
		}
		$have = self::info();
		if ( $have && version_compare( $version, $have['version'], '<' ) ) {
			return false; // Never replace a newer copy with an older one.
		}
		self::protect();
		$dest = self::dir() . '/unstoppable-site-connector-' . $version . '.zip';
		$ok   = copy( $src, $dest );
		if ( $ok && $move ) {
			wp_delete_file( $src ); // The upload's temporary file.
		}
		if ( ! $ok ) {
			return false;
		}
		if ( $have && $have['file'] !== $dest ) {
			wp_delete_file( $have['file'] );
		}
		update_option(
			self::OPT,
			array(
				'file'    => $dest,
				'version' => $version,
				'time'    => time(),
			),
			false
		);
		return true;
	}

	/** Upload form on Add Site. */
	public static function handle_upload() {
		if ( ! current_user_can( USMD_Security::CAP ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( 'usmd_connector_upload' );
		$back = admin_url( 'admin.php?page=usmd-add' );
		$file = isset( $_FILES['zip'] ) ? $_FILES['zip'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
		$err  = '';

		if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$err = __( 'The file did not upload. Try again.', 'unstoppable-site-manager' );
		} elseif ( (int) $file['size'] > self::MAX_BYTES ) {
			$err = __( 'That file is too large to be the connector.', 'unstoppable-site-manager' );
		} else {
			$check   = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( (string) $file['name'] ), array( 'zip' => 'application/zip' ) );
			$headers = 'zip' === $check['ext'] ? USMD_Packages::zip_headers( $file['tmp_name'] ) : false;
			if ( ! $headers || self::NAME !== $headers['name'] ) {
				$err = __( 'That ZIP is not Unstoppable Site Connector.', 'unstoppable-site-manager' );
			} elseif ( ! self::keep( $file['tmp_name'], $headers['version'], true ) ) {
				$have = self::info();
				$err  = $have
					/* translators: %s: version number. */
					? sprintf( __( 'You already have version %s here, which is the same or newer.', 'unstoppable-site-manager' ), $have['version'] )
					: __( 'The file could not be saved. Check that wp-content/uploads is writable.', 'unstoppable-site-manager' );
			}
		}
		if ( $err ) {
			set_transient( 'usmd_add_error_' . get_current_user_id(), $err, 120 );
			wp_safe_redirect( $back );
		} else {
			wp_safe_redirect( add_query_arg( 'connector', 'saved', $back ) );
		}
		exit;
	}

	/** Stream the stored ZIP to an administrator. */
	public static function handle_download() {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( 'usmd_connector_download' );
		$i = self::info();
		if ( ! $i ) {
			wp_die( esc_html__( 'No copy of the connector is stored yet. Upload it on the Add Site screen.', 'unstoppable-site-manager' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="unstoppable-site-connector-' . $i['version'] . '.zip"' );
		header( 'Content-Length: ' . filesize( $i['file'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $i['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming a file we stored.
		exit;
	}
}
