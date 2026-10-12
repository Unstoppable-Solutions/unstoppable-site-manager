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
	const PER_TICK = 10; // Emails per hourly run, so a big list never times out.

	public static function hooks() {
		add_action( self::TICK, array( __CLASS__, 'tick' ) );
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

	/** Most recent send for a site: array( month, time, to, auto ) or null. */
	public static function last_sent( $site_id ) {
		$all = get_option( self::SENT );
		if ( ! is_array( $all ) || empty( $all[ (int) $site_id ] ) ) {
			return null;
		}
		$last = null;
		foreach ( $all[ (int) $site_id ] as $month => $row ) {
			if ( ! $last || $row['time'] > $last['time'] ) {
				$last          = $row;
				$last['month'] = $month;
			}
		}
		return $last;
	}

	private static function mark_sent( $site_id, $month, array $to, $auto ) {
		$all = get_option( self::SENT );
		$all = is_array( $all ) ? $all : array();
		$all[ (int) $site_id ][ $month ] = array(
			'time' => time(),
			'to'   => implode( ', ', $to ),
			'auto' => (bool) $auto,
		);
		update_option( self::SENT, $all, false );
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
		$s       = USMD_Admin::settings();
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

	/** Hourly: on or after the chosen day, send last month's report to every client who gets them. */
	public static function tick() {
		$s   = USMD_Admin::settings();
		$day = (int) $s['report_day'];
		if ( $day < 1 ) {
			return; // Automatic sending is off.
		}
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		if ( (int) $now->format( 'j' ) < $day || (int) $now->format( 'G' ) < 8 ) {
			return; // Not yet: from 8 am on the chosen day.
		}
		$month = $now->modify( 'first day of last month' )->format( 'Y-m' );
		$sent  = 0;
		foreach ( USMD_Sites::all() as $site ) {
			if ( $sent >= self::PER_TICK ) {
				break; // The rest go out next hour.
			}
			if ( empty( $site->report_auto ) || '' === $site->client_email || self::sent( $site->id, $month ) ) {
				continue;
			}
			$r = self::send( $site, $month, null, false, true );
			++$sent;
			if ( is_wp_error( $r ) ) {
				USMD_Sites::add_local_activity( $site->id, 'report', 'email_failed', $r->get_error_message() );
				// Record the attempt so a broken mail setup doesn't retry every hour all month.
				self::mark_sent( $site->id, $month, array(), true );
			}
		}
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
					<strong><?php echo esc_html( $w['title'] ); ?></strong>
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
