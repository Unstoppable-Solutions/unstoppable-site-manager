<?php
/**
 * Admin screens: site grid, add site, site detail, settings.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Admin {

	const SLUG = 'unstoppable-site-manager';

	const SSL_WARN_DAYS = 14;

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_menu_branding' ) );
		add_action( 'admin_post_usmd_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_usmd_settings', array( __CLASS__, 'handle_settings' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Unstoppable Site Manager', 'unstoppable-site-manager' ), __( 'Unstoppable Site Manager', 'unstoppable-site-manager' ), USMD_Security::CAP, self::SLUG, array( __CLASS__, 'route' ), 'dashicons-networking', 3 );
		add_submenu_page( self::SLUG, __( 'Sites', 'unstoppable-site-manager' ), __( 'Sites', 'unstoppable-site-manager' ), USMD_Security::CAP, self::SLUG, array( __CLASS__, 'route' ) );
		add_submenu_page( self::SLUG, __( 'Add Site', 'unstoppable-site-manager' ), __( 'Add Site', 'unstoppable-site-manager' ), USMD_Security::CAP, 'usmd-add', array( __CLASS__, 'page_add' ) );
		add_submenu_page( self::SLUG, __( 'Client reports', 'unstoppable-site-manager' ), __( 'Reports', 'unstoppable-site-manager' ), USMD_Security::CAP, 'usmd-reports', array( __CLASS__, 'page_reports' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'unstoppable-site-manager' ), __( 'Settings', 'unstoppable-site-manager' ), USMD_Security::CAP, 'usmd-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * Gold menu item. Loaded on every admin screen because the menu appears on
	 * every admin screen; it is a handful of colour rules and nothing else.
	 */
	public static function enqueue_menu_branding() {
		wp_enqueue_style( 'usmd-admin-menu', USMD_URL . 'assets/css/admin-menu.css', array(), USMD_VERSION );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'usmd-admin', USMD_URL . 'assets/admin.css', array(), USMD_VERSION );
		if ( false !== strpos( $hook, 'usmd-settings' ) && current_user_can( 'upload_files' ) ) {
			wp_enqueue_media(); // Media Library picker for the logo.
		}
		if ( USMD_Report::is_report_screen() ) {
			wp_add_inline_style( 'usmd-admin', USMD_Report::accent_css() ); // Built from a sanitised hex colour in Settings.
		}
		wp_enqueue_script( 'usmd-admin', USMD_URL . 'assets/admin.js', array(), USMD_VERSION, true );
		wp_localize_script(
			'usmd-admin',
			'USMD',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'usmd' ),
				'grid'  => admin_url( 'admin.php?page=unstoppable-site-manager' ),
				'i18n'  => array(
					'signingIn'   => __( 'Signing in…', 'unstoppable-site-manager' ),
					/* translators: %s: site name. */
					'signingInTo' => __( 'Signing in to %s…', 'unstoppable-site-manager' ),
					/* translators: 1: site name, 2: username. */
					'signedIn'    => __( 'Opened %1$s signed in as %2$s.', 'unstoppable-site-manager' ),
					/* translators: %s: error message. */
					'loginFailed' => __( 'Could not sign in automatically: %s Opened the normal login page instead.', 'unstoppable-site-manager' ),
					'popup'       => __( 'Your browser blocked the new tab. Allow pop-ups for this dashboard, or Ctrl/Cmd-click WP Admin to open the normal login page.', 'unstoppable-site-manager' ),
					'logoTitle'   => __( 'Choose your logo', 'unstoppable-site-manager' ),
					/* translators: %s: name of the permission. */
					'secOffAsk'   => __( 'Switch off "%s" for this site? Only an administrator of the site can switch it back on, in its own wp-admin.', 'unstoppable-site-manager' ),
					'secLockAsk'  => __( 'Lock this site to the address this dashboard\'s requests come from? Only an administrator of the site can remove the lock, in its own wp-admin.', 'unstoppable-site-manager' ),
					/* translators: %s: number of sites. */
					'secLockAll'  => __( 'Lock %s sites to the address this dashboard\'s requests come from? Only an administrator of each site can remove its lock, in its own wp-admin. If the dashboard later moves to a new server, you will need to add its new address on each site.', 'unstoppable-site-manager' ),
					/* translators: 1: position, 2: total, 3: site name. */
					'secLocking'  => __( 'Locking %1$s of %2$s: %3$s…', 'unstoppable-site-manager' ),
					/* translators: 1: sites locked, 2: sites failed. */
					'secDone'     => __( 'Done: %1$s locked, %2$s failed.', 'unstoppable-site-manager' ),
					'secNone'     => __( 'Every site that can be locked already is.', 'unstoppable-site-manager' ),
					'workDelete'  => __( 'Delete this task from the report?', 'unstoppable-site-manager' ),
					'logoButton'  => __( 'Use this logo', 'unstoppable-site-manager' ),
					'logoOk'      => __( 'Logo found. Save to use it on reports.', 'unstoppable-site-manager' ),
					'logoBad'     => __( 'This address doesn\'t load as an image, so reports will show your agency name instead. Use "Choose from Media Library", or the address of the image file itself (ending in .png, .jpg, .webp or .svg), not its attachment page.', 'unstoppable-site-manager' ),
					'logoInsecure' => __( 'This logo address starts with http:// while your dashboard uses https://, so browsers may block it. Use the https:// address.', 'unstoppable-site-manager' ),
				),
			)
		);
	}

	public static function settings() {
		$s = get_option( 'usmd_settings' );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'agency_name'  => get_bloginfo( 'name' ),
				'logo_url'     => '',
				'report_brand' => 'logo',
				'report_intro'   => 'Here is a summary of the care and maintenance performed on your website this month.',
				'report_color'   => '#1e5aa8',
				'report_contact' => '',
				'vuln_scan'      => 0,
				'report_day'        => 1,
				'report_subject'    => '{site}: Website Care Report for {month}',
				'report_from_name'  => '',
				'report_from_email' => '',
				'report_reply_to'   => '',
				'report_bcc_me'     => 1,
				'agency_email'      => '',
				'thumb_provider' => 'mshots',
				'thumb_template' => '',
			)
		);
	}

	/**
	 * Your email: test copies, blind copies, and the default From and Reply-To.
	 * Falls back to this WordPress site's admin email when not set.
	 */
	public static function agency_email() {
		$e = (string) self::settings()['agency_email'];
		return is_email( $e ) ? $e : (string) get_option( 'admin_email' );
	}

	public static function site_url( $id, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'unstoppable-site-manager', 'site' => (int) $id ), $args ), admin_url( 'admin.php' ) );
	}

	public static function ago( $gmt ) {
		return $gmt ? human_time_diff( strtotime( $gmt . ' UTC' ) ) . ' ago' : 'never';
	}

	/* ---------- routing ---------- */

	public static function route() {
		$id = isset( $_GET['site'] ) ? absint( $_GET['site'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id ) {
			self::page_grid();
			return;
		}
		$site = USMD_Sites::get( $id );
		if ( ! $site ) {
			echo '<div class="wrap"><h1>Site not found</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=unstoppable-site-manager' ) ) . '">Back to sites</a></p></div>';
			return;
		}
		if ( isset( $_GET['view'] ) && 'report' === $_GET['view'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			USMD_Report::render( $site, isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		self::page_site( $site );
	}

	/* ---------- grid ---------- */

	public static function card_html( $site ) {
		if ( ! $site ) {
			return '';
		}
		$host    = wp_parse_url( $site->url, PHP_URL_HOST );
		$ssl     = null === $site->ssl_days ? null : (int) $site->ssl_days;
		$https   = 0 === strpos( $site->url, 'https://' );
		$stale   = ! $site->last_seen || ( time() - strtotime( $site->last_seen . ' UTC' ) ) > DAY_IN_SECONDS;
		$problem = $site->last_error || $stale;
		$ssl_bad = ! $https || ( null !== $ssl && $ssl < self::SSL_WARN_DAYS );

		$thumb     = USMD_Thumbs::url( $site );
		$thumb_due = USMD_Thumbs::browser_mode() && USMD_Thumbs::is_due( $site );
		$initial   = strtoupper( substr( (string) preg_replace( '/^www\./', '', (string) $host ), 0, 1 ) );
		$link      = self::site_url( $site->id );
		$vulns     = USMD_Vulns::enabled() ? USMD_Vulns::count( $site->id ) : 0;
		$conn_ver  = USMD_Sites::connector_version( $site );
		$conn_old  = USMD_Sites::connector_state( $site );

		ob_start();
		?>
		<div class="usmd-card<?php echo $problem ? ' is-problem' : ''; ?><?php echo ! empty( $site->starred ) ? ' is-starred' : ''; ?>"
			data-id="<?php echo (int) $site->id; ?>"
			data-starred="<?php echo ! empty( $site->starred ) ? 1 : 0; ?>"
			data-has-thumb="<?php echo $thumb ? 1 : 0; ?>"
			data-name="<?php echo esc_attr( strtolower( $site->name ) ); ?>"
			data-updates="<?php echo (int) $site->updates; ?>"
			data-problem="<?php echo $problem ? 1 : 0; ?>"
			data-ssl="<?php echo $ssl_bad ? 1 : 0; ?>"
			data-plugin-updates="<?php echo (int) $site->plugin_updates; ?>"
			data-theme-updates="<?php echo (int) $site->theme_updates; ?>"
			data-core-update="<?php echo $site->core_update ? 1 : 0; ?>"
			data-connector="<?php echo esc_attr( $conn_old ); ?>"
			data-vulns="<?php echo (int) $vulns; ?>"
			data-search="<?php echo esc_attr( strtolower( $site->name . ' ' . $site->url ) ); ?>"
			<?php if ( USMD_Thumbs::browser_mode() ) : ?>
				data-thumb-src="<?php echo esc_url( USMD_Thumbs::mshots_url( $site->url ) ); ?>"
				data-thumb-due="<?php echo $thumb_due ? 1 : 0; ?>"
			<?php endif; ?>>
			<button type="button" class="usmd-star" data-site="<?php echo (int) $site->id; ?>"
				aria-pressed="<?php echo ! empty( $site->starred ) ? 'true' : 'false'; ?>"
				title="<?php echo ! empty( $site->starred ) ? esc_attr__( 'Unstar: remove from the top', 'unstoppable-site-manager' ) : esc_attr__( 'Star: pin to the top', 'unstoppable-site-manager' ); ?>">
				<span aria-hidden="true">★</span><span class="screen-reader-text"><?php esc_html_e( 'Star this site', 'unstoppable-site-manager' ); ?></span>
			</button>
			<?php if ( 'off' !== USMD_Thumbs::provider() ) : ?>
				<button type="button" class="usmd-retake<?php echo $thumb ? '' : ' is-missing'; ?>" data-site="<?php echo (int) $site->id; ?>" title="<?php esc_attr_e( 'Retake screenshot', 'unstoppable-site-manager' ); ?>">
					<span aria-hidden="true">↻</span><span class="screen-reader-text"><?php esc_html_e( 'Retake screenshot', 'unstoppable-site-manager' ); ?></span>
				</button>
			<?php endif; ?>
			<a class="usmd-card-thumb" href="<?php echo esc_url( $link ); ?>" tabindex="-1" aria-hidden="true"
				<?php echo $site->thumb_error ? 'title="' . esc_attr( 'Screenshot: ' . $site->thumb_error ) . '"' : ''; ?>>
				<?php if ( $thumb ) : ?>
					<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy" width="800" height="600">
				<?php else : ?>
					<span class="usmd-thumb-empty"><?php echo esc_html( $initial ); ?></span>
				<?php endif; ?>
				<span class="usmd-thumb-status" hidden></span>
			</a>
			<div class="usmd-card-body">
				<div class="usmd-card-head">
					<a class="usmd-card-name" href="<?php echo esc_url( $link ); ?>" title="<?php echo esc_attr( $site->name ); ?>"><?php echo esc_html( $site->name ); ?></a>
					<?php if ( ! $site->last_seen ) : ?>
						<span class="usmd-pill usmd-pill-bad">No data</span>
					<?php elseif ( $site->last_error ) : ?>
						<span class="usmd-pill usmd-pill-bad">Offline</span>
					<?php elseif ( $site->updates ) : ?>
						<span class="usmd-pill usmd-pill-warn" title="<?php echo esc_attr( sprintf( '%d plugin, %d theme%s', $site->plugin_updates, $site->theme_updates, $site->core_update ? ', WordPress core' : '' ) ); ?>"><?php echo (int) $site->updates; ?></span>
					<?php else : ?>
						<span class="usmd-pill usmd-pill-ok" title="Up to date">✓</span>
					<?php endif; ?>
				</div>
				<a class="usmd-card-url" href="<?php echo esc_url( $site->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $host ); ?></a>
				<ul class="usmd-card-meta">
					<li>WP <?php echo esc_html( $site->wp_version ? $site->wp_version : '?' ); ?></li>
					<li>PHP <?php echo esc_html( $site->php_version ? $site->php_version : '?' ); ?></li>
					<li class="<?php echo $ssl_bad ? 'is-bad' : ''; ?>">
						<?php
						if ( ! $https ) {
							echo 'No SSL';
						} elseif ( null === $ssl ) {
							echo 'SSL ?';
						} else {
							echo esc_html( $ssl < 0 ? 'SSL expired' : 'SSL ' . $ssl . 'd' );
						}
						?>
					</li>
					<li class="<?php echo $stale ? 'is-bad' : ''; ?>" title="Last report from the site"><?php echo esc_html( self::ago( $site->last_seen ) ); ?></li>
				</ul>
				<?php if ( $site->last_error ) : ?>
					<p class="usmd-card-error" title="<?php echo esc_attr( $site->last_error ); ?>"><?php echo esc_html( $site->last_error ); ?></p>
				<?php endif; ?>
				<?php if ( $vulns ) : ?>
					<p class="usmd-card-vuln is-<?php echo esc_attr( USMD_Vulns::worst( $site->id ) ); ?>"><a href="<?php echo esc_url( $link . '#usmd-security' ); ?>">
						<?php
						/* translators: %d: number of plugins, themes or WordPress with known vulnerabilities. */
						echo esc_html( sprintf( _n( '%d known vulnerability', '%d items with known vulnerabilities', $vulns, 'unstoppable-site-manager' ), $vulns ) );
						?>
					</a></p>
				<?php endif; ?>
				<?php self::connector_notice( $site, $conn_ver, $conn_old, 'card' ); ?>
				<div class="usmd-card-actions">
					<button type="button" class="button button-small usmd-act" data-act="refresh" data-site="<?php echo (int) $site->id; ?>" title="Check this site now">Refresh</button>
					<button type="button" class="button button-small button-primary usmd-act" data-act="update" data-site="<?php echo (int) $site->id; ?>" <?php disabled( ! $site->updates ); ?> title="Install all available updates">Update</button>
					<a class="button button-small usmd-card-admin usmd-login" data-site="<?php echo (int) $site->id; ?>" href="<?php echo esc_url( trailingslashit( $site->url ) . 'wp-admin/' ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open WP Admin in a new tab, already signed in', 'unstoppable-site-manager' ); ?>"><span class="dashicons dashicons-wordpress-alt" aria-hidden="true"></span><?php esc_html_e( 'Admin', 'unstoppable-site-manager' ); ?></a>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * "Connector 0.1 — update by hand" (link to the site's Upload Plugin screen) or
	 * "Connector 0.2.0 — push update" (link to Plugins > Add plugin here).
	 */
	public static function connector_notice( $site, $ver, $state, $context ) {
		if ( ! $state ) {
			return;
		}
		$label = '0.1' === $ver ? '0.1.x' : $ver;
		// Connectors before 0.4.0 are FM Connector, this connector's former name.
		$old_name = version_compare( '0.1' === $ver ? '0.1.0' : $ver, '0.4.0', '<' );
		if ( 'manual' === $state ) {
			$url   = USMD_Sites::upload_url( $site );
			/* translators: %s: connector version. */
			$text  = sprintf( __( 'Connector %s: update by hand', 'unstoppable-site-manager' ), $label );
			$title = __( 'This connector can\'t receive plugins from the dashboard. Opens the site\'s Upload Plugin screen: upload the new connector ZIP and choose "Replace current with uploaded". After this one update, connector updates can be pushed from here.', 'unstoppable-site-manager' );
			$attrs = ' target="_blank" rel="noopener"';
		} else {
			$url   = admin_url( 'admin.php?page=usmd-plugins&view=add' );
			/* translators: %s: connector version. */
			$text  = $old_name
				/* translators: %s: connector version. */
				? sprintf( __( 'FM Connector %s: switch to the new connector', 'unstoppable-site-manager' ), $label )
				/* translators: %s: connector version. */
				: sprintf( __( 'Connector %s: update available', 'unstoppable-site-manager' ), $label );
			$title = $old_name
				? __( 'Drop the Unstoppable Site Connector ZIP on Plugins > Add plugin and install it on this site. It takes over the connection (no re-pairing) and removes FM Connector.', 'unstoppable-site-manager' )
				: __( 'Drop the new connector ZIP on Plugins > Add plugin and install it on this site. It replaces the old copy and stays connected.', 'unstoppable-site-manager' );
			$attrs = '';
		}
		printf(
			'<p class="usmd-connector-old usmd-connector-old-%1$s is-%2$s"><a href="%3$s" title="%4$s"%5$s>%6$s%7$s</a></p>',
			esc_attr( $context ),
			esc_attr( $state ),
			esc_url( $url ),
			esc_attr( $title ),
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- fixed string.
			esc_html( $text ),
			'manual' === $state ? ' ↗' : ''
		);
	}

	/** Site page: known vulnerabilities in what is installed. */
	public static function security_panel( $site ) {
		if ( ! USMD_Vulns::enabled() ) {
			return;
		}
		$r      = USMD_Vulns::for_site( $site->id );
		$labels = array(
			'critical' => __( 'Critical', 'unstoppable-site-manager' ),
			'high'     => __( 'High', 'unstoppable-site-manager' ),
			'medium'   => __( 'Medium', 'unstoppable-site-manager' ),
			'low'      => __( 'Low', 'unstoppable-site-manager' ),
			'unrated'  => __( 'Unrated', 'unstoppable-site-manager' ),
		);
		$types  = array(
			'plugin' => __( 'Plugin', 'unstoppable-site-manager' ),
			'theme'  => __( 'Theme', 'unstoppable-site-manager' ),
			'core'   => __( 'WordPress', 'unstoppable-site-manager' ),
		);
		?>
		<div class="usmd-panel usmd-security<?php echo ( $r && $r['items'] ) ? ' has-vulns' : ''; ?>" id="usmd-security">
			<h2><?php esc_html_e( 'Security: known vulnerabilities', 'unstoppable-site-manager' ); ?></h2>
			<?php if ( ! $r ) : ?>
				<p class="usmd-muted"><?php esc_html_e( 'Not checked yet. Click Refresh now to check.', 'unstoppable-site-manager' ); ?></p>
			<?php elseif ( ! $r['items'] ) : ?>
				<p class="usmd-security-ok"><?php echo esc_html( sprintf( /* translators: %s: time ago. */ __( 'No known vulnerabilities in this site\'s plugins, themes or WordPress version. Checked %s.', 'unstoppable-site-manager' ), self::ago( gmdate( 'Y-m-d H:i:s', $r['checked'] ) ) ) ); ?></p>
			<?php else : ?>
				<ul class="usmd-vuln-list">
					<?php foreach ( $r['items'] as $it ) : ?>
						<li>
							<div class="usmd-vuln-item">
								<strong><?php echo esc_html( $it['name'] ); ?></strong>
								<span class="usmd-muted"><?php echo esc_html( $types[ $it['type'] ] . ' ' . $it['version'] ); ?></span>
							</div>
							<ul>
								<?php foreach ( $it['vulns'] as $v ) : ?>
									<li>
										<span class="usmd-sev is-<?php echo esc_attr( $v['severity'] ); ?>"><?php echo esc_html( $labels[ $v['severity'] ] . ( $v['score'] ? ' ' . number_format_i18n( $v['score'], 1 ) : '' ) ); ?></span>
										<?php if ( $v['link'] ) : ?>
											<a href="<?php echo esc_url( $v['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $v['title'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $v['title'] ); ?>
										<?php endif; ?>
										<span class="usmd-vuln-fix">
											<?php
											if ( $v['unfixed'] ) {
												esc_html_e( 'No fix available yet: consider deactivating or replacing it.', 'unstoppable-site-manager' );
											} elseif ( $v['fixed_in'] ) {
												/* translators: %s: version number. */
												echo esc_html( sprintf( __( 'Fixed in %s. Update to fix.', 'unstoppable-site-manager' ), $v['fixed_in'] ) );
											} elseif ( $v['max'] ) {
												/* translators: %s: version number. */
												echo esc_html( sprintf( __( 'Affects versions up to %s. Update to fix.', 'unstoppable-site-manager' ), $v['max'] ) );
											}
											?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="usmd-muted usmd-vuln-source">
					<?php
					printf(
						/* translators: 1: time ago, 2: link to WPVulnerability. */
						esc_html__( 'Checked %1$s against %2$s.', 'unstoppable-site-manager' ),
						esc_html( self::ago( gmdate( 'Y-m-d H:i:s', $r['checked'] ) ) ),
						'<a href="https://www.wpvulnerability.com/" target="_blank" rel="noopener">WPVulnerability</a>'
					);
					if ( ! empty( $r['partial'] ) ) {
						echo ' ' . esc_html__( 'Some items could not be checked this time.', 'unstoppable-site-manager' );
					}
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Site page: who gets this site's reports. */
	public static function client_panel( $site, $back = '' ) {
		$last = USMD_Report_Mail::last_sent( $site->id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$saved = isset( $_GET['client'] );
		?>
		<div class="usmd-panel usmd-client" id="usmd-client">
			<h2><?php esc_html_e( 'Client', 'unstoppable-site-manager' ); ?><?php if ( $saved ) : ?> <span class="usmd-saved"><?php esc_html_e( 'Saved', 'unstoppable-site-manager' ); ?></span><?php endif; ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-client-form">
				<input type="hidden" name="action" value="usmd_client">
				<input type="hidden" name="site" value="<?php echo (int) $site->id; ?>">
				<?php wp_nonce_field( 'usmd_client' ); ?>
				<label for="usmd-client-name-<?php echo (int) $site->id; ?>"><?php esc_html_e( 'Contact name', 'unstoppable-site-manager' ); ?></label>
				<input id="usmd-client-name-<?php echo (int) $site->id; ?>" name="client_name" class="widefat" value="<?php echo esc_attr( $site->client_name ); ?>" placeholder="<?php esc_attr_e( 'Used in the greeting: Hi Sarah,', 'unstoppable-site-manager' ); ?>">
				<label for="usmd-client-email-<?php echo (int) $site->id; ?>"><?php esc_html_e( 'Report email', 'unstoppable-site-manager' ); ?></label>
				<input id="usmd-client-email-<?php echo (int) $site->id; ?>" name="client_email" class="widefat" value="<?php echo esc_attr( $site->client_email ); ?>" placeholder="owner@clientsite.com, manager@clientsite.com">
				<label class="usmd-client-auto"><input type="checkbox" name="report_auto" value="1" <?php checked( ! empty( $site->report_auto ) ); ?>> <?php esc_html_e( 'Email the monthly report automatically', 'unstoppable-site-manager' ); ?></label>
				<p class="usmd-muted">
					<?php
					$day = (int) self::settings()['report_day'];
					if ( ! $day ) {
						esc_html_e( 'Automatic sending is off in Settings.', 'unstoppable-site-manager' );
					} else {
						/* translators: %d: day of the month. */
						echo esc_html( sprintf( __( 'Sent on day %d of each month.', 'unstoppable-site-manager' ), $day ) );
					}
					if ( $last && ! empty( $last['to'] ) ) {
						/* translators: 1: month, 2: time ago. */
						echo ' ' . esc_html( sprintf( __( 'Last sent: %1$s report, %2$s.', 'unstoppable-site-manager' ), wp_date( 'F Y', strtotime( $last['month'] . '-01' ) ), human_time_diff( $last['time'] ) . ' ' . __( 'ago', 'unstoppable-site-manager' ) ) );
					}
					?>
				</p>
				<?php submit_button( __( 'Save client', 'unstoppable-site-manager' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/** Reports: every site's report contact, schedule and last send in one place. */
	public static function page_reports() {
		$s     = self::settings();
		$month = wp_date( 'Y-m', strtotime( 'first day of last month', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- local month.
		$label = wp_date( 'F Y', strtotime( $month . '-01' ) );
		$sites = USMD_Sites::all_starred_first();
		$auto  = 0;
		foreach ( $sites as $site ) {
			$auto += ( $site->report_auto && $site->client_email ) ? 1 : 0;
		}
		?>
		<div class="wrap usmd-wrap usmd-reports">
			<h1><?php esc_html_e( 'Client reports', 'unstoppable-site-manager' ); ?></h1>
			<p class="usmd-reports-lead">
				<?php
				if ( (int) $s['report_day'] ) {
					/* translators: 1: number of sites, 2: day of month. */
					echo esc_html( sprintf( _n( '%1$d client gets their report by email automatically on day %2$d of each month.', '%1$d clients get their report by email automatically on day %2$d of each month.', $auto, 'unstoppable-site-manager' ), $auto, (int) $s['report_day'] ) );
				} else {
					esc_html_e( 'Automatic sending is off.', 'unstoppable-site-manager' );
				}
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=usmd-settings#usmd-report-email-settings' ) ); ?>"><?php esc_html_e( 'Email settings', 'unstoppable-site-manager' ); ?></a>
			</p>
			<table class="widefat striped usmd-table usmd-reports-table">
				<thead><tr>
					<th><?php esc_html_e( 'Site', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Client', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Monthly email', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Last sent', 'unstoppable-site-manager' ); ?></th>
					<th><?php echo esc_html( sprintf( /* translators: %s: month. */ __( '%s report', 'unstoppable-site-manager' ), $label ) ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $sites as $site ) : ?>
					<?php
					$last = USMD_Report_Mail::last_sent( $site->id );
					$done = USMD_Report_Mail::sent( $site->id, $month );
					?>
					<tr>
						<td><a href="<?php echo esc_url( self::site_url( $site->id ) ); ?>"><strong><?php echo esc_html( $site->name ); ?></strong></a><br><span class="usmd-muted"><?php echo esc_html( (string) wp_parse_url( $site->url, PHP_URL_HOST ) ); ?></span></td>
						<td>
							<?php if ( $site->client_email ) : ?>
								<?php echo esc_html( $site->client_name ? $site->client_name : '' ); ?><?php echo $site->client_name ? '<br>' : ''; ?><span class="usmd-muted"><?php echo esc_html( $site->client_email ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( self::site_url( $site->id ) . '#usmd-client' ); ?>"><?php esc_html_e( 'Add email', 'unstoppable-site-manager' ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo ( $site->report_auto && $site->client_email ) ? '<span class="usmd-pill usmd-pill-ok">' . esc_html__( 'On', 'unstoppable-site-manager' ) . '</span>' : '<span class="usmd-muted">' . esc_html__( 'Off', 'unstoppable-site-manager' ) . '</span>'; ?></td>
						<td><?php echo $last && ! empty( $last['to'] ) ? esc_html( sprintf( /* translators: 1: month, 2: date. */ __( '%1$s report, %2$s', 'unstoppable-site-manager' ), wp_date( 'M Y', strtotime( $last['month'] . '-01' ) ), wp_date( 'M j', $last['time'] ) ) ) : '<span class="usmd-muted">' . esc_html__( 'Never', 'unstoppable-site-manager' ) . '</span>'; ?></td>
						<td class="usmd-reports-actions">
							<a class="button button-small" href="<?php echo esc_url( self::site_url( $site->id, array( 'view' => 'report', 'month' => $month ) ) ); ?>"><?php esc_html_e( 'View', 'unstoppable-site-manager' ); ?></a>
							<?php if ( $site->client_email ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="usmd_report_send">
									<input type="hidden" name="site" value="<?php echo (int) $site->id; ?>">
									<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
									<input type="hidden" name="to" value="<?php echo esc_attr( $site->client_email ); ?>">
									<?php wp_nonce_field( 'usmd_report_send' ); ?>
									<button type="submit" class="button button-small"><?php echo $done && ! empty( $done['to'] ) ? esc_html__( 'Send again', 'unstoppable-site-manager' ) : esc_html__( 'Send now', 'unstoppable-site-manager' ); ?></button>
								</form>
								<?php if ( $done && ! empty( $done['to'] ) ) : ?><span class="usmd-muted usmd-reports-sent">✓ <?php echo esc_html( wp_date( 'M j', $done['time'] ) ); ?></span><?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function page_grid() {
		$sites   = USMD_Sites::all();
		// Totals for the tiles at the top (the browser keeps them current after refreshes).
		$t = array( 'plugins' => 0, 'plugin_sites' => 0, 'themes' => 0, 'theme_sites' => 0, 'core_sites' => 0, 'errors' => 0, 'connector' => 0, 'connector_manual' => 0, 'vulns' => 0, 'vuln_sites' => 0 );
		foreach ( $sites as $s ) {
			$t['plugins']      += (int) $s->plugin_updates;
			$t['plugin_sites'] += $s->plugin_updates ? 1 : 0;
			$t['themes']       += (int) $s->theme_updates;
			$t['theme_sites']  += $s->theme_updates ? 1 : 0;
			$t['core_sites']   += $s->core_update ? 1 : 0;
			$t['errors']       += $s->last_error ? 1 : 0;
			$cs                 = USMD_Sites::connector_state( $s );
			$t['connector']        += $cs ? 1 : 0;
			$t['connector_manual'] += 'manual' === $cs ? 1 : 0;
			$vc                     = USMD_Vulns::enabled() ? USMD_Vulns::count( $s->id ) : 0;
			$t['vulns']            += $vc;
			$t['vuln_sites']       += $vc ? 1 : 0;
		}
		$on = static function ( $n ) {
			/* translators: %d: number of sites */
			return $n ? sprintf( _n( 'on %d site', 'on %d sites', $n, 'unstoppable-site-manager' ), $n ) : __( 'all up to date', 'unstoppable-site-manager' );
		};
		?>
		<div class="wrap usmd-wrap">
			<h1 class="wp-heading-inline">Sites</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=usmd-add' ) ); ?>" class="page-title-action">Add site</a>

			<?php if ( ! $sites ) : ?>
				<div class="usmd-empty">
					<h2>No sites yet</h2>
					<p>Install <strong>Unstoppable Site Connector</strong> on a client site, generate a connection key under Tools &gt; Unstoppable Site Connector, and paste it here.</p>
					<?php self::connector_box(); ?>
					<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=usmd-add' ) ); ?>">Add your first site</a>
				</div>
			</div>
				<?php
				return;
			endif;
			?>

			<div class="usmd-stats" id="usmd-stats">
				<button type="button" class="usmd-stat" data-filter="all">
					<strong data-stat="sites"><?php echo count( $sites ); ?></strong>
					<span><?php esc_html_e( 'Sites', 'unstoppable-site-manager' ); ?></span>
				</button>
				<button type="button" class="usmd-stat usmd-stat-plugins<?php echo $t['plugins'] ? ' has-updates' : ''; ?>" data-filter="plugins" title="<?php esc_attr_e( 'Show sites with plugin updates', 'unstoppable-site-manager' ); ?>">
					<strong data-stat="plugins"><?php echo (int) $t['plugins']; ?></strong>
					<span><?php esc_html_e( 'Plugin updates', 'unstoppable-site-manager' ); ?></span>
					<small data-stat="plugin-sites"><?php echo esc_html( $on( $t['plugin_sites'] ) ); ?></small>
				</button>
				<button type="button" class="usmd-stat usmd-stat-themes<?php echo $t['themes'] ? ' has-updates' : ''; ?>" data-filter="themes" title="<?php esc_attr_e( 'Show sites with theme updates', 'unstoppable-site-manager' ); ?>">
					<strong data-stat="themes"><?php echo (int) $t['themes']; ?></strong>
					<span><?php esc_html_e( 'Theme updates', 'unstoppable-site-manager' ); ?></span>
					<small data-stat="theme-sites"><?php echo esc_html( $on( $t['theme_sites'] ) ); ?></small>
				</button>
				<button type="button" class="usmd-stat usmd-stat-core<?php echo $t['core_sites'] ? ' has-updates' : ''; ?>" data-filter="core" title="<?php esc_attr_e( 'Show sites with a WordPress core update', 'unstoppable-site-manager' ); ?>">
					<strong data-stat="core"><?php echo (int) $t['core_sites']; ?></strong>
					<span><?php esc_html_e( 'WordPress core updates', 'unstoppable-site-manager' ); ?></span>
					<small data-stat="core-sites"><?php echo esc_html( $on( $t['core_sites'] ) ); ?></small>
				</button>
				<?php if ( USMD_Vulns::enabled() ) : ?>
					<button type="button" class="usmd-stat usmd-stat-vulns<?php echo $t['vulns'] ? ' is-bad' : ''; ?>" data-filter="vulns" title="<?php esc_attr_e( 'Show sites with known vulnerabilities', 'unstoppable-site-manager' ); ?>">
						<strong data-stat="vulns"><?php echo (int) $t['vulns']; ?></strong>
						<span><?php esc_html_e( 'Vulnerable items', 'unstoppable-site-manager' ); ?></span>
						<small data-stat="vuln-sites"><?php echo esc_html( $on( $t['vuln_sites'] ) ); ?></small>
					</button>
				<?php endif; ?>
				<button type="button" class="usmd-stat usmd-stat-errors<?php echo $t['errors'] ? ' is-bad' : ''; ?>" data-filter="problem" title="<?php esc_attr_e( 'Show sites with connection problems', 'unstoppable-site-manager' ); ?>">
					<strong data-stat="errors"><?php echo (int) $t['errors']; ?></strong>
					<span><?php esc_html_e( 'Connection problems', 'unstoppable-site-manager' ); ?></span>
				</button>
				<button type="button" class="usmd-stat usmd-stat-connector<?php echo $t['connector'] ? ' has-updates' : ''; ?>" data-filter="connector" <?php echo $t['connector'] ? '' : 'hidden'; ?> title="<?php esc_attr_e( 'Show sites running an older connector', 'unstoppable-site-manager' ); ?>">
					<strong data-stat="connector"><?php echo (int) $t['connector']; ?></strong>
					<span><?php esc_html_e( 'Old connectors', 'unstoppable-site-manager' ); ?></span>
					<small data-stat="connector-manual">
						<?php
						/* translators: %d: number of sites. */
						echo esc_html( $t['connector_manual'] ? sprintf( _n( '%d needs a manual update', '%d need a manual update', $t['connector_manual'], 'unstoppable-site-manager' ), $t['connector_manual'] ) : __( 'update from Add plugin', 'unstoppable-site-manager' ) );
						?>
					</small>
				</button>
			</div>

			<div class="usmd-toolbar">
				<input type="search" id="usmd-search" placeholder="Search sites…" class="regular-text">
				<div class="usmd-filters" role="group" aria-label="Filter sites">
					<button type="button" class="button is-active" data-filter="all">All</button>
					<button type="button" class="button" data-filter="updates">Needs updates</button>
					<button type="button" class="button" data-filter="problem">Problems</button>
					<button type="button" class="button" data-filter="ssl">SSL</button>
				</div>
				<div class="usmd-sizes" role="group" aria-label="Card size">
					<button type="button" class="button" data-size="s" title="Small cards">S</button>
					<button type="button" class="button" data-size="m" title="Medium cards">M</button>
					<button type="button" class="button" data-size="l" title="Large cards">L</button>
				</div>
				<div class="usmd-bulk">
					<?php if ( 'off' !== USMD_Thumbs::provider() ) : ?>
						<button type="button" class="button" id="usmd-retake-missing" disabled><?php esc_html_e( 'Retake missing screenshots', 'unstoppable-site-manager' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button" id="usmd-sync-all">Refresh all</button>
					<button type="button" class="button button-primary" id="usmd-update-all">Update all sites</button>
				</div>
			</div>
			<div id="usmd-progress" class="usmd-progress" hidden></div>

			<?php
			$starred = array_values( array_filter( $sites, static function ( $s ) { return ! empty( $s->starred ); } ) );
			$others  = array_values( array_filter( $sites, static function ( $s ) { return empty( $s->starred ); } ) );
			?>
			<section class="usmd-grid-section" id="usmd-starred-section"<?php echo $starred ? '' : ' hidden'; ?>>
				<h2 class="usmd-grid-title"><span class="usmd-star-icon" aria-hidden="true">★</span> <?php esc_html_e( 'Starred', 'unstoppable-site-manager' ); ?> <span class="usmd-muted" data-count></span></h2>
				<div class="usmd-grid usmd-size-s" id="usmd-grid-starred">
					<?php
					foreach ( $starred as $s ) {
						echo self::card_html( $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
					}
					?>
				</div>
			</section>
			<section class="usmd-grid-section" id="usmd-all-section">
				<h2 class="usmd-grid-title"<?php echo $starred ? '' : ' hidden'; ?>><?php esc_html_e( 'All other sites', 'unstoppable-site-manager' ); ?> <span class="usmd-muted" data-count></span></h2>
				<div class="usmd-grid usmd-size-s" id="usmd-grid">
					<?php
					foreach ( $others as $s ) {
						echo self::card_html( $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
					}
					?>
				</div>
			</section>
		</div>
		<?php
	}

	/* ---------- add site ---------- */

	/**
	 * Where to get the connector: its WordPress.org page, and a shortcut that opens
	 * Plugins > Add New on the client's site already searched for it.
	 */
	public static function connector_box() {
		$slug  = apply_filters( 'usmd_connector_slug', USMD_CONNECTOR_SLUG );
		$page  = apply_filters( 'usmd_connector_page', USMD_CONNECTOR_PAGE );
		$zip   = USMD_Connector_Zip::info();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$saved = isset( $_GET['connector'] );
		?>
		<div class="usmd-connector-box" id="usmd-connector">
			<div class="usmd-connector-box-text">
				<h2><?php esc_html_e( 'Step 1: put the connector on the client site', 'unstoppable-site-manager' ); ?></h2>
				<p><?php esc_html_e( 'Install Unstoppable Site Connector on the client site, from WordPress.org (search for it under Plugins > Add New) or by uploading the ZIP, and activate it.', 'unstoppable-site-manager' ); ?></p>
			</div>
			<?php if ( $saved ) : ?>
				<p class="usmd-connector-saved"><?php esc_html_e( 'Connector saved for future sites. Use the Download the connector button below for each new client site.', 'unstoppable-site-manager' ); ?></p>
			<?php endif; ?>
			<div class="usmd-connector-actions">
				<?php if ( $zip ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( USMD_Connector_Zip::download_url() ); ?>">
						<?php
						/* translators: %s: version number. */
						echo esc_html( sprintf( __( 'Download the connector (version %s)', 'unstoppable-site-manager' ), $zip['version'] ) );
						?>
					</a>
				<?php endif; ?>
				<a class="button<?php echo $zip ? '' : ' button-primary'; ?>" href="<?php echo esc_url( $page ); ?>" target="_blank" rel="noopener">
					<?php
					esc_html_e( 'View on WordPress.org ↗', 'unstoppable-site-manager' );
					?>
				</a>
				<form class="usmd-connector-open" id="usmd-connector-open" data-slug="<?php echo esc_attr( $slug ); ?>">
					<label for="usmd-client-url" class="screen-reader-text"><?php esc_html_e( 'Client site address', 'unstoppable-site-manager' ); ?></label>
					<input type="text" inputmode="url" autocomplete="url" id="usmd-client-url" class="regular-text" placeholder="clientsite.com" required>
					<button type="submit" class="button"><?php esc_html_e( 'Install it on their site ↗', 'unstoppable-site-manager' ); ?></button>
				</form>
			</div>
			<?php
			$form = function ( $label ) {
				?>
				<form class="usmd-connector-save" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="usmd_connector_upload">
					<?php wp_nonce_field( 'usmd_connector_upload' ); ?>
					<label for="usmd-connector-file" class="screen-reader-text"><?php esc_html_e( 'Connector ZIP', 'unstoppable-site-manager' ); ?></label>
					<input type="file" id="usmd-connector-file" name="zip" accept=".zip,application/zip" required>
					<?php submit_button( $label, 'primary', 'submit', false, array( 'id' => 'usmd-connector-save' ) ); ?>
					<p class="usmd-connector-nudge" id="usmd-connector-nudge" hidden><?php esc_html_e( 'File chosen. Now click the Save button to keep it.', 'unstoppable-site-manager' ); ?></p>
				</form>
				<?php
			};
			?>
			<div class="usmd-connector-store<?php echo $zip ? ' has-copy' : ''; ?>">
				<?php if ( $zip ) : ?>
					<p class="usmd-connector-stored">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: version number, 2: date. */
								__( 'Saved for future sites: version %1$s (saved %2$s). Use the Download button above for each new client site.', 'unstoppable-site-manager' ),
								$zip['version'],
								wp_date( get_option( 'date_format' ), (int) $zip['time'] )
							)
						);
						?>
					</p>
					<details class="usmd-connector-upload">
						<summary><?php esc_html_e( 'Save a newer version instead', 'unstoppable-site-manager' ); ?></summary>
						<p class="usmd-muted"><?php esc_html_e( 'Choose the newer connector ZIP, then click Save. It replaces the saved copy. Pushing a newer connector to your sites from Plugins > Add plugin does this for you.', 'unstoppable-site-manager' ); ?></p>
						<?php $form( __( 'Save newer version', 'unstoppable-site-manager' ) ); ?>
					</details>
				<?php else : ?>
					<h3><?php esc_html_e( 'Save the connector here for future sites (one time)', 'unstoppable-site-manager' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Click Download the connector above and save the ZIP file to your computer.', 'unstoppable-site-manager' ); ?></li>
						<li><?php esc_html_e( 'Click Choose File below and pick that ZIP (unstoppable-site-connector-….zip).', 'unstoppable-site-manager' ); ?></li>
						<li><?php esc_html_e( 'Click Save connector for future sites.', 'unstoppable-site-manager' ); ?></li>
					</ol>
					<?php $form( __( 'Save connector for future sites', 'unstoppable-site-manager' ) ); ?>
					<p class="usmd-muted"><?php esc_html_e( 'After that, this box shows a Download button with the saved version, so you never have to go looking for the connector again. The file is kept on this dashboard only; nothing is sent to your client sites or any website.', 'unstoppable-site-manager' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function page_add() {
		$err = get_transient( 'usmd_add_error_' . get_current_user_id() );
		delete_transient( 'usmd_add_error_' . get_current_user_id() );
		?>
		<div class="wrap usmd-wrap">
			<h1>Add a site</h1>
			<?php if ( $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>
			<?php endif; ?>
			<?php self::connector_box(); ?>
			<h2 class="usmd-add-one"><?php esc_html_e( 'Connect one site', 'unstoppable-site-manager' ); ?></h2>
			<ol class="usmd-steps">
				<li>On the client site, install and activate <strong>Unstoppable Site Connector</strong> (see above).</li>
				<li>Go to <strong>Tools &gt; Unstoppable Site Connector</strong> and click <strong>Generate connection key</strong>.</li>
				<li>Paste the key below. It works once and expires after 15 minutes.</li>
			</ol>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="usmd_add">
				<?php wp_nonce_field( 'usmd_add' ); ?>
				<textarea name="key" rows="4" class="large-text code" required placeholder="Paste connection key"></textarea>
				<?php submit_button( 'Connect site' ); ?>
			</form>
			<?php USMD_Bulk::box(); ?>
		</div>
		<?php
	}

	public static function handle_add() {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'usmd_add' );
		$key = isset( $_POST['key'] ) ? sanitize_textarea_field( wp_unslash( $_POST['key'] ) ) : '';
		$id  = USMD_Client::pair( $key );
		if ( is_wp_error( $id ) ) {
			set_transient( 'usmd_add_error_' . get_current_user_id(), $id->get_error_message(), 120 );
			wp_safe_redirect( admin_url( 'admin.php?page=usmd-add' ) );
			exit;
		}
		wp_safe_redirect( self::site_url( $id, array( 'added' => 1 ) ) );
		exit;
	}

	/* ---------- site detail ---------- */

	public static function page_site( $site ) {
		$st      = USMD_Sites::status( $site );
		$plugins = isset( $st['plugins'] ) ? $st['plugins'] : array();
		$themes  = isset( $st['themes'] ) ? $st['themes'] : array();
		$env     = isset( $st['environment'] ) ? $st['environment'] : array();
		$int     = isset( $st['integrations'] ) ? $st['integrations'] : array();
		$core    = isset( $st['core'] ) ? $st['core'] : array();
		$ssl     = isset( $st['ssl'] ) ? $st['ssl'] : array();

		// Updates first, then alphabetical.
		usort(
			$plugins,
			static function ( $a, $b ) {
				return array( empty( $a['update'] ), strtolower( $a['name'] ) ) <=> array( empty( $b['update'] ), strtolower( $b['name'] ) );
			}
		);
		?>
		<div class="wrap usmd-wrap usmd-site" data-site="<?php echo (int) $site->id; ?>">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=unstoppable-site-manager' ) ); ?>">← All sites</a></p>
			<?php if ( isset( $_GET['added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p>Connected<?php echo $site->push_enabled ? '' : ' in check-in mode (this site can\'t push reports to the dashboard, so the dashboard will poll it every 6 hours)'; ?>.</p></div>
			<?php endif; ?>

			<div class="usmd-site-head">
				<div>
					<h1><?php echo esc_html( $site->name ); ?></h1>
					<a href="<?php echo esc_url( $site->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $site->url ); ?></a>
					<span class="usmd-muted"> · last report <?php echo esc_html( self::ago( $site->last_seen ) ); ?> · <?php echo $site->push_enabled ? 'reports automatically' : 'polled every 6 hours'; ?></span>
					<?php self::connector_notice( $site, USMD_Sites::connector_version( $site ), USMD_Sites::connector_state( $site ), 'page' ); ?>
				</div>
				<div class="usmd-site-actions">
					<button type="button" class="button usmd-act" data-act="refresh" data-site="<?php echo (int) $site->id; ?>" data-reload="1">Refresh now</button>
					<a class="button" href="<?php echo esc_url( self::site_url( $site->id, array( 'view' => 'report' ) ) ); ?>">Client report</a>
					<a class="button usmd-login" data-site="<?php echo (int) $site->id; ?>" href="<?php echo esc_url( trailingslashit( $site->url ) . 'wp-admin/' ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open WP Admin in a new tab, already signed in', 'unstoppable-site-manager' ); ?>"><?php esc_html_e( 'WP Admin', 'unstoppable-site-manager' ); ?> ↗</a>
					<button type="button" class="button button-link-delete usmd-act" data-act="remove" data-site="<?php echo (int) $site->id; ?>">Remove</button>
				</div>
			</div>

			<p id="usmd-site-status" class="usmd-site-status" role="status" aria-live="polite"></p>
			<?php
			$usmd_st = USMD_Sites::status( $site );
			if ( isset( $usmd_st['connector']['admin_context'] ) && false === $usmd_st['connector']['admin_context'] ) :
				?>
				<div class="notice notice-warning inline usmd-loopback-note"><p>
					<?php esc_html_e( 'This site\'s server blocks requests the site makes to itself, so update checks run outside its admin area. Updates for some paid plugins (such as Crocoblock\'s Jet plugins) may be missing here and may not install from the dashboard. Ask the host to allow loopback requests; WordPress\'s Tools > Site Health shows whether they work.', 'unstoppable-site-manager' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( $site->last_error ) : ?>
				<div class="notice notice-error inline"><p><strong>Last error:</strong> <?php echo esc_html( $site->last_error ); ?></p></div>
			<?php endif; ?>
			<div id="usmd-progress" class="usmd-progress" hidden></div>

			<div class="usmd-cols">
				<div class="usmd-main">
					<?php self::security_panel( $site ); ?>
					<div class="usmd-panel">
						<div class="usmd-panel-head">
							<h2>Updates</h2>
							<div>
								<button type="button" class="button" id="usmd-update-selected" disabled>Update selected</button>
								<button type="button" class="button button-primary usmd-act" data-act="update" data-site="<?php echo (int) $site->id; ?>" data-reload="1" <?php disabled( ! $site->updates ); ?>>Update all (<?php echo (int) $site->updates; ?>)</button>
							</div>
						</div>

						<table class="widefat striped usmd-table">
							<thead><tr><td class="check-column"></td><th>WordPress core</th><th>Installed</th><th>Available</th></tr></thead>
							<tbody>
								<tr>
									<th class="check-column"><?php if ( ! empty( $core['update'] ) ) : ?><input type="checkbox" class="usmd-item" value="core"><?php endif; ?></th>
									<td>WordPress</td>
									<td><?php echo esc_html( isset( $core['version'] ) ? $core['version'] : '?' ); ?></td>
									<td><?php echo ! empty( $core['update'] ) ? '<span class="usmd-pill usmd-pill-warn">' . esc_html( $core['update'] ) . '</span>' : '—'; ?></td>
								</tr>
							</tbody>
						</table>

						<h3>Plugins <span class="usmd-muted">(<?php echo count( $plugins ); ?>)</span></h3>
						<table class="widefat striped usmd-table">
							<thead><tr><td class="check-column"></td><th>Plugin</th><th>Installed</th><th>Available</th><th>Status</th></tr></thead>
							<tbody>
							<?php foreach ( $plugins as $p ) : ?>
								<tr>
									<th class="check-column"><?php if ( ! empty( $p['update'] ) ) : ?><input type="checkbox" class="usmd-item" value="<?php echo esc_attr( 'plugin:' . $p['file'] ); ?>"><?php endif; ?></th>
									<td><strong><?php echo esc_html( $p['name'] ); ?></strong><br><code class="usmd-muted"><?php echo esc_html( $p['file'] ); ?></code></td>
									<td><?php echo esc_html( $p['version'] ); ?></td>
									<td><?php echo ! empty( $p['update'] ) ? '<span class="usmd-pill usmd-pill-warn">' . esc_html( $p['update'] ) . '</span>' : '—'; ?></td>
									<td><?php echo ! empty( $p['active'] ) ? 'Active' : '<span class="usmd-muted">Inactive</span>'; ?><?php echo ! empty( $p['auto_update'] ) ? ' · auto' : ''; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>

						<h3>Themes <span class="usmd-muted">(<?php echo count( $themes ); ?>)</span></h3>
						<table class="widefat striped usmd-table">
							<thead><tr><td class="check-column"></td><th>Theme</th><th>Installed</th><th>Available</th><th>Status</th></tr></thead>
							<tbody>
							<?php foreach ( $themes as $t ) : ?>
								<tr>
									<th class="check-column"><?php if ( ! empty( $t['update'] ) ) : ?><input type="checkbox" class="usmd-item" value="<?php echo esc_attr( 'theme:' . $t['slug'] ); ?>"><?php endif; ?></th>
									<td><strong><?php echo esc_html( $t['name'] ); ?></strong></td>
									<td><?php echo esc_html( $t['version'] ); ?></td>
									<td><?php echo ! empty( $t['update'] ) ? '<span class="usmd-pill usmd-pill-warn">' . esc_html( $t['update'] ) . '</span>' : '—'; ?></td>
									<td><?php echo ! empty( $t['active'] ) ? 'Active' : ( ! empty( $t['parent'] ) ? 'Parent theme' : '<span class="usmd-muted">Inactive</span>' ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<div class="usmd-panel">
						<h2>Recent activity</h2>
						<?php self::activity_table( USMD_Sites::activity( $site->id, 50 ) ); ?>
					</div>
				</div>

				<aside class="usmd-side">
					<?php self::client_panel( $site ); ?>
					<?php echo USMD_Security::panel_html( $site ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in panel_html(). ?>
					<div class="usmd-panel usmd-shot-panel"
						<?php if ( USMD_Thumbs::browser_mode() ) : ?>
							data-id="<?php echo (int) $site->id; ?>"
							data-thumb-src="<?php echo esc_url( USMD_Thumbs::mshots_url( $site->url ) ); ?>"
							data-thumb-due="<?php echo USMD_Thumbs::is_due( $site ) ? 1 : 0; ?>"
						<?php endif; ?>>
						<?php $thumb = USMD_Thumbs::url( $site ); ?>
						<a href="<?php echo esc_url( $site->url ); ?>" target="_blank" rel="noopener" class="usmd-shot">
							<?php if ( $thumb ) : ?>
								<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( 'Screenshot of ' . $site->name ); ?>" width="800" height="600">
							<?php else : ?>
								<span class="usmd-thumb-empty">No screenshot yet</span>
							<?php endif; ?>
							<span class="usmd-thumb-status" hidden></span>
						</a>
						<div class="usmd-shot-meta">
							<span class="usmd-muted"><?php echo $site->thumb_at ? esc_html( 'Taken ' . self::ago( $site->thumb_at ) ) : ''; ?></span>
							<?php if ( 'off' !== USMD_Thumbs::provider() ) : ?>
								<button type="button" class="button button-small usmd-act" data-act="thumb" data-site="<?php echo (int) $site->id; ?>">Retake</button>
							<?php endif; ?>
						</div>
						<p class="usmd-shot-error"<?php echo $site->thumb_error ? '' : ' hidden'; ?>><?php echo esc_html( $site->thumb_error ? 'Last attempt failed: ' . $site->thumb_error : '' ); ?></p>
					</div>
					<div class="usmd-panel">
						<h2>Health</h2>
						<dl class="usmd-dl">
							<dt>WordPress</dt><dd><?php echo esc_html( isset( $core['version'] ) ? $core['version'] : '?' ); ?></dd>
							<dt>PHP</dt><dd><?php echo esc_html( isset( $env['php'] ) ? $env['php'] : '?' ); ?></dd>
							<dt>Database</dt><dd><?php echo esc_html( isset( $env['db'] ) ? $env['db'] : '?' ); ?></dd>
							<dt>SSL</dt><dd>
								<?php
								if ( empty( $ssl['enabled'] ) ) {
									echo '<span class="is-bad">Not using HTTPS</span>';
								} elseif ( ! empty( $ssl['error'] ) ) {
									echo '<span class="is-bad">' . esc_html( $ssl['error'] ) . '</span>';
								} else {
									$d = (int) $ssl['days_left'];
									echo '<span class="' . ( $d < self::SSL_WARN_DAYS ? 'is-bad' : '' ) . '">' . esc_html( $d . ' days left' ) . '</span>';
									echo ! empty( $ssl['issuer'] ) ? '<br><span class="usmd-muted">' . esc_html( $ssl['issuer'] ) . '</span>' : '';
								}
								?>
							</dd>
							<dt>Admins</dt><dd><?php echo esc_html( isset( $env['admin_count'] ) ? $env['admin_count'] : '?' ); ?></dd>
							<dt>Debug mode</dt><dd><?php echo ! empty( $env['debug'] ) ? '<span class="is-bad">On</span>' : 'Off'; ?></dd>
							<dt>Remote updates</dt><dd><?php echo ( isset( $env['filesystem_method'] ) && 'direct' !== $env['filesystem_method'] ) ? '<span class="is-bad">Blocked (needs FTP)</span>' : 'Available'; ?></dd>
							<dt>Server</dt><dd class="usmd-muted"><?php echo esc_html( isset( $env['server'] ) ? $env['server'] : '' ); ?></dd>
						</dl>
					</div>

					<?php if ( ! empty( $int['woocommerce'] ) ) : $wc = $int['woocommerce']; ?>
						<div class="usmd-panel">
							<h2>WooCommerce</h2>
							<dl class="usmd-dl">
								<dt>Version</dt><dd><?php echo esc_html( $wc['version'] ); ?></dd>
								<dt>Orders (30 days)</dt><dd><?php echo esc_html( isset( $wc['orders_30d'] ) ? $wc['orders_30d'] : '?' ); ?></dd>
								<dt>Failed payments</dt><dd class="<?php echo ! empty( $wc['failed_30d'] ) ? 'is-bad' : ''; ?>"><?php echo esc_html( isset( $wc['failed_30d'] ) ? $wc['failed_30d'] : '?' ); ?></dd>
								<dt>Pending payment</dt><dd><?php echo esc_html( isset( $wc['pending_30d'] ) ? $wc['pending_30d'] : '?' ); ?></dd>
							</dl>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $int['elementor'] ) ) : $el = $int['elementor']; ?>
						<div class="usmd-panel">
							<h2>Elementor</h2>
							<dl class="usmd-dl">
								<dt>Elementor</dt><dd><?php echo esc_html( $el['version'] ); ?></dd>
								<dt>Elementor Pro</dt><dd><?php echo esc_html( $el['pro_version'] ? $el['pro_version'] : 'Not installed' ); ?></dd>
							</dl>
						</div>
					<?php endif; ?>
				</aside>
			</div>
		</div>
		<?php
	}

	public static function describe( $a ) {
		$d    = is_array( $a->details ) ? $a->details : array();
		$name = ! empty( $d['name'] ) ? $d['name'] : $a->object;
		switch ( $a->type . '.' . $a->action ) {
			case 'report.emailed':
				/* translators: %s: month and recipients. */
				return sprintf( __( 'Emailed the %s', 'unstoppable-site-manager' ), $a->object ) . ( ! empty( $d['auto'] ) ? ' ' . __( '(scheduled)', 'unstoppable-site-manager' ) : '' );
			case 'report.email_failed':
				/* translators: %s: error message. */
				return sprintf( __( 'Scheduled report email failed: %s', 'unstoppable-site-manager' ), $a->object );
			case 'security.vulnerable':
				/* translators: %s: plugin, theme or WordPress name and version. */
				return sprintf( __( 'Known vulnerability found in %s', 'unstoppable-site-manager' ), $a->object );
			case 'core.updated':
				return sprintf( 'Updated WordPress from %s to %s', $d['from'], $d['to'] );
			case 'plugin.updated':
			case 'theme.updated':
				return sprintf( 'Updated %s %s from %s to %s', $a->type, $name, $d['from'], $d['to'] );
			case 'plugin.installed':
			case 'theme.installed':
				return sprintf( 'Installed %s %s %s', $a->type, $name, isset( $d['to'] ) ? $d['to'] : '' );
			case 'plugin.deleted':
			case 'theme.deleted':
				return sprintf( 'Removed %s %s', $a->type, $name );
			case 'plugin.activated':
				return 'Activated plugin ' . $name;
			case 'plugin.deactivated':
				return 'Deactivated plugin ' . $name;
			case 'theme.switched':
				return 'Switched theme to ' . $name;
			case 'user.created':
				return 'New user ' . $a->object . ( ! empty( $d['roles'] ) ? ' (' . implode( ', ', $d['roles'] ) . ')' : '' );
			case 'user.deleted':
				return 'Deleted user ' . $a->object;
			case 'user.admin_login':
				return 'Administrator login: ' . $a->object;
			case 'user.dashboard_login':
				/* translators: %s: username. */
				return sprintf( __( 'Signed in from the dashboard (one-click login) as %s', 'unstoppable-site-manager' ), $a->object );
			case 'connector.paired':
				return 'Connected to dashboard';
			case 'connector.unpaired':
				return 'Disconnected from dashboard';
			case 'connector.bulk_connected':
				return __( 'Connected with bulk connect', 'unstoppable-site-manager' );
			case 'bulk.connected':
				/* translators: %s: site address. */
				return sprintf( __( 'Bulk connect: %s connected', 'unstoppable-site-manager' ), $a->object );
			case 'work.note':
				/* translators: %s: task name. */
				return sprintf( __( 'Work note: %s', 'unstoppable-site-manager' ), $a->object );
			case 'security.request_blocked':
				/* translators: %s: IP address. */
				return sprintf( __( 'Blocked a request signed with this dashboard\'s key from %s (not an allowed address)', 'unstoppable-site-manager' ), $a->object );
			case 'security.settings_tightened':
				/* translators: %s: list of changes. */
				return sprintf( __( 'Security settings made stricter from the dashboard: %s', 'unstoppable-site-manager' ), $a->object );
			case 'security.settings_saved':
				return __( 'Connector security settings changed on the site', 'unstoppable-site-manager' );
		}
		return trim( $a->type . ' ' . $a->action . ' ' . $a->object );
	}

	public static function activity_table( $rows ) {
		if ( ! $rows ) {
			echo '<p class="usmd-muted">No activity recorded yet.</p>';
			return;
		}
		echo '<table class="widefat striped usmd-table"><thead><tr><th>When</th><th>What</th><th>By</th></tr></thead><tbody>';
		foreach ( $rows as $a ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( wp_date( 'M j, g:i a', strtotime( $a->created_at . ' UTC' ) ) ),
				esc_html( self::describe( $a ) ),
				esc_html( $a->actor )
			);
		}
		echo '</tbody></table>';
	}

	/* ---------- settings ---------- */

	public static function page_settings() {
		$s = self::settings();
		?>
		<div class="wrap usmd-wrap">
			<h1>Settings</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p>Saved.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="usmd_settings">
				<?php wp_nonce_field( 'usmd_settings' ); ?>
				<h2>Client reports</h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="usmd-agency">Agency name</label></th><td><input id="usmd-agency" name="agency_name" class="regular-text" value="<?php echo esc_attr( $s['agency_name'] ); ?>"></td></tr>
					<tr><th><label for="usmd-logo"><?php esc_html_e( 'Logo', 'unstoppable-site-manager' ); ?></label></th><td>
						<div class="usmd-logo-field">
							<img id="usmd-logo-preview" class="usmd-logo-preview" alt="" <?php echo $s['logo_url'] ? 'src="' . esc_url( $s['logo_url'] ) . '"' : 'hidden'; ?>>
							<div class="usmd-logo-controls">
								<?php if ( current_user_can( 'upload_files' ) ) : ?>
									<button type="button" class="button" id="usmd-logo-pick"><?php esc_html_e( 'Choose from Media Library', 'unstoppable-site-manager' ); ?></button>
								<?php endif; ?>
								<button type="button" class="button-link button-link-delete" id="usmd-logo-clear" <?php echo $s['logo_url'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove logo', 'unstoppable-site-manager' ); ?></button>
								<input id="usmd-logo" name="logo_url" type="url" class="large-text" value="<?php echo esc_attr( $s['logo_url'] ); ?>" placeholder="https://yoursite.com/wp-content/uploads/logo.png">
								<p class="usmd-logo-status" id="usmd-logo-status" role="status" aria-live="polite"></p>
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'Shown at the top of client reports and report emails. Choose it from the Media Library, or paste the address of the image file itself (ending in .png, .jpg, .webp or .svg).', 'unstoppable-site-manager' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Report header', 'unstoppable-site-manager' ); ?></th><td>
						<fieldset>
							<label><input type="radio" name="report_brand" value="both" <?php checked( $s['report_brand'], 'both' ); ?>> <?php esc_html_e( 'Logo and agency name', 'unstoppable-site-manager' ); ?></label><br>
							<label><input type="radio" name="report_brand" value="logo" <?php checked( $s['report_brand'], 'logo' ); ?>> <?php esc_html_e( 'Logo only', 'unstoppable-site-manager' ); ?></label><br>
							<label><input type="radio" name="report_brand" value="name" <?php checked( $s['report_brand'], 'name' ); ?>> <?php esc_html_e( 'Agency name only', 'unstoppable-site-manager' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Top left of reports and report emails. Without a logo URL, the agency name is shown.', 'unstoppable-site-manager' ); ?></p>
					</td></tr>
					<tr><th><label for="usmd-intro">Report intro</label></th><td><textarea id="usmd-intro" name="report_intro" rows="3" class="large-text"><?php echo esc_textarea( $s['report_intro'] ); ?></textarea></td></tr>
					<tr><th><label for="usmd-report-color"><?php esc_html_e( 'Report accent color', 'unstoppable-site-manager' ); ?></label></th><td><input id="usmd-report-color" name="report_color" type="color" value="<?php echo esc_attr( sanitize_hex_color( $s['report_color'] ) ? $s['report_color'] : '#1e5aa8' ); ?>"><p class="description"><?php esc_html_e( 'Used for the headings and highlights on client reports. Pick your brand color.', 'unstoppable-site-manager' ); ?></p></td></tr>
					<tr><th><label for="usmd-report-contact"><?php esc_html_e( 'Contact line', 'unstoppable-site-manager' ); ?></label></th><td><input id="usmd-report-contact" name="report_contact" class="large-text" value="<?php echo esc_attr( $s['report_contact'] ); ?>" placeholder="<?php esc_attr_e( 'Questions? Call 740-555-0100 or email help@youragency.com', 'unstoppable-site-manager' ); ?>"><p class="description"><?php esc_html_e( 'Printed at the bottom of every client report.', 'unstoppable-site-manager' ); ?></p></td></tr>
				</table>
				<h2 id="usmd-report-email-settings"><?php esc_html_e( 'Report emails', 'unstoppable-site-manager' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="usmd-report-day"><?php esc_html_e( 'Send automatically', 'unstoppable-site-manager' ); ?></label></th><td>
						<select id="usmd-report-day" name="report_day">
							<option value="0" <?php selected( 0, (int) $s['report_day'] ); ?>><?php esc_html_e( 'Never (send by hand only)', 'unstoppable-site-manager' ); ?></option>
							<?php for ( $i = 1; $i <= 28; $i++ ) : ?>
								<option value="<?php echo (int) $i; ?>" <?php selected( $i, (int) $s['report_day'] ); ?>><?php echo esc_html( sprintf( /* translators: %s: day of the month, e.g. 1st. */ __( 'On day %s of each month', 'unstoppable-site-manager' ), number_format_i18n( $i ) ) ); ?></option>
							<?php endfor; ?>
						</select>
						<p class="description"><?php esc_html_e( 'From 8 am that day, last month\'s report goes to every client whose site has "Email the monthly report" switched on (on the site page or under Reports). Each report is sent once.', 'unstoppable-site-manager' ); ?></p>
					</td></tr>
					<tr><th><label for="usmd-agency-email"><?php esc_html_e( 'Your email', 'unstoppable-site-manager' ); ?></label></th><td><input id="usmd-agency-email" name="agency_email" type="email" class="regular-text" value="<?php echo esc_attr( $s['agency_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><p class="description"><?php esc_html_e( 'Test reports and your blind copies go here, and it is the From and reply address unless you set others below. Empty: this WordPress site\'s admin email.', 'unstoppable-site-manager' ); ?></p></td></tr>
					<tr><th><label for="usmd-report-subject"><?php esc_html_e( 'Subject', 'unstoppable-site-manager' ); ?></label></th><td><input id="usmd-report-subject" name="report_subject" class="large-text" value="<?php echo esc_attr( $s['report_subject'] ); ?>"><p class="description"><?php esc_html_e( 'You can use {site}, {month} and {agency}.', 'unstoppable-site-manager' ); ?></p></td></tr>
					<tr><th><label for="usmd-report-from-name"><?php esc_html_e( 'From name', 'unstoppable-site-manager' ); ?></label></th><td><input id="usmd-report-from-name" name="report_from_name" class="regular-text" value="<?php echo esc_attr( $s['report_from_name'] ); ?>" placeholder="<?php echo esc_attr( $s['agency_name'] ); ?>"></td></tr>
					<tr><th><label for="usmd-report-from-email"><?php esc_html_e( 'From email', 'unstoppable-site-manager' ); ?></label></th><td><input id="usmd-report-from-email" name="report_from_email" type="email" class="regular-text" value="<?php echo esc_attr( $s['report_from_email'] ); ?>" placeholder="<?php echo esc_attr( self::agency_email() ); ?>"><p class="description"><?php esc_html_e( 'Use an address on this site\'s domain so the email isn\'t marked as spam. For reliable delivery, send this site\'s mail through an SMTP plugin.', 'unstoppable-site-manager' ); ?></p></td></tr>
					<tr><th><label for="usmd-report-reply"><?php esc_html_e( 'Replies go to', 'unstoppable-site-manager' ); ?></label></th><td><input id="usmd-report-reply" name="report_reply_to" type="email" class="regular-text" value="<?php echo esc_attr( $s['report_reply_to'] ); ?>" placeholder="<?php echo esc_attr( self::agency_email() ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Copy for you', 'unstoppable-site-manager' ); ?></th><td><label><input type="checkbox" name="report_bcc_me" value="1" <?php checked( ! empty( $s['report_bcc_me'] ) ); ?>> <?php echo esc_html( sprintf( /* translators: %s: email address. */ __( 'Send me a blind copy of every report (%s)', 'unstoppable-site-manager' ), self::agency_email() ) ); ?></label></td></tr>
				</table>
				<h2 id="usmd-vuln-settings"><?php esc_html_e( 'Vulnerability checks', 'unstoppable-site-manager' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Check for known vulnerabilities', 'unstoppable-site-manager' ); ?></th><td>
						<label><input type="checkbox" name="vuln_scan" value="1" <?php checked( ! empty( $s['vuln_scan'] ) ); ?>> <?php esc_html_e( 'Check every site\'s plugins, themes and WordPress version against the WPVulnerability database', 'unstoppable-site-manager' ); ?></label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to the WPVulnerability website. */
								esc_html__( 'Free, no account needed. Plugin and theme names and versions are sent to %s (never your client sites\' addresses). Each item is looked up once and the answer kept for 12 hours. Sites are checked when they are refreshed and when they report in.', 'unstoppable-site-manager' ),
								'<a href="https://www.wpvulnerability.com/" target="_blank" rel="noopener">WPVulnerability</a>'
							);
							?>
						</p>
					</td></tr>
				</table>
				<h2>Screenshots</h2>
				<table class="form-table" role="presentation">
					<tr><th>Screenshot service</th><td>
						<fieldset>
							<label><input type="radio" name="thumb_provider" value="mshots" <?php checked( $s['thumb_provider'], 'mshots' ); ?>> WordPress.com mShots <span class="usmd-muted">(free, no account; taken by your browser while the dashboard is open)</span></label><br>
							<label><input type="radio" name="thumb_provider" value="custom" <?php checked( $s['thumb_provider'], 'custom' ); ?>> Another service (URL below)</label><br>
							<label><input type="radio" name="thumb_provider" value="off" <?php checked( $s['thumb_provider'], 'off' ); ?>> Off</label>
						</fieldset>
						<p class="description">The service visits each client site's home page and takes the picture, so it only sees what a public visitor sees. Screenshots refresh weekly.</p>
						<p><button type="button" class="button" id="usmd-thumb-test" data-mshots="<?php echo esc_url( USMD_Thumbs::mshots_url( 'https://wordpress.org/' ) ); ?>">Test screenshot service</button> <span id="usmd-thumb-test-result" class="usmd-test-result"></span></p>
					</td></tr>
					<tr><th><label for="usmd-thumb-tpl">Service URL</label></th><td>
						<input id="usmd-thumb-tpl" name="thumb_template" class="large-text code" value="<?php echo esc_attr( $s['thumb_template'] ); ?>" placeholder="https://screenshot-service.example/capture?key=YOUR_KEY&amp;url={url}">
						<p class="description">For "Another service": paste your provider's screenshot URL with <code>{url}</code> where the site address goes. It must return a JPEG, PNG or WebP image.</p>
					</td></tr>
				</table>
				<h2>Connection details</h2>
				<table class="form-table" role="presentation">
					<tr><th>Push URL</th><td><code><?php echo esc_html( USMD_Receiver::push_url() ); ?></code><p class="description">Client sites send their reports here. It must be reachable from the internet on port 80, 443 or 8080.</p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_settings() {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'usmd_settings' );
		update_option(
			'usmd_settings',
			array(
				'agency_name'  => isset( $_POST['agency_name'] ) ? sanitize_text_field( wp_unslash( $_POST['agency_name'] ) ) : '',
				'logo_url'     => isset( $_POST['logo_url'] ) ? esc_url_raw( wp_unslash( $_POST['logo_url'] ) ) : '',
				'report_brand' => isset( $_POST['report_brand'] ) && in_array( $_POST['report_brand'], array( 'both', 'logo', 'name' ), true ) ? sanitize_key( $_POST['report_brand'] ) : 'logo',
				'report_intro'   => isset( $_POST['report_intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['report_intro'] ) ) : '',
				'report_color'   => isset( $_POST['report_color'] ) && sanitize_hex_color( wp_unslash( $_POST['report_color'] ) ) ? sanitize_hex_color( wp_unslash( $_POST['report_color'] ) ) : '#1e5aa8',
				'report_contact' => isset( $_POST['report_contact'] ) ? sanitize_text_field( wp_unslash( $_POST['report_contact'] ) ) : '',
				'vuln_scan'      => empty( $_POST['vuln_scan'] ) ? 0 : 1,
				'report_day'        => isset( $_POST['report_day'] ) ? min( 28, max( 0, absint( $_POST['report_day'] ) ) ) : 1,
				'report_subject'    => isset( $_POST['report_subject'] ) && '' !== trim( sanitize_text_field( wp_unslash( $_POST['report_subject'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['report_subject'] ) ) : '{site}: Website Care Report for {month}',
				'report_from_name'  => isset( $_POST['report_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['report_from_name'] ) ) : '',
				'report_from_email' => isset( $_POST['report_from_email'] ) ? sanitize_email( wp_unslash( $_POST['report_from_email'] ) ) : '',
				'report_reply_to'   => isset( $_POST['report_reply_to'] ) ? sanitize_email( wp_unslash( $_POST['report_reply_to'] ) ) : '',
				'report_bcc_me'     => empty( $_POST['report_bcc_me'] ) ? 0 : 1,
				'agency_email'      => isset( $_POST['agency_email'] ) ? sanitize_email( wp_unslash( $_POST['agency_email'] ) ) : '',
				'thumb_provider' => isset( $_POST['thumb_provider'] ) && in_array( $_POST['thumb_provider'], array( 'mshots', 'custom', 'off' ), true ) ? sanitize_key( $_POST['thumb_provider'] ) : 'mshots',
				'thumb_template' => isset( $_POST['thumb_template'] ) ? esc_url_raw( str_replace( '{url}', 'USMDURLTOKEN', sanitize_text_field( wp_unslash( $_POST['thumb_template'] ) ) ) ) : '',
			)
		);
		// esc_url_raw would mangle the {url} placeholder, so it's swapped out and back.
		$saved                   = get_option( 'usmd_settings' );
		$saved['thumb_template'] = str_replace( 'USMDURLTOKEN', '{url}', $saved['thumb_template'] );
		update_option( 'usmd_settings', $saved );
		if ( ! empty( $saved['vuln_scan'] ) ) {
			// Just switched on (or still on): check every site in the background, a few seconds apart.
			$i = 0;
			foreach ( USMD_Sites::all() as $site ) {
				if ( ! wp_next_scheduled( USMD_Vulns::HOOK, array( (int) $site->id ) ) ) {
					wp_schedule_single_event( time() + 10 + 5 * $i++, USMD_Vulns::HOOK, array( (int) $site->id ) );
				}
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=usmd-settings&saved=1' ) );
		exit;
	}
}
