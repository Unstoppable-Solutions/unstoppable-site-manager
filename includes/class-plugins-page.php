<?php
/**
 * Unstoppable Site Manager > Plugins: every plugin across every site in one list, with bulk
 * activate / deactivate / update / delete, plus "Add plugin" to install from the
 * WordPress plugin directory, a URL, or ZIPs dropped onto the page.
 *
 * Bulk work runs in the browser one site per request, so a slow or offline site
 * never holds up the rest.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Plugins_Page {

	const SLUG = 'usmd-plugins';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 11 );
		foreach ( array( 'plugin_action', 'plugin_install', 'package_upload', 'repo_search', 'plugin_index' ) as $a ) {
			add_action( 'wp_ajax_usmd_' . $a, array( __CLASS__, $a ) );
		}
	}

	public static function menu() {
		add_submenu_page( USMD_Admin::SLUG, __( 'Plugins', 'unstoppable-site-manager' ), __( 'Plugins', 'unstoppable-site-manager' ), USMD_Security::CAP, self::SLUG, array( __CLASS__, 'render' ), 1 );
	}

	private static function is_add_view() {
		return isset( $_GET['view'] ) && 'add' === sanitize_key( wp_unslash( $_GET['view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_script( 'usmd-plugins', USMD_URL . 'assets/plugins.js', array( 'usmd-admin' ), USMD_VERSION, true );
		wp_localize_script(
			'usmd-plugins',
			'USMDP',
			array(
				'view'    => self::is_add_view() ? 'add' : 'manage',
				'sites'   => self::sites_for_js(),
				'plugins' => self::is_add_view() ? array() : self::index(),
				'manage'  => admin_url( 'admin.php?page=' . self::SLUG ),
				'protect' => array_values( array_unique( array( 'fm-connector', apply_filters( 'usmd_connector_slug', USMD_CONNECTOR_SLUG ) ) ) ),
				'i18n'    => array(
					/* translators: 1: number of plugins, 2: number of sites. */
					'confirmDelete' => __( 'Delete %1$d plugin(s) from %2$d site(s)? Their files are removed and this cannot be undone.', 'unstoppable-site-manager' ),
					/* translators: 1: action, 2: number of plugins, 3: number of sites. */
					'confirmBulk'   => __( '%1$s %2$d plugin(s) on %3$d site(s)?', 'unstoppable-site-manager' ),
					'nothingToDo'   => __( 'None of the selected sites have anything to change for these plugins.', 'unstoppable-site-manager' ),
				),
			)
		);
	}

	/* ---------- data ---------- */

	private static function sites_for_js() {
		$out = array();
		foreach ( USMD_Sites::all_starred_first() as $s ) {
			$out[] = array(
				'id'      => (int) $s->id,
				// Site titles arrive HTML-encoded (e.g. "&amp;"); JS shows them as text.
				'name'    => html_entity_decode( $s->name, ENT_QUOTES, 'UTF-8' ),
				'host'    => (string) wp_parse_url( $s->url, PHP_URL_HOST ),
				'starred' => ! empty( $s->starred ),
				'offline' => (bool) $s->last_error,
				'client'  => (int) $s->client_id,
			);
		}
		return $out;
	}

	/** One entry per plugin file, with where it is installed and active. */
	private static function index() {
		$index = array();
		foreach ( USMD_Sites::all() as $site ) {
			$status = USMD_Sites::status( $site );
			foreach ( isset( $status['plugins'] ) ? $status['plugins'] : array() as $p ) {
				if ( empty( $p['file'] ) ) {
					continue;
				}
				$file = $p['file'];
				if ( ! isset( $index[ $file ] ) ) {
					$slug           = false !== strpos( $file, '/' ) ? dirname( $file ) : preg_replace( '/\.php$/', '', $file );
					$index[ $file ] = array(
						'file'  => $file,
						'slug'  => $slug,
						'name'  => $p['name'],
						'sites' => array(),
					);
				}
				$index[ $file ]['sites'][] = array(
					'id'      => (int) $site->id,
					'version' => $p['version'],
					'active'  => ! empty( $p['active'] ),
					'update'  => ! empty( $p['update'] ) ? $p['update'] : null,
				);
			}
		}
		$index = array_values( $index );
		usort(
			$index,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $index;
	}

	/* ---------- screens ---------- */

	public static function render() {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unstoppable-site-manager' ) );
		}
		$add = self::is_add_view();
		?>
		<div class="wrap usmd-wrap usmd-plugins-page">
			<div class="usmd-page-head">
				<?php if ( $add ) : ?>
					<a class="button usmd-back" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" aria-label="<?php esc_attr_e( 'Back to plugins', 'unstoppable-site-manager' ); ?>">←</a>
					<h1><?php esc_html_e( 'Plugins', 'unstoppable-site-manager' ); ?> <span class="usmd-muted">›</span> <?php esc_html_e( 'Add plugin', 'unstoppable-site-manager' ); ?></h1>
				<?php else : ?>
					<h1><?php esc_html_e( 'Plugins', 'unstoppable-site-manager' ); ?></h1>
					<a class="button button-primary usmd-add-plugin" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&view=add' ) ); ?>">+ <?php esc_html_e( 'Add plugin', 'unstoppable-site-manager' ); ?></a>
				<?php endif; ?>
			</div>

			<?php if ( ! USMD_Sites::all() ) : ?>
				<div class="usmd-empty"><p><?php esc_html_e( 'Add a site first. Plugins from all your sites will be listed here.', 'unstoppable-site-manager' ); ?></p></div>
			</div>
				<?php
				return;
			endif;
			?>

			<div class="usmd-plugins-layout">
				<aside class="usmd-panel usmd-site-picker">
					<div class="usmd-picker-head">
						<strong id="usmd-picker-count"></strong>
						<span><button type="button" class="button-link" id="usmd-pick-all"><?php esc_html_e( 'All', 'unstoppable-site-manager' ); ?></button> · <button type="button" class="button-link" id="usmd-pick-none"><?php esc_html_e( 'None', 'unstoppable-site-manager' ); ?></button> · <button type="button" class="button-link" id="usmd-pick-starred"><?php esc_html_e( 'Starred', 'unstoppable-site-manager' ); ?></button></span>
					</div>
					<?php if ( USMD_Clients::names() ) : ?>
						<label for="usmd-pick-client" class="screen-reader-text"><?php esc_html_e( 'Pick a client\'s sites', 'unstoppable-site-manager' ); ?></label>
						<?php echo USMD_Clients::select( 'pick_client', 0, __( 'Pick a client\'s sites…', 'unstoppable-site-manager' ), ' id="usmd-pick-client" class="widefat"' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in select(). ?>
					<?php endif; ?>
					<input type="search" id="usmd-picker-search" class="widefat" placeholder="<?php esc_attr_e( 'Find a site…', 'unstoppable-site-manager' ); ?>">
					<ul id="usmd-picker-list" class="usmd-picker-list"></ul>
				</aside>

				<section class="usmd-plugins-main">
					<div id="usmd-progress" class="usmd-progress" hidden></div>
					<?php
					if ( $add ) {
						self::render_add();
					} else {
						self::render_manage();
					}
					?>
				</section>
			</div>
		</div>
		<?php
	}

	private static function render_manage() {
		?>
		<div class="usmd-panel">
			<div class="usmd-plugin-toolbar">
				<input type="search" id="usmd-plugin-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter plugins', 'unstoppable-site-manager' ); ?>">
				<select id="usmd-plugin-sort" aria-label="<?php esc_attr_e( 'Sort by', 'unstoppable-site-manager' ); ?>">
					<option value="name"><?php esc_html_e( 'Sort: Name', 'unstoppable-site-manager' ); ?></option>
					<option value="installs"><?php esc_html_e( 'Sort: Most sites', 'unstoppable-site-manager' ); ?></option>
					<option value="updates"><?php esc_html_e( 'Sort: Updates first', 'unstoppable-site-manager' ); ?></option>
				</select>
				<select id="usmd-plugin-show" aria-label="<?php esc_attr_e( 'Filter by', 'unstoppable-site-manager' ); ?>">
					<option value="all"><?php esc_html_e( 'Show: All', 'unstoppable-site-manager' ); ?></option>
					<option value="updates"><?php esc_html_e( 'Show: Has updates', 'unstoppable-site-manager' ); ?></option>
					<option value="active"><?php esc_html_e( 'Show: Active somewhere', 'unstoppable-site-manager' ); ?></option>
					<option value="inactive"><?php esc_html_e( 'Show: Inactive everywhere', 'unstoppable-site-manager' ); ?></option>
				</select>
				<span id="usmd-plugin-count" class="usmd-muted"></span>
			</div>
			<div class="usmd-plugin-actions">
				<label><input type="checkbox" id="usmd-plugin-all"> <span id="usmd-select-label"><?php esc_html_e( 'Select all', 'unstoppable-site-manager' ); ?></span></label>
				<span id="usmd-selected-count" class="usmd-muted"><?php esc_html_e( 'None selected', 'unstoppable-site-manager' ); ?></span>
				<span class="usmd-plugin-buttons">
					<button type="button" class="button" data-op="activate" disabled><?php esc_html_e( 'Activate', 'unstoppable-site-manager' ); ?></button>
					<button type="button" class="button" data-op="deactivate" disabled><?php esc_html_e( 'Deactivate', 'unstoppable-site-manager' ); ?></button>
					<button type="button" class="button button-primary" data-op="update" disabled><?php esc_html_e( 'Update', 'unstoppable-site-manager' ); ?></button>
					<button type="button" class="button usmd-button-danger" data-op="delete" disabled><?php esc_html_e( 'Delete', 'unstoppable-site-manager' ); ?></button>
				</span>
			</div>
			<p class="description usmd-scope-note"><?php esc_html_e( 'Actions apply only to the sites ticked on the left.', 'unstoppable-site-manager' ); ?></p>
			<ul id="usmd-plugin-list" class="usmd-plugin-list"></ul>
		</div>
		<?php
	}

	private static function render_add() {
		?>
		<div class="usmd-panel">
			<nav class="usmd-tabs" role="tablist">
				<button type="button" class="usmd-tab is-active" data-tab="repo" role="tab"><?php esc_html_e( 'WordPress plugin directory', 'unstoppable-site-manager' ); ?></button>
				<button type="button" class="usmd-tab" data-tab="zip" role="tab"><?php esc_html_e( 'URL & ZIP', 'unstoppable-site-manager' ); ?></button>
			</nav>

			<div class="usmd-tab-panel" data-panel="repo">
				<form id="usmd-repo-form" class="usmd-repo-form">
					<input type="search" id="usmd-repo-q" class="regular-text" placeholder="<?php esc_attr_e( 'Search plugins, e.g. "contact form"', 'unstoppable-site-manager' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'unstoppable-site-manager' ); ?></button>
				</form>
				<div id="usmd-repo-results" class="usmd-repo-results"></div>
			</div>

			<div class="usmd-tab-panel" data-panel="zip" hidden>
				<form id="usmd-url-form" class="usmd-repo-form">
					<input type="url" id="usmd-url" class="regular-text" placeholder="https://example.com/my-plugin.zip">
					<button type="submit" class="button"><?php esc_html_e( 'Add URL', 'unstoppable-site-manager' ); ?></button>
				</form>
				<label class="usmd-dropzone" id="usmd-dropzone">
					<input type="file" id="usmd-zip-input" accept=".zip,application/zip" multiple hidden>
					<span class="usmd-dropzone-icon" aria-hidden="true">⇪</span>
					<strong><?php esc_html_e( 'Drop plugin ZIP files here', 'unstoppable-site-manager' ); ?></strong>
					<span class="usmd-muted"><?php esc_html_e( 'or click to choose. Up to 50 MB each.', 'unstoppable-site-manager' ); ?></span>
				</label>
			</div>
		</div>

		<div class="usmd-panel usmd-install-queue">
			<h2><?php esc_html_e( 'Install list', 'unstoppable-site-manager' ); ?> <span id="usmd-install-status" class="usmd-install-status" role="status" aria-live="polite"></span></h2>
			<ul id="usmd-queue" class="usmd-queue"><li class="usmd-muted usmd-queue-empty"><?php esc_html_e( 'Nothing selected yet. Add plugins from the directory, a URL or a ZIP.', 'unstoppable-site-manager' ); ?></li></ul>
			<div class="usmd-install-options">
				<label><input type="checkbox" id="usmd-opt-activate" checked> <?php esc_html_e( 'Activate after install', 'unstoppable-site-manager' ); ?></label>
				<label><input type="checkbox" id="usmd-opt-overwrite"> <?php esc_html_e( 'Replace if already installed', 'unstoppable-site-manager' ); ?></label>
				<button type="button" class="button button-primary" id="usmd-install-go" disabled><?php esc_html_e( 'Install', 'unstoppable-site-manager' ); ?></button>
			</div>
		</div>
		<?php
	}

	/* ---------- AJAX ---------- */

	private static function guard( $cap = USMD_Security::CAP ) {
		check_ajax_referer( 'usmd', 'nonce' );
		if ( ! current_user_can( USMD_Security::CAP ) || ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'unstoppable-site-manager' ) ), 403 );
		}
	}

	private static function site_from_request() {
		$site = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
		if ( ! $site ) {
			wp_send_json_error( array( 'message' => __( 'Site not found.', 'unstoppable-site-manager' ) ), 404 );
		}
		return $site;
	}

	/** Activate / deactivate / delete plugin files on one site. */
	public static function plugin_action() {
		self::guard();
		$site = self::site_from_request();
		// phpcs:disable WordPress.Security.NonceVerification -- checked in guard().
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( ! in_array( $op, array( 'activate', 'deactivate', 'delete' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'unstoppable-site-manager' ) ), 400 );
		}
		$files = isset( $_POST['files'] ) ? array_map( array( __CLASS__, 'clean_file' ), (array) wp_unslash( $_POST['files'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$files = array_values( array_filter( $files ) );
		if ( ! $files ) {
			wp_send_json_error( array( 'message' => __( 'No plugins selected.', 'unstoppable-site-manager' ) ), 400 );
		}
		$r = USMD_Client::plugins( $site, $op, $files );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'results' => $r ) );
	}

	/** A plugin file like "dir/file.php": no traversal, no odd characters. */
	public static function clean_file( $f ) {
		$f = sanitize_text_field( (string) $f );
		return ( preg_match( '#^[A-Za-z0-9._ -]+(/[A-Za-z0-9._ -]+)?\.php$#', $f ) && false === strpos( $f, '..' ) ) ? $f : '';
	}

	/** Install a list of plugins on one site. */
	public static function plugin_install() {
		self::guard( 'install_plugins' );
		$site = self::site_from_request();
		// phpcs:disable WordPress.Security.NonceVerification -- checked in guard().
		$raw       = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$activate  = ! empty( $_POST['activate'] );
		$overwrite = ! empty( $_POST['overwrite'] );
		// phpcs:enable
		if ( ! is_array( $raw ) || ! $raw || count( $raw ) > USMD_Plugins_Page_Limits::MAX_ITEMS ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to install.', 'unstoppable-site-manager' ) ), 400 );
		}

		$items     = array();
		$connector = array(); // The connector itself: always replaces the old copy, and goes last.
		foreach ( $raw as $it ) {
			$type = isset( $it['type'] ) ? sanitize_key( $it['type'] ) : '';
			if ( 'wporg' === $type && ! empty( $it['slug'] ) && USMD_CONNECTOR_SLUG !== $it['slug'] ) {
				$items[] = array( 'source' => 'wporg', 'slug' => sanitize_key( $it['slug'] ) );
			} elseif ( 'url' === $type && ! empty( $it['url'] ) ) {
				// Fetched once by this dashboard and offered to client sites from here:
				// client sites only ever download plugin files from WordPress.org or this dashboard.
				$pkg = USMD_Packages::store_from_url( esc_url_raw( $it['url'], array( 'https', 'http' ) ) );
				if ( is_wp_error( $pkg ) ) {
					wp_send_json_error( array( 'message' => $pkg->get_error_message() ), 400 );
				}
				$items[] = array(
					'source' => 'url',
					'url'    => $pkg['url'],
					'name'   => $pkg['name'],
				);
			} elseif ( 'zip' === $type && ! empty( $it['token'] ) && preg_match( '/^[a-f0-9]{32}$/', $it['token'] ) ) {
				$pkg = array(
					'source' => 'url',
					'url'    => USMD_Packages::url( $it['token'] ),
					'name'   => isset( $it['name'] ) ? sanitize_text_field( $it['name'] ) : '',
				);
				if ( USMD_Packages::is_connector( $it['token'] ) ) {
					$connector[] = $pkg;
				} else {
					$items[] = $pkg;
				}
			} elseif ( 'wporg' === $type && in_array( isset( $it['slug'] ) ? $it['slug'] : '', array( USMD_CONNECTOR_SLUG ), true ) ) {
				$connector[] = array( 'source' => 'wporg', 'slug' => USMD_CONNECTOR_SLUG );
			}
		}
		if ( ! $items && ! $connector ) {
			wp_send_json_error( array( 'message' => __( 'Nothing valid to install.', 'unstoppable-site-manager' ) ), 400 );
		}

		$results = array();
		if ( $items ) {
			$r = USMD_Client::plugins( $site, 'install', $items, array( 'activate' => $activate, 'overwrite' => $overwrite ) );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			$results = $r;
		}
		if ( $connector ) {
			// Replacing the connector keeps its connection (stored in the database) and it stays active.
			$r = USMD_Client::plugins( $site, 'install', $connector, array( 'activate' => true, 'overwrite' => true ) );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			$results = array_merge( $results, $r );
			USMD_Client::refresh( $site, false ); // Pick up the new connector version.
		}
		wp_send_json_success( array( 'results' => $results ) );
	}

	/** Fresh plugin list after a bulk job, without reloading the page. */
	public static function plugin_index() {
		self::guard();
		wp_send_json_success( array( 'plugins' => self::index() ) );
	}

	/** A ZIP dropped onto the Add plugin screen. */
	public static function package_upload() {
		self::guard( 'install_plugins' );
		$file = isset( $_FILES['zip'] ) ? $_FILES['zip'] : null; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- nonce checked in guard(); validated in store_upload().
		if ( ! is_array( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received.', 'unstoppable-site-manager' ) ), 400 );
		}
		$r = USMD_Packages::store_upload( $file );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'token' => $r['token'], 'name' => $r['name'] ) );
	}

	/** Search the WordPress plugin directory. */
	public static function repo_search() {
		self::guard( 'install_plugins' );
		$q    = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
		$page = isset( $_POST['paged'] ) ? max( 1, min( 20, absint( $_POST['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		if ( strlen( $q ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Type at least two characters.', 'unstoppable-site-manager' ) ) );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$api = plugins_api(
			'query_plugins',
			array(
				'search'   => $q,
				'page'     => $page,
				'per_page' => 24,
				'fields'   => array(
					'short_description' => true,
					'icons'             => true,
					'active_installs'   => true,
					'rating'            => true,
					'num_ratings'       => true,
					'tested'            => true,
					'last_updated'      => true,
					'sections'          => false,
					'description'       => false,
					'banners'           => false,
					'versions'          => false,
				),
			)
		);
		if ( is_wp_error( $api ) ) {
			wp_send_json_error( array( 'message' => __( 'The WordPress plugin directory could not be reached. Try again in a moment.', 'unstoppable-site-manager' ) ) );
		}
		$out = array();
		foreach ( (array) $api->plugins as $p ) {
			$p     = (array) $p;
			$icons = isset( $p['icons'] ) ? (array) $p['icons'] : array();
			$icon  = '';
			foreach ( array( '1x', '2x', 'svg', 'default' ) as $k ) {
				if ( ! empty( $icons[ $k ] ) ) {
					$icon = $icons[ $k ];
					break;
				}
			}
			$out[] = array(
				'slug'     => sanitize_key( $p['slug'] ),
				'name'     => html_entity_decode( wp_strip_all_tags( $p['name'] ), ENT_QUOTES ),
				'desc'     => html_entity_decode( wp_strip_all_tags( isset( $p['short_description'] ) ? $p['short_description'] : '' ), ENT_QUOTES ),
				'author'   => html_entity_decode( wp_strip_all_tags( isset( $p['author'] ) ? $p['author'] : '' ), ENT_QUOTES ),
				'version'  => isset( $p['version'] ) ? $p['version'] : '',
				'installs' => isset( $p['active_installs'] ) ? (int) $p['active_installs'] : 0,
				'rating'   => isset( $p['rating'] ) ? (int) $p['rating'] : 0,
				'tested'   => isset( $p['tested'] ) ? $p['tested'] : '',
				'icon'     => esc_url_raw( $icon ),
			);
		}
		wp_send_json_success(
			array(
				'plugins' => $out,
				'page'    => $page,
				'pages'   => isset( $api->info['pages'] ) ? (int) $api->info['pages'] : 1,
				'total'   => isset( $api->info['results'] ) ? (int) $api->info['results'] : count( $out ),
			)
		);
	}
}

/** Shared limits (kept in step with the connector's FMC_Plugins::MAX_ITEMS). */
final class USMD_Plugins_Page_Limits {
	const MAX_ITEMS = 25;
}
