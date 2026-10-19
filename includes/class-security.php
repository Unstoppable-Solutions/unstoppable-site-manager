<?php
/**
 * Dashboard security: who may use Unstoppable Site Manager, where the signing
 * key is kept, and each client site's own connector security settings.
 *
 * Client sites keep their own security settings (allowed dashboard addresses,
 * permissions, alerts). From here they can only be made stricter; loosening
 * them is only possible in each site's own wp-admin.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Security {

	/** Capability for everything in Unstoppable Site Manager. */
	const CAP      = 'usmd_manage';
	const OPT      = 'usmd_allowed_users';
	const SLUG     = 'usmd-security';
	const MIN_CONN = '0.5.0';

	public static function hooks() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map' ), 10, 4 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
			add_action( 'admin_post_usmd_security_users', array( __CLASS__, 'handle_users' ) );
			add_action( 'admin_post_usmd_key_cleanup', array( __CLASS__, 'handle_key_cleanup' ) );
			add_action( 'admin_post_usmd_key_new', array( __CLASS__, 'handle_key_new' ) );
			add_action( 'wp_ajax_usmd_secure', array( __CLASS__, 'ajax_secure' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		}
	}

	/* ---------- who may use the dashboard ---------- */

	/** @return int[] Chosen users who still exist and are administrators. Empty = every administrator. */
	public static function allowed_ids() {
		if ( defined( 'USMD_ALLOWED_USERS' ) && '' !== trim( (string) USMD_ALLOWED_USERS ) ) {
			$ids = array();
			foreach ( preg_split( '/[\s,]+/', (string) USMD_ALLOWED_USERS, -1, PREG_SPLIT_NO_EMPTY ) as $login ) {
				$u = get_user_by( 'login', $login );
				if ( $u ) {
					$ids[] = (int) $u->ID;
				}
			}
		} else {
			$ids = array_map( 'intval', (array) get_option( self::OPT, array() ) );
		}
		$ok = array();
		foreach ( $ids as $id ) {
			$u = get_userdata( $id );
			if ( $u && ! empty( $u->allcaps['manage_options'] ) ) {
				$ok[] = $id;
			}
		}
		return $ok; // If none of the chosen users is left, every administrator gets in (never a lockout).
	}

	public static function users_from_config() {
		return defined( 'USMD_ALLOWED_USERS' ) && '' !== trim( (string) USMD_ALLOWED_USERS );
	}

	/**
	 * map_meta_cap: usmd_manage means manage_options, for the chosen administrators only.
	 * do_not_allow also stops multisite super admins who aren't chosen.
	 */
	public static function map( $caps, $cap, $user_id, $args ) {
		if ( self::CAP !== $cap ) {
			return $caps;
		}
		$allowed = self::allowed_ids();
		if ( $allowed && ! in_array( (int) $user_id, $allowed, true ) ) {
			return array( 'do_not_allow' );
		}
		return array( 'manage_options' );
	}

	public static function admins() {
		return get_users(
			array(
				'role__in' => array( 'administrator' ),
				'orderby'  => 'login',
				'number'   => 100,
			)
		);
	}

	public static function handle_users() {
		self::guard( 'usmd_security_users' );
		if ( self::users_from_config() ) {
			self::back( 'config' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
		$ids = isset( $_POST['users'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['users'] ) ) : array();
		if ( empty( $_POST['limit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$ids = array();
		} elseif ( ! in_array( get_current_user_id(), $ids, true ) ) {
			$ids[] = get_current_user_id(); // Never lock yourself out.
		}
		update_option( self::OPT, array_values( array_unique( array_filter( $ids ) ) ), false );
		self::back( 'users' );
	}

	public static function handle_key_cleanup() {
		self::guard( 'usmd_key_cleanup' );
		self::back( USMD_Keys::remove_db_secret() ? 'key_moved' : 'key_failed' );
	}

	/** Last resort when the key is lost: a new key. Every site must then be reconnected. */
	public static function handle_key_new() {
		self::guard( 'usmd_key_new' );
		if ( 'missing' !== USMD_Keys::storage() || defined( 'USMD_SECRET_KEY' ) ) {
			self::back( 'key_failed' );
		}
		USMD_Keys::regenerate();
		self::back( 'key_new' );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( $action );
	}

	private static function back( $msg ) {
		wp_safe_redirect( add_query_arg( 'sec', $msg, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/* ---------- client sites ---------- */

	/** The site's connector security settings, or null when its connector is too old to have them. */
	public static function for_site( $site ) {
		$st = ( $site && $site->status ) ? json_decode( $site->status, true ) : null;
		return ( is_array( $st ) && isset( $st['security'] ) && is_array( $st['security'] ) ) ? $st['security'] : null;
	}

	public static function perm_labels() {
		return array(
			'login'         => __( 'One-click login', 'unstoppable-site-manager' ),
			'update'        => __( 'Updates', 'unstoppable-site-manager' ),
			'toggle'        => __( 'Activate/deactivate plugins', 'unstoppable-site-manager' ),
			'install_wporg' => __( 'Install from WordPress.org', 'unstoppable-site-manager' ),
			'install_zip'   => __( 'Install from ZIP or web address', 'unstoppable-site-manager' ),
			'delete'        => __( 'Delete plugins', 'unstoppable-site-manager' ),
			'branding'      => __( 'Admin footer branding', 'unstoppable-site-manager' ),
		);
	}

	/** A correctly signed request was refused by this site in the last 14 days. */
	public static function recent_block( $sec ) {
		return $sec && ! empty( $sec['last_blocked']['time'] ) && ( time() - (int) $sec['last_blocked']['time'] ) < 14 * DAY_IN_SECONDS;
	}

	/** Lock a site to this dashboard's address, or switch permissions off. */
	public static function ajax_secure() {
		check_ajax_referer( 'usmd', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'unstoppable-site-manager' ) ), 403 );
		}
		$site = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 );
		if ( ! $site ) {
			wp_send_json_error( array( 'message' => __( 'Site not found.', 'unstoppable-site-manager' ) ), 404 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		if ( ! self::for_site( $site ) ) {
			/* translators: %s: version number. */
			wp_send_json_error( array( 'message' => sprintf( __( 'This site needs Unstoppable Site Connector %s or newer.', 'unstoppable-site-manager' ), self::MIN_CONN ) ) );
		}
		$params = array( 'op' => 'lock' === $op ? 'lock_ip' : 'restrict' );
		if ( 'restrict' === $op ) {
			$perm = isset( $_POST['perm'] ) ? sanitize_key( $_POST['perm'] ) : '';
			if ( ! isset( self::perm_labels()[ $perm ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Unknown permission.', 'unstoppable-site-manager' ) ) );
			}
			$params['perms'] = array( $perm );
		} elseif ( 'lock' !== $op ) {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'unstoppable-site-manager' ) ) );
		}

		$r = USMD_Client::call( $site, 'security', $params, 30, false );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		// Keep the stored status in step without a full refresh.
		$st = json_decode( $site->status, true );
		if ( is_array( $st ) && isset( $r['security'] ) ) {
			$st['security'] = $r['security'];
			USMD_Sites::store_status( $site->id, $st );
		}
		$site = USMD_Sites::get( $site->id );
		wp_send_json_success(
			array(
				'changed' => isset( $r['changed'] ) ? $r['changed'] : array(),
				'row'     => self::row_html( $site ),
				'panel'   => self::panel_html( $site ),
			)
		);
	}

	/* ---------- screens ---------- */

	public static function menu() {
		add_submenu_page( USMD_Admin::SLUG, __( 'Security', 'unstoppable-site-manager' ), __( 'Security', 'unstoppable-site-manager' ), self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'unstoppable-site-manager' ) && false === strpos( (string) $screen->id, 'usmd-' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$state = USMD_Keys::storage();
		if ( 'missing' === $state || 'mismatch' === $state ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Signing key problem.', 'unstoppable-site-manager' ) . '</strong> ' . esc_html( self::key_problem( $state ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Security', 'unstoppable-site-manager' ) . '</a></p></div>';
		}
		$blocked = 0;
		foreach ( USMD_Sites::all() as $s ) {
			if ( self::recent_block( self::for_site( $s ) ) ) {
				++$blocked;
			}
		}
		if ( $blocked && self::SLUG !== ( isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Blocked requests:', 'unstoppable-site-manager' ) . '</strong> ' . esc_html(
				sprintf(
					/* translators: %d: number of sites. */
					_n( '%d client site refused a request signed with this dashboard\'s key from an address it doesn\'t allow.', '%d client sites refused requests signed with this dashboard\'s key from addresses they don\'t allow.', $blocked, 'unstoppable-site-manager' ),
					$blocked
				)
			) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'See which', 'unstoppable-site-manager' ) . '</a></p></div>';
		}
	}

	private static function key_problem( $state ) {
		return 'mismatch' === $state
			? __( 'USMD_SECRET_KEY in wp-config.php is not this dashboard\'s key, so it is being ignored. Copy the line from the Security screen again.', 'unstoppable-site-manager' )
			: __( 'This dashboard\'s signing key is missing, so it cannot reach your sites. If you moved it to wp-config.php, put the USMD_SECRET_KEY line back exactly as it was.', 'unstoppable-site-manager' );
	}

	/** @return array [ state good|warn|bad, label, detail ] */
	private static function checks() {
		$out = array();

		$key = USMD_Keys::storage();
		$map = array(
			'config'   => array( 'good', __( 'Signing key is in wp-config.php, not the database', 'unstoppable-site-manager' ), __( 'A leaked database or backup can\'t be used to control your sites.', 'unstoppable-site-manager' ) ),
			'both'     => array( 'warn', __( 'Signing key is in wp-config.php and still in the database', 'unstoppable-site-manager' ), __( 'wp-config.php has the right key. Remove the copy from the database below to finish.', 'unstoppable-site-manager' ) ),
			'database' => array( 'warn', __( 'Signing key is stored in the database', 'unstoppable-site-manager' ), __( 'Anyone who gets a copy of the database (a leaked backup, an SQL injection in another plugin) could control your sites. Move it to wp-config.php below.', 'unstoppable-site-manager' ) ),
			'mismatch' => array( 'bad', __( 'wp-config.php has the wrong signing key', 'unstoppable-site-manager' ), self::key_problem( 'mismatch' ) ),
			'missing'  => array( 'bad', __( 'Signing key is missing', 'unstoppable-site-manager' ), self::key_problem( 'missing' ) ),
		);
		$out['key'] = $map[ $key ];

		$admins  = count( self::admins() );
		$allowed = self::allowed_ids();
		if ( $allowed ) {
			/* translators: %d: number of people. */
			$out['users'] = array( 'good', sprintf( _n( 'Only %d chosen administrator can use the dashboard', 'Only %d chosen administrators can use the dashboard', count( $allowed ), 'unstoppable-site-manager' ), count( $allowed ) ), __( 'Other administrator accounts on this site can\'t open it or send anything to your client sites.', 'unstoppable-site-manager' ) );
		} else {
			$out['users'] = array(
				$admins > 1 ? 'warn' : 'good',
				/* translators: %d: number of administrators. */
				sprintf( _n( 'Every administrator can use the dashboard (%d)', 'Every administrator can use the dashboard (%d)', $admins, 'unstoppable-site-manager' ), $admins ),
				$admins > 1 ? __( 'Choose who may use it below, so a second administrator account can\'t reach your client sites.', 'unstoppable-site-manager' ) : __( 'You are the only administrator. If you add more, choose who may use the dashboard below.', 'unstoppable-site-manager' ),
			);
		}

		$https        = 'https' === wp_parse_url( admin_url(), PHP_URL_SCHEME );
		$out['https'] = $https
			? array( 'good', __( 'The dashboard uses HTTPS', 'unstoppable-site-manager' ), '' )
			: array( 'bad', __( 'The dashboard does not use HTTPS', 'unstoppable-site-manager' ), __( 'Your sign-in to this dashboard can be intercepted. Switch the site to https://.', 'unstoppable-site-manager' ) );

		$out['edit'] = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT )
			? array( 'good', __( 'Plugin and theme file editing is switched off', 'unstoppable-site-manager' ), '' )
			: array( 'warn', __( 'Plugin and theme file editing is switched on', 'unstoppable-site-manager' ), __( 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php so a stolen admin login can\'t edit code (or read wp-config.php) from wp-admin.', 'unstoppable-site-manager' ) );

		$two_fa       = class_exists( 'Two_Factor_Core' ) || defined( 'WP_2FA_VERSION' ) || class_exists( 'wordfence' ) || defined( 'ITSEC_CORE_FILE' ) || defined( 'WPMUDEV_DEFENDER_VERSION' ) || defined( 'AIOWPSEC_VERSION' );
		$out['2fa']   = $two_fa
			? array( 'good', __( 'A two-factor sign-in plugin is active', 'unstoppable-site-manager' ), __( 'Make sure it is turned on for every administrator.', 'unstoppable-site-manager' ) )
			: array( 'warn', __( 'No two-factor sign-in plugin found', 'unstoppable-site-manager' ), __( 'Install one (for example the free Two Factor plugin) and turn it on for every administrator. A stolen password is the most common way in.', 'unstoppable-site-manager' ) );

		$sites  = USMD_Sites::all();
		$locked = 0;
		$old    = 0;
		foreach ( $sites as $s ) {
			$sec = self::for_site( $s );
			if ( ! $sec ) {
				++$old;
			} elseif ( ! empty( $sec['ip_lock'] ) ) {
				++$locked;
			}
		}
		if ( $sites ) {
			$out['lock'] = array(
				count( $sites ) === $locked ? 'good' : 'warn',
				/* translators: 1: locked sites, 2: all sites. */
				sprintf( __( '%1$d of %2$d client sites only accept this dashboard\'s address', 'unstoppable-site-manager' ), $locked, count( $sites ) ),
				count( $sites ) === $locked ? __( 'A stolen key would be useless from anywhere else.', 'unstoppable-site-manager' ) : __( 'Lock the rest below, so a stolen key would be useless from anywhere else.', 'unstoppable-site-manager' )
					. ( $old ? ' ' . sprintf( /* translators: 1: count, 2: version. */ _n( '%1$d site needs Unstoppable Site Connector %2$s first.', '%1$d sites need Unstoppable Site Connector %2$s first.', $old, 'unstoppable-site-manager' ), $old, self::MIN_CONN ) : '' ),
			);
		}
		return $out;
	}

	public static function page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$msg     = isset( $_GET['sec'] ) ? sanitize_key( $_GET['sec'] ) : '';
		$checks  = self::checks();
		$state   = USMD_Keys::storage();
		$allowed = self::allowed_ids();
		$sites   = USMD_Sites::all();
		$labels  = array(
			'good' => __( 'Good', 'unstoppable-site-manager' ),
			'warn' => __( 'Improve', 'unstoppable-site-manager' ),
			'bad'  => __( 'Fix now', 'unstoppable-site-manager' ),
		);
		$msgs    = array(
			'users'      => array( 'success', __( 'Saved who can use the dashboard.', 'unstoppable-site-manager' ) ),
			'key_moved'  => array( 'success', __( 'Done: the signing key is now only in wp-config.php.', 'unstoppable-site-manager' ) ),
			'key_failed' => array( 'error', __( 'The key was not removed from the database, because wp-config.php doesn\'t hold the same key yet.', 'unstoppable-site-manager' ) ),
			'key_new'    => array( 'warning', __( 'A new signing key was made. Reconnect each site: generate a connection key on it and add it again here.', 'unstoppable-site-manager' ) ),
			'config'     => array( 'warning', __( 'Who can use the dashboard is set in wp-config.php (USMD_ALLOWED_USERS).', 'unstoppable-site-manager' ) ),
		);
		?>
		<div class="wrap usmd-wrap usmd-sec-page">
			<h1><?php esc_html_e( 'Security', 'unstoppable-site-manager' ); ?></h1>
			<?php if ( isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $msgs[ $msg ][1] ); ?></p></div>
			<?php endif; ?>
			<p class="usmd-sec-intro"><?php esc_html_e( 'This dashboard can update and sign in to every connected site, so it deserves the most protection. No client passwords are stored here: sites trust this dashboard\'s signing key, every request is signed and can only be used once, and each client site keeps its own security settings that can only be loosened on that site.', 'unstoppable-site-manager' ); ?></p>

			<h2><?php esc_html_e( 'Checklist', 'unstoppable-site-manager' ); ?></h2>
			<ul class="usmd-sec-checks">
				<?php foreach ( $checks as $key => $c ) : ?>
					<li class="is-<?php echo esc_attr( $c[0] ); ?>">
						<span class="usmd-sec-state"><?php echo esc_html( $labels[ $c[0] ] ); ?></span>
						<div><strong><?php echo esc_html( $c[1] ); ?></strong><?php if ( $c[2] ) : ?><span><?php echo esc_html( $c[2] ); ?></span><?php endif; ?></div>
					</li>
				<?php endforeach; ?>
			</ul>

			<h2 id="usmd-sec-key"><?php esc_html_e( 'Signing key', 'unstoppable-site-manager' ); ?></h2>
			<?php if ( 'database' === $state ) : ?>
				<ol class="usmd-sec-steps">
					<li><?php esc_html_e( 'Copy this line. Treat it like a password: anyone with it can control your sites.', 'unstoppable-site-manager' ); ?>
						<details class="usmd-sec-reveal">
							<summary><?php esc_html_e( 'Show the line', 'unstoppable-site-manager' ); ?></summary>
							<textarea readonly rows="2" class="large-text code usmd-select-all"><?php echo esc_textarea( USMD_Keys::config_line() ); ?></textarea>
						</details>
					</li>
					<li><?php echo wp_kses( __( 'Paste it into <code>wp-config.php</code> on this site, above the line <code>/* That\'s all, stop editing! */</code>, using your host\'s file manager or FTP.', 'unstoppable-site-manager' ), array( 'code' => array() ) ); ?></li>
					<li><?php esc_html_e( 'Reload this screen. When it confirms the key is there, remove the copy from the database.', 'unstoppable-site-manager' ); ?></li>
				</ol>
				<p class="usmd-muted"><?php esc_html_e( 'Nothing changes for your client sites: it is the same key, kept somewhere safer. Keep a copy of the line with your other passwords; if wp-config.php is ever replaced without it, the dashboard can\'t reach your sites until it is put back.', 'unstoppable-site-manager' ); ?></p>
			<?php elseif ( 'both' === $state ) : ?>
				<p><?php esc_html_e( 'wp-config.php holds this dashboard\'s key. Last step: remove the copy from the database.', 'unstoppable-site-manager' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="usmd_key_cleanup">
					<?php wp_nonce_field( 'usmd_key_cleanup' ); ?>
					<?php submit_button( __( 'Remove the key from the database', 'unstoppable-site-manager' ), 'primary', 'submit', false ); ?>
				</form>
			<?php elseif ( 'config' === $state ) : ?>
				<p class="usmd-security-ok"><?php esc_html_e( 'The signing key is kept in wp-config.php only. Keep a private copy of that line; if it is lost, the dashboard can\'t reach your sites.', 'unstoppable-site-manager' ); ?></p>
			<?php else : ?>
				<p class="usmd-sec-bad"><?php echo esc_html( self::key_problem( $state ) ); ?></p>
				<?php if ( 'missing' === $state && ! defined( 'USMD_SECRET_KEY' ) ) : ?>
					<details class="usmd-sec-reveal">
						<summary><?php esc_html_e( 'The key is lost for good', 'unstoppable-site-manager' ); ?></summary>
						<p><?php esc_html_e( 'Make a new key. None of your sites will accept this dashboard until you reconnect each one with a fresh connection key from its Tools > Unstoppable Site Connector screen.', 'unstoppable-site-manager' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="usmd_key_new">
							<?php wp_nonce_field( 'usmd_key_new' ); ?>
							<?php submit_button( __( 'Make a new signing key', 'unstoppable-site-manager' ), 'delete', 'submit', false ); ?>
						</form>
					</details>
				<?php endif; ?>
			<?php endif; ?>

			<h2 id="usmd-sec-users"><?php esc_html_e( 'Who can use the dashboard', 'unstoppable-site-manager' ); ?></h2>
			<?php if ( self::users_from_config() ) : ?>
				<p><?php esc_html_e( 'Set in wp-config.php with USMD_ALLOWED_USERS:', 'unstoppable-site-manager' ); ?> <code><?php echo esc_html( (string) USMD_ALLOWED_USERS ); ?></code></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-sec-users">
					<input type="hidden" name="action" value="usmd_security_users">
					<?php wp_nonce_field( 'usmd_security_users' ); ?>
					<fieldset>
						<label><input type="radio" name="limit" value="0" <?php checked( ! $allowed ); ?>> <?php esc_html_e( 'Every administrator of this site', 'unstoppable-site-manager' ); ?></label>
						<label><input type="radio" name="limit" value="1" <?php checked( (bool) $allowed ); ?>> <?php esc_html_e( 'Only these administrators:', 'unstoppable-site-manager' ); ?></label>
						<div class="usmd-sec-userlist">
							<?php foreach ( self::admins() as $u ) : ?>
								<label><input type="checkbox" name="users[]" value="<?php echo esc_attr( $u->ID ); ?>" <?php checked( in_array( (int) $u->ID, $allowed, true ) || ( ! $allowed && get_current_user_id() === (int) $u->ID ) ); ?>> <?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?><?php echo get_current_user_id() === (int) $u->ID ? ' — ' . esc_html__( 'you', 'unstoppable-site-manager' ) : ''; ?></label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<p class="usmd-muted"><?php esc_html_e( 'You are always kept on the list, so you can\'t lock yourself out. Administrators who aren\'t chosen don\'t see the dashboard menu and can\'t send anything to client sites.', 'unstoppable-site-manager' ); ?></p>
					<?php submit_button( __( 'Save', 'unstoppable-site-manager' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<h2 id="usmd-sec-sites"><?php esc_html_e( 'Client sites', 'unstoppable-site-manager' ); ?></h2>
			<p><?php esc_html_e( 'Each site\'s connector has its own security settings, in that site\'s wp-admin under Tools > Unstoppable Site Connector > Security. From here you can only make them stricter.', 'unstoppable-site-manager' ); ?></p>
			<p class="usmd-muted"><?php esc_html_e( 'The address lock is the main protection: with it, a stolen key is useless from anywhere but this server. One-click login and installing from a ZIP or web address each give this dashboard full control of a site, so switch them off on sites where you don\'t need them.', 'unstoppable-site-manager' ); ?></p>
			<?php if ( $sites ) : ?>
				<p class="usmd-sec-bulk">
					<button type="button" class="button button-primary" id="usmd-sec-lock-all"><?php esc_html_e( 'Lock all sites to this dashboard\'s address', 'unstoppable-site-manager' ); ?></button>
					<span class="usmd-muted"><?php esc_html_e( 'Each site then only accepts requests from the address this dashboard\'s requests come from. If you move the dashboard to a new server, add its new address on each site.', 'unstoppable-site-manager' ); ?></span>
				</p>
				<p class="usmd-sec-progress" id="usmd-sec-progress" role="status" aria-live="polite" hidden></p>
				<table class="widefat striped usmd-sec-table">
					<thead><tr>
						<th><?php esc_html_e( 'Site', 'unstoppable-site-manager' ); ?></th>
						<th><?php esc_html_e( 'Address lock', 'unstoppable-site-manager' ); ?></th>
						<th><?php esc_html_e( 'Switched off', 'unstoppable-site-manager' ); ?></th>
						<th><?php esc_html_e( 'Alerts', 'unstoppable-site-manager' ); ?></th>
						<th><?php esc_html_e( 'Blocked requests', 'unstoppable-site-manager' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $sites as $s ) : ?>
							<?php echo self::row_html( $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in row_html(). ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="usmd-muted"><?php esc_html_e( 'No sites yet.', 'unstoppable-site-manager' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'If you think the dashboard was compromised', 'unstoppable-site-manager' ); ?></h2>
			<ol class="usmd-sec-steps">
				<li><?php esc_html_e( 'On each client site, go to Tools > Unstoppable Site Connector and click Disconnect. The old key stops working there at once.', 'unstoppable-site-manager' ); ?></li>
				<li><?php esc_html_e( 'Clean and secure the dashboard site (passwords, two-factor, plugins), then make a new signing key: delete the usmd_keys option (and any USMD_SECRET_KEY line in wp-config.php), then use "Make a new signing key" on this screen.', 'unstoppable-site-manager' ); ?></li>
				<li><?php esc_html_e( 'Reconnect each site with a fresh connection key.', 'unstoppable-site-manager' ); ?></li>
			</ol>
		</div>
		<?php
	}

	public static function row_html( $site ) {
		$sec  = self::for_site( $site );
		$name = $site->name ? $site->name : $site->url;
		$link = admin_url( 'admin.php?page=' . USMD_Admin::SLUG . '&site=' . (int) $site->id );
		ob_start();
		?>
		<tr data-site="<?php echo esc_attr( $site->id ); ?>" data-locked="<?php echo ( $sec && ! empty( $sec['ip_lock'] ) ) ? '1' : '0'; ?>" data-ready="<?php echo $sec ? '1' : '0'; ?>">
			<td><a href="<?php echo esc_url( $link . '#usmd-conn-security' ); ?>"><?php echo esc_html( $name ); ?></a></td>
			<?php if ( ! $sec ) : ?>
				<td colspan="4" class="usmd-muted">
					<?php
					/* translators: %s: version number. */
					echo esc_html( sprintf( __( 'Needs Unstoppable Site Connector %s or newer (then refresh the site).', 'unstoppable-site-manager' ), self::MIN_CONN ) );
					?>
				</td>
			<?php else : ?>
				<td>
					<?php if ( ! empty( $sec['ip_lock'] ) ) : ?>
						<span class="usmd-sec-yes">
							<?php
							echo esc_html(
								! empty( $sec['ip_config'] )
									? __( 'Locked (wp-config.php)', 'unstoppable-site-manager' )
									/* translators: %d: number of addresses. */
									: sprintf( _n( 'Locked (%d address)', 'Locked (%d addresses)', (int) $sec['ip_count'], 'unstoppable-site-manager' ), (int) $sec['ip_count'] )
							);
							?>
						</span>
					<?php else : ?>
						<span class="usmd-sec-no"><?php esc_html_e( 'Any address', 'unstoppable-site-manager' ); ?></span>
						<button type="button" class="button button-small usmd-sec-lock" data-site="<?php echo esc_attr( $site->id ); ?>"><?php esc_html_e( 'Lock', 'unstoppable-site-manager' ); ?></button>
					<?php endif; ?>
				</td>
				<td>
					<?php
					$off = array();
					foreach ( self::perm_labels() as $p => $label ) {
						if ( isset( $sec['perms'][ $p ] ) && ! $sec['perms'][ $p ] ) {
							$off[] = $label;
						}
					}
					echo $off ? esc_html( implode( ', ', $off ) ) : '<span class="usmd-muted">' . esc_html__( 'Nothing', 'unstoppable-site-manager' ) . '</span>';
					?>
				</td>
				<td><?php echo ! empty( $sec['alerts'] ) ? esc_html( sprintf( /* translators: %d: number of alert types. */ _n( '%d type on', '%d types on', count( $sec['alerts'] ), 'unstoppable-site-manager' ), count( $sec['alerts'] ) ) ) : '<span class="usmd-sec-no">' . esc_html__( 'Off', 'unstoppable-site-manager' ) . '</span>'; ?></td>
				<td>
					<?php if ( self::recent_block( $sec ) ) : ?>
						<span class="usmd-sec-bad">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: IP address, 2: time ago. */
									__( 'From %1$s, %2$s', 'unstoppable-site-manager' ),
									$sec['last_blocked']['ip'],
									USMD_Admin::ago( gmdate( 'Y-m-d H:i:s', (int) $sec['last_blocked']['time'] ) )
								)
							);
							?>
						</span>
					<?php else : ?>
						<span class="usmd-muted"><?php esc_html_e( 'None', 'unstoppable-site-manager' ); ?></span>
					<?php endif; ?>
				</td>
			<?php endif; ?>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** The connector security panel on a site's page. */
	public static function panel_html( $site ) {
		$sec = self::for_site( $site );
		ob_start();
		?>
		<div class="usmd-panel usmd-conn-security" id="usmd-conn-security" data-site="<?php echo esc_attr( $site->id ); ?>">
			<h2><?php esc_html_e( 'Security: what this dashboard may do here', 'unstoppable-site-manager' ); ?></h2>
			<?php if ( ! $sec ) : ?>
				<p class="usmd-muted">
					<?php
					/* translators: %s: version number. */
					echo esc_html( sprintf( __( 'Update this site to Unstoppable Site Connector %s or newer to see and control its security settings here.', 'unstoppable-site-manager' ), self::MIN_CONN ) );
					?>
				</p>
			<?php else : ?>
				<?php if ( self::recent_block( $sec ) ) : ?>
					<p class="usmd-sec-bad">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: IP address, 2: time ago, 3: count. */
								_n( 'This site refused a request signed with this dashboard\'s key from %1$s (%2$s, %3$d time). If that isn\'t this dashboard\'s server, someone else may have the key: see Security > If you think the dashboard was compromised.', 'This site refused a request signed with this dashboard\'s key from %1$s (%2$s, %3$d times). If that isn\'t this dashboard\'s server, someone else may have the key: see Security > If you think the dashboard was compromised.', (int) $sec['last_blocked']['count'], 'unstoppable-site-manager' ),
								$sec['last_blocked']['ip'],
								USMD_Admin::ago( gmdate( 'Y-m-d H:i:s', (int) $sec['last_blocked']['time'] ) ),
								(int) $sec['last_blocked']['count']
							)
						);
						?>
					</p>
				<?php endif; ?>
				<ul class="usmd-sec-perms">
					<li>
						<span><?php esc_html_e( 'Accepts requests from', 'unstoppable-site-manager' ); ?></span>
						<?php if ( ! empty( $sec['ip_lock'] ) ) : ?>
							<strong class="usmd-sec-yes"><?php echo esc_html( sprintf( /* translators: %d: count. */ _n( 'Only its %d allowed address', 'Only its %d allowed addresses', (int) $sec['ip_count'], 'unstoppable-site-manager' ), (int) $sec['ip_count'] ) ); ?></strong>
						<?php else : ?>
							<strong class="usmd-sec-no"><?php esc_html_e( 'Any address', 'unstoppable-site-manager' ); ?></strong>
							<button type="button" class="button button-small usmd-sec-lock" data-site="<?php echo esc_attr( $site->id ); ?>"><?php esc_html_e( 'Lock to this dashboard\'s address', 'unstoppable-site-manager' ); ?></button>
						<?php endif; ?>
					</li>
					<?php foreach ( self::perm_labels() as $p => $label ) : ?>
						<?php
						if ( 'branding' === $p && ! isset( $sec['perms'][ $p ] ) ) {
							continue; // Connectors before 0.7.0 have no branding.
						}
						$on = ! isset( $sec['perms'][ $p ] ) || $sec['perms'][ $p ];
						?>
						<li>
							<span><?php echo esc_html( $label ); ?></span>
							<?php if ( $on ) : ?>
								<strong><?php esc_html_e( 'Allowed', 'unstoppable-site-manager' ); ?></strong>
								<button type="button" class="button-link usmd-sec-off" data-site="<?php echo esc_attr( $site->id ); ?>" data-perm="<?php echo esc_attr( $p ); ?>" data-label="<?php echo esc_attr( $label ); ?>"><?php esc_html_e( 'Switch off', 'unstoppable-site-manager' ); ?></button>
							<?php else : ?>
								<strong class="usmd-sec-off-state"><?php esc_html_e( 'Switched off', 'unstoppable-site-manager' ); ?></strong>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="usmd-muted"><?php esc_html_e( 'Switching something back on, unlocking the address, and the site\'s alert emails can only be changed in the site\'s own wp-admin, under Tools > Unstoppable Site Connector > Security.', 'unstoppable-site-manager' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
