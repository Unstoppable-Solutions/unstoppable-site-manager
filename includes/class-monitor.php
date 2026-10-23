<?php
/**
 * Uptime monitoring and SSL / domain expiry alerts.
 *
 * Uptime: every few minutes the dashboard requests each client site's home page,
 * twenty at a time. A site that fails twice in a row (no answer, or a 5xx error) is
 * "down" and you get one email; when it answers again you get another, with how
 * long it was down. A 503 while the site is in maintenance mode counts as up.
 * If most sites fail in the same run, the problem is almost certainly this
 * dashboard's own connection, so no alerts go out for that run.
 *
 * SSL: once a day per site the dashboard opens a TLS connection to the site and
 * reads the certificate visitors get, so an expiring certificate is caught even
 * when the site can't report in.
 *
 * Domain (opt-in, off by default): once a week per domain, the registration
 * expiry date is looked up in public registry data (RDAP), through rdap.org,
 * which forwards to the domain's registry. Only the domain name is sent.
 *
 * Expiry emails go out once per step (SSL: 14, 7, 3, 1 days and expired;
 * domain: 30, 14, 7, 1 days and expired) and start again after a renewal.
 *
 * Everything runs on WP-Cron, on a five-minute event. Requests go only to your
 * client sites and, if you turn domain checks on, to rdap.org and the registry.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Monitor {

	const HOOK        = 'usmd_monitor_tick';
	const OPT_UPTIME  = 'usmd_uptime';      // site id => state.
	const OPT_EXPIRY  = 'usmd_expiry';      // site id => SSL check.
	const OPT_DOMAINS = 'usmd_domains';     // domain => RDAP result.
	const OPT_LAST    = 'usmd_uptime_last'; // When the last uptime run started.
	const OPT_NET     = 'usmd_uptime_net';  // When this dashboard last looked offline.
	const BATCH       = 20;                 // Sites requested at the same time.
	const FAILS       = 2;                  // Failures in a row before a site counts as down.
	const SSL_STEPS   = array( 14, 7, 3, 1, 0 );
	const DOM_STEPS   = array( 30, 14, 7, 1, 0 );
	const SSL_PER_RUN = 25;
	const DOM_PER_RUN = 10;

	public static function hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval -- five minutes, for uptime checks.
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
	}

	public static function schedules( $s ) {
		$s['usmd_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Unstoppable Site Manager uptime checks)', 'unstoppable-site-manager' ),
		);
		return $s;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'usmd_five_minutes', self::HOOK );
		}
	}

	private static function minutes() {
		$m = USMD_Admin::settings()['uptime'];
		return in_array( $m, array( '5', '15', '30' ), true ) ? (int) $m : 0;
	}

	public static function enabled() {
		return self::minutes() > 0;
	}

	public static function tick() {
		if ( USMD_Move::is_moved() ) {
			return;
		}
		$every = self::minutes();
		$last  = (int) get_option( self::OPT_LAST );
		if ( $every && time() - $last >= $every * MINUTE_IN_SECONDS - 30 && ! get_transient( 'usmd_uptime_running' ) ) {
			update_option( self::OPT_LAST, time(), false );
			set_transient( 'usmd_uptime_running', 1, 10 * MINUTE_IN_SECONDS ); // Never two runs at once on a big list.
			self::run_uptime();
			delete_transient( 'usmd_uptime_running' );
		}
		$alerts = self::check_ssl_due();
		if ( ! empty( USMD_Admin::settings()['domain_check'] ) ) {
			$alerts = array_merge( $alerts, self::check_domains_due() );
		}
		if ( $alerts && ! empty( USMD_Admin::settings()['expiry_alerts'] ) ) {
			self::email_expiry( $alerts );
		}
	}

	/* ---------- uptime ---------- */

	public static function states() {
		$s = get_option( self::OPT_UPTIME );
		return is_array( $s ) ? $s : array();
	}

	/** @return array { state: up|down|unknown, since, checked, code, ms, error, fails } */
	public static function state( $site_id ) {
		$all = self::states();
		$s   = isset( $all[ (int) $site_id ] ) ? $all[ (int) $site_id ] : array();
		return wp_parse_args(
			$s,
			array(
				'state'   => 'unknown',
				'since'   => 0,
				'checked' => 0,
				'code'    => 0,
				'ms'      => 0,
				'error'   => '',
				'fails'   => 0,
				'first'   => 0,
			)
		);
	}

	public static function is_down( $site_id ) {
		return self::enabled() && 'down' === self::state( $site_id )['state'];
	}

	private static function requests_class() {
		return class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : 'Requests';
	}

	/**
	 * Request every site's home page, BATCH at a time.
	 *
	 * @param object[]|null $sites Sites to check (all when null).
	 * @return array site id => [ ok, code, ms, error ].
	 */
	public static function probe( $sites = null ) {
		$sites  = null === $sites ? USMD_Sites::all() : $sites;
		$cls    = self::requests_class();
		$out    = array();
		$agent  = 'Mozilla/5.0 (compatible; UnstoppableSiteManager-Uptime/' . USMD_VERSION . '; +' . home_url( '/' ) . ')';
		$bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		$hooks_cls = class_exists( '\WpOrg\Requests\Hooks' ) ? '\WpOrg\Requests\Hooks' : 'Requests_Hooks';
		foreach ( array_chunk( $sites, self::BATCH ) as $chunk ) {
			$reqs  = array();
			$times = array();
			foreach ( $chunk as $site ) {
				$id = (int) $site->id;
				// Own hooks per request, to read each one's response time (curl's total_time).
				$hooks = new $hooks_cls();
				$hooks->register(
					'curl.after_request',
					static function ( $headers, $info = null ) use ( $id, &$times ) {
						if ( is_array( $info ) && isset( $info['total_time'] ) ) {
							$times[ $id ] = (int) round( $info['total_time'] * 1000 );
						}
					}
				);
				$reqs[ $id ] = array(
					'url'     => trailingslashit( $site->url ) . '?usmd_uptime=' . time(), // Skip page caches that hide an outage.
					'type'    => 'GET',
					'headers' => array( 'User-Agent' => $agent ),
					'options' => array(
						'timeout'          => 12,
						'connect_timeout'  => 10,
						'follow_redirects' => true,
						'redirects'        => 5,
						'max_bytes'        => 65536,
						'verify'           => file_exists( $bundle ) ? $bundle : true,
						'useragent'        => $agent,
						'hooks'            => $hooks,
					),
				);
			}
			$start = microtime( true );
			try {
				$res = call_user_func( array( $cls, 'request_multiple' ), $reqs, array( 'timeout' => 12 ) );
			} catch ( Exception $e ) {
				$res = array();
			}
			$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			foreach ( $chunk as $site ) {
				$id = (int) $site->id;
				$r  = isset( $res[ $id ] ) ? $res[ $id ] : null;
				if ( ! is_object( $r ) || ! isset( $r->status_code ) || $r instanceof Exception ) {
					$err        = $r instanceof Exception ? $r->getMessage() : __( 'No answer', 'unstoppable-site-manager' );
					$out[ $id ] = array( false, 0, $ms, self::short_error( $err ) );
					continue;
				}
				$code    = (int) $r->status_code;
				$maint   = 503 === $code && USMD_Maintenance::state( $site )['on'];
				$ok      = $code > 0 && ( $code < 500 || $maint );
				$elapsed = isset( $times[ $id ] ) ? $times[ $id ] : $ms;
				/* translators: %d: HTTP status code. */
				$out[ $id ] = array( $ok, $code, $elapsed, $ok ? '' : sprintf( __( 'Server error (HTTP %d)', 'unstoppable-site-manager' ), $code ) );
			}
		}
		return $out;
	}

	private static function short_error( $msg ) {
		$msg = (string) $msg;
		if ( preg_match( '/timed? ?out/i', $msg ) ) {
			return __( 'Timed out (no answer within 12 seconds)', 'unstoppable-site-manager' );
		}
		if ( preg_match( '/resolve host|getaddrinfo|Could not resolve/i', $msg ) ) {
			return __( 'Domain not found (DNS)', 'unstoppable-site-manager' );
		}
		if ( preg_match( '/SSL|certificate/i', $msg ) ) {
			return __( 'SSL error: the certificate is not accepted', 'unstoppable-site-manager' );
		}
		if ( preg_match( '/refused|connect/i', $msg ) ) {
			return __( 'Connection refused', 'unstoppable-site-manager' );
		}
		return substr( wp_strip_all_tags( $msg ), 0, 150 );
	}

	/** One uptime run: probe, update states, email changes. */
	public static function run_uptime() {
		$sites = USMD_Sites::all();
		if ( ! $sites ) {
			return array();
		}
		if ( ! get_option( 'usmd_uptime_since' ) ) {
			update_option( 'usmd_uptime_since', time(), false ); // Uptime figures cover the time from here on.
		}
		$res    = self::probe( $sites );
		$failed = count( array_filter( $res, static function ( $r ) { return ! $r[0]; } ) );
		$now    = time();
		$all    = self::states();

		// Most sites failing at once: this dashboard's connection, not the sites.
		if ( count( $sites ) >= 4 && $failed >= max( 3, (int) ceil( count( $sites ) * 0.6 ) ) ) {
			update_option( self::OPT_NET, $now, false );
			return array( 'network' => true );
		}

		$down = array();
		$up   = array();
		foreach ( $sites as $site ) {
			$id = (int) $site->id;
			if ( ! isset( $res[ $id ] ) ) {
				continue;
			}
			list( $ok, $code, $ms, $err ) = $res[ $id ];
			$s = isset( $all[ $id ] ) ? $all[ $id ] : array( 'state' => 'unknown', 'since' => $now, 'fails' => 0, 'first' => 0 );
			$s['checked'] = $now;
			$s['code']    = $code;
			$s['ms']      = $ms;
			if ( $ok ) {
				if ( 'down' === $s['state'] ) {
					$secs = $now - ( $s['first'] ? $s['first'] : $s['since'] );
					$up[] = array( $site, $secs );
					USMD_Sites::add_local_activity( $id, 'uptime', 'up', $site->url, array( 'down_for' => $secs ) );
				}
				if ( 'up' !== $s['state'] ) {
					$s['since'] = $now;
				}
				$s['state'] = 'up';
				$s['fails'] = 0;
				$s['first'] = 0;
				$s['error'] = '';
			} else {
				$s['fails'] = (int) $s['fails'] + 1;
				$s['error'] = $err;
				if ( ! $s['first'] ) {
					$s['first'] = $now;
				}
				if ( 'down' !== $s['state'] && $s['fails'] >= self::FAILS ) {
					$s['state'] = 'down';
					$s['since'] = $s['first'];
					$down[]     = array( $site, $err );
					USMD_Sites::add_local_activity( $id, 'uptime', 'down', $site->url, array( 'error' => $err, 'code' => $code ) );
				}
			}
			$all[ $id ] = $s;
		}
		// Forget sites that have been removed.
		$ids = array_map( 'intval', wp_list_pluck( $sites, 'id' ) );
		$all = array_intersect_key( $all, array_flip( $ids ) );
		update_option( self::OPT_UPTIME, $all, false );

		if ( ( $down || $up ) && ! empty( USMD_Admin::settings()['uptime_alerts'] ) ) {
			self::email_uptime( $down, $up );
		}
		return array(
			'down' => $down,
			'up'   => $up,
		);
	}

	public static function duration( $secs ) {
		$secs = max( 60, (int) $secs );
		return human_time_diff( 0, $secs );
	}

	private static function mail( $subject, $html ) {
		$s       = USMD_Admin::settings();
		$agency  = $s['agency_name'] ? $s['agency_name'] : get_bloginfo( 'name' );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $s['report_from_email'] ) ) {
			$headers[] = 'From: ' . ( $s['report_from_name'] ? $s['report_from_name'] : $agency ) . ' <' . $s['report_from_email'] . '>';
		}
		$color = sanitize_hex_color( $s['report_color'] );
		$color = $color ? $color : '#1e5aa8';
		$body  = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;max-width:640px;margin:0 auto;">'
			. $html
			. '<p style="margin:24px 0 0;"><a href="' . esc_url( admin_url( 'admin.php?page=unstoppable-site-manager' ) ) . '" style="display:inline-block;background:' . esc_attr( $color ) . ';color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;">' . esc_html__( 'Open the dashboard', 'unstoppable-site-manager' ) . '</a></p>'
			/* translators: %s: agency name. */
			. '<p style="margin:20px 0 0;font-size:12px;color:#8c8f94;">' . esc_html( sprintf( __( 'Sent by Unstoppable Site Manager for %s. Change these alerts under Settings > Uptime and expiry alerts.', 'unstoppable-site-manager' ), $agency ) ) . '</p></div>';
		return wp_mail( USMD_Watch::recipient(), $subject, $body, $headers );
	}

	private static function rows( array $rows ) {
		$h = '<table style="width:100%;border-collapse:collapse;table-layout:fixed;" role="presentation">';
		foreach ( $rows as $r ) {
			$h .= '<tr><td style="width:38%;padding:6px 12px 6px 0;border-bottom:1px solid #eee;vertical-align:top;"><a href="' . esc_url( USMD_Admin::site_url( $r[0]->id ) ) . '" style="font-weight:600;text-decoration:none;">' . esc_html( html_entity_decode( $r[0]->name, ENT_QUOTES, 'UTF-8' ) ) . '</a><br><span style="color:#8c8f94;font-size:13px;">' . esc_html( (string) wp_parse_url( $r[0]->url, PHP_URL_HOST ) ) . '</span></td>'
				. '<td style="padding:6px 0;border-bottom:1px solid #eee;color:#50575e;vertical-align:top;">' . esc_html( $r[1] ) . '</td></tr>';
		}
		return $h . '</table>';
	}

	private static function email_uptime( array $down, array $up ) {
		$html = '';
		if ( $down ) {
			$html .= '<h2 style="font-size:18px;margin:0 0 8px;color:#b32d2e;">' . esc_html(
				/* translators: %d: number of sites. */
				sprintf( _n( '%d site is down', '%d sites are down', count( $down ), 'unstoppable-site-manager' ), count( $down ) )
			) . '</h2>' . self::rows( $down );
		}
		if ( $up ) {
			$rows = array();
			foreach ( $up as $u ) {
				/* translators: %s: duration. */
				$rows[] = array( $u[0], sprintf( __( 'Back up after %s', 'unstoppable-site-manager' ), self::duration( $u[1] ) ) );
			}
			$html .= '<h2 style="font-size:18px;margin:' . ( $down ? '24px' : '0' ) . ' 0 8px;color:#00a32a;">' . esc_html(
				/* translators: %d: number of sites. */
				sprintf( _n( '%d site is back up', '%d sites are back up', count( $up ), 'unstoppable-site-manager' ), count( $up ) )
			) . '</h2>' . self::rows( $rows );
		}
		if ( 1 === count( $down ) + count( $up ) ) {
			$one     = $down ? $down[0][0] : $up[0][0];
			$name    = html_entity_decode( $one->name, ENT_QUOTES, 'UTF-8' );
			/* translators: %s: site name. */
			$subject = $down ? sprintf( __( 'Down: %s', 'unstoppable-site-manager' ), $name ) : sprintf( __( 'Back up: %s', 'unstoppable-site-manager' ), $name );
		} else {
			/* translators: 1: sites down, 2: sites back up. */
			$subject = sprintf( __( 'Uptime: %1$d down, %2$d back up', 'unstoppable-site-manager' ), count( $down ), count( $up ) );
		}
		self::mail( $subject, $html );
	}

	/**
	 * Uptime for a period, from the down/up entries in the activity log.
	 *
	 * @return float|null Percent, or null when monitoring didn't cover the period.
	 */
	public static function uptime_percent( $site_id, $from, $to ) {
		$to = min( $to, time() );
		if ( $to <= $from ) {
			return null;
		}
		$first_check = (int) get_option( 'usmd_uptime_since' );
		if ( ! $first_check || $first_check > $to ) {
			return null;
		}
		$from = max( $from, $first_check );
		if ( $to - $from < DAY_IN_SECONDS ) {
			return null; // Too short to mean anything yet.
		}
		$rows = USMD_Sites::activity( $site_id, 1000, gmdate( 'Y-m-d H:i:s', $from - 30 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $to ) );
		$down = 0;
		$open = null;
		foreach ( array_reverse( $rows ) as $r ) { // Oldest first.
			if ( 'uptime' !== $r->type ) {
				continue;
			}
			$t = strtotime( $r->created_at . ' UTC' );
			if ( 'down' === $r->action ) {
				$open = $t;
			} elseif ( 'up' === $r->action && null !== $open ) {
				$start = isset( $r->details['down_for'] ) ? $t - (int) $r->details['down_for'] : $open;
				$down += max( 0, min( $t, $to ) - max( $start, $from ) );
				$open  = null;
			}
		}
		if ( null !== $open ) {
			$down += max( 0, $to - max( $open, $from ) );
		}
		return max( 0, 100 * ( 1 - $down / ( $to - $from ) ) );
	}

	/* ---------- SSL ---------- */

	public static function expiry( $site_id ) {
		$all = get_option( self::OPT_EXPIRY );
		return ( is_array( $all ) && isset( $all[ (int) $site_id ] ) ) ? $all[ (int) $site_id ] : array();
	}

	/**
	 * Read the certificate a visitor gets.
	 *
	 * @return array { expires, issuer, error }
	 */
	public static function read_cert( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port = (int) wp_parse_url( $url, PHP_URL_PORT );
		$port = $port ? $port : 443;
		if ( '' === $host ) {
			return array( 'expires' => 0, 'issuer' => '', 'error' => __( 'No address', 'unstoppable-site-manager' ) );
		}
		$ctx = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false, // Read even an expired or mismatched certificate; validity is judged below.
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);
		$errno  = 0;
		$errstr = '';
		$sock   = @stream_socket_client( 'ssl://' . $host . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failed connection is reported below.
		if ( ! $sock ) {
			return array( 'expires' => 0, 'issuer' => '', 'error' => $errstr ? self::short_error( $errstr ) : __( 'Could not connect on port 443', 'unstoppable-site-manager' ) );
		}
		$params = stream_context_get_params( $sock );
		fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a network socket, not a file.
		$cert = isset( $params['options']['ssl']['peer_certificate'] ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : false;
		if ( ! $cert || empty( $cert['validTo_time_t'] ) ) {
			return array( 'expires' => 0, 'issuer' => '', 'error' => __( 'No certificate returned', 'unstoppable-site-manager' ) );
		}
		$issuer = isset( $cert['issuer']['O'] ) ? $cert['issuer']['O'] : ( isset( $cert['issuer']['CN'] ) ? $cert['issuer']['CN'] : '' );
		return array( 'expires' => (int) $cert['validTo_time_t'], 'issuer' => (string) $issuer, 'error' => '' );
	}

	/** @return array Alerts: [ kind, site, days, date ]. */
	public static function check_ssl_due( $force = false ) {
		$all    = get_option( self::OPT_EXPIRY );
		$all    = is_array( $all ) ? $all : array();
		$alerts = array();
		$done   = 0;
		foreach ( USMD_Sites::all() as $site ) {
			if ( 0 !== strpos( $site->url, 'https://' ) ) {
				continue;
			}
			$id = (int) $site->id;
			$e  = isset( $all[ $id ] ) ? $all[ $id ] : array();
			if ( ! $force && ! empty( $e['ssl_checked'] ) && time() - $e['ssl_checked'] < 20 * HOUR_IN_SECONDS ) {
				continue;
			}
			if ( $done++ >= self::SSL_PER_RUN ) {
				break; // The rest are checked in the next runs.
			}
			$c = self::read_cert( $site->url );
			if ( ! empty( $e['ssl_expires'] ) && $c['expires'] && $c['expires'] !== (int) $e['ssl_expires'] ) {
				unset( $e['ssl_step'] ); // Renewed: start the steps again.
			}
			$e['ssl_checked'] = time();
			$e['ssl_error']   = $c['error'];
			if ( $c['expires'] ) {
				$e['ssl_expires'] = $c['expires'];
				$e['ssl_issuer']  = $c['issuer'];
				$step             = self::step( $c['expires'], self::SSL_STEPS, isset( $e['ssl_step'] ) ? (int) $e['ssl_step'] : null );
				if ( null !== $step ) {
					$e['ssl_step'] = $step;
					$alerts[]      = array( 'ssl', $site, self::days_left( $c['expires'] ), $c['expires'] );
				}
			}
			$all[ $id ] = $e;
		}
		update_option( self::OPT_EXPIRY, $all, false );
		return $alerts;
	}

	public static function days_left( $ts ) {
		return (int) floor( ( $ts - time() ) / DAY_IN_SECONDS );
	}

	/**
	 * The alert step reached, if it's new.
	 *
	 * @param int|null $sent The step already emailed for this expiry date.
	 * @return int|null
	 */
	private static function step( $expires, array $steps, $sent ) {
		$days = self::days_left( $expires );
		$hit  = null;
		foreach ( $steps as $s ) {
			if ( $days <= $s ) {
				$hit = $s; // Smallest step reached.
			}
		}
		if ( null === $hit || ( null !== $sent && $sent <= $hit ) ) {
			return null;
		}
		return $hit;
	}

	/* ---------- domains ---------- */

	/** "shop.example.co.uk" -> "example.co.uk". Good for nearly all domains; RDAP corrects the rest. */
	public static function registrable( $host ) {
		$host = strtolower( preg_replace( '/^www\./', '', (string) $host ) );
		if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) || false === strpos( $host, '.' ) || 'localhost' === $host ) {
			return '';
		}
		$p   = explode( '.', $host );
		$n   = count( $p );
		$sld = $n >= 3 ? $p[ $n - 2 ] : '';
		$two = in_array( $sld, array( 'co', 'com', 'org', 'net', 'gov', 'ac', 'edu', 'ltd', 'plc', 'me', 'nom', 'sch', 'gen', 'or', 'ne', 'go' ), true ) && strlen( $p[ $n - 1 ] ) === 2;
		return implode( '.', array_slice( $p, $two ? -3 : -2 ) );
	}

	public static function domain_info( $site ) {
		$d   = self::registrable( wp_parse_url( $site->url, PHP_URL_HOST ) );
		$all = get_option( self::OPT_DOMAINS );
		return ( '' !== $d && is_array( $all ) && isset( $all[ $d ] ) ) ? array( 'domain' => $d ) + $all[ $d ] : array( 'domain' => $d );
	}

	/** @return array { expires, registrar, error } */
	public static function rdap( $domain ) {
		$r = wp_remote_get(
			'https://rdap.org/domain/' . rawurlencode( $domain ),
			array(
				'timeout'     => 12,
				'redirection' => 3,
				'headers'     => array( 'Accept' => 'application/rdap+json, application/json' ),
				'user-agent'  => 'UnstoppableSiteManager/' . USMD_VERSION,
			)
		);
		if ( is_wp_error( $r ) ) {
			return array( 'expires' => 0, 'registrar' => '', 'error' => self::short_error( $r->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 404 === $code ) {
			return array( 'expires' => 0, 'registrar' => '', 'error' => __( 'This domain\'s registry does not publish expiry dates (no RDAP).', 'unstoppable-site-manager' ) );
		}
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code || ! is_array( $j ) ) {
			/* translators: %d: HTTP status code. */
			return array( 'expires' => 0, 'registrar' => '', 'error' => sprintf( __( 'Lookup failed (HTTP %d)', 'unstoppable-site-manager' ), $code ) );
		}
		$exp = 0;
		foreach ( isset( $j['events'] ) ? (array) $j['events'] : array() as $ev ) {
			if ( isset( $ev['eventAction'], $ev['eventDate'] ) && 'expiration' === $ev['eventAction'] ) {
				$exp = (int) strtotime( $ev['eventDate'] );
			}
		}
		$registrar = '';
		foreach ( isset( $j['entities'] ) ? (array) $j['entities'] : array() as $ent ) {
			if ( in_array( 'registrar', isset( $ent['roles'] ) ? (array) $ent['roles'] : array(), true ) && isset( $ent['vcardArray'][1] ) ) {
				foreach ( (array) $ent['vcardArray'][1] as $v ) {
					if ( isset( $v[0], $v[3] ) && 'fn' === $v[0] ) {
						$registrar = (string) $v[3];
					}
				}
			}
		}
		return array(
			'expires'   => $exp,
			'registrar' => sanitize_text_field( $registrar ),
			'error'     => $exp ? '' : __( 'The registry did not give an expiry date.', 'unstoppable-site-manager' ),
		);
	}

	/** @return array Alerts. */
	public static function check_domains_due( $force = false ) {
		$all     = get_option( self::OPT_DOMAINS );
		$all     = is_array( $all ) ? $all : array();
		$alerts  = array();
		$done    = 0;
		$by_dom  = array();
		foreach ( USMD_Sites::all() as $site ) {
			$d = self::registrable( wp_parse_url( $site->url, PHP_URL_HOST ) );
			if ( '' !== $d ) {
				$by_dom[ $d ][] = $site;
			}
		}
		foreach ( $by_dom as $d => $sites ) {
			$e     = isset( $all[ $d ] ) ? $all[ $d ] : array();
			$soon  = ! empty( $e['expires'] ) && self::days_left( $e['expires'] ) <= 30;
			$every = $soon ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
			if ( ! $force && ! empty( $e['checked'] ) && time() - $e['checked'] < $every - HOUR_IN_SECONDS ) {
				continue;
			}
			if ( $done++ >= self::DOM_PER_RUN ) {
				break;
			}
			$r = self::rdap( $d );
			if ( ! empty( $e['expires'] ) && $r['expires'] && $r['expires'] !== (int) $e['expires'] ) {
				unset( $e['step'] ); // Renewed.
			}
			$e['checked'] = time();
			$e['error']   = $r['error'];
			if ( $r['expires'] ) {
				$e['expires']   = $r['expires'];
				$e['registrar'] = $r['registrar'];
				$step           = self::step( $r['expires'], self::DOM_STEPS, isset( $e['step'] ) ? (int) $e['step'] : null );
				if ( null !== $step ) {
					$e['step'] = $step;
					$alerts[]  = array( 'domain', $sites[0], self::days_left( $r['expires'] ), $r['expires'], $d );
				}
			}
			$all[ $d ] = $e;
		}
		$all = array_intersect_key( $all, $by_dom );
		update_option( self::OPT_DOMAINS, $all, false );
		return $alerts;
	}

	private static function email_expiry( array $alerts ) {
		$rows = array();
		foreach ( $alerts as $a ) {
			$when = wp_date( get_option( 'date_format' ), $a[3] );
			if ( 'ssl' === $a[0] ) {
				$text = $a[2] < 0
					/* translators: %s: date. */
					? sprintf( __( 'SSL certificate expired on %s', 'unstoppable-site-manager' ), $when )
					/* translators: 1: days, 2: date. */
					: sprintf( _n( 'SSL certificate expires in %1$d day (%2$s)', 'SSL certificate expires in %1$d days (%2$s)', $a[2], 'unstoppable-site-manager' ), $a[2], $when );
			} else {
				$text = $a[2] < 0
					/* translators: 1: domain, 2: date. */
					? sprintf( __( 'Domain %1$s expired on %2$s', 'unstoppable-site-manager' ), $a[4], $when )
					/* translators: 1: domain, 2: days, 3: date. */
					: sprintf( _n( 'Domain %1$s expires in %2$d day (%3$s)', 'Domain %1$s expires in %2$d days (%3$s)', $a[2], 'unstoppable-site-manager' ), $a[4], $a[2], $when );
			}
			$rows[] = array( $a[1], $text );
		}
		$html = '<h2 style="font-size:18px;margin:0 0 8px;">' . esc_html__( 'Renewal needed soon', 'unstoppable-site-manager' ) . '</h2>' . self::rows( $rows );
		/* translators: %d: number of items. */
		self::mail( 1 === count( $rows ) ? html_entity_decode( $rows[0][0]->name, ENT_QUOTES, 'UTF-8' ) . ': ' . $rows[0][1] : sprintf( __( '%d renewals coming up', 'unstoppable-site-manager' ), count( $rows ) ), $html );
	}

	/* ---------- display ---------- */

	/** Card line on the Sites screen when a site is down. */
	public static function pill( $site ) {
		if ( ! self::is_down( $site->id ) ) {
			return '';
		}
		$s = self::state( $site->id );
		/* translators: 1: time ago, 2: reason. */
		$title = sprintf( __( 'Not answering since %1$s: %2$s', 'unstoppable-site-manager' ), human_time_diff( $s['since'] ) . ' ago', $s['error'] );
		return '<span class="usmd-pill usmd-pill-bad usmd-pill-down" title="' . esc_attr( $title ) . '">' . esc_html__( 'Down', 'unstoppable-site-manager' ) . '</span>';
	}

	/** Rows for the site page Health panel. */
	public static function health_rows( $site ) {
		$rows = array();
		if ( self::enabled() ) {
			$s = self::state( $site->id );
			if ( 'down' === $s['state'] ) {
				/* translators: 1: time ago, 2: reason. */
				$rows[] = array( __( 'Uptime', 'unstoppable-site-manager' ), sprintf( __( 'Down since %1$s ago: %2$s', 'unstoppable-site-manager' ), human_time_diff( $s['since'] ), $s['error'] ), 'bad' );
			} elseif ( $s['checked'] ) {
				$p = self::uptime_percent( $site->id, time() - 30 * DAY_IN_SECONDS, time() );
				/* translators: 1: response time in ms, 2: time ago. */
				$txt = sprintf( __( 'Up · %1$d ms · checked %2$s ago', 'unstoppable-site-manager' ), $s['ms'], human_time_diff( $s['checked'] ) );
				if ( null !== $p ) {
					/* translators: %s: percent. */
					$txt .= ' · ' . sprintf( __( '%s%% last 30 days', 'unstoppable-site-manager' ), self::fmt_pct( $p ) );
				}
				$rows[] = array( __( 'Uptime', 'unstoppable-site-manager' ), $txt, '' );
			} else {
				$rows[] = array( __( 'Uptime', 'unstoppable-site-manager' ), __( 'First check in the next few minutes', 'unstoppable-site-manager' ), '' );
			}
		}
		$e = self::expiry( $site->id );
		if ( ! empty( $e['ssl_expires'] ) ) {
			$d = self::days_left( $e['ssl_expires'] );
			/* translators: 1: date, 2: issuer. */
			$rows[] = array( __( 'SSL (seen by visitors)', 'unstoppable-site-manager' ), sprintf( __( 'Expires %1$s · %2$s', 'unstoppable-site-manager' ), wp_date( get_option( 'date_format' ), $e['ssl_expires'] ), $e['ssl_issuer'] ), $d < 14 ? 'bad' : '' );
		} elseif ( ! empty( $e['ssl_error'] ) ) {
			$rows[] = array( __( 'SSL (seen by visitors)', 'unstoppable-site-manager' ), $e['ssl_error'], 'bad' );
		}
		if ( ! empty( USMD_Admin::settings()['domain_check'] ) ) {
			$di = self::domain_info( $site );
			if ( '' === $di['domain'] ) {
				$rows[] = array( __( 'Domain', 'unstoppable-site-manager' ), __( 'Not a registered domain (an IP address or local name)', 'unstoppable-site-manager' ), '' );
			} elseif ( ! empty( $di['expires'] ) ) {
				$d = self::days_left( $di['expires'] );
				/* translators: 1: domain, 2: date. */
				$txt = sprintf( __( '%1$s expires %2$s', 'unstoppable-site-manager' ), $di['domain'], wp_date( get_option( 'date_format' ), $di['expires'] ) );
				$txt .= ! empty( $di['registrar'] ) ? ' · ' . $di['registrar'] : '';
				$rows[] = array( __( 'Domain', 'unstoppable-site-manager' ), $txt, $d < 30 ? 'bad' : '' );
			} elseif ( ! empty( $di['error'] ) ) {
				$rows[] = array( __( 'Domain', 'unstoppable-site-manager' ), $di['domain'] . ': ' . $di['error'], '' );
			} else {
				$rows[] = array( __( 'Domain', 'unstoppable-site-manager' ), $di['domain'] . ': ' . __( 'not looked up yet', 'unstoppable-site-manager' ), '' );
			}
		}
		return $rows;
	}

	public static function fmt_pct( $p ) {
		return $p >= 99.995 ? '100' : number_format_i18n( floor( $p * 100 ) / 100, 2 );
	}
}
