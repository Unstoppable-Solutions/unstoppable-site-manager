<?php
/**
 * Plugin Name: Unstoppable PageSpeed Relay
 * Description: Runs Google PageSpeed Insights checks for Unstoppable Site Manager dashboards using your Google API key, which never leaves this site. Each dashboard and each address gets a daily allowance, so no one can use up the key.
 * Version: 1.0.0
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
	const API       = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const CACHE_TTL = HOUR_IN_SECONDS;  // The same page asked again within an hour gets the last answer.

	/** Daily allowances (changeable under Settings > PageSpeed Relay). */
	const DEFAULTS = array(
		'per_dashboard' => 400,   // One dashboard checking 339 sites weekly uses about 50 a day.
		'per_ip'        => 800,
		'per_day'       => 20000, // Google allows 25,000 a day per key; keep a margin.
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_unstoppable_psi_relay', array( __CLASS__, 'save' ) );
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
				// Public on purpose: any Unstoppable Site Manager dashboard may ask. Abuse is held back by the daily allowances.
				'permission_callback' => '__return_true',
				'args'                => array(
					'url' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	private static function fail( $code, $message ) {
		return new WP_REST_Response( array( 'error' => array( 'code' => $code, 'message' => $message ) ), $code );
	}

	/** Today's counters: total, per dashboard, per IP. */
	private static function counters() {
		$c = get_transient( 'unstoppable_psi_' . gmdate( 'Ymd' ) );
		return is_array( $c ) ? $c : array( 'total' => 0, 'dash' => array(), 'ip' => array(), 'cached' => 0, 'refused' => 0 );
	}

	private static function save_counters( $c ) {
		set_transient( 'unstoppable_psi_' . gmdate( 'Ymd' ), $c, 2 * DAY_IN_SECONDS );
	}

	public static function pagespeed( WP_REST_Request $req ) {
		nocache_headers();
		$key = self::key();
		if ( '' === $key ) {
			return self::fail( 503, 'The speed service is not set up yet.' );
		}

		$url = esc_url_raw( (string) $req->get_param( 'url' ), array( 'http', 'https' ) );
		// wp_http_validate_url refuses local and private network addresses.
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$ip_ok = ! filter_var( $host, FILTER_VALIDATE_IP ) || filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( '' === $url || '' === $host || 'localhost' === $host || '.local' === substr( $host, -6 ) || ! $ip_ok || ! wp_http_validate_url( $url ) ) {
			return self::fail( 400, 'That is not a public web address.' );
		}
		$dash = strtolower( (string) $req->get_header( 'x_usmd_dashboard' ) );
		if ( ! preg_match( '/^[a-f0-9]{16,64}$/', $dash ) ) {
			return self::fail( 400, 'Missing dashboard id.' );
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// The same page asked again soon: the last answer, free.
		$ck     = 'unstoppable_psi_r_' . md5( $url );
		$cached = get_transient( $ck );
		$c      = self::counters();
		if ( is_array( $cached ) ) {
			++$c['cached'];
			self::save_counters( $c );
			return new WP_REST_Response( $cached, 200 );
		}

		$o  = self::opts();
		$nd = isset( $c['dash'][ $dash ] ) ? $c['dash'][ $dash ] : 0;
		$ni = isset( $c['ip'][ $ip ] ) ? $c['ip'][ $ip ] : 0;
		if ( $c['total'] >= (int) $o['per_day'] || $nd >= (int) $o['per_dashboard'] || $ni >= (int) $o['per_ip'] ) {
			++$c['refused'];
			self::save_counters( $c );
			return self::fail( 429, 'relay_limit' );
		}
		++$c['total'];
		$c['dash'][ $dash ] = $nd + 1;
		$c['ip'][ $ip ]     = $ni + 1;
		self::save_counters( $c );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Google can take up to a minute.
		}
		$res = wp_remote_get(
			add_query_arg(
				array(
					'url'      => rawurlencode( $url ),
					'strategy' => 'mobile',
					'category' => 'performance',
					'key'      => rawurlencode( $key ),
				),
				self::API
			),
			array( 'timeout' => 90 )
		);
		if ( is_wp_error( $res ) ) {
			return self::fail( 502, 'Could not reach Google: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$d    = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $d ) ) {
			$msg = is_array( $d ) && isset( $d['error']['message'] ) ? (string) $d['error']['message'] : 'Google answered with HTTP ' . $code . '.';
			// Never pass on anything that could hold the key.
			$msg = str_replace( $key, '[key]', $msg );
			return self::fail( 429 === $code ? 429 : ( $code >= 400 ? $code : 502 ), 429 === $code ? 'relay_limit' : $msg );
		}

		// Only what the dashboard reads: the score and five timings.
		$lr  = isset( $d['lighthouseResult'] ) ? $d['lighthouseResult'] : array();
		$out = array( 'lighthouseResult' => array( 'categories' => array(), 'audits' => array() ) );
		if ( isset( $lr['categories']['performance']['score'] ) ) {
			$out['lighthouseResult']['categories']['performance'] = array( 'score' => $lr['categories']['performance']['score'] );
		}
		foreach ( array( 'largest-contentful-paint', 'first-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index' ) as $a ) {
			if ( isset( $lr['audits'][ $a ]['displayValue'] ) ) {
				$out['lighthouseResult']['audits'][ $a ] = array( 'displayValue' => (string) $lr['audits'][ $a ]['displayValue'] );
			}
		}
		if ( isset( $lr['runtimeError']['message'] ) ) {
			$out['lighthouseResult']['runtimeError'] = array( 'message' => (string) $lr['runtimeError']['message'] );
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

	public static function page() {
		$o   = self::opts();
		$c   = self::counters();
		$has = '' !== self::key();
		arsort( $c['dash'] );
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
					<tr><th>Daily allowances</th><td>
						<label>Per dashboard <input type="number" min="1" name="per_dashboard" value="<?php echo (int) $o['per_dashboard']; ?>" class="small-text"></label>
						&nbsp; <label>Per address <input type="number" min="1" name="per_ip" value="<?php echo (int) $o['per_ip']; ?>" class="small-text"></label>
						&nbsp; <label>Total <input type="number" min="1" name="per_day" value="<?php echo (int) $o['per_day']; ?>" class="small-text"></label>
						<p class="description">Google allows 25,000 checks a day per key. Days start at midnight UTC.</p>
					</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2>Today (UTC)</h2>
			<p>
				Checks sent to Google: <strong><?php echo (int) $c['total']; ?></strong> of <?php echo (int) $o['per_day']; ?>
				&middot; Answered from the last hour: <?php echo (int) $c['cached']; ?>
				&middot; Refused (over an allowance): <?php echo (int) $c['refused']; ?>
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
