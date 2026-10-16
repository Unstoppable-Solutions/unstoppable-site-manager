<?php
/**
 * AJAX actions. Bulk work is driven by the browser one site per request,
 * so the dashboard never runs 30 sites inside a single PHP request.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Ajax {

	public static function hooks() {
		foreach ( array( 'refresh', 'update', 'remove', 'thumb', 'thumb_upload', 'thumb_failed', 'thumb_test', 'star', 'login' ) as $a ) {
			add_action( 'wp_ajax_usmd_' . $a, array( __CLASS__, $a ) );
		}
		add_action( 'wp_ajax_usmd_nonce', array( __CLASS__, 'fresh_nonce' ) );
	}

	/**
	 * A new security token for a dashboard page left open past the nonce lifetime
	 * (12-24 hours), so it can carry on without a reload. Like core's
	 * wp_ajax_rest_nonce(): only for a signed-in user allowed to use the dashboard,
	 * and other sites can't read the answer (same-origin policy). Every action the
	 * token is then used for still checks it with check_ajax_referer().
	 */
	public static function fresh_nonce() {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'usmd' ) ) );
	}

	private static function site() {
		check_ajax_referer( 'usmd', 'nonce' );
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		$site = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 );
		if ( ! $site ) {
			wp_send_json_error( array( 'message' => 'Site not found.' ), 404 );
		}
		return $site;
	}

	public static function refresh() {
		$site = self::site();
		$r    = USMD_Client::refresh( $site, true );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message(), 'card' => USMD_Admin::card_html( USMD_Sites::get( $site->id ) ) ) );
		}
		if ( USMD_Vulns::enabled() ) {
			USMD_Vulns::check_site( USMD_Sites::get( $site->id ) ); // Cached lookups: usually instant.
		}
		wp_send_json_success( array( 'card' => USMD_Admin::card_html( USMD_Sites::get( $site->id ) ) ) );
	}

	public static function update() {
		$site  = self::site();
		$items = null;
		// phpcs:ignore WordPress.Security.NonceVerification -- checked in site().
		if ( ! empty( $_POST['items'] ) ) {
			$items = array();
			foreach ( (array) wp_unslash( $_POST['items'] ) as $raw ) { // phpcs:ignore
				$parts = explode( ':', sanitize_text_field( $raw ), 2 );
				if ( 'core' === $parts[0] ) {
					$items[] = array( 'type' => 'core' );
				} elseif ( in_array( $parts[0], array( 'plugin', 'theme' ), true ) && ! empty( $parts[1] ) ) {
					$items[] = array( 'type' => $parts[0], 'id' => $parts[1] );
				}
			}
		}

		$results = USMD_Client::update( $site, $items );
		if ( USMD_Vulns::enabled() ) {
			USMD_Vulns::check_site( USMD_Sites::get( $site->id ) );
		}
		$card    = USMD_Admin::card_html( USMD_Sites::get( $site->id ) );
		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message(), 'card' => $card ) );
		}

		$counts = array( 'updated' => 0, 'up_to_date' => 0, 'failed' => 0 );
		$errors = array();
		foreach ( $results as $r ) {
			$counts[ $r['result'] ] = isset( $counts[ $r['result'] ] ) ? $counts[ $r['result'] ] + 1 : 1;
			if ( 'failed' === $r['result'] ) {
				$errors[] = $r['id'] . ': ' . ( isset( $r['error'] ) ? $r['error'] : 'failed' );
			}
		}
		wp_send_json_success( array( 'counts' => $counts, 'errors' => $errors, 'results' => $results, 'card' => $card ) );
	}

	public static function thumb() {
		$site = self::site();
		$r    = USMD_Thumbs::capture( $site->id, 1 );
		$site = USMD_Sites::get( $site->id );
		if ( 'ok' === $r ) {
			wp_send_json_success( array( 'url' => USMD_Thumbs::url( $site ), 'card' => USMD_Admin::card_html( $site ) ) );
		}
		if ( 'pending' === $r ) {
			wp_send_json_success( array( 'pending' => true, 'message' => 'The screenshot service is rendering the page. It will appear in a minute or two.' ) );
		}
		wp_send_json_error( array( 'message' => 'off' === $r ? 'Screenshots are turned off in Unstoppable Site Manager > Settings.' : $r ) );
	}

	/** The browser fetched a screenshot (mShots) and sends it here to be stored. */
	public static function thumb_upload() {
		$site = self::site();
		$file = isset( $_FILES['image'] ) ? $_FILES['image'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce checked in site(); the upload is validated below.
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => 'No image was received.' ), 400 );
		}
		if ( (int) $file['size'] > USMD_Thumbs::MAX_BYTES ) {
			wp_send_json_error( array( 'message' => 'Image is too large.' ), 400 );
		}
		$r    = USMD_Thumbs::store( $site, $file['tmp_name'] );
		$site = USMD_Sites::get( $site->id );
		if ( 'ok' === $r ) {
			wp_send_json_success( array( 'url' => USMD_Thumbs::url( $site ), 'card' => USMD_Admin::card_html( $site ) ) );
		}
		if ( 'pending' === $r ) {
			wp_send_json_success( array( 'pending' => true ) );
		}
		USMD_Sites::set_thumb_error( $site->id, $r );
		wp_send_json_error( array( 'message' => $r ) );
	}

	/** The browser gave up on a screenshot; remember why so the site page can show it. */
	public static function thumb_failed() {
		$site = self::site();
		$msg  = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : 'Screenshot failed.'; // phpcs:ignore WordPress.Security.NonceVerification
		USMD_Sites::set_thumb_error( $site->id, $msg );
		wp_send_json_success();
	}

	/** Settings > Test: try the custom service from this server and report what came back. */
	public static function thumb_test() {
		check_ajax_referer( 'usmd', 'nonce' );
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		$tpl = isset( $_POST['template'] ) ? trim( wp_unslash( $_POST['template'] ) ) : ''; // phpcs:ignore
		if ( false === strpos( $tpl, '{url}' ) ) {
			wp_send_json_error( array( 'message' => 'The service URL needs {url} where the site address goes.' ) );
		}
		$src = str_replace( '{url}', rawurlencode( 'https://wordpress.org/' ), esc_url_raw( str_replace( '{url}', 'USMDURLTOKEN', $tpl ) ) );
		$src = str_replace( 'USMDURLTOKEN', rawurlencode( 'https://wordpress.org/' ), $src );
		$r   = USMD_Thumbs::fetch( $src );
		if ( $r['file'] ) {
			wp_delete_file( $r['file'] );
		}
		$detail = sprintf( 'HTTP %d, %s, %s bytes%s', $r['code'], $r['type'] ? $r['type'] : 'no type', number_format_i18n( $r['bytes'] ), $r['width'] ? sprintf( ', %d×%d', $r['width'], $r['height'] ) : '' );
		if ( 'image' === $r['state'] && $r['width'] >= USMD_Thumbs::MIN_WIDTH ) {
			wp_send_json_success( array( 'message' => 'Working. Got a screenshot (' . $detail . ').' ) );
		}
		if ( 'error' !== $r['state'] ) {
			wp_send_json_success( array( 'message' => 'The service answered but the screenshot isn\'t ready yet (' . $detail . '). That is normal on a first request; test again in a minute.' ) );
		}
		wp_send_json_error( array( 'message' => $r['message'] . ' (' . $detail . ')' ) );
	}

	/** Star or unstar a site (starred sites are pinned to the top). */
	public static function star() {
		$site    = self::site();
		$starred = ! empty( $_POST['starred'] ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in site().
		USMD_Sites::set_starred( $site->id, $starred );
		wp_send_json_success( array( 'starred' => $starred, 'card' => USMD_Admin::card_html( USMD_Sites::get( $site->id ) ) ) );
	}

	/** One-click login: returns a single-use link that signs the browser in to the site's wp-admin. */
	public static function login() {
		$site     = self::site();
		$redirect = isset( $_POST['redirect'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in site().
		$r        = USMD_Client::login_url( $site, $redirect );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error(
				array(
					'message'  => $r->get_error_message(),
					'fallback' => trailingslashit( $site->url ) . 'wp-admin/',
				)
			);
		}
		wp_send_json_success( $r );
	}

	public static function remove() {
		$site = self::site();
		USMD_Client::call( $site, 'unpair' ); // Best effort; remove locally either way.
		USMD_Sites::delete( $site->id );
		wp_send_json_success();
	}
}
