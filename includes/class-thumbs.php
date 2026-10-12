<?php
/**
 * Site screenshot thumbnails.
 *
 * WordPress can't render web pages itself, so a screenshot service takes the
 * picture and the dashboard keeps a cropped copy in uploads/usmd-thumbs/.
 *
 * WordPress.com mShots (the free default) refuses requests from many hosting
 * servers, but works from a normal web browser. So with mShots, your browser
 * fetches the screenshot while you have the dashboard open and hands a copy
 * to the dashboard to store. A custom (usually paid, key-based) service is
 * called from the server on a schedule instead.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Thumbs {

	const MAX_AGE   = 604800; // Refresh weekly.
	const RETRY     = 90;
	const MAX_TRIES = 6;
	const WIDTH     = 800;
	const HEIGHT    = 600;
	const MIN_WIDTH = 600;    // Smaller images are "generating…" placeholders, not screenshots.
	const MAX_BYTES = 8388608;

	public static function hooks() {
		add_action( 'usmd_thumb', array( __CLASS__, 'capture' ), 10, 2 );
	}

	/* ---------- settings ---------- */

	public static function provider() {
		$s = USMD_Admin::settings();
		return in_array( $s['thumb_provider'], array( 'mshots', 'custom', 'off' ), true ) ? $s['thumb_provider'] : 'mshots';
	}

	/** True when screenshots are fetched by the browser (mShots). */
	public static function browser_mode() {
		return 'mshots' === self::provider();
	}

	public static function mshots_url( $site_url ) {
		return 'https://s0.wp.com/mshots/v1/' . rawurlencode( trailingslashit( $site_url ) ) . '?w=1280';
	}

	public static function provider_url( $site_url ) {
		switch ( self::provider() ) {
			case 'off':
				return '';
			case 'custom':
				$s = USMD_Admin::settings();
				$t = trim( (string) $s['thumb_template'] );
				return ( '' === $t || false === strpos( $t, '{url}' ) ) ? '' : str_replace( '{url}', rawurlencode( $site_url ), $t );
			default:
				return self::mshots_url( $site_url );
		}
	}

	/* ---------- storage ---------- */

	public static function dir() {
		$u = wp_upload_dir( null, false );
		return array(
			'path' => trailingslashit( $u['basedir'] ) . 'usmd-thumbs',
			'url'  => trailingslashit( $u['baseurl'] ) . 'usmd-thumbs',
		);
	}

	private static function ensure_dir() {
		$d = self::dir();
		if ( ! wp_mkdir_p( $d['path'] ) ) {
			return false;
		}
		if ( ! file_exists( $d['path'] . '/index.php' ) ) {
			file_put_contents( $d['path'] . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $d['path'];
	}

	public static function url( $site ) {
		if ( ! $site || empty( $site->thumb_file ) ) {
			return '';
		}
		$d = self::dir();
		if ( ! file_exists( $d['path'] . '/' . $site->thumb_file ) ) {
			return '';
		}
		return $d['url'] . '/' . rawurlencode( $site->thumb_file ) . '?v=' . ( $site->thumb_at ? strtotime( $site->thumb_at . ' UTC' ) : 0 );
	}

	public static function delete( $site ) {
		if ( $site && ! empty( $site->thumb_file ) ) {
			$d = self::dir();
			wp_delete_file( $d['path'] . '/' . basename( $site->thumb_file ) );
		}
	}

	public static function is_due( $site ) {
		return 'off' !== self::provider()
			&& ( empty( $site->thumb_at ) || ( time() - strtotime( $site->thumb_at . ' UTC' ) ) > self::MAX_AGE );
	}

	/**
	 * Validate, crop and save an image file as the site's thumbnail.
	 *
	 * @return string 'ok', 'pending' (a placeholder image), or an error message.
	 */
	public static function store( $site, $tmp ) {
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return 'pending'; // GIF "generating" images and HTML error pages land here.
		}
		if ( $info[0] < self::MIN_WIDTH ) {
			return 'pending';
		}

		$dir = self::ensure_dir();
		if ( ! $dir ) {
			return 'Could not create the uploads/usmd-thumbs folder. Check that wp-content/uploads is writable.';
		}

		// Unguessable name so thumbnails can't be enumerated.
		$name   = 'site-' . (int) $site->id . '-' . substr( hash_hmac( 'sha256', $site->site_uuid . microtime(), wp_salt( 'auth' ) ), 0, 16 ) . '.jpg';
		$saved  = false;
		$editor = wp_get_image_editor( $tmp );
		if ( ! is_wp_error( $editor ) ) {
			$editor->set_quality( 82 );
			$size   = $editor->get_size();
			$crop_h = (int) min( $size['height'], round( $size['width'] * 3 / 4 ) ); // Keep the top: header + hero.
			$editor->crop( 0, 0, $size['width'], $crop_h, self::WIDTH, self::HEIGHT );
			$saved = ! is_wp_error( $editor->save( $dir . '/' . $name, 'image/jpeg' ) );
		}
		if ( ! $saved ) {
			$ext   = 'image/png' === $info['mime'] ? '.png' : ( 'image/webp' === $info['mime'] ? '.webp' : '.jpg' );
			$name  = substr( $name, 0, -4 ) . $ext;
			$saved = (bool) copy( $tmp, $dir . '/' . $name );
		}
		if ( ! $saved ) {
			return 'Could not save the screenshot in the uploads folder.';
		}

		self::delete( $site );
		USMD_Sites::set_thumb( $site->id, $name );
		for ( $n = 1; $n <= self::MAX_TRIES; $n++ ) {
			wp_clear_scheduled_hook( 'usmd_thumb', array( (int) $site->id, $n ) );
		}
		return 'ok';
	}

	/* ---------- server-side capture (custom services) ---------- */

	public static function schedule( $site_id, $delay = 5 ) {
		if ( 'custom' !== self::provider() || '' === self::provider_url( 'https://example.com' ) ) {
			return; // mShots is fetched by the browser instead.
		}
		$args = array( (int) $site_id, 1 );
		if ( ! wp_next_scheduled( 'usmd_thumb', $args ) ) {
			wp_schedule_single_event( time() + (int) $delay, 'usmd_thumb', $args );
		}
	}

	/**
	 * Fetch a screenshot from the server.
	 *
	 * @return string 'ok', 'pending', 'off', or an error message (also stored on the site).
	 */
	public static function capture( $site_id, $attempt = 1 ) {
		$site = USMD_Sites::get( $site_id );
		if ( ! $site ) {
			return 'Site not found.';
		}
		$src = self::provider_url( $site->url );
		if ( '' === $src ) {
			return 'off';
		}

		$r      = self::fetch( $src );
		$result = 'error' === $r['state'] ? $r['message'] : 'pending';
		if ( 'image' === $r['state'] ) {
			$result = self::store( $site, $r['file'] );
			wp_delete_file( $r['file'] );
		}
		if ( 'ok' === $result ) {
			return 'ok';
		}

		$retryable = 'pending' === $result || in_array( $r['code'], array( 0, 429, 500, 502, 503, 504 ), true );
		if ( $retryable && $attempt < self::MAX_TRIES ) {
			wp_schedule_single_event( time() + self::RETRY * $attempt, 'usmd_thumb', array( (int) $site->id, $attempt + 1 ) );
			return 'pending';
		}
		$message = 'pending' === $result ? 'The screenshot service never returned a finished screenshot.' : $result;
		USMD_Sites::set_thumb_error( $site->id, $message );
		return $message;
	}

	/**
	 * @return array state: image|pending|error, code, type, bytes, width, height, file, message
	 */
	public static function fetch( $src ) {
		$is_mshots = 0 === strpos( $src, 'https://s0.wp.com/mshots/' );
		$response  = wp_remote_get(
			$src,
			array(
				'timeout'     => 45,
				'redirection' => $is_mshots ? 0 : 3, // mShots redirects to its "generating" image.
				'user-agent'  => 'Mozilla/5.0 (compatible; Unstoppable-Site-Manager/' . USMD_VERSION . '; +' . home_url() . ')',
			)
		);
		$out = array( 'state' => 'error', 'code' => 0, 'type' => '', 'bytes' => 0, 'width' => 0, 'height' => 0, 'file' => '', 'message' => '' );

		if ( is_wp_error( $response ) ) {
			$out['message'] = 'Could not reach the screenshot service: ' . $response->get_error_message();
			return $out;
		}
		$out['code']  = (int) wp_remote_retrieve_response_code( $response );
		$out['type']  = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body         = wp_remote_retrieve_body( $response );
		$out['bytes'] = strlen( $body );

		if ( $out['code'] >= 300 && $out['code'] < 400 ) {
			$out['state'] = 'pending';
			return $out;
		}
		if ( 403 === $out['code'] || 401 === $out['code'] ) {
			$out['message'] = 'The screenshot service refused this server (HTTP ' . $out['code'] . ').';
			return $out;
		}
		if ( 429 === $out['code'] ) {
			$out['message'] = 'The screenshot service is rate-limiting this server (HTTP 429).';
			return $out;
		}
		if ( $out['code'] < 200 || $out['code'] >= 300 ) {
			$out['message'] = 'The screenshot service answered HTTP ' . $out['code'] . '.';
			return $out;
		}
		if ( $out['bytes'] < 1000 || $out['bytes'] > self::MAX_BYTES ) {
			$out['state'] = 'pending';
			return $out;
		}

		$tmp = wp_tempnam( 'usmd-thumb' );
		file_put_contents( $tmp, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $info ) {
			$out['width']  = (int) $info[0];
			$out['height'] = (int) $info[1];
		}
		$out['file']  = $tmp;
		$out['state'] = 'image';
		return $out;
	}
}
