<?php
/**
 * The Unstoppable Site Connector ZIP comes from WordPress.org.
 *
 * Nothing is stored on this dashboard: the Add Site screen links to the
 * WordPress.org download, and the bulk-connect copy is built from a fresh
 * WordPress.org download (in the server's temporary folder, deleted as soon
 * as it has been sent to the browser).
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Connector_Zip {

	const NAME = 'Unstoppable Site Connector';

	/** The current stable release on WordPress.org. */
	public static function download_url() {
		return 'https://downloads.wordpress.org/plugin/' . USMD_CONNECTOR_SLUG . '.latest-stable.zip';
	}

	/**
	 * Download the connector from WordPress.org to a temporary file.
	 *
	 * @return array|WP_Error { file, version }. The caller deletes the file.
	 */
	public static function fetch() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( self::download_url(), 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$h = USMD_Packages::zip_headers( $tmp );
		if ( ! $h || self::NAME !== $h['name'] ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'usmd_connector', __( 'WordPress.org did not return the connector.', 'unstoppable-site-manager' ) );
		}
		return array(
			'file'    => $tmp,
			'version' => $h['version'],
		);
	}
}
