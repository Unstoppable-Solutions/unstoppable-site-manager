<?php
/**
 * Emailing client reports: on demand from a report, and automatically each
 * month on the day chosen in Settings.
 *
 * Mail goes through wp_mail(), so it uses whatever this site already sends
 * mail with (an SMTP plugin is recommended for reliable delivery).
 *
 * Email clients ignore stylesheets, so the email is a table layout with the
 * styles written on the elements. This is email markup only; it is never
 * output on an admin screen.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Report_Mail {

	const TICK     = 'usmd_report_tick';
	const SENT     = 'usmd_report_sent';
	const MORE     = 'usmd_report_more';
	const PER_TICK = 50; // At most this many emails per run…
	const BUDGET   = 20; // …and stop after this many seconds, so a slow mail server never times out.
	const KEEP     = 13; // Months of sent-log kept per site.
	const RUN      = 'usmd_report_run';  // What the last automatic run did (shown in Settings).
	const TRYING   = 'usmd_report_try';  // site id => time a send started, to spot one that never finished.
	const GRACE    = 2 * HOUR_IN_SECONDS; // After this, reports still waiting are shown as overdue.

	public static function hooks() {
		add_action( self::TICK, array( __CLASS__, 'tick' ) );
		add_action( self::MORE, array( __CLASS__, 'tick' ) ); // Follow-up run while a big batch is still going out.
		// Also from the five-minute uptime run, so reports go out even if the hourly event is lost or late.
		add_action( 'usmd_monitor_tick', array( __CLASS__, 'tick_backup' ) );
		add_action( 'admin_post_usmd_reports_send_due', array( __CLASS__, 'handle_send_due' ) );
		add_action( 'admin_post_usmd_reports_send_month', array( __CLASS__, 'handle_send_month' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( 'admin_post_usmd_report_send', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_usmd_client', array( __CLASS__, 'handle_client' ) );
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::TICK );
		}
	}

	/* ---------- sent log ---------- */

	public static function sent( $site_id, $month ) {
		$all = get_option( self::SENT );
		return ( is_array( $all ) && isset( $all[ (int) $site_id ][ $month ] ) ) ? $all[ (int) $site_id ][ $month ] : null;
	}

	/** Most recent successful send for a site: array( month, time, to, auto ) or null. */
	public static function last_sent( $site_id ) {
		$all = get_option( self::SENT );
		if ( ! is_array( $all ) || empty( $all[ (int) $site_id ] ) ) {
			return null;
		}
		$last = null;
		foreach ( $all[ (int) $site_id ] as $month => $row ) {
			if ( empty( $row['to'] ) ) {
				continue; // A failed attempt.
			}
			if ( ! $last || $row['time'] > $last['time'] ) {
				$last          = $row;
				$last['month'] = $month;
			}
		}
		return $last;
	}

	private static function mark_sent( $site_id, $month, array $to, $auto, $error = '' ) {
		$all = get_option( self::SENT );
		$all = is_array( $all ) ? $all : array();
		$all[ (int) $site_id ][ $month ] = array(
			'time'  => time(),
			'to'    => implode( ', ', $to ),
			'auto'  => (bool) $auto,
			'error'   => (string) $error, // Set when the attempt failed; sent ( to ) is then empty.
			// Sent before the month was over: the automatic send after it ends still sends the full report.
			'partial' => $month === wp_date( 'Y-m' ),
		);
		// Keep the log small on dashboards with hundreds of sites: only the last KEEP months.
		ksort( $all[ (int) $site_id ] );
		$all[ (int) $site_id ] = array_slice( $all[ (int) $site_id ], -self::KEEP, null, true );
		update_option( self::SENT, $all, false );
	}

	/** Why this site's report for the month now going out failed, or ''. */
	public static function failure( $site_id ) {
		$month = self::due_month();
		$row   = $month ? self::sent( $site_id, $month ) : null;
		if ( ! $row || ! empty( $row['to'] ) ) {
			return '';
		}
		return ! empty( $row['error'] ) ? (string) $row['error'] : __( 'The email could not be sent.', 'unstoppable-site-manager' );
	}

	/* ---------- sending ---------- */

	/**
	 * Email one site's report for one month.
	 *
	 * @param string[]|null $to   Recipients; null = the site's client emails.
	 * @param bool          $test A test copy: not logged as sent, subject marked.
	 * @return true|WP_Error
	 */
	public static function send( $site, $month, $to = null, $test = false, $auto = false ) {
		$to = null === $to ? USMD_Sites::parse_emails( $site->client_email ) : $to;
		if ( ! $to ) {
			return new WP_Error( 'usmd_no_email', __( 'Add the client\'s email address on the site page first.', 'unstoppable-site-manager' ) );
		}
		$s       = USMD_Clients::settings_for( $site );
		$d       = USMD_Report::data( $site, $month );
		$subject = strtr(
			$s['report_subject'],
			array(
				'{site}'   => $site->name,
				'{month}'  => $d['label'],
				'{agency}' => $s['agency_name'],
			)
		);
		$subject = wp_specialchars_decode( wp_strip_all_tags( $subject ), ENT_QUOTES );
		if ( $test ) {
			/* translators: %s: email subject. */
			$subject = sprintf( __( '[Test] %s', 'unstoppable-site-manager' ), $subject );
		}

		$from_email = is_email( $s['report_from_email'] ) ? $s['report_from_email'] : USMD_Admin::agency_email();
		$from_name  = str_replace( array( "\r", "\n", '"' ), '', $s['report_from_name'] ? $s['report_from_name'] : $s['agency_name'] );
		$headers    = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);
		$reply = is_email( $s['report_reply_to'] ) ? $s['report_reply_to'] : $from_email;
		$headers[] = 'Reply-To: ' . $reply;
		if ( ! $test && ! empty( $s['report_bcc_me'] ) && is_email( USMD_Admin::agency_email() ) ) {
			$headers[] = 'Bcc: ' . USMD_Admin::agency_email();
		}

		$html = self::email_html( $site, $d );
		$ok   = wp_mail( $to, $subject, $html, $headers );
		if ( ! $ok ) {
			return new WP_Error( 'usmd_mail_failed', __( 'WordPress could not send the email. Check this site\'s mail settings (an SMTP plugin is recommended).', 'unstoppable-site-manager' ) );
		}
		if ( ! $test ) {
			self::mark_sent( $site->id, $d['month'], $to, $auto );
			/* translators: 1: month, 2: email addresses. */
			USMD_Sites::add_local_activity( $site->id, 'report', 'emailed', sprintf( __( '%1$s report to %2$s', 'unstoppable-site-manager' ), $d['label'], implode( ', ', $to ) ), array( 'auto' => (bool) $auto ) );
		}
		return true;
	}

	/** The month whose reports are going out now ('' before the chosen day and hour, or when sending is off). */
	public static function due_month() {
		$day = (int) USMD_Admin::settings()['report_day'];
		if ( $day < 1 || USMD_Move::is_moved() ) {
			return '';
		}
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		if ( (int) $now->format( 'j' ) < $day || ( (int) $now->format( 'j' ) === $day && (int) $now->format( 'G' ) < 8 ) ) {
			return ''; // Not yet: from 8 am on the chosen day.
		}
		return $now->modify( 'first day of last month' )->format( 'Y-m' );
	}

	/** When this month's sending started (8 am on the chosen day), as a timestamp. */
	private static function due_since() {
		$day = max( 1, (int) USMD_Admin::settings()['report_day'] );
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		return $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), $day )->setTime( 8, 0 )->getTimestamp();
	}

	/**
	 * Sites whose report for $month should go out and hasn't: [ site, error ] pairs.
	 * Failed attempts are included only with $failed.
	 */
	public static function waiting( $month, $failed = false, $all = false, $redo_partial = true ) {
		$out = array();
		foreach ( apply_filters( 'usmd_report_sites', USMD_Sites::all() ) as $site ) {
			if ( ( ! $all && empty( $site->report_auto ) ) || '' === trim( (string) $site->client_email ) ) {
				continue;
			}
			$row = self::sent( $site->id, $month );
			if ( ! $row || ( $redo_partial && ! empty( $row['partial'] ) && $month !== wp_date( 'Y-m' ) ) ) {
				$out[] = array( $site, '' );
			} elseif ( $failed && empty( $row['to'] ) ) {
				$out[] = array( $site, isset( $row['error'] ) ? (string) $row['error'] : '' );
			}
		}
		return $out;
	}

	/** The five-minute uptime run checks for reports to send at most every 15 minutes. */
	public static function tick_backup() {
		if ( get_transient( 'usmd_report_backup' ) ) {
			return;
		}
		set_transient( 'usmd_report_backup', 1, 15 * MINUTE_IN_SECONDS );
		self::tick();
	}

	/** Hourly: on or after the chosen day, send last month's report to every client who gets them. */
	public static function tick() {
		$month = self::due_month();
		if ( '' === $month ) {
			return;
		}
		self::run( $month, false );
	}

	/**
	 * Send $month's waiting reports, within the time budget; the rest follow in a couple of minutes.
	 *
	 * @param bool $retry Also try again the ones that failed (Send now).
	 * @return array sent, failed, left
	 */
	public static function run( $month, $retry, $all = false ) {
		if ( get_transient( 'usmd_report_running' ) ) {
			return array( 'sent' => 0, 'failed' => 0, 'left' => count( self::waiting( $month, $retry, $all ) ) );
		}
		set_transient( 'usmd_report_running', 1, 5 * MINUTE_IN_SECONDS ); // Never two runs at once (double emails).
		$trying = get_option( self::TRYING );
		$trying = is_array( $trying ) ? $trying : array();
		$sent   = 0;
		$failed = 0;
		$left   = 0;
		$start  = microtime( true );
		// A report sent while its month was still running is sent again in full once the month is over, whichever button is used.
		foreach ( self::waiting( $month, $retry, $all ) as $w ) {
			$site = $w[0];
			// A send that started over ten minutes ago and never finished: the server stopped it (time or memory limit).
			if ( ! $retry && isset( $trying[ $site->id ] ) && time() - (int) $trying[ $site->id ] > 10 * MINUTE_IN_SECONDS ) {
				unset( $trying[ $site->id ] );
				update_option( self::TRYING, $trying, false );
				$msg = __( 'Sending stopped before it finished (the server ran out of time or memory). Use Send now to try again.', 'unstoppable-site-manager' );
				self::mark_sent( $site->id, $month, array(), true, $msg );
				USMD_Sites::add_local_activity( $site->id, 'report', 'email_failed', $msg );
				++$failed;
				continue;
			}
			if ( $sent + $failed >= self::PER_TICK || microtime( true ) - $start > self::BUDGET ) {
				++$left;
				continue;
			}
			$trying[ $site->id ] = time();
			update_option( self::TRYING, $trying, false );
			try {
				$r = self::send( $site, $month, null, false, true );
			} catch ( Throwable $e ) {
				/* translators: %s: error message. */
				$r = new WP_Error( 'usmd_report_crash', sprintf( __( 'The report could not be built: %s', 'unstoppable-site-manager' ), $e->getMessage() ) );
			}
			unset( $trying[ $site->id ] );
			update_option( self::TRYING, $trying, false );
			if ( is_wp_error( $r ) ) {
				++$failed;
				USMD_Sites::add_local_activity( $site->id, 'report', 'email_failed', $r->get_error_message() );
				// Recorded, so a broken mail setup doesn't retry every hour all month; Send now tries again.
				self::mark_sent( $site->id, $month, array(), true, $r->get_error_message() );
			} else {
				++$sent;
			}
		}
		delete_transient( 'usmd_report_running' );
		if ( $left && ! $all && ! wp_next_scheduled( self::MORE ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::MORE );
		}
		if ( ! $all && ( $sent || $failed || $left ) ) {
			update_option( self::RUN, array( 'time' => time(), 'month' => $month, 'sent' => $sent, 'failed' => $failed, 'left' => $left ), false );
		}
		return array( 'sent' => $sent, 'failed' => $failed, 'left' => $left );
	}

	/** Reports overdue now (still waiting or failed, two hours after sending began), for the notice on the Sites screen. */
	public static function overdue() {
		$month = self::due_month();
		if ( '' === $month || time() < self::due_since() + self::GRACE ) {
			return array();
		}
		return array( 'month' => $month, 'items' => self::waiting( $month, true ) );
	}

	/** Notice: reports that should have gone out and didn't, with Send now. */
	public static function overdue_notice() {
		$o = self::overdue();
		if ( ! $o || ! $o['items'] ) {
			return;
		}
		$failed = count( array_filter( $o['items'], static function ( $w ) { return '' !== $w[1]; } ) );
		$n      = count( $o['items'] );
		$label  = wp_date( 'F Y', strtotime( $o['month'] . '-01' ) );
		?>
		<div class="notice notice-warning usmd-reports-overdue"><p>
			<strong>
				<?php
				/* translators: 1: number of reports, 2: month. */
				echo esc_html( sprintf( _n( '%1$d report for %2$s has not gone out yet.', '%1$d reports for %2$s have not gone out yet.', $n, 'unstoppable-site-manager' ), $n, $label ) );
				?>
			</strong>
			<?php
			$early = count(
				array_filter(
					$o['items'],
					static function ( $w ) use ( $o ) {
						$row = self::sent( $w[0]->id, $o['month'] );
						return $row && ! empty( $row['partial'] ) && ! empty( $row['to'] );
					}
				)
			);
			if ( $early ) {
				/* translators: %d: number of reports. */
				echo esc_html( sprintf( _n( '%d of them was sent before the month ended, so it is missing the last days; Send now sends the full month once.', '%d of them were sent before the month ended, so they are missing the last days; Send now sends the full month once.', $early, 'unstoppable-site-manager' ), $early ) ) . ' ';
			}
			if ( $failed ) {
				/* translators: %d: number of reports. */
				echo esc_html( sprintf( _n( '%d failed: see the reason on the Reports screen.', '%d failed: see the reasons on the Reports screen.', $failed, 'unstoppable-site-manager' ), $failed ) ) . ' ';
			}
			?>
		</p><p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="usmd_reports_send_due">
				<?php wp_nonce_field( 'usmd_reports_send_due' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Send now', 'unstoppable-site-manager' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=usmd-reports' ) ); ?>"><?php esc_html_e( 'Reports', 'unstoppable-site-manager' ); ?></a>
		</p></div>
		<?php
	}

	/** One line for Settings: when automatic sending last ran and what it did. */
	public static function status_line() {
		$r = get_option( self::RUN );
		if ( ! is_array( $r ) || empty( $r['time'] ) ) {
			return __( 'Automatic sending has not sent anything yet.', 'unstoppable-site-manager' );
		}
		/* translators: 1: time ago, 2: month, 3: sent, 4: failed. */
		$line = sprintf( __( 'Last automatic run %1$s ago (%2$s reports): %3$d sent, %4$d failed.', 'unstoppable-site-manager' ), human_time_diff( (int) $r['time'] ), wp_date( 'F Y', strtotime( $r['month'] . '-01' ) ), (int) $r['sent'], (int) $r['failed'] );
		if ( ! empty( $r['left'] ) ) {
			/* translators: %d: number of reports. */
			$line .= ' ' . sprintf( __( '%d more going out in a few minutes.', 'unstoppable-site-manager' ), (int) $r['left'] );
		}
		return $line;
	}

	/** Months a report can be sent for: this month so far, then the last twelve. month => label */
	public static function months() {
		$out = array();
		$now = new DateTimeImmutable( 'first day of this month', wp_timezone() );
		for ( $i = 0; $i <= 12; $i++ ) {
			$m         = $now->modify( "-{$i} months" );
			$label     = wp_date( 'F Y', $m->getTimestamp() );
			$out[ $m->format( 'Y-m' ) ] = 0 === $i
				/* translators: %s: month. */
				? sprintf( __( '%s (this month so far)', 'unstoppable-site-manager' ), $label )
				: ( 1 === $i ? sprintf( /* translators: %s: month. */ __( '%s (last month)', 'unstoppable-site-manager' ), $label ) : $label );
		}
		return $out;
	}

	/** Reports screen: send the chosen month's report to every client with an email (or only those with monthly email on). */
	public static function handle_send_month() {
		self::guard( 'usmd_reports_send_month' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$month = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$every = isset( $_POST['scope'] ) && 'all' === $_POST['scope'];
		// phpcs:enable
		if ( ! isset( self::months()[ $month ] ) ) {
			wp_die( esc_html__( 'That month is not available.', 'unstoppable-site-manager' ) );
		}
		delete_transient( 'usmd_report_running' );
		// Sites already sent this month's report are skipped, so pressing it again only sends the rest.
		$r = $every ? self::run( $month, true, true ) : self::run_auto_only( $month );
		wp_safe_redirect( add_query_arg( array( 'page' => 'usmd-reports', 'month' => $month, 'sent' => (int) $r['sent'], 'failed' => (int) $r['failed'], 'left' => (int) $r['left'], 'manual' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Only the sites with monthly email on, skipping any already sent for $month. */
	private static function run_auto_only( $month ) {
		$keep = array();
		foreach ( USMD_Sites::all() as $site ) {
			if ( empty( $site->report_auto ) ) {
				$keep[] = (int) $site->id;
			}
		}
		$skip = static function ( $sites ) use ( $keep ) {
			return array_values( array_filter( $sites, static function ( $s ) use ( $keep ) { return ! in_array( (int) $s->id, $keep, true ); } ) );
		};
		add_filter( 'usmd_report_sites', $skip );
		$r = self::run( $month, true, true );
		remove_filter( 'usmd_report_sites', $skip );
		return $r;
	}

	/** Send now: every report due this month that hasn't gone out, including failed ones. */
	public static function handle_send_due() {
		self::guard( 'usmd_reports_send_due' );
		$month = self::due_month();
		if ( '' === $month ) {
			$month = wp_date( 'Y-m', strtotime( 'first day of last month', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- local month.
		}
		delete_transient( 'usmd_report_running' ); // A run stopped by the server must not block a manual send.
		$r = self::run( $month, true );
		wp_safe_redirect( add_query_arg( array( 'page' => 'usmd-reports', 'sent' => (int) $r['sent'], 'failed' => (int) $r['failed'], 'left' => (int) $r['left'] ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------- admin actions ---------- */

	private static function guard( $action ) {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( $action );
	}

	/** "Email this report" on a report page. */
	public static function handle_send() {
		self::guard( 'usmd_report_send' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$site  = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 );
		$month = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$test     = isset( $_POST['test'] );
		$typed    = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$remember = ! empty( $_POST['remember'] );
		$auto     = ! empty( $_POST['report_auto'] );
		$to       = $test ? array( USMD_Admin::agency_email() ) : USMD_Sites::parse_emails( $typed );
		// phpcs:enable
		if ( ! $site || ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			wp_die( esc_html__( 'That report could not be found.', 'unstoppable-site-manager' ) );
		}
		// Keep the address (and the monthly switch) for next time, so it never has to be typed again.
		if ( $remember && USMD_Sites::parse_emails( $typed ) ) {
			USMD_Sites::set_client( $site->id, $site->client_name, $typed, $auto );
			$site = USMD_Sites::get( $site->id );
		}
		$r    = $to ? self::send( $site, $month, $to, $test ) : new WP_Error( 'usmd_no_email', __( 'Enter at least one valid email address.', 'unstoppable-site-manager' ) );
		$args = array(
			'view'   => 'report',
			'month'  => $month,
			'mailed' => is_wp_error( $r ) ? 'error' : ( $test ? 'test' : 'sent' ),
		);
		if ( is_wp_error( $r ) ) {
			set_transient( 'usmd_mail_error_' . get_current_user_id(), $r->get_error_message(), 120 );
		}
		if ( isset( $_POST['back'] ) && 'reports' === $_POST['back'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			wp_safe_redirect( add_query_arg( array( 'page' => 'usmd-reports', 'month' => $month, 'one' => is_wp_error( $r ) ? 'error' : 'sent', 'site' => (int) $site->id ), admin_url( 'admin.php' ) ) );
			exit;
		}
		wp_safe_redirect( USMD_Admin::site_url( $site->id, $args ) );
		exit;
	}

	/** Client details form on a site page. */
	public static function handle_client() {
		self::guard( 'usmd_client' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$site = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 );
		if ( ! $site ) {
			wp_die( esc_html__( 'Site not found.', 'unstoppable-site-manager' ) );
		}
		USMD_Sites::set_client(
			$site->id,
			isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '',
			isset( $_POST['client_email'] ) ? sanitize_text_field( wp_unslash( $_POST['client_email'] ) ) : '',
			! empty( $_POST['report_auto'] )
		);
		$back = isset( $_POST['back'] ) && 'reports' === $_POST['back'] ? admin_url( 'admin.php?page=usmd-reports&saved=1' ) : USMD_Admin::site_url( $site->id, array( 'client' => 'saved' ) ) . '#usmd-client';
		// phpcs:enable
		wp_safe_redirect( $back );
		exit;
	}

	/* ---------- the email ---------- */

	/** Report as email-safe HTML: tables, styles on the elements, 600px wide. */
	public static function email_html( $site, array $d ) {
		$s      = $d['settings'];
		$accent = sanitize_hex_color( $s['report_color'] );
		$accent = $accent ? $accent : '#1e5aa8';
		$ink    = '#1b1f24';
		$muted  = '#5b6470';
		$rule   = '#e3e6ea';
		$tone   = array(
			'ok'   => array( '#1a7f4b', '#e8f5ee' ),
			'warn' => array( '#a15c00', '#fdf3e2' ),
			'bad'  => array( '#b42318', '#fdecea' ),
		);
		$font   = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		$sum    = $d['sum'];
		$c      = $sum['counts'];
		$v      = $d['verdict'];
		$thumb  = USMD_Thumbs::url( $site );
		$name   = $site->name;
		$greet  = $site->client_name ? sprintf( /* translators: %s: client name. */ __( 'Hi %s,', 'unstoppable-site-manager' ), $site->client_name ) : __( 'Hello,', 'unstoppable-site-manager' );

		ob_start();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $name . ' · ' . $d['label'] ); ?></title></head>
<body style="margin:0;padding:0;background:#f2f4f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;">
<tr><td align="center" style="padding:16px 4px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-top:6px solid <?php echo esc_attr( $accent ); ?>;border-radius:4px;<?php echo esc_attr( $font ); ?>color:<?php echo esc_attr( $ink ); ?>;font-size:15px;line-height:1.55;">

<tr><td style="padding:24px 20px 16px;border-bottom:1px solid <?php echo esc_attr( $rule ); ?>;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
		<td valign="bottom">
			<?php list( $show_logo, $show_name ) = USMD_Report::brand( $s ); ?>
			<table role="presentation" cellpadding="0" cellspacing="0"><tr>
				<?php if ( $show_logo ) : ?>
					<td valign="middle" style="padding-right:<?php echo $show_name ? '12px' : '0'; ?>;"><img src="<?php echo esc_url( $s['logo_url'] ); ?>" alt="<?php echo esc_attr( $show_name ? '' : $s['agency_name'] ); ?>" style="display:block;max-height:56px;max-width:<?php echo $show_name ? '120px' : '220px'; ?>;border:0;"></td>
				<?php endif; ?>
				<?php if ( $show_name ) : ?>
					<td valign="middle"><span style="font-size:19px;font-weight:700;line-height:1.2;"><?php echo esc_html( $s['agency_name'] ); ?></span></td>
				<?php endif; ?>
			</tr></table>
		</td>
		<td valign="bottom" align="right">
			<div style="font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Website Care Report', 'unstoppable-site-manager' ); ?></div>
			<div style="font-size:24px;font-weight:700;line-height:1.2;"><?php echo esc_html( $d['label'] ); ?></div>
			<div style="font-size:13px;color:<?php echo esc_attr( $muted ); ?>;"><?php echo esc_html( $name . ' · ' . $d['host'] ); ?></div>
		</td>
	</tr></table>
</td></tr>

<tr><td style="padding:20px 20px 0;">
	<p style="margin:0 0 10px;"><?php echo esc_html( $greet ); ?></p>
	<?php if ( $s['report_intro'] ) : ?>
		<p style="margin:0;"><?php echo esc_html( $s['report_intro'] ); ?></p>
	<?php endif; ?>
</td></tr>

<tr><td style="padding:16px 20px 0;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr( $tone[ $v[0] ][1] ); ?>;border-radius:8px;"><tr>
		<td width="44" valign="top" style="padding:16px 0 16px 16px;">
			<div style="width:30px;height:30px;line-height:30px;border-radius:15px;text-align:center;color:#ffffff;font-weight:700;background:<?php echo esc_attr( $tone[ $v[0] ][0] ); ?>;"><?php echo esc_html( USMD_Report::ICONS[ $v[0] ] ); ?></div>
		</td>
		<td style="padding:16px 18px 16px 10px;">
			<div style="font-size:17px;font-weight:700;margin:2px 0 4px;"><?php echo esc_html( $v[1] ); ?></div>
			<div><?php echo esc_html( $d['lead'] ); ?></div>
		</td>
	</tr></table>
</td></tr>

<tr><td style="padding:16px 20px 0;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid <?php echo esc_attr( $rule ); ?>;border-radius:8px;"><tr>
		<?php
		$kpis = array(
			array( (string) (int) $c['total'], __( 'Updates applied', 'unstoppable-site-manager' ) ),
			array( (string) (int) $c['plugin'], __( 'Plugins updated', 'unstoppable-site-manager' ) ),
			array( (string) (int) $c['theme'], __( 'Themes updated', 'unstoppable-site-manager' ) ),
			array( $c['core'] ? __( 'Yes', 'unstoppable-site-manager' ) : __( 'No', 'unstoppable-site-manager' ), __( 'WordPress updated', 'unstoppable-site-manager' ) ),
		);
		foreach ( $kpis as $i => $k ) :
			?>
			<td width="25%" valign="top" style="padding:12px 14px;<?php echo $i ? 'border-left:1px solid ' . esc_attr( $rule ) . ';' : ''; ?>">
				<div style="font-size:24px;font-weight:700;line-height:1.1;color:<?php echo esc_attr( $accent ); ?>;"><?php echo esc_html( $k[0] ); ?></div>
				<div style="font-size:12px;color:<?php echo esc_attr( $muted ); ?>;"><?php echo esc_html( $k[1] ); ?></div>
			</td>
		<?php endforeach; ?>
	</tr></table>
</td></tr>

<?php if ( $thumb ) : ?>
<tr><td style="padding:20px 20px 0;">
	<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: site name. */ __( 'Home page of %s', 'unstoppable-site-manager' ), $name ) ); ?>" width="560" style="display:block;width:100%;max-width:560px;height:auto;border:1px solid <?php echo esc_attr( $rule ); ?>;border-radius:6px;">
</td></tr>
<?php endif; ?>

<tr><td style="padding:20px 20px 0;">
	<div style="font-size:16px;font-weight:700;margin:0 0 6px;"><?php esc_html_e( 'Site health today', 'unstoppable-site-manager' ); ?></div>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
		<?php foreach ( $d['health'] as $h ) : ?>
			<tr>
				<td width="30" valign="top" style="padding:9px 0;border-top:1px solid <?php echo esc_attr( $rule ); ?>;">
					<div style="width:20px;height:20px;line-height:20px;border-radius:10px;text-align:center;color:#ffffff;font-size:12px;font-weight:700;background:<?php echo esc_attr( $tone[ $h[2] ][0] ); ?>;"><?php echo esc_html( USMD_Report::ICONS[ $h[2] ] ); ?></div>
				</td>
				<td valign="top" style="padding:9px 0;border-top:1px solid <?php echo esc_attr( $rule ); ?>;">
					<div style="font-weight:600;"><?php echo esc_html( $h[0] ); ?></div>
					<div style="font-size:14px;color:<?php echo esc_attr( 'ok' === $h[2] ? $muted : $tone[ $h[2] ][0] ); ?>;"><?php echo esc_html( $h[1] ); ?></div>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
</td></tr>

<?php $sec = isset( $d['security'] ) ? $d['security'] : array( 'open' => array(), 'fixed' => array() ); ?>
<?php if ( $sec['open'] || $sec['fixed'] ) : ?>
<tr><td style="padding:20px 20px 0;">
	<div style="font-size:16px;font-weight:700;padding-bottom:6px;border-bottom:2px solid <?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Security', 'unstoppable-site-manager' ); ?></div>
	<?php if ( $sec['fixed'] ) : ?>
		<div style="font-weight:700;margin:12px 0 2px;"><?php esc_html_e( 'Security fixes this month', 'unstoppable-site-manager' ); ?></div>
		<?php foreach ( $sec['fixed'] as $f ) : ?>
			<div style="padding:8px 0;border-top:1px solid <?php echo esc_attr( $rule ); ?>;">
				<span style="color:<?php echo esc_attr( $tone['ok'][0] ); ?>;font-weight:700;">✓</span>
				<strong><?php echo esc_html( $f[0] ); ?></strong> <span style="color:<?php echo esc_attr( $muted ); ?>;"><?php echo esc_html( $f[1] . ' → ' . $f[2] . ' · ' . USMD_Report::severity_label( $f[4] ) ); ?></span>
				<div style="font-size:14px;color:<?php echo esc_attr( $muted ); ?>;">
					<?php
					/* translators: 1: number fixed, 2: titles. */
					echo esc_html( sprintf( _n( 'Fixed %1$d issue: %2$s', 'Fixed %1$d issues, including: %2$s', $f[3], 'unstoppable-site-manager' ), $f[3], implode( '; ', $f[5] ) ) );
					?>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
	<?php if ( $sec['open'] ) : ?>
		<div style="font-weight:700;margin:12px 0 2px;"><?php esc_html_e( 'Still to fix', 'unstoppable-site-manager' ); ?></div>
		<?php foreach ( $sec['open'] as $o ) : ?>
			<div style="padding:8px 0;border-top:1px solid <?php echo esc_attr( $rule ); ?>;">
				<span style="color:<?php echo esc_attr( $tone['bad'][0] ); ?>;font-weight:700;">!</span>
				<strong><?php echo esc_html( $o[0] . ' ' . $o[1] ); ?></strong> <span style="color:<?php echo esc_attr( $tone['bad'][0] ); ?>;"><?php echo esc_html( USMD_Report::severity_label( $o[3] ) ); ?></span>
				<div style="font-size:14px;color:<?php echo esc_attr( $muted ); ?>;"><?php echo esc_html( $o[2] ); ?></div>
				<div style="font-size:14px;">
					<?php
					echo esc_html(
						$o[4]
							/* translators: %s: version. */
							? sprintf( __( 'Fixed by updating to %s or newer.', 'unstoppable-site-manager' ), $o[4] )
							: __( 'No fix released yet: we are watching it.', 'unstoppable-site-manager' )
					);
					?>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</td></tr>
<?php endif; ?>

<?php if ( '' !== $d['note'] ) : ?>
<tr><td style="padding:20px 20px 0;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6fb;border-left:4px solid <?php echo esc_attr( $accent ); ?>;"><tr><td style="padding:14px 18px;">
		<div style="font-size:16px;font-weight:700;margin:0 0 6px;"><?php echo esc_html( sprintf( /* translators: %s: agency name. */ __( 'A note from %s', 'unstoppable-site-manager' ), $s['agency_name'] ) ); ?></div>
		<?php foreach ( preg_split( "/\n\s*\n/", $d['note'] ) as $para ) : ?>
			<p style="margin:0 0 8px;"><?php echo nl2br( esc_html( trim( $para ) ) ); ?></p>
		<?php endforeach; ?>
	</td></tr></table>
</td></tr>
<?php endif; ?>

<?php if ( ! empty( $d['work'] ) ) : ?>
<tr><td style="padding:24px 20px 0;">
	<div style="font-size:16px;font-weight:700;padding-bottom:6px;border-bottom:2px solid <?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Work we did for you', 'unstoppable-site-manager' ); ?></div>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
		<?php foreach ( $d['work'] as $w ) : ?>
			<tr>
				<td width="64" valign="top" style="padding:9px 10px 9px 0;border-bottom:1px solid <?php echo esc_attr( $rule ); ?>;color:<?php echo esc_attr( $muted ); ?>;white-space:nowrap;"><?php echo esc_html( wp_date( 'M j', strtotime( $w['date'] . ' 12:00:00' ) ) ); ?></td>
				<td valign="top" style="padding:9px 0;border-bottom:1px solid <?php echo esc_attr( $rule ); ?>;">
					<strong><?php echo esc_html( $w['title'] ); ?></strong><?php if ( ! empty( $w['min'] ) && ! empty( USMD_Admin::settings()['report_time'] ) ) : ?> <span style="color:#5b6470;font-weight:400;">(<?php echo esc_html( USMD_Work::fmt( $w['min'] ) ); ?>)</span><?php endif; ?>
					<?php if ( '' !== $w['desc'] ) : ?>
						<div style="margin-top:3px;color:<?php echo esc_attr( $muted ); ?>;"><?php echo nl2br( esc_html( $w['desc'] ) ); ?></div>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
</td></tr>
<?php endif; ?>

<tr><td style="padding:24px 20px 0;">
	<div style="font-size:16px;font-weight:700;padding-bottom:6px;border-bottom:2px solid <?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Updates applied', 'unstoppable-site-manager' ); ?></div>
	<?php if ( $sum['updates'] ) : ?>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
			<?php foreach ( $sum['updates'] as $u ) : ?>
				<tr>
					<td valign="top" style="padding:9px 8px 9px 0;border-bottom:1px solid <?php echo esc_attr( $rule ); ?>;">
						<?php echo esc_html( $u['name'] ); ?>
						<span style="font-size:12px;color:<?php echo esc_attr( $muted ); ?>;"> · <?php echo esc_html( 'core' === $u['type'] ? __( 'Core', 'unstoppable-site-manager' ) : ( 'theme' === $u['type'] ? __( 'Theme', 'unstoppable-site-manager' ) : __( 'Plugin', 'unstoppable-site-manager' ) ) ); ?></span>
					</td>
					<td valign="top" align="right" style="padding:9px 0;border-bottom:1px solid <?php echo esc_attr( $rule ); ?>;white-space:nowrap;color:<?php echo esc_attr( $muted ); ?>;">
						<?php if ( $u['from'] && $u['to'] && $u['from'] !== $u['to'] ) : ?>
							<?php echo esc_html( $u['from'] . ' → ' ); ?><strong style="color:<?php echo esc_attr( $ink ); ?>;"><?php echo esc_html( $u['to'] ); ?></strong>
						<?php else : ?>
							<strong style="color:<?php echo esc_attr( $ink ); ?>;"><?php echo esc_html( $u['to'] ? $u['to'] : $u['from'] ); ?></strong>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php else : ?>
		<p style="margin:10px 0 0;color:<?php echo esc_attr( $muted ); ?>;"><?php esc_html_e( 'No updates were applied this month.', 'unstoppable-site-manager' ); ?></p>
	<?php endif; ?>
</td></tr>

<?php if ( $sum['changes'] ) : ?>
<tr><td style="padding:24px 20px 0;">
	<div style="font-size:16px;font-weight:700;padding-bottom:6px;border-bottom:2px solid <?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Other changes to your site', 'unstoppable-site-manager' ); ?></div>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
		<?php foreach ( $sum['changes'] as $g ) : ?>
			<tr>
				<td width="130" valign="top" style="padding:9px 10px 9px 0;border-bottom:1px solid <?php echo esc_attr( $rule ); ?>;font-weight:600;"><?php echo esc_html( $g['title'] ); ?></td>
				<td valign="top" style="padding:9px 0;border-bottom:1px solid <?php echo esc_attr( $rule ); ?>;"><?php echo esc_html( implode( ', ', $g['items'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
</td></tr>
<?php endif; ?>

<tr><td style="padding:24px 20px 26px;">
	<p style="margin:0 0 6px;"><?php esc_html_e( 'Questions about your website? Just reply to this email.', 'unstoppable-site-manager' ); ?></p>
	<?php if ( $s['report_contact'] ) : ?>
		<p style="margin:0 0 6px;color:<?php echo esc_attr( $muted ); ?>;font-size:14px;"><?php echo esc_html( $s['report_contact'] ); ?></p>
	<?php endif; ?>
	<p style="margin:14px 0 0;padding-top:12px;border-top:1px solid <?php echo esc_attr( $rule ); ?>;color:<?php echo esc_attr( $muted ); ?>;font-size:12px;"><?php echo esc_html( sprintf( /* translators: 1: agency name, 2: date. */ __( 'Prepared by %1$s on %2$s', 'unstoppable-site-manager' ), $s['agency_name'], wp_date( 'F j, Y' ) ) ); ?></p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
