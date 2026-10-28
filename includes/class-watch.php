<?php
/**
 * Background update checks and the daily "needs attention" email.
 *
 * Background check: every site sends a report each hour, but that report uses
 * WordPress's own cached update list (refreshed about twice a day). This runs the
 * same fresh check as "Refresh now" on every site, one site at a time, spread
 * out so neither the dashboard nor the client sites notice: daily (finishing
 * before the morning email) or every 6 hours. It needs no browser tab open.
 *
 * Daily email: once a day, at the hour you choose, one email listing only what
 * needs you: known vulnerabilities, sites that stopped answering, SSL certificates
 * about to expire, pending updates, and sites left in maintenance mode. Nothing
 * to report, no email.
 *
 * Both ride on the hourly usmd_sync_tick, so they run on WP-Cron like the rest
 * of the dashboard. On a quiet dashboard site a real server cron keeps them on time.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Watch {

	const HOOK        = 'usmd_bg_site';
	const OPT_LAST    = 'usmd_bg_last';     // When the last background sweep started.
	const OPT_DIGEST  = 'usmd_digest_last'; // Local date (Y-m-d) of the last daily email.
	const SSL_WARN    = 14;                 // Days.
	const MAINT_LONG  = DAY_IN_SECONDS;     // Maintenance mode on for longer than this is listed.

	public static function hooks() {
		add_action( 'usmd_sync_tick', array( __CLASS__, 'tick' ), 20 );
		add_action( self::HOOK, array( __CLASS__, 'check_site' ), 10, 1 );
		add_action( 'admin_post_usmd_digest_test', array( __CLASS__, 'handle_test' ) );
	}

	/* ---------- schedule ---------- */

	public static function tick() {
		if ( USMD_Move::is_moved() ) {
			return; // The new dashboard watches these sites now.
		}
		$s = USMD_Admin::settings();
		if ( self::sweep_due( $s ) ) {
			self::start_sweep( $s );
		}
		if ( self::digest_due( $s ) ) {
			update_option( self::OPT_DIGEST, wp_date( 'Y-m-d' ), false );
			self::send_digest( false );
		}
	}

	private static function local_hour() {
		return (int) wp_date( 'G' );
	}

	/** Daily: from three hours before the email hour, once a day. Every 6 hours: when 6 hours have passed. */
	public static function sweep_due( array $s ) {
		$last = (int) get_option( self::OPT_LAST );
		if ( '6h' === $s['bg_check'] ) {
			return time() - $last >= 6 * HOUR_IN_SECONDS - 10 * MINUTE_IN_SECONDS;
		}
		if ( 'daily' !== $s['bg_check'] ) {
			return false;
		}
		$start = ( (int) $s['digest_hour'] + 21 ) % 24; // Three hours before the email.
		$today = wp_date( 'Y-m-d' );
		if ( time() - $last > 26 * HOUR_IN_SECONDS ) {
			return true; // First run, or a missed day (dashboard asleep): catch up now.
		}
		return self::local_hour() >= $start && wp_date( 'Y-m-d', $last ) !== $today;
	}

	public static function digest_due( array $s ) {
		return ! empty( $s['digest'] )
			&& self::local_hour() >= (int) $s['digest_hour']
			&& get_option( self::OPT_DIGEST ) !== wp_date( 'Y-m-d' );
	}

	/** Queue one fresh check per site, spread over about two hours (less for small lists). */
	public static function start_sweep( array $s ) {
		update_option( self::OPT_LAST, time(), false );
		$sites = USMD_Sites::all();
		if ( ! $sites ) {
			return 0;
		}
		$window = ( '6h' === $s['bg_check'] ? 90 : 150 ) * MINUTE_IN_SECONDS;
		$gap    = max( 5, min( 30, (int) floor( $window / count( $sites ) ) ) );
		$at     = time() + 30;
		$n      = 0;
		foreach ( $sites as $site ) {
			if ( ! wp_next_scheduled( self::HOOK, array( (int) $site->id ) ) ) {
				wp_schedule_single_event( $at, self::HOOK, array( (int) $site->id ) );
				$at += $gap;
				++$n;
			}
		}
		return $n;
	}

	public static function check_site( $id ) {
		$site = USMD_Sites::get( (int) $id );
		if ( ! $site || USMD_Move::is_moved() ) {
			return;
		}
		$r = USMD_Client::refresh( $site, true ); // A failure is stored as the site's last error.
		if ( ! is_wp_error( $r ) && USMD_Vulns::enabled() ) {
			USMD_Vulns::check_site( USMD_Sites::get( $site->id ) );
		}
	}

	/* ---------- the email ---------- */

	public static function recipient() {
		$e = (string) USMD_Admin::settings()['digest_email'];
		return is_email( $e ) ? $e : USMD_Admin::agency_email();
	}

	/** @return array Section key => list of [ site, text ]. Empty sections left out. */
	public static function findings() {
		$out = array(
			'vulns'   => array(),
			'down'    => array(),
			'ssl'     => array(),
			'domain'  => array(),
			'maint'   => array(),
			'speed'   => array(),
			'updates' => array(),
		);
		$seen_domains = array();
		foreach ( USMD_Sites::all() as $site ) {
			$stale = ! $site->last_seen || ( time() - strtotime( $site->last_seen . ' UTC' ) ) > DAY_IN_SECONDS;
			if ( USMD_Monitor::is_down( $site->id ) ) {
				$us            = USMD_Monitor::state( $site->id );
				/* translators: 1: time ago, 2: reason. */
				$out['down'][] = array( $site, sprintf( __( 'Down since %1$s ago: %2$s', 'unstoppable-site-manager' ), human_time_diff( $us['since'] ), $us['error'] ) );
			} elseif ( $site->last_error || $stale ) {
				$out['down'][] = array(
					$site,
					$site->last_error
						? $site->last_error
						/* translators: %s: time ago. */
						: sprintf( __( 'No report since %s', 'unstoppable-site-manager' ), USMD_Admin::ago( $site->last_seen ) ),
				);
			}
			if ( USMD_Vulns::enabled() ) {
				$v = USMD_Vulns::count( $site->id );
				if ( $v ) {
					/* translators: 1: number of items, 2: severity. */
					$out['vulns'][] = array( $site, sprintf( _n( '%1$d item with a known vulnerability (worst: %2$s)', '%1$d items with known vulnerabilities (worst: %2$s)', $v, 'unstoppable-site-manager' ), $v, USMD_Vulns::worst( $site->id ) ) );
				}
			}
			$ex       = USMD_Monitor::expiry( $site->id );
			$ssl_days = ! empty( $ex['ssl_expires'] ) ? USMD_Monitor::days_left( $ex['ssl_expires'] ) : ( null === $site->ssl_days ? null : (int) $site->ssl_days );
			if ( 0 === strpos( $site->url, 'https://' ) && null !== $ssl_days && $ssl_days < self::SSL_WARN ) {
				$d             = $ssl_days;
				$out['ssl'][]  = array(
					$site,
					$d < 0
						? __( 'SSL certificate has expired', 'unstoppable-site-manager' )
						/* translators: %d: days. */
						: sprintf( _n( 'SSL certificate expires in %d day', 'SSL certificate expires in %d days', $d, 'unstoppable-site-manager' ), $d ),
				);
			}
			if ( ! empty( USMD_Admin::settings()['domain_check'] ) ) {
				$di = USMD_Monitor::domain_info( $site );
				if ( ! empty( $di['expires'] ) && USMD_Monitor::days_left( $di['expires'] ) <= 30 && ! isset( $seen_domains[ $di['domain'] ] ) ) {
					$seen_domains[ $di['domain'] ] = true;
					$dd                            = USMD_Monitor::days_left( $di['expires'] );
					$out['domain'][]               = array(
						$site,
						$dd < 0
							/* translators: %s: domain. */
							? sprintf( __( 'Domain %s has expired', 'unstoppable-site-manager' ), $di['domain'] )
							/* translators: 1: domain, 2: days. */
							: sprintf( _n( 'Domain %1$s expires in %2$d day', 'Domain %1$s expires in %2$d days', $dd, 'unstoppable-site-manager' ), $di['domain'], $dd ),
					);
				}
			}
			$m = USMD_Maintenance::state( $site );
			if ( $m['on'] && $m['since'] && time() - $m['since'] > self::MAINT_LONG ) {
				/* translators: 1: template name, 2: time ago. */
				$out['maint'][] = array( $site, sprintf( __( '%1$s page on since %2$s', 'unstoppable-site-manager' ), USMD_Maintenance::label( $m['template'] ), human_time_diff( $m['since'] ) . ' ago' ) );
			}
			if ( USMD_Speed::enabled() ) {
				$sr = USMD_Speed::get( $site->id );
				if ( USMD_Speed::dropped( $sr ) ) {
					/* translators: 1: earlier score, 2: new score. */
					$out['speed'][] = array( $site, sprintf( __( 'Page speed score fell from %1$d to %2$d (mobile)', 'unstoppable-site-manager' ), (int) $sr['prev'], (int) $sr['score'] ) );
				}
			}
			if ( $site->updates && ! $site->last_error ) {
				$parts = array();
				if ( $site->plugin_updates ) {
					/* translators: %d: number of plugins. */
					$parts[] = sprintf( _n( '%d plugin', '%d plugins', $site->plugin_updates, 'unstoppable-site-manager' ), $site->plugin_updates );
				}
				if ( $site->theme_updates ) {
					/* translators: %d: number of themes. */
					$parts[] = sprintf( _n( '%d theme', '%d themes', $site->theme_updates, 'unstoppable-site-manager' ), $site->theme_updates );
				}
				if ( $site->core_update ) {
					$parts[] = __( 'WordPress', 'unstoppable-site-manager' );
				}
				$out['updates'][] = array( $site, implode( ', ', $parts ) );
			}
		}
		return array_filter( $out );
	}

	public static function section_titles() {
		return array(
			'vulns'   => __( 'Known vulnerabilities', 'unstoppable-site-manager' ),
			'down'    => __( 'Sites down or not syncing', 'unstoppable-site-manager' ),
			'ssl'     => __( 'SSL certificates expiring', 'unstoppable-site-manager' ),
			'domain'  => __( 'Domains expiring', 'unstoppable-site-manager' ),
			'maint'   => __( 'Still in maintenance mode', 'unstoppable-site-manager' ),
			'speed'   => __( 'Page speed dropped', 'unstoppable-site-manager' ),
			'updates' => __( 'Updates waiting', 'unstoppable-site-manager' ),
		);
	}

	/**
	 * @param bool $test A test from Settings: sent even when there is nothing to report.
	 * @return true|WP_Error|null Null when there was nothing to send.
	 */
	public static function send_digest( $test ) {
		$f = self::findings();
		if ( ! $f && ! $test ) {
			return null;
		}
		$s      = USMD_Admin::settings();
		$agency = $s['agency_name'] ? $s['agency_name'] : get_bloginfo( 'name' );
		$sites  = array();
		foreach ( $f as $rows ) {
			foreach ( $rows as $r ) {
				$sites[ $r[0]->id ] = true;
			}
		}
		$n       = count( $sites );
		$subject = $n
			/* translators: 1: agency name, 2: number of sites. */
			? sprintf( _n( '%1$s: %2$d site needs attention', '%1$s: %2$d sites need attention', $n, 'unstoppable-site-manager' ), $agency, $n )
			/* translators: %s: agency name. */
			: sprintf( __( '%s: all sites look good', 'unstoppable-site-manager' ), $agency );
		if ( $test ) {
			$subject = '[' . __( 'Test', 'unstoppable-site-manager' ) . '] ' . $subject;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $s['report_from_email'] ) ) {
			$headers[] = 'From: ' . ( $s['report_from_name'] ? $s['report_from_name'] : $agency ) . ' <' . $s['report_from_email'] . '>';
		}
		$ok = wp_mail( self::recipient(), $subject, self::email_html( $f, $agency ), $headers );
		return $ok ? true : new WP_Error( 'usmd_mail', __( 'WordPress could not send the email. Check this site\'s mail setup (an SMTP plugin usually fixes this).', 'unstoppable-site-manager' ) );
	}

	private static function email_html( array $f, $agency ) {
		$color = sanitize_hex_color( USMD_Admin::settings()['report_color'] );
		$color = $color ? $color : '#1e5aa8';
		ob_start();
		?>
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;max-width:640px;margin:0 auto;">
	<h1 style="font-size:20px;margin:0 0 4px;color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( wp_date( get_option( 'date_format' ) ) ); ?></h1>
	<p style="margin:0 0 20px;color:#50575e;">
		<?php
		echo $f
			? esc_html__( 'Here is what needs your attention across your client sites today.', 'unstoppable-site-manager' )
			: esc_html__( 'Nothing needs your attention today. This is a test of the daily email.', 'unstoppable-site-manager' );
		?>
	</p>
	<?php foreach ( self::section_titles() as $key => $title ) : ?>
		<?php
		if ( empty( $f[ $key ] ) ) {
			continue;
		}
		?>
		<h2 style="font-size:16px;margin:24px 0 8px;border-bottom:2px solid <?php echo esc_attr( $color ); ?>;padding-bottom:4px;"><?php echo esc_html( $title ); ?> (<?php echo (int) count( $f[ $key ] ); ?>)</h2>
		<table style="width:100%;border-collapse:collapse;table-layout:fixed;" role="presentation">
			<?php foreach ( array_slice( $f[ $key ], 0, 50 ) as $row ) : ?>
				<tr>
					<td style="width:38%;padding:6px 12px 6px 0;border-bottom:1px solid #eee;vertical-align:top;"><a href="<?php echo esc_url( USMD_Admin::site_url( $row[0]->id ) ); ?>" style="color:<?php echo esc_attr( $color ); ?>;text-decoration:none;font-weight:600;"><?php echo esc_html( html_entity_decode( $row[0]->name, ENT_QUOTES, 'UTF-8' ) ); ?></a></td>
					<td style="padding:6px 0;border-bottom:1px solid #eee;color:#50575e;"><?php echo esc_html( $row[1] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( count( $f[ $key ] ) > 50 ) : ?>
				<tr><td colspan="2" style="padding:6px 0;color:#50575e;">
					<?php
					/* translators: %d: number of sites. */
					echo esc_html( sprintf( __( '…and %d more on the dashboard.', 'unstoppable-site-manager' ), count( $f[ $key ] ) - 50 ) );
					?>
				</td></tr>
			<?php endif; ?>
		</table>
	<?php endforeach; ?>
	<p style="margin:28px 0 0;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=unstoppable-site-manager' ) ); ?>" style="display:inline-block;background:<?php echo esc_attr( $color ); ?>;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;"><?php esc_html_e( 'Open the dashboard', 'unstoppable-site-manager' ); ?></a></p>
	<p style="margin:24px 0 0;font-size:12px;color:#8c8f94;">
		<?php
		/* translators: %s: agency name. */
		echo esc_html( sprintf( __( 'Sent by Unstoppable Site Manager for %s. Change or switch off this email under Settings > Background checks and daily email.', 'unstoppable-site-manager' ), $agency ) );
		?>
	</p>
</div>
		<?php
		return ob_get_clean();
	}

	/** "Send a test email now" on Settings. */
	public static function handle_test() {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( 'usmd_digest_test' );
		$r = self::send_digest( true );
		wp_safe_redirect( admin_url( 'admin.php?page=usmd-settings&digest=' . ( is_wp_error( $r ) ? 'failed' : 'sent' ) . '#usmd-watch' ) );
		exit;
	}
}
