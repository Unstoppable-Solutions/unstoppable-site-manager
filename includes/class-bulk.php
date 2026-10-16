<?php
/**
 * Bulk connect: connect many client sites without visiting each one.
 *
 * 1. Create a bulk-connect pass here (valid for a number of days and sites).
 * 2. Download the connector ZIP made for it: the connector from WordPress.org
 *    plus an enroll.json file holding this dashboard's address and the pass.
 *    The ZIP is built when you download it and is never stored here.
 * 3. Install that ZIP on the sites with any bulk tool (MainWP, ManageWP, WP-CLI...).
 *    Each site then asks this dashboard to connect it, using the pass.
 *
 * The pass only lets a site ask to be connected to this dashboard. The dashboard
 * then runs the normal pairing with the one-time code the site made for itself,
 * so a site can only be added if it really runs the connector at that address.
 * The pass is kept in this plugin's settings only while it is open, so the ZIP
 * can be downloaded again; sites are checked against its hash.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Bulk {

	const OPT       = 'usmd_bulk';
	const TYPE      = 'bulk';
	const MAX_SITES = 2000;
	const MAX_DAYS  = 30;
	const MIN_VER   = '0.6.0'; // First connector with bulk connect.

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		if ( is_admin() ) {
			add_action( 'admin_post_usmd_bulk_create', array( __CLASS__, 'handle_create' ) );
			add_action( 'admin_post_usmd_bulk_cancel', array( __CLASS__, 'handle_cancel' ) );
			add_action( 'admin_post_usmd_bulk_download', array( __CLASS__, 'handle_download' ) );
			add_action( 'admin_post_usmd_bulk_check', array( __CLASS__, 'handle_check' ) );
		}
	}

	/* ---------- the pass ---------- */

	/** @return array|null The current pass: id, hash, token, created, expires, max, cancelled. */
	public static function pass() {
		$p = get_option( self::OPT );
		return is_array( $p ) && ! empty( $p['id'] ) ? $p : null;
	}

	public static function is_open( $p ) {
		return $p && empty( $p['cancelled'] ) && time() < (int) $p['expires'] && self::used( $p['id'] ) < (int) $p['max'];
	}

	/** Sites connected with this pass. */
	public static function used( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND action = %s AND actor = %s', USMD_Sites::ta(), self::TYPE, 'connected', 'bulk:' . $id ) );
	}

	/** Recent results for this pass: connected sites and refused attempts. */
	public static function log( $id, $limit = 100 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE type = %s AND actor = %s ORDER BY id DESC LIMIT %d', USMD_Sites::ta(), self::TYPE, 'bulk:' . $id, $limit ) );
	}

	private static function record( $id, $action, $site_id, $url, $error = '' ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
			USMD_Sites::ta(),
			array(
				'site_id'    => (int) $site_id,
				'remote_id'  => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'type'       => self::TYPE,
				'action'     => $action,
				'object'     => substr( (string) $url, 0, 191 ),
				'actor'      => 'bulk:' . $id,
				'details'    => $error ? wp_json_encode( array( 'error' => $error ) ) : null,
			)
		);
	}

	private static function guard( $action ) {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( $action );
	}

	private static function back( $msg ) {
		wp_safe_redirect( add_query_arg( 'bulk', $msg, admin_url( 'admin.php?page=usmd-add' ) ) . '#usmd-bc' );
		exit;
	}

	public static function handle_create() {
		self::guard( 'usmd_bulk_create' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$days = isset( $_POST['days'] ) ? max( 1, min( self::MAX_DAYS, absint( $_POST['days'] ) ) ) : 7;
		$max  = isset( $_POST['max'] ) ? max( 1, min( self::MAX_SITES, absint( $_POST['max'] ) ) ) : 50;
		// phpcs:enable
		$token = bin2hex( random_bytes( 32 ) );
		update_option(
			self::OPT,
			array(
				'id'        => substr( bin2hex( random_bytes( 6 ) ), 0, 12 ),
				'hash'      => hash( 'sha256', $token ),
				'token'     => $token,
				'created'   => time(),
				'expires'   => time() + $days * DAY_IN_SECONDS,
				'max'       => $max,
				'cancelled' => false,
			),
			false
		);
		self::back( 'created' );
	}

	public static function handle_cancel() {
		self::guard( 'usmd_bulk_cancel' );
		$p = self::pass();
		if ( $p ) {
			$p['cancelled'] = true;
			self::forget_token( $p );
		}
		self::back( 'cancelled' );
	}

	/** An ended pass no longer needs its secret: only the hash and the counts stay. */
	private static function forget_token( array $p ) {
		unset( $p['token'] );
		update_option( self::OPT, $p, false );
	}

	/**
	 * Build the bulk-connect ZIP and send it to the browser: the connector from
	 * WordPress.org (downloaded to WordPress's temporary folder) plus enroll.json.
	 * The temporary file is deleted straight after.
	 */
	public static function handle_download() {
		self::guard( 'usmd_bulk_download' );
		$p = self::pass();
		if ( ! self::is_open( $p ) || empty( $p['token'] ) ) {
			wp_die( esc_html__( 'This bulk-connect pass has ended. Create a new one on the Add Site screen.', 'unstoppable-site-manager' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			self::back( 'no_ziparchive' );
		}
		$zip = USMD_Connector_Zip::fetch();
		if ( is_wp_error( $zip ) ) {
			set_transient( 'usmd_bulk_error_' . get_current_user_id(), $zip->get_error_message(), 300 );
			self::back( 'wporg' );
		}
		if ( version_compare( $zip['version'], self::MIN_VER, '<' ) ) {
			wp_delete_file( $zip['file'] );
			self::back( 'old' );
		}
		$conf = array(
			'v'         => 1,
			'dashboard' => get_bloginfo( 'name' ),
			'home'      => home_url( '/' ),
			'enroll'    => rest_url( 'usmd/v1/enroll' ),
			'pass'      => $p['id'] . '.' . $p['token'],
		);
		$z = new ZipArchive();
		if ( true !== $z->open( $zip['file'] ) ) {
			wp_delete_file( $zip['file'] );
			self::back( 'failed' );
		}
		$z->addFromString( USMD_CONNECTOR_SLUG . '/enroll.json', wp_json_encode( $conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$z->close();

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="unstoppable-site-connector.zip"' );
		header( 'Content-Length: ' . filesize( $zip['file'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $zip['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- sending the temporary file just built.
		wp_delete_file( $zip['file'] );
		exit;
	}

	/* ---------- the endpoint sites call ---------- */

	public static function register() {
		register_rest_route(
			'usmd/v1',
			'/enroll',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'enroll' ),
				'permission_callback' => '__return_true', // Protected by the bulk-connect pass below.
			)
		);
	}

	public static function enroll( WP_REST_Request $request ) {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$limit = 'usmd_bulk_fail_' . md5( $ip );
		if ( (int) get_transient( $limit ) >= 20 ) {
			return new WP_Error( 'usmd_bulk_limit', 'Too many failed attempts. Try again later.', array( 'status' => 429 ) );
		}
		$fail = static function ( $code, $message, $status ) use ( $limit ) {
			set_transient( $limit, (int) get_transient( $limit ) + 1, HOUR_IN_SECONDS );
			return new WP_Error( $code, $message, array( 'status' => $status ) );
		};

		$body  = $request->get_json_params();
		$body  = is_array( $body ) ? $body : array();
		$parts = explode( '.', isset( $body['pass'] ) ? (string) $body['pass'] : '', 2 );
		$p     = self::pass();
		if ( 2 !== count( $parts ) || ! $p || ! hash_equals( (string) $p['id'], $parts[0] ) || ! hash_equals( (string) $p['hash'], hash( 'sha256', $parts[1] ) ) ) {
			return $fail( 'usmd_bulk_pass', 'This bulk-connect pass is not valid for this dashboard.', 403 );
		}
		if ( ! self::is_open( $p ) ) {
			return $fail( 'usmd_bulk_closed', 'This bulk-connect pass has expired, been cancelled or been used up. Create a new one on the dashboard.', 403 );
		}

		$key = isset( $body['key'] ) ? (string) $body['key'] : '';
		$k   = USMD_Client::decode_key( $key );
		if ( is_wp_error( $k ) ) {
			return $fail( 'usmd_bulk_key', 'Missing or malformed connection key.', 400 );
		}
		// The site must be a public web address, and its API must live on that same site.
		$host = static function ( $u ) {
			return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) );
		};
		if ( ! wp_http_validate_url( $k['site'] ) || ! wp_http_validate_url( $k['api'] ) || $host( $k['site'] ) !== $host( $k['api'] ) ) {
			self::record( $p['id'], 'refused', 0, $k['site'], 'Not a public web address.' );
			return $fail( 'usmd_bulk_url', 'The site address is not a public web address.', 400 );
		}

		// The normal pairing: this dashboard calls the site back with its one-time code.
		$id = USMD_Client::pair( $key );
		if ( is_wp_error( $id ) ) {
			self::record( $p['id'], 'refused', 0, $k['site'], $id->get_error_message() );
			return $fail( 'usmd_bulk_pair', $id->get_error_message(), 502 );
		}
		self::record( $p['id'], 'connected', $id, $k['site'] );
		USMD_Sites::add_local_activity( $id, 'connector', 'bulk_connected', $k['site'] );
		return rest_ensure_response(
			array(
				'ok'        => true,
				'dashboard' => get_bloginfo( 'name' ),
			)
		);
	}

	/* ---------- MainWP export check ---------- */

	private static function normalise( $url ) {
		$url  = strtolower( trim( (string) $url ) );
		$url  = preg_replace( '#^https?://(www\.)?#', '', $url );
		return untrailingslashit( $url );
	}

	public static function handle_check() {
		self::guard( 'usmd_bulk_check' );
		$file = isset( $_FILES['csv'] ) ? $_FILES['csv'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce checked in guard(); validated below.
		if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 5 * MB_IN_BYTES ) {
			self::back( 'csv_bad' );
		}
		$rows = array();
		$fh   = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading an uploaded CSV.
		if ( $fh ) {
			while ( false !== ( $r = fgetcsv( $fh, 0, ',', '"', '\\' ) ) && count( $rows ) < 5000 ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$rows[] = $r;
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		wp_delete_file( $file['tmp_name'] );
		if ( count( $rows ) < 2 ) {
			self::back( 'csv_bad' );
		}
		// Find the URL column (MainWP's export calls it "URL"); otherwise the first column holding web addresses.
		$head = array_map( 'strtolower', array_map( 'trim', $rows[0] ) );
		$col  = array_search( 'url', $head, true );
		$name = array_search( 'site name', $head, true );
		if ( false === $col ) {
			foreach ( $rows[1] as $i => $v ) {
				if ( preg_match( '#^https?://#i', trim( (string) $v ) ) ) {
					$col = $i;
					break;
				}
			}
		}
		if ( false === $col ) {
			self::back( 'csv_bad' );
		}
		$have = array();
		foreach ( USMD_Sites::all() as $s ) {
			$have[ self::normalise( $s->url ) ] = true;
		}
		$listed  = 0;
		$missing = array();
		foreach ( array_slice( $rows, 1 ) as $r ) {
			$url = isset( $r[ $col ] ) ? trim( (string) $r[ $col ] ) : '';
			if ( '' === $url ) {
				continue;
			}
			++$listed;
			if ( ! isset( $have[ self::normalise( $url ) ] ) ) {
				$missing[] = array(
					'url'  => esc_url_raw( $url ),
					'name' => false !== $name && isset( $r[ $name ] ) ? sanitize_text_field( $r[ $name ] ) : '',
				);
			}
		}
		set_transient(
			'usmd_bulk_check_' . get_current_user_id(),
			array(
				'listed'  => $listed,
				'missing' => array_slice( $missing, 0, 1000 ),
				'total'   => count( $missing ),
			),
			HOUR_IN_SECONDS
		);
		self::back( 'checked' );
	}

	/* ---------- the Add Site box ---------- */

	public static function box() {
		$p     = self::pass();
		$open  = self::is_open( $p );
		if ( $p && ! $open && ! empty( $p['token'] ) ) {
			self::forget_token( $p ); // Expired or used up.
		}
		$error = get_transient( 'usmd_bulk_error_' . get_current_user_id() );
		delete_transient( 'usmd_bulk_error_' . get_current_user_id() );
		$check = get_transient( 'usmd_bulk_check_' . get_current_user_id() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$msg  = isset( $_GET['bulk'] ) ? sanitize_key( $_GET['bulk'] ) : '';
		$msgs = array(
			'created'       => array( 'success', __( 'Bulk-connect pass created. Download the connector below and install it on your sites.', 'unstoppable-site-manager' ) ),
			'cancelled'     => array( 'success', __( 'Bulk-connect pass cancelled. Sites can no longer use it.', 'unstoppable-site-manager' ) ),
			/* translators: %s: error message. */
			'wporg'         => array( 'error', sprintf( __( 'The connector could not be downloaded from WordPress.org: %s', 'unstoppable-site-manager' ), (string) $error ) ),
			/* translators: %s: version number. */
			'old'           => array( 'error', sprintf( __( 'The connector on WordPress.org is older than %s, which bulk connect needs. Try again later.', 'unstoppable-site-manager' ), self::MIN_VER ) ),
			'no_ziparchive' => array( 'error', __( 'This server\'s PHP is missing the Zip extension, which is needed to make the connector ZIP. Ask your host to enable it.', 'unstoppable-site-manager' ) ),
			'failed'        => array( 'error', __( 'The connector ZIP could not be made. Check that WordPress\'s temporary folder is writable.', 'unstoppable-site-manager' ) ),
			'csv_bad'       => array( 'error', __( 'That file could not be read. Upload the CSV from MainWP > Settings > Tools > Export child sites, or any CSV with a URL column.', 'unstoppable-site-manager' ) ),
		);
		?>
		<div class="usmd-bc" id="usmd-bc">
			<h2><?php esc_html_e( 'Connect many sites at once', 'unstoppable-site-manager' ); ?></h2>
			<p><?php esc_html_e( 'Moving from MainWP, ManageWP or another tool? Make a connector that knows this dashboard, install it on all your sites with that tool\'s bulk plugin install, and each site connects itself. No codes to copy.', 'unstoppable-site-manager' ); ?></p>
			<?php if ( isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $msg ][0] ); ?> inline"><p><?php echo esc_html( $msgs[ $msg ][1] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $open ) : ?>
				<?php $used = self::used( $p['id'] ); ?>
				<div class="usmd-bc-pass">
					<p class="usmd-bc-stat">
						<strong>
							<?php
							/* translators: 1: sites connected, 2: maximum. */
							echo esc_html( sprintf( __( '%1$d of %2$d sites connected', 'unstoppable-site-manager' ), $used, (int) $p['max'] ) );
							?>
						</strong>
						<span class="usmd-muted">
							<?php
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Pass works until %s', 'unstoppable-site-manager' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $p['expires'] ) ) );
							?>
						</span>
					</p>
					<div class="usmd-bc-actions">
						<?php if ( ! empty( $p['token'] ) ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=usmd_bulk_download' ), 'usmd_bulk_download' ) ); ?>">
								<?php esc_html_e( 'Download bulk-connect connector', 'unstoppable-site-manager' ); ?>
							</a>
						<?php else : ?>
							<span class="usmd-muted"><?php esc_html_e( 'This pass was made by an older version and its ZIP can\'t be downloaded again. Sites that already have it still connect; create a new pass for a new download.', 'unstoppable-site-manager' ); ?></span>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-bc-cancel">
							<input type="hidden" name="action" value="usmd_bulk_cancel">
							<?php wp_nonce_field( 'usmd_bulk_cancel' ); ?>
							<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Cancel this pass', 'unstoppable-site-manager' ); ?></button>
						</form>
					</div>
					<ol class="usmd-bc-steps">
						<li><?php esc_html_e( 'Download the connector above. Keep it private: anyone with it can add their own sites to this dashboard until the pass ends.', 'unstoppable-site-manager' ); ?></li>
						<li><?php esc_html_e( 'In MainWP, go to Plugins > Install > Upload .zip, choose this file, select your sites, tick "Overwrite existing" and "Activate plugin after installation", and install. Any bulk tool or WP-CLI works too.', 'unstoppable-site-manager' ); ?></li>
						<li><?php esc_html_e( 'Each site connects itself within a few minutes (on its next visit or scheduled task) and appears on your Sites screen. Refresh this page to watch the count go up.', 'unstoppable-site-manager' ); ?></li>
					</ol>
				</div>
			<?php else : ?>
				<?php if ( $p && ! empty( $p['cancelled'] ) ) : ?>
					<p class="usmd-muted"><?php esc_html_e( 'The last pass was cancelled.', 'unstoppable-site-manager' ); ?></p>
				<?php elseif ( $p ) : ?>
					<p class="usmd-muted"><?php esc_html_e( 'The last pass has ended (expired or used up).', 'unstoppable-site-manager' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-bc-create">
					<input type="hidden" name="action" value="usmd_bulk_create">
					<?php wp_nonce_field( 'usmd_bulk_create' ); ?>
					<label><?php esc_html_e( 'Up to', 'unstoppable-site-manager' ); ?> <input type="number" name="max" min="1" max="<?php echo (int) self::MAX_SITES; ?>" value="50" class="small-text"> <?php esc_html_e( 'sites', 'unstoppable-site-manager' ); ?></label>
					<label><?php esc_html_e( 'for', 'unstoppable-site-manager' ); ?> <input type="number" name="days" min="1" max="<?php echo (int) self::MAX_DAYS; ?>" value="7" class="small-text"> <?php esc_html_e( 'days', 'unstoppable-site-manager' ); ?></label>
					<?php submit_button( __( 'Create bulk-connect connector', 'unstoppable-site-manager' ), 'primary', 'submit', false ); ?>
				</form>
				<p class="usmd-muted"><?php esc_html_e( 'Made from the current connector on WordPress.org when you download it. Creating a new pass ends the previous one.', 'unstoppable-site-manager' ); ?></p>
			<?php endif; ?>

			<?php if ( $p ) : ?>
				<?php $log = self::log( $p['id'], 50 ); ?>
				<?php if ( $log ) : ?>
					<details class="usmd-bc-log">
						<summary><?php esc_html_e( 'Recent bulk-connect results', 'unstoppable-site-manager' ); ?></summary>
						<ul>
							<?php foreach ( $log as $row ) : ?>
								<?php $d = $row->details ? json_decode( $row->details, true ) : array(); ?>
								<li class="is-<?php echo esc_attr( 'connected' === $row->action ? 'ok' : 'bad' ); ?>">
									<span class="usmd-muted"><?php echo esc_html( USMD_Admin::ago( $row->created_at ) ); ?></span>
									<?php echo esc_html( $row->object ); ?>
									<?php if ( 'connected' === $row->action ) : ?>
										<strong><?php esc_html_e( 'connected', 'unstoppable-site-manager' ); ?></strong>
									<?php else : ?>
										<strong><?php esc_html_e( 'not connected', 'unstoppable-site-manager' ); ?></strong>: <?php echo esc_html( isset( $d['error'] ) ? $d['error'] : '' ); ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			<?php endif; ?>

			<details class="usmd-bc-check"<?php echo is_array( $check ) ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Check against your MainWP site list', 'unstoppable-site-manager' ); ?></summary>
				<p class="usmd-muted"><?php esc_html_e( 'In MainWP, go to Settings > Tools > Export child sites and upload that CSV here to see which sites haven\'t connected yet. The file is read once and not kept.', 'unstoppable-site-manager' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="usmd_bulk_check">
					<?php wp_nonce_field( 'usmd_bulk_check' ); ?>
					<input type="file" name="csv" accept=".csv,text/csv" required>
					<?php submit_button( __( 'Check', 'unstoppable-site-manager' ), 'secondary', 'submit', false ); ?>
				</form>
				<?php if ( is_array( $check ) ) : ?>
					<p class="usmd-bc-result">
						<strong>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: connected count, 2: listed count. */
									__( '%1$d of %2$d sites in your list are connected here.', 'unstoppable-site-manager' ),
									$check['listed'] - $check['total'],
									$check['listed']
								)
							);
							?>
						</strong>
					</p>
					<?php if ( $check['missing'] ) : ?>
						<p><?php esc_html_e( 'Not connected yet:', 'unstoppable-site-manager' ); ?></p>
						<ul class="usmd-bc-missing">
							<?php foreach ( $check['missing'] as $m ) : ?>
								<li><a href="<?php echo esc_url( $m['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $m['name'] ? $m['name'] . ' — ' . $m['url'] : $m['url'] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>
			</details>
		</div>
		<?php
	}
}
