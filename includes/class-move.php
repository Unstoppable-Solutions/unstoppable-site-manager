<?php
/**
 * Move this dashboard to another WordPress install (a new domain).
 *
 * Old dashboard ("Move away"):
 *   1. You enter the new dashboard's address.
 *   2. Each site is asked (signed) for a handover code: a connection key that
 *      works once, for 24 hours, and only for a dashboard at that address.
 *   3. You download the move file: sites, clients, settings, report history,
 *      work notes and the handover keys. Nothing is stored on disk; it is built
 *      and sent to your browser. From then on this dashboard is "moved": it
 *      stops checking sites and sending reports. Cancel the move to undo.
 *
 * New dashboard ("Move here"):
 *   4. You choose the move file. Your browser sends it one site at a time.
 *      Each site is recreated with its history, then connected with its
 *      handover key, exactly like a normal connection. The site now trusts
 *      this dashboard's own key, so the old dashboard's key stops working.
 *
 * Sites locked to the old dashboard's IP address stay locked: their owners
 * allow the new address on the site (the dashboard can never loosen it).
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Move {

	const OPT           = 'usmd_move';
	const MIN_CONNECTOR = '0.8.0';
	const FORMAT        = 'unstoppable-site-manager-move';
	const SITE_PREFIX   = 'usmd_move_site_'; // One option per site, so parallel requests never overwrite each other.

	public static function hooks() {
		add_action( 'admin_post_usmd_move_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_usmd_move_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_usmd_move_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'wp_ajax_usmd_move_code', array( __CLASS__, 'ajax_code' ) );
		add_action( 'wp_ajax_usmd_move_import_start', array( __CLASS__, 'ajax_import_start' ) );
		add_action( 'wp_ajax_usmd_move_import_site', array( __CLASS__, 'ajax_import_site' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/* ---------- state of this (old) dashboard ---------- */

	/** @return array|null { target, host, started, keys, errors, downloaded } */
	public static function state() {
		$m = get_option( self::OPT );
		if ( ! is_array( $m ) || empty( $m['host'] ) ) {
			return null;
		}
		$m['keys']   = array();
		$m['errors'] = array();
		foreach ( USMD_Sites::all() as $s ) {
			$r = get_option( self::SITE_PREFIX . (int) $s->id );
			if ( is_array( $r ) && ! empty( $r['key'] ) ) {
				$m['keys'][ (int) $s->id ] = $r['key'];
			} elseif ( is_array( $r ) && ! empty( $r['error'] ) ) {
				$m['errors'][ (int) $s->id ] = $r['error'];
			}
		}
		return $m;
	}

	/** Forget every site's handover result. */
	private static function clear_sites() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removing this plugin's own options by prefix.
		$names = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( self::SITE_PREFIX ) . '%' ) );
		foreach ( $names as $n ) {
			delete_option( $n );
		}
	}

	/** True once the move file was downloaded: this dashboard no longer manages sites. */
	public static function is_moved() {
		$m = self::state();
		return $m && ! empty( $m['downloaded'] );
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=usmd-move' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( $action );
	}

	private static function ajax_guard() {
		check_ajax_referer( 'usmd', 'nonce' );
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'unstoppable-site-manager' ) ), 403 );
		}
	}

	private static function host( $url ) {
		return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ) );
	}

	/** A banner on this plugin's screens while a move is under way or done. */
	public static function notice() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'usmd' ) && false === strpos( (string) $screen->id, 'unstoppable-site-manager' ) ) {
			return;
		}
		$m = self::state();
		if ( ! $m || false !== strpos( (string) $screen->id, 'usmd-move' ) ) {
			return;
		}
		$text = $m['downloaded']
			/* translators: %s: new dashboard address. */
			? sprintf( __( 'This dashboard has been moved to %s. It no longer checks sites or sends reports, and sites stop accepting it once the new dashboard imports them.', 'unstoppable-site-manager' ), $m['host'] )
			/* translators: %s: new dashboard address. */
			: sprintf( __( 'A move to %s is being prepared.', 'unstoppable-site-manager' ), $m['host'] );
		printf(
			'<div class="notice notice-%1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			esc_attr( $m['downloaded'] ? 'warning' : 'info' ),
			esc_html( $text ),
			esc_url( self::page_url() ),
			esc_html__( 'Move dashboard', 'unstoppable-site-manager' )
		);
	}

	/* ---------- move away (old dashboard) ---------- */

	public static function handle_start() {
		self::guard( 'usmd_move_start' );
		$url  = isset( $_POST['target'] ) ? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['target'] ) ), array( 'https', 'http' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$host = self::host( $url );
		if ( ! $host || ! wp_http_validate_url( $url ) ) {
			wp_safe_redirect( self::page_url( array( 'msg' => 'badurl' ) ) );
			exit;
		}
		if ( self::host( home_url() ) === $host ) {
			wp_safe_redirect( self::page_url( array( 'msg' => 'same' ) ) );
			exit;
		}
		self::clear_sites();
		update_option(
			self::OPT,
			array(
				'target'     => untrailingslashit( $url ),
				'host'       => $host,
				'started'    => time(),
				'downloaded' => false,
			),
			false
		);
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_cancel() {
		self::guard( 'usmd_move_cancel' );
		delete_option( self::OPT );
		self::clear_sites();
		wp_safe_redirect( self::page_url( array( 'msg' => 'cancelled' ) ) );
		exit;
	}

	/** Ask one site for its handover key. */
	public static function ajax_code() {
		self::ajax_guard();
		$m    = self::state();
		$site = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		if ( ! $m || ! $site ) {
			wp_send_json_error( array( 'message' => __( 'No move is being prepared.', 'unstoppable-site-manager' ) ) );
		}
		$ver = USMD_Sites::connector_version( $site );
		if ( '' === $ver || version_compare( $ver, self::MIN_CONNECTOR, '<' ) ) {
			$msg = sprintf(
				/* translators: 1: needed connector version, 2: installed version. */
				__( 'Needs Unstoppable Site Connector %1$s or newer (this site has %2$s). Update it first, or reconnect it on the new dashboard by hand.', 'unstoppable-site-manager' ),
				self::MIN_CONNECTOR,
				$ver ? $ver : __( 'an unknown version: it has not reported yet', 'unstoppable-site-manager' )
			);
			self::record( $site->id, '', $msg );
			wp_send_json_error( array( 'message' => $msg ) );
		}
		$r = USMD_Client::call( $site, 'handover', array( 'dashboard' => $m['target'] ), 30, false );
		if ( is_wp_error( $r ) || empty( $r['key'] ) ) {
			$msg = is_wp_error( $r ) ? $r->get_error_message() : __( 'The site did not return a handover key.', 'unstoppable-site-manager' );
			self::record( $site->id, '', $msg );
			wp_send_json_error( array( 'message' => $msg ) );
		}
		self::record( $site->id, (string) $r['key'], '' );
		wp_send_json_success( array( 'ok' => true ) );
	}

	private static function record( $site_id, $key, $error ) {
		update_option(
			self::SITE_PREFIX . (int) $site_id,
			array(
				'key'   => (string) $key,
				'error' => (string) $error,
				'time'  => time(),
			),
			false
		);
	}

	/** Build the move file and send it to the browser. Nothing is written to disk. */
	public static function handle_download() {
		global $wpdb;
		self::guard( 'usmd_move_download' );
		$m = self::state();
		if ( ! $m ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}
		$notes = get_option( USMD_Report::NOTES );
		$notes = is_array( $notes ) ? $notes : array();
		$sent  = get_option( USMD_Report_Mail::SENT );
		$sent  = is_array( $sent ) ? $sent : array();
		$sites = array();
		foreach ( USMD_Sites::all() as $s ) {
			$sid       = (int) $s->id;
			$site_note = array();
			foreach ( $notes as $k => $text ) {
				if ( 0 === strpos( (string) $k, $sid . '-' ) ) {
					$site_note[ substr( (string) $k, strlen( $sid . '-' ) ) ] = $text;
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT remote_id, created_at, type, action, object, actor, details FROM %i WHERE site_id = %d ORDER BY id ASC', USMD_Sites::ta(), $sid ), ARRAY_A );
			$sites[] = array(
				'id'           => $sid,
				'url'          => $s->url,
				'name'         => $s->name,
				'client_id'    => (int) $s->client_id,
				'client_name'  => $s->client_name,
				'client_email' => $s->client_email,
				'report_auto'  => (int) $s->report_auto,
				'starred'      => (int) $s->starred,
				'created_at'   => $s->created_at,
				'key'          => isset( $m['keys'][ $sid ] ) ? $m['keys'][ $sid ] : '',
				'error'        => isset( $m['errors'][ $sid ] ) ? $m['errors'][ $sid ] : '',
				'notes'        => $site_note,
				'sent'         => isset( $sent[ $sid ] ) ? $sent[ $sid ] : array(),
				'activity'     => $rows,
			);
		}
		$clients = array();
		foreach ( USMD_Clients::all() as $c ) {
			$clients[] = array(
				'id'       => (int) $c->id,
				'name'     => $c->name,
				'settings' => $c->settings,
			);
		}
		$file = array(
			'format'    => self::FORMAT,
			'v'         => 1,
			'version'   => USMD_VERSION,
			'from'      => home_url( '/' ),
			'from_name' => get_bloginfo( 'name' ),
			'target'    => $m['target'],
			'host'      => $m['host'],
			'created'   => time(),
			'expires'   => time() + DAY_IN_SECONDS,
			'settings'  => get_option( 'usmd_settings' ),
			'clients'   => $clients,
			'sites'     => $sites,
		);
		$saved               = get_option( self::OPT );
		$saved['downloaded'] = true;
		update_option( self::OPT, $saved, false );

		$json = wp_json_encode( $file );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="unstoppable-move-' . sanitize_file_name( $m['host'] ) . '.json"' );
		header( 'Content-Length: ' . strlen( (string) $json ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON file download.
		exit;
	}

	/* ---------- move here (new dashboard) ---------- */

	/** Check the file is for this dashboard; create its clients and (optionally) copy settings. */
	public static function ajax_import_start() {
		global $wpdb;
		self::ajax_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		$head = isset( $_POST['head'] ) ? json_decode( wp_unslash( (string) $_POST['head'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, checked below.
		$copy = ! empty( $_POST['settings'] );
		// phpcs:enable
		if ( ! is_array( $head ) || self::FORMAT !== ( isset( $head['format'] ) ? $head['format'] : '' ) ) {
			wp_send_json_error( array( 'message' => __( 'That is not a move file from Unstoppable Site Manager.', 'unstoppable-site-manager' ) ) );
		}
		if ( self::host( isset( $head['target'] ) ? $head['target'] : '' ) !== self::host( home_url() ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: address the file was made for. */
					'message' => sprintf( __( 'This move file was made for %s, not this site. Make a new one on the old dashboard with this site\'s address.', 'unstoppable-site-manager' ), (string) ( isset( $head['host'] ) ? $head['host'] : '?' ) ),
				)
			);
		}
		if ( isset( $head['expires'] ) && time() > (int) $head['expires'] ) {
			wp_send_json_error( array( 'message' => __( 'This move file has expired (handover codes last 24 hours). Cancel the move on the old dashboard and start it again.', 'unstoppable-site-manager' ) ) );
		}

		if ( $copy && isset( $head['settings'] ) && is_array( $head['settings'] ) ) {
			$keep = array_intersect_key( $head['settings'], USMD_Admin::settings() );
			update_option( 'usmd_settings', array_map( array( __CLASS__, 'clean_setting' ), $keep ) );
		}

		$map = array();
		foreach ( isset( $head['clients'] ) && is_array( $head['clients'] ) ? $head['clients'] : array() as $c ) {
			$name = substr( sanitize_text_field( isset( $c['name'] ) ? (string) $c['name'] : '' ), 0, 191 );
			if ( '' === $name ) {
				continue;
			}
			$existing = 0;
			foreach ( USMD_Clients::all() as $have ) {
				if ( 0 === strcasecmp( $have->name, $name ) ) {
					$existing = (int) $have->id;
					break;
				}
			}
			if ( ! $existing ) {
				$settings = array();
				foreach ( isset( $c['settings'] ) && is_array( $c['settings'] ) ? $c['settings'] : array() as $k => $v ) {
					if ( isset( USMD_Clients::FIELDS[ $k ] ) ) {
						$settings[ $k ] = self::clean_setting( $v );
					}
				}
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
					USMD_Clients::table(),
					array(
						'name'       => $name,
						'settings'   => wp_json_encode( $settings ),
						'created_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$existing = (int) $wpdb->insert_id;
				USMD_Clients::flush();
			}
			$map[ (int) $c['id'] ] = $existing;
		}
		wp_send_json_success( array( 'clients' => $map ) );
	}

	/** Plain values only: strings are sanitised as text, numbers kept. */
	public static function clean_setting( $v ) {
		if ( is_int( $v ) || is_float( $v ) || is_bool( $v ) ) {
			return $v;
		}
		return is_string( $v ) ? sanitize_textarea_field( $v ) : '';
	}

	/** Recreate one site with its history, then connect it with its handover key. */
	public static function ajax_import_site() {
		global $wpdb;
		self::ajax_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		$d   = isset( $_POST['site'] ) ? json_decode( wp_unslash( (string) $_POST['site'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, checked below.
		$map = isset( $_POST['clients'] ) ? json_decode( wp_unslash( (string) $_POST['clients'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, ids cast below.
		// phpcs:enable
		$map = is_array( $map ) ? $map : array();
		if ( ! is_array( $d ) || empty( $d['url'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing site details.', 'unstoppable-site-manager' ) ) );
		}
		$url = untrailingslashit( esc_url_raw( (string) $d['url'], array( 'https', 'http' ) ) );
		$key = isset( $d['key'] ) ? (string) $d['key'] : '';
		$k   = $key ? USMD_Client::decode_key( $key ) : null;
		if ( $k && ! is_wp_error( $k ) ) {
			// The key's code only works on the site that issued it, so the address inside it
			// is the site's current one (it may have changed since the old dashboard saw it).
			$url = untrailingslashit( esc_url_raw( (string) $k['site'], array( 'https', 'http' ) ) );
		}

		// 1. The site row and its history (skipped if this site is already here).
		$site = USMD_Sites::get_by_url( $url );
		if ( $site && '' !== (string) $site->child_pubkey ) {
			// Already here: really connected (moved on an earlier run), or an old, stale record?
			$ping = USMD_Client::call( $site, 'ping', array(), 20, false );
			if ( ! is_wp_error( $ping ) ) {
				wp_send_json_success( array( 'already' => true, 'locked' => false ) );
			}
		}
		if ( ! $site ) {
			$cid = isset( $map[ (int) $d['client_id'] ] ) ? (int) $map[ (int) $d['client_id'] ] : 0;
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
				USMD_Sites::t(),
				array(
					'site_uuid'    => '',
					'name'         => substr( sanitize_text_field( (string) $d['name'] ), 0, 191 ),
					'url'          => $url,
					'api'          => ( $k && ! is_wp_error( $k ) ) ? esc_url_raw( $k['api'] ) : '',
					'child_pubkey' => '',
					'client_id'    => $cid,
					'client_name'  => substr( sanitize_text_field( (string) $d['client_name'] ), 0, 191 ),
					'client_email' => implode( ', ', USMD_Sites::parse_emails( (string) $d['client_email'] ) ),
					'report_auto'  => empty( $d['report_auto'] ) ? 0 : 1,
					'starred'      => empty( $d['starred'] ) ? 0 : 1,
					'last_error'   => __( 'Moved here: waiting to connect.', 'unstoppable-site-manager' ),
					'created_at'   => preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $d['created_at'] ) ? $d['created_at'] : gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$id = (int) $wpdb->insert_id;
			foreach ( isset( $d['activity'] ) && is_array( $d['activity'] ) ? $d['activity'] : array() as $r ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
					USMD_Sites::ta(),
					array(
						'site_id'    => $id,
						'remote_id'  => isset( $r['remote_id'] ) ? (int) $r['remote_id'] : 0,
						'created_at' => preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $r['created_at'] ) ? $r['created_at'] : gmdate( 'Y-m-d H:i:s' ),
						'type'       => substr( sanitize_key( (string) $r['type'] ), 0, 20 ),
						'action'     => substr( sanitize_key( (string) $r['action'] ), 0, 40 ),
						'object'     => substr( sanitize_text_field( (string) $r['object'] ), 0, 191 ),
						'actor'      => substr( sanitize_text_field( (string) $r['actor'] ), 0, 60 ),
						'details'    => ( isset( $r['details'] ) && null !== $r['details'] ) ? wp_json_encode( json_decode( (string) $r['details'], true ) ) : null,
					)
				);
			}
			$notes = get_option( USMD_Report::NOTES );
			$notes = is_array( $notes ) ? $notes : array();
			foreach ( isset( $d['notes'] ) && is_array( $d['notes'] ) ? $d['notes'] : array() as $month => $text ) {
				if ( preg_match( '/^\d{4}-\d\d$/', (string) $month ) ) {
					$notes[ $id . '-' . $month ] = sanitize_textarea_field( (string) $text );
				}
			}
			update_option( USMD_Report::NOTES, $notes, false );
			$sent = get_option( USMD_Report_Mail::SENT );
			$sent = is_array( $sent ) ? $sent : array();
			foreach ( isset( $d['sent'] ) && is_array( $d['sent'] ) ? $d['sent'] : array() as $month => $row ) {
				if ( preg_match( '/^\d{4}-\d\d$/', (string) $month ) && is_array( $row ) ) {
					$sent[ $id ][ $month ] = array(
						'time' => isset( $row['time'] ) ? (int) $row['time'] : 0,
						'to'   => isset( $row['to'] ) ? sanitize_text_field( (string) $row['to'] ) : '',
						'auto' => ! empty( $row['auto'] ),
					);
				}
			}
			update_option( USMD_Report_Mail::SENT, $sent, false );
			$site = USMD_Sites::get( $id );
		}

		// 2. Connect it with the handover key.
		if ( ! $k || is_wp_error( $k ) ) {
			$why = ! empty( $d['error'] ) ? (string) $d['error'] : __( 'No handover key in the move file.', 'unstoppable-site-manager' );
			USMD_Sites::mark_error( $site->id, __( 'Not connected yet: get a connection key from the site and paste it into Add Site.', 'unstoppable-site-manager' ) );
			wp_send_json_error( array( 'message' => $why, 'kept' => true ) );
		}
		$pid = USMD_Client::pair( $key );
		if ( is_wp_error( $pid ) ) {
			USMD_Sites::mark_error( $site->id, __( 'Not connected yet: get a connection key from the site and paste it into Add Site.', 'unstoppable-site-manager' ) );
			wp_send_json_error( array( 'message' => $pid->get_error_message(), 'kept' => true ) );
		}
		// 3. Does the site accept requests from this server, or is it locked to the old dashboard's address?
		$ping   = USMD_Client::call( USMD_Sites::get( $pid ), 'ping', array(), 20, false );
		$locked = is_wp_error( $ping ) && 'ip_blocked' === USMD_Client::connector_code( $ping );
		if ( $locked ) {
			USMD_Sites::mark_error( $pid, __( 'This site is locked to the old dashboard\'s IP address. On the site, open Tools > Unstoppable Site Connector > Security and allow this dashboard\'s address.', 'unstoppable-site-manager' ) );
		}
		wp_send_json_success( array( 'locked' => $locked ) );
	}

	/* ---------- screen ---------- */

	public static function page() {
		$m     = self::state();
		$sites = USMD_Sites::all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$msg  = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$msgs = array(
			'badurl'    => array( 'error', __( 'Enter the full address of the new dashboard, like https://manage.youragency.com', 'unstoppable-site-manager' ) ),
			'same'      => array( 'error', __( 'That is this dashboard\'s own address. Enter the new dashboard\'s address.', 'unstoppable-site-manager' ) ),
			'cancelled' => array( 'success', __( 'Move cancelled. This dashboard manages its sites as before. Handover codes already made expire on their own within 24 hours.', 'unstoppable-site-manager' ) ),
		);
		?>
		<div class="wrap usmd-wrap usmd-move">
			<h1><?php esc_html_e( 'Move dashboard', 'unstoppable-site-manager' ); ?></h1>
			<p class="usmd-move-lead"><?php esc_html_e( 'Move all your sites, clients, settings and report history from one Unstoppable Site Manager to another, for example from a test site to its permanent home. Sites stay connected; after the move only the new dashboard can manage them.', 'unstoppable-site-manager' ); ?></p>
			<?php if ( isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $msg ][0] ); ?>"><p><?php echo esc_html( $msgs[ $msg ][1] ); ?></p></div>
			<?php endif; ?>

			<div class="usmd-move-cols">
				<section class="usmd-panel usmd-move-away">
					<h2><?php esc_html_e( 'Move away from this dashboard', 'unstoppable-site-manager' ); ?></h2>
					<?php if ( ! $m ) : ?>
						<?php if ( ! $sites ) : ?>
							<p class="usmd-muted"><?php esc_html_e( 'No sites are connected here, so there is nothing to move.', 'unstoppable-site-manager' ); ?></p>
						<?php else : ?>
							<ol class="usmd-move-steps">
								<li><?php esc_html_e( 'Install Unstoppable Site Manager on the new site first.', 'unstoppable-site-manager' ); ?></li>
								<li><?php esc_html_e( 'Enter its address below and start. Each site is asked for a one-time handover code that only works for that address, for 24 hours.', 'unstoppable-site-manager' ); ?></li>
								<li><?php esc_html_e( 'Download the move file and import it on the new dashboard under Move dashboard > Move here.', 'unstoppable-site-manager' ); ?></li>
							</ol>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-move-start">
								<input type="hidden" name="action" value="usmd_move_start">
								<?php wp_nonce_field( 'usmd_move_start' ); ?>
								<label for="usmd-move-target"><?php esc_html_e( 'New dashboard address', 'unstoppable-site-manager' ); ?></label>
								<input type="url" id="usmd-move-target" name="target" class="regular-text" required placeholder="https://manage.youragency.com">
								<?php submit_button( __( 'Start the move', 'unstoppable-site-manager' ), 'primary', 'submit', false ); ?>
							</form>
							<p class="usmd-muted"><?php esc_html_e( 'Sites locked to this dashboard\'s IP address stay locked. If the new dashboard is on another server, each of those sites must allow its address (the import lists them).', 'unstoppable-site-manager' ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p>
							<?php
							/* translators: %s: new dashboard address. */
							echo esc_html( sprintf( __( 'Moving to %s.', 'unstoppable-site-manager' ), $m['target'] ) );
							?>
						</p>
						<?php
						$ok  = count( $m['keys'] );
						$bad = count( $m['errors'] );
						?>
						<div id="usmd-move-codes" data-sites="<?php echo esc_attr( wp_json_encode( array_map( static function ( $s ) { return (int) $s->id; }, $sites ) ) ); ?>" data-done="<?php echo esc_attr( wp_json_encode( array_map( 'intval', array_keys( $m['keys'] ) ) ) ); ?>">
							<p class="usmd-move-count">
								<strong id="usmd-move-ok"><?php echo (int) $ok; ?></strong> / <?php echo (int) count( $sites ); ?> <?php esc_html_e( 'sites ready to move', 'unstoppable-site-manager' ); ?>
								<?php if ( $bad ) : ?>
									· <span class="usmd-bad"><?php echo esc_html( sprintf( /* translators: %d: number of sites. */ _n( '%d needs attention', '%d need attention', $bad, 'unstoppable-site-manager' ), $bad ) ); ?></span>
								<?php endif; ?>
							</p>
							<p><button type="button" class="button button-primary" id="usmd-move-run"><?php echo $ok ? esc_html__( 'Try the remaining sites again', 'unstoppable-site-manager' ) : esc_html__( 'Get handover codes from all sites', 'unstoppable-site-manager' ); ?></button></p>
							<ul class="usmd-move-errors" id="usmd-move-errors">
								<?php foreach ( $m['errors'] as $sid => $err ) : ?>
									<?php $s = USMD_Sites::get( $sid ); ?>
									<?php if ( $s ) : ?>
										<li><strong><?php echo esc_html( $s->name ); ?></strong>: <?php echo esc_html( $err ); ?></li>
									<?php endif; ?>
								<?php endforeach; ?>
							</ul>
						</div>
						<div class="usmd-move-actions">
							<a class="button<?php echo $ok ? ' button-primary' : ''; ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=usmd_move_download' ), 'usmd_move_download' ) ); ?>"><?php esc_html_e( 'Download move file', 'unstoppable-site-manager' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ask="<?php esc_attr_e( 'Cancel the move? This dashboard goes back to managing its sites. Sites already imported on the new dashboard stay there.', 'unstoppable-site-manager' ); ?>">
								<input type="hidden" name="action" value="usmd_move_cancel">
								<?php wp_nonce_field( 'usmd_move_cancel' ); ?>
								<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Cancel the move', 'unstoppable-site-manager' ); ?></button>
							</form>
						</div>
						<p class="usmd-muted">
							<?php
							echo $m['downloaded']
								? esc_html__( 'The move file was downloaded, so this dashboard no longer checks sites or sends reports. Keep the file private: it can connect your sites to the new address for 24 hours.', 'unstoppable-site-manager' )
								: esc_html__( 'Downloading the move file switches this dashboard off: it stops checking sites and sending reports, so clients never get two reports. Keep the file private.', 'unstoppable-site-manager' );
							?>
						</p>
					<?php endif; ?>
				</section>

				<section class="usmd-panel usmd-move-here">
					<h2><?php esc_html_e( 'Move here', 'unstoppable-site-manager' ); ?></h2>
					<p><?php esc_html_e( 'Choose the move file made on your old dashboard. Each site is recreated with its clients, report history and work notes, then connected to this dashboard.', 'unstoppable-site-manager' ); ?></p>
					<input type="file" id="usmd-move-file" accept=".json,application/json">
					<div id="usmd-move-preview" hidden>
						<p id="usmd-move-summary"></p>
						<label><input type="checkbox" id="usmd-move-settings" checked> <?php esc_html_e( 'Also copy Settings (branding, report email settings, screenshots, admin footer)', 'unstoppable-site-manager' ); ?></label>
						<p><button type="button" class="button button-primary" id="usmd-move-import"><?php esc_html_e( 'Import and connect sites', 'unstoppable-site-manager' ); ?></button></p>
					</div>
					<div id="usmd-move-results" hidden>
						<p id="usmd-move-progress" role="status"></p>
						<ul id="usmd-move-list" class="usmd-move-list"></ul>
					</div>
				</section>
			</div>
		</div>
		<?php
	}
}
