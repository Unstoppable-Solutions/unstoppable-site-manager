<?php
/**
 * Plugin Name: Unstoppable PageSpeed Relay
 * Description: Runs Google PageSpeed Insights checks for Unstoppable Site Manager dashboards using your Google API key, which never leaves this site. Each dashboard and each network address gets a daily allowance, and only a few checks run at once, so no one can use up the key or tie up this site.
 * Version: 1.1.0
 * Author: Unstoppable Solutions
 * Author URI: https://unstoppablesolutions.app
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 *
 * Install on one of your own WordPress sites (not a client site), then add the
 * key under Settings > PageSpeed Relay, or in wp-config.php:
 *     define( 'UNSTOPPABLE_PSI_KEY', 'AIza...' );
 *
 * Dashboards call:
 *     GET /wp-json/unstoppable-relay/v1/pagespeed?url=https://client-site.com/
 *     Header X-USMD-Dashboard: <anonymous id of the dashboard>
 * and get Google's answer back, trimmed to what the dashboard reads.
 */

defined( 'ABSPATH' ) || exit;

final class Unstoppable_PSI_Relay {

	const OPT       = 'unstoppable_psi_relay';
	const DB_VER    = 1;
	const API       = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const CACHE_TTL = HOUR_IN_SECONDS;  // The same page asked again within an hour gets the last answer.
	const TIMEOUT   = 70;               // Google takes 10 to 60 seconds a page.

	/** Limits (changeable under Settings > PageSpeed Relay). Days start at midnight UTC. */
	const DEFAULTS = array(
		'per_dashboard' => 300,   // A dashboard checking 339 sites weekly uses about 50 a day.
		'per_ip'        => 1000,  // Per network address (a whole IPv6 /64 counts as one). Shared hosts put many dashboards behind one address.
		'ids_per_ip'    => 20,    // Different dashboard ids one address may use in a day.
		'per_day'       => 20000, // Google allows 25,000 a day per key; keep a margin.
		'at_once'       => 4,     // Google checks running at the same time.
	);

