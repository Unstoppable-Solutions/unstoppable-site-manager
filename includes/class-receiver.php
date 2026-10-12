<?php
/**
 * Receives signed status pushes from client sites: POST /wp-json/usmd/v1/push
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Receiver {

	public static function push_url() {
		return rest_url( 'usmd/v1/push' );
	}

	/** The path+query the client signs, derived exactly as the client derives it. */
	private static function signed_path( WP_REST_Request $request ) {
		// Sites paired before the rename push to the old address (fmd/v1, FM Dashboard).
		$url   = 0 === strpos( $request->get_route(), '/' . self::LEGACY_NS . '/' ) ? rest_url( self::LEGACY_NS . '/push' ) : self::push_url();
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' );
	}

	/** Namespace used by FM Dashboard, this plugin's former name; client sites paired before the rename still push here. */
	const LEGACY_NS = 'fmd/v1';

	public static function register() {
		$namespaces = array( 'usmd/v1' );
		if ( ! class_exists( 'FMD_Plugin' ) ) {
			$namespaces[] = self::LEGACY_NS; // Not while FM Dashboard itself is still running.
		}
		foreach ( $namespaces as $ns ) {
			register_rest_route(
				$ns,
				'/push',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'receive' ),
					'permission_callback' => '__return_true', // Verified by signature below.
				)
			);
		}
	}

	public static function receive( WP_REST_Request $request ) {
		$site = USMD_Sites::get_by_uuid( (string) $request->get_header( 'x-fmc-site' ) );
		if ( ! $site ) {
			return new WP_Error( 'usmd_unknown_site', 'Unknown site.', array( 'status' => 404 ) );
		}

		$ts    = (string) $request->get_header( 'x-fmc-timestamp' );
		$nonce = (string) $request->get_header( 'x-fmc-nonce' );
		$sig   = (string) $request->get_header( 'x-fmc-signature' );
		$body  = $request->get_body();

		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > USMD_Keys::MAX_SKEW ) {
			return new WP_Error( 'usmd_clock', 'Stale or missing timestamp.', array( 'status' => 401 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return new WP_Error( 'usmd_nonce', 'Malformed nonce.', array( 'status' => 401 ) );
		}
		if ( ! USMD_Keys::verify( $site->child_pubkey, $sig, USMD_Keys::canonical( 'POST', self::signed_path( $request ), $ts, $nonce, $body ) ) ) {
			return new WP_Error( 'usmd_bad_signature', 'Signature check failed.', array( 'status' => 401 ) );
		}

		$replay_key = 'usmd_n_' . md5( $site->id . $nonce );
		if ( get_transient( $replay_key ) ) {
			return new WP_Error( 'usmd_replay', 'Duplicate request.', array( 'status' => 401 ) );
		}
		set_transient( $replay_key, 1, USMD_Keys::MAX_SKEW * 2 + 60 );

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'usmd_json', 'Invalid JSON.', array( 'status' => 400 ) );
		}

		if ( ! empty( $data['status'] ) && is_array( $data['status'] ) ) {
			USMD_Sites::store_status( $site->id, $data['status'] );
		}
		$ack = USMD_Sites::add_activity( $site->id, isset( $data['activity'] ) && is_array( $data['activity'] ) ? $data['activity'] : array() );

		return rest_ensure_response( array( 'ack_activity_id' => $ack ) );
	}
}
