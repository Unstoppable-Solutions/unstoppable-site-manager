<?php
/**
 * The dashboard's Ed25519 keypair and the shared signing format.
 * Must stay byte-for-byte compatible with FMC_Auth::canonical_string().
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Keys {

	const OPT      = 'usmd_keys';
	const MAX_SKEW = 300;

	/**
	 * The signing keypair. The secret half comes from the USMD_SECRET_KEY constant in
	 * wp-config.php when it is set (and matches), otherwise from the database.
	 *
	 * A new keypair is only ever made on a fresh install. If the secret key goes
	 * missing later (moved to wp-config.php and the line was lost), signing stops
	 * with a clear message instead of silently making a new key, which would
	 * disconnect every site.
	 *
	 * @return array { public, secret } (secret is '' when it is missing).
	 */
	public static function get() {
		$k = get_option( self::OPT );
		if ( ! is_array( $k ) || empty( $k['public'] ) ) {
			$from_config = self::config_secret();
			if ( ! $from_config && ( defined( 'USMD_SECRET_KEY' ) || self::has_sites() ) ) {
				// Sites already trust a key we no longer have: never replace it silently.
				return array(
					'public' => '',
					'secret' => '',
				);
			}
			if ( $from_config ) {
				$k = array(
					'public' => base64_encode( sodium_crypto_sign_publickey_from_secretkey( base64_decode( $from_config ) ) ),
					'secret' => '',
				);
				update_option( self::OPT, $k, false );
			} else {
				$pair = sodium_crypto_sign_keypair();
				$k    = array(
					'public' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
					'secret' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ),
				);
				update_option( self::OPT, $k, false );
			}
		}
		$secret = '';
		if ( self::uses_config( self::storage( $k ) ) ) {
			$secret = self::config_secret();
		} elseif ( ! empty( $k['secret'] ) ) {
			$secret = $k['secret'];
		}
		return array(
			'public' => $k['public'],
			'secret' => $secret,
		);
	}

	private static function has_sites() {
		global $wpdb;
		$t = $wpdb->prefix . 'usmd_sites';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		return (bool) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin's own table; name from $wpdb->prefix.
	}

	/** Make a brand-new keypair (Security screen, after the old key was lost). */
	public static function regenerate() {
		$pair = sodium_crypto_sign_keypair();
		update_option(
			self::OPT,
			array(
				'public' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
				'secret' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ),
			),
			false
		);
	}

	/** A well-formed secret key from wp-config.php, or ''. */
	private static function config_secret() {
		if ( ! defined( 'USMD_SECRET_KEY' ) ) {
			return '';
		}
		$raw = base64_decode( (string) USMD_SECRET_KEY, true );
		return ( false !== $raw && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $raw ) ) ? (string) USMD_SECRET_KEY : '';
	}

	/**
	 * Where the secret key lives.
	 *
	 * @return string config | database | both | mismatch | missing
	 *   both: in wp-config.php and still in the database (remove it from the database).
	 *   mismatch: wp-config.php has a different key; the database key is used.
	 */
	public static function storage( $k = null ) {
		$k      = is_array( $k ) ? $k : get_option( self::OPT );
		$k      = is_array( $k ) ? $k : array();
		$config = self::config_secret();
		if ( empty( $k['public'] ) && ! $config ) {
			return 'missing';
		}
		$in_db  = ! empty( $k['secret'] );
		if ( $config ) {
			$pub = base64_encode( sodium_crypto_sign_publickey_from_secretkey( base64_decode( $config ) ) );
			if ( empty( $k['public'] ) || hash_equals( $k['public'], $pub ) ) {
				return $in_db ? 'both' : 'config';
			}
			return $in_db ? 'mismatch' : 'missing';
		}
		if ( defined( 'USMD_SECRET_KEY' ) ) {
			return $in_db ? 'mismatch' : 'missing'; // Set, but not a valid key.
		}
		return $in_db ? 'database' : 'missing';
	}

	/** Same as config: 'both' signs with the wp-config.php key. */
	private static function uses_config( $state ) {
		return in_array( $state, array( 'config', 'both' ), true );
	}

	/** The line to paste into wp-config.php. Only while the secret is still in the database. */
	public static function config_line() {
		$k = get_option( self::OPT );
		if ( ! is_array( $k ) || empty( $k['secret'] ) ) {
			return '';
		}
		return "define( 'USMD_SECRET_KEY', '" . $k['secret'] . "' );";
	}

	/** Remove the secret from the database once wp-config.php holds the same key. */
	public static function remove_db_secret() {
		$k = get_option( self::OPT );
		if ( 'both' !== self::storage( $k ) ) {
			return false;
		}
		$k['secret'] = '';
		update_option( self::OPT, $k, false );
		return true;
	}

	public static function public_key() {
		$k = self::get();
		return $k['public'];
	}

	public static function canonical( $method, $path, $ts, $nonce, $body ) {
		return implode( "\n", array( strtoupper( (string) $method ), (string) $path, (string) $ts, (string) $nonce, hash( 'sha256', (string) $body ) ) );
	}

	/** @return array|WP_Error Signature headers, or an error when the secret key is missing. */
	public static function sign_headers( $path, $body ) {
		$k = self::get();
		if ( '' === $k['secret'] ) {
			return new WP_Error( 'usmd_no_key', __( "This dashboard's signing key is missing, so it can't send requests to your sites. If you moved it to wp-config.php, put the USMD_SECRET_KEY line back.", 'unstoppable-site-manager' ) );
		}
		$ts    = (string) time();
		$nonce = bin2hex( random_bytes( 16 ) );
		$sig   = sodium_crypto_sign_detached( self::canonical( 'POST', $path, $ts, $nonce, $body ), base64_decode( $k['secret'] ) );
		return array(
			'X-FMC-Timestamp' => $ts,
			'X-FMC-Nonce'     => $nonce,
			'X-FMC-Signature' => base64_encode( $sig ),
		);
	}

	public static function verify( $pub_b64, $sig_b64, $message ) {
		$pub = base64_decode( (string) $pub_b64, true );
		$sig = base64_decode( (string) $sig_b64, true );
		if ( false === $pub || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) || false === $sig || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, $message, $pub );
		} catch ( Throwable $e ) {
			return false;
		}
	}
}