	/** Cloudflare's published ranges (cloudflare.com/ips): only from these is CF-Connecting-IP trusted. */
	const CLOUDFLARE = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
		'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_unstoppable_psi_relay', array( __CLASS__, 'save' ) );
		register_activation_hook( __FILE__, array( __CLASS__, 'install' ) );
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'unstoppable_psi_counts';
	}

	/** One row per counter per day, so counting is a single atomic update. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				day char(8) NOT NULL,
				k varchar(100) NOT NULL,
				n int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,k)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::OPT . '_db', self::DB_VER, false );
	}

	public static function uninstall() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- this plugin's own table.
		delete_option( self::OPT );
		delete_option( self::OPT . '_db' );
	}

	private static function opts() {
		$o = get_option( self::OPT );
		return wp_parse_args( is_array( $o ) ? $o : array(), self::DEFAULTS + array( 'key' => '' ) );
	}

	private static function key() {
		if ( defined( 'UNSTOPPABLE_PSI_KEY' ) && UNSTOPPABLE_PSI_KEY ) {
			return (string) UNSTOPPABLE_PSI_KEY;
		}
		return (string) self::opts()['key'];
	}

	public static function routes() {
		register_rest_route(
			'unstoppable-relay/v1',
			'/pagespeed',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'pagespeed' ),
				// Public on purpose: any Unstoppable Site Manager dashboard may ask. Abuse is held back by the limits.
				'permission_callback' => '__return_true',
				'args'                => array(
					'url' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	private static function fail( $code, $message, $headers = array() ) {
		$r = new WP_REST_Response( array( 'error' => array( 'code' => $code, 'message' => $message ) ), $code );
		foreach ( $headers as $h => $v ) {
			$r->header( $h, $v );
		}
		return $r;
	}

	/* ---------- counting ---------- */

	/** Add one to a counter for today and return the new count. */
	private static function incr( $k ) {
		global $wpdb;
		if ( (int) get_option( self::OPT . '_db' ) < self::DB_VER ) {
			self::install();
		}
		$t   = self::table();
		$day = gmdate( 'Ymd' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this plugin's own counter table; atomic increments.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (day, k, n) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1', $t, $day, $k ) );
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT n FROM %i WHERE day = %s AND k = %s', $t, $day, $k ) );
		if ( 'total' === $k && 1 === $n ) {
			// First check of a new day: forget days older than yesterday.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', $t, gmdate( 'Ymd', time() - DAY_IN_SECONDS ) ) );
		}
		// phpcs:enable
		return $n;
	}

	/** The caller's network address: IPv4 as is, IPv6 by its /64, and the visitor behind Cloudflare. */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as an IP below.
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $ip, self::CLOUDFLARE ) ) {
			$cf = (string) $_SERVER['HTTP_CF_CONNECTING_IP']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as an IP below.
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				$ip = $cf;
			}
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return 'unknown';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			return 'v6:' . bin2hex( substr( $bin, 0, 8 ) ); // The /64 network.
		}
		return $ip;
	}

	private static function in_ranges( $ip, array $ranges ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- not an IP: just no match.
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $r ) {
			list( $net, $bits ) = explode( '/', $r );
			$nb = inet_pton( $net );
			if ( strlen( $nb ) !== strlen( $bin ) ) {
				continue;
			}
			$bytes = intdiv( (int) $bits, 8 );
			$rest  = (int) $bits % 8;
			if ( substr( $bin, 0, $bytes ) !== substr( $nb, 0, $bytes ) ) {
				continue;
			}
			if ( $rest ) {
				$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
				if ( ( $bin[ $bytes ] & $mask ) !== ( $nb[ $bytes ] & $mask ) ) {
					continue;
				}
			}
			return true;
		}
		return false;
	}

	/* ---------- running at most a few checks at once ---------- */

	/** @return string|null|false Lock name held, null when locks aren't available here, false when all are busy. */
	private static function take_slot( $slots ) {
		global $wpdb;
		$prefix = 'upsr_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 ) . '_';
		for ( $i = 0; $i < $slots; $i++ ) {
			$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $prefix . $i ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a MySQL named lock, not data.
			if ( '1' === (string) $got ) {
				return $prefix . $i;
			}
			if ( '0' !== (string) $got ) {
				return null; // No named locks here (not MySQL/MariaDB): checks aren't limited, rather than refused.
			}
		}
		return false;
	}

	private static function free_slot( $lock ) {
		global $wpdb;
		if ( $lock ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a MySQL named lock, not data.
		}
	}

	/* ---------- the check ---------- */

	/** The page to check, as scheme://host[:port]/path: no query or fragment, so it can't be used to dodge the cache. */
	private static function normalise( $raw ) {
		$url = esc_url_raw( (string) $raw, array( 'http', 'https' ) );
		$p   = wp_parse_url( $url );
		if ( ! $p || empty( $p['host'] ) || empty( $p['scheme'] ) ) {
			return '';
		}
		$host = strtolower( $p['host'] );
		$path = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
		return strtolower( $p['scheme'] ) . '://' . $host . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' ) . $path;
	}

	public static function pagespeed( WP_REST_Request $req ) {
		nocache_headers();
		$key = self::key();
		if ( '' === $key ) {
			return self::fail( 503, 'The speed service is not set up yet.' );
		}

		$url   = self::normalise( $req->get_param( 'url' ) );
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ip_ok = ! filter_var( $host, FILTER_VALIDATE_IP ) || filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		// wp_http_validate_url also refuses names that point at local or private network addresses.
		if ( '' === $url || 'localhost' === $host || '.local' === substr( $host, -6 ) || false === strpos( $host, '.' ) || ! $ip_ok || ! wp_http_validate_url( $url ) ) {
			return self::fail( 400, 'That is not a public web address.' );
		}
		$dash = strtolower( (string) $req->get_header( 'x_usmd_dashboard' ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $dash ) ) {
			return self::fail( 400, 'Missing dashboard id.' );
		}

		// The same page asked again soon: the last answer, free.
		$ck     = 'unstoppable_psi_r_' . md5( $url );
		$cached = get_transient( $ck );
		if ( is_array( $cached ) ) {
			self::incr( 'cached' );
			return new WP_REST_Response( $cached, 200 );
		}

		$o  = self::opts();
		$ip = self::client_ip();
		// Each address may only use a few dashboard ids a day, so making ids up gains nothing.
		if ( 1 === self::incr( 'ipd:' . md5( $ip . '|' . $dash ) ) && self::incr( 'ids:' . $ip ) > (int) $o['ids_per_ip'] ) {
			self::incr( 'refused' );
			return self::fail( 429, 'relay_limit' );
		}
		if ( self::incr( 'ip:' . $ip ) > (int) $o['per_ip'] || self::incr( 'd:' . $dash ) > (int) $o['per_dashboard'] ) {
			self::incr( 'refused' );
			return self::fail( 429, 'relay_limit' );
		}

		$lock = self::take_slot( max( 1, (int) $o['at_once'] ) );
		if ( false === $lock ) {
			self::incr( 'busy' );
			return self::fail( 503, 'relay_busy', array( 'Retry-After' => '120' ) );
		}
		if ( self::incr( 'total' ) > (int) $o['per_day'] ) {
			self::free_slot( $lock );
			self::incr( 'refused' );
			return self::fail( 429, 'relay_limit' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIMEOUT + 20 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Google can take up to a minute.
		}
		$res = wp_remote_get(
			add_query_arg(
				array(
					'url'      => rawurlencode( $url ),
					'strategy' => 'mobile',
					'category' => 'performance',
				),
				self::API
			),
			array(
				'timeout'             => self::TIMEOUT,
				'headers'             => array( 'X-Goog-Api-Key' => $key ), // In a header, not the address, so it stays out of request logs.
				'limit_response_size' => 8 * MB_IN_BYTES,
			)
		);
		self::free_slot( $lock );

		if ( is_wp_error( $res ) ) {
			return self::fail( 502, 'Could not reach Google. Try again later.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		$d    = json_decode( $body, true );
		if ( 200 !== $code || ! is_array( $d ) ) {
			// Fixed wording only: Google's own messages can name the Cloud project or the key's settings.
			if ( 429 === $code ) {
				return self::fail( 429, 'relay_limit' );
			}
			if ( false !== strpos( $body, 'FAILED_DOCUMENT_REQUEST' ) || false !== strpos( $body, 'ERRORED_DOCUMENT_REQUEST' ) || false !== strpos( $body, 'DNS_FAILURE' ) ) {
				return self::fail( 400, 'Google could not load the page: FAILED_DOCUMENT_REQUEST' );
			}
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Unstoppable PageSpeed Relay: Google answered HTTP ' . $code . ': ' . str_replace( $key, '[key]', substr( $body, 0, 300 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- only with WP_DEBUG_LOG on.
			}
			return self::fail( 502, 'Google could not check this page right now. Try again later.' );
		}

		// Only what the dashboard reads: the score and five timings.
		$lr  = isset( $d['lighthouseResult'] ) && is_array( $d['lighthouseResult'] ) ? $d['lighthouseResult'] : array();
		$out = array( 'lighthouseResult' => array( 'categories' => array(), 'audits' => array() ) );
		if ( isset( $lr['categories']['performance']['score'] ) && is_numeric( $lr['categories']['performance']['score'] ) ) {
			$out['lighthouseResult']['categories']['performance'] = array( 'score' => max( 0, min( 1, (float) $lr['categories']['performance']['score'] ) ) );
		}
		foreach ( array( 'largest-contentful-paint', 'first-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index' ) as $a ) {
			if ( isset( $lr['audits'][ $a ]['displayValue'] ) && is_scalar( $lr['audits'][ $a ]['displayValue'] ) ) {
				$out['lighthouseResult']['audits'][ $a ] = array( 'displayValue' => substr( (string) $lr['audits'][ $a ]['displayValue'], 0, 32 ) );
			}
		}
		if ( isset( $lr['runtimeError']['code'] ) && is_string( $lr['runtimeError']['code'] ) ) {
			$out['lighthouseResult']['runtimeError'] = array( 'message' => 'Google could not load the page: ' . substr( preg_replace( '/[^A-Z_]/', '', $lr['runtimeError']['code'] ), 0, 40 ) );
		}
		if ( isset( $out['lighthouseResult']['categories']['performance'] ) ) {
			set_transient( $ck, $out, self::CACHE_TTL );
		}
		return new WP_REST_Response( $out, 200 );
	}

	/* ---------- settings ---------- */

	public static function menu() {
		add_options_page( 'PageSpeed Relay', 'PageSpeed Relay', 'manage_options', 'unstoppable-psi-relay', array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'unstoppable_psi_relay' );
		$o = self::opts();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		if ( isset( $_POST['key'] ) && '' !== trim( (string) wp_unslash( $_POST['key'] ) ) ) {
			$o['key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', sanitize_text_field( wp_unslash( $_POST['key'] ) ) );
		}
		if ( ! empty( $_POST['clear_key'] ) ) {
			$o['key'] = '';
		}
		foreach ( array_keys( self::DEFAULTS ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$o[ $k ] = max( 1, absint( $_POST[ $k ] ) );
			}
		}
		// phpcs:enable
		update_option( self::OPT, $o, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=unstoppable-psi-relay&saved=1' ) );
		exit;
	}

	/** Today's counters. */
	private static function today() {
		global $wpdb;
		if ( (int) get_option( self::OPT . '_db' ) < self::DB_VER ) {
			self::install();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- this plugin's own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT k, n FROM %i WHERE day = %s', self::table(), gmdate( 'Ymd' ) ), ARRAY_A );
		$out  = array( 'total' => 0, 'cached' => 0, 'refused' => 0, 'busy' => 0, 'dash' => array() );
		foreach ( $rows as $r ) {
			if ( 0 === strpos( $r['k'], 'd:' ) ) {
				$out['dash'][ substr( $r['k'], 2 ) ] = (int) $r['n'];
			} elseif ( isset( $out[ $r['k'] ] ) ) {
				$out[ $r['k'] ] = (int) $r['n'];
			}
		}
		arsort( $out['dash'] );
		return $out;
	}

	public static function page() {
		$o   = self::opts();
		$c   = self::today();
		$has = '' !== self::key();
		?>
		<div class="wrap">
			<h1>Unstoppable PageSpeed Relay</h1>
			<p>Runs Google PageSpeed Insights for Unstoppable Site Manager dashboards with your key. The key stays on this site; dashboards only get the score and timings back.</p>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p>Saved.</p></div>
			<?php endif; ?>
			<p><strong>Relay address</strong> (for the dashboard plugin): <code><?php echo esc_html( rest_url( 'unstoppable-relay/v1/pagespeed' ) ); ?></code></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="unstoppable_psi_relay">
				<?php wp_nonce_field( 'unstoppable_psi_relay' ); ?>
				<table class="form-table" role="presentation">
					<tr><th>Google API key</th><td>
						<?php if ( defined( 'UNSTOPPABLE_PSI_KEY' ) && UNSTOPPABLE_PSI_KEY ) : ?>
							Set in wp-config.php.
						<?php else : ?>
							<input name="key" class="regular-text code" value="" placeholder="<?php echo $has ? esc_attr( 'Saved (…' . substr( self::key(), -4 ) . '). Paste a new one to replace it.' ) : 'AIza…'; ?>" autocomplete="off">
							<?php if ( $has ) : ?><label><input type="checkbox" name="clear_key" value="1"> Remove the key</label><?php endif; ?>
						<?php endif; ?>
						<p class="description">Restrict the key in Google Cloud to the PageSpeed Insights API only.</p>
					</td></tr>
					<tr><th>Daily limits</th><td>
						<label>Per dashboard <input type="number" min="1" name="per_dashboard" value="<?php echo (int) $o['per_dashboard']; ?>" class="small-text"></label>
						&nbsp; <label>Per network address <input type="number" min="1" name="per_ip" value="<?php echo (int) $o['per_ip']; ?>" class="small-text"></label>
						&nbsp; <label>Dashboards per address <input type="number" min="1" name="ids_per_ip" value="<?php echo (int) $o['ids_per_ip']; ?>" class="small-text"></label>
						&nbsp; <label>Total <input type="number" min="1" name="per_day" value="<?php echo (int) $o['per_day']; ?>" class="small-text"></label>
						<p class="description">Google allows 25,000 checks a day per key. Days start at midnight UTC.</p>
					</td></tr>
					<tr><th>At the same time</th><td>
						<label><input type="number" min="1" max="20" name="at_once" value="<?php echo (int) $o['at_once']; ?>" class="small-text"> checks</label>
						<p class="description">Each check keeps one of this site's PHP workers busy for up to a minute. More are turned away for a few minutes ("busy"), so the site itself stays up.</p>
					</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2>Today (UTC)</h2>
			<p>
				Checks sent to Google: <strong><?php echo (int) $c['total']; ?></strong> of <?php echo (int) $o['per_day']; ?>
				&middot; Answered from the last hour: <?php echo (int) $c['cached']; ?>
				&middot; Turned away (over a limit): <?php echo (int) $c['refused']; ?>
				&middot; Busy: <?php echo (int) $c['busy']; ?>
				&middot; Dashboards: <?php echo count( $c['dash'] ); ?>
			</p>
			<?php if ( $c['dash'] ) : ?>
				<table class="widefat striped" style="max-width:520px"><thead><tr><th>Dashboard (anonymous id)</th><th>Checks</th></tr></thead><tbody>
					<?php foreach ( array_slice( $c['dash'], 0, 20, true ) as $id => $n ) : ?>
						<tr><td><code><?php echo esc_html( substr( $id, 0, 12 ) ); ?>…</code></td><td><?php echo (int) $n; ?></td></tr>
					<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}

Unstoppable_PSI_Relay::init();
