<?php
/**
 * Plugin Name: Unstoppable Site Manager
 * Plugin URI: https://unstoppablesolutions.app/plugins/unstoppable-site-manager
 * Description: Manage all your clients' WordPress sites from your own site: updates, plugins, health, SSL, one-click sign-in and monthly client reports. Pair it with Unstoppable Site Connector on each client site. Replaces FM Dashboard.
 * Version: 0.17.2
 * Author: Unstoppable Solutions
 * Author URI: https://unstoppablesolutions.app
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: unstoppable-site-manager
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 *
 * @package Unstoppable_Site_Manager
 */

defined( 'ABSPATH' ) || exit;

define( 'USMD_VERSION', '0.17.2' );
// The connector's WordPress.org slug, used for install links on the Add Site screen.
define( 'USMD_CONNECTOR_SLUG', 'unstoppable-site-connector' );
// Where people can read about and get the connector.
define( 'USMD_CONNECTOR_PAGE', 'https://wordpress.org/plugins/unstoppable-site-connector/' );
// Newest connector release. Sites below it are flagged on the Sites screen.
define( 'USMD_LATEST_CONNECTOR', '0.8.0' );
// Oldest connector that can receive plugins (and so connector updates) from the dashboard.
define( 'USMD_PUSHABLE_CONNECTOR', '0.2.0' );
define( 'USMD_FILE', __FILE__ );
define( 'USMD_DIR', plugin_dir_path( __FILE__ ) );
define( 'USMD_URL', plugin_dir_url( __FILE__ ) );

require_once USMD_DIR . 'includes/class-keys.php';
require_once USMD_DIR . 'includes/class-security.php';
require_once USMD_DIR . 'includes/class-sites.php';
require_once USMD_DIR . 'includes/class-client.php';
require_once USMD_DIR . 'includes/class-receiver.php';
require_once USMD_DIR . 'includes/class-sync.php';
require_once USMD_DIR . 'includes/class-ajax.php';
require_once USMD_DIR . 'includes/class-admin.php';
require_once USMD_DIR . 'includes/class-report.php';
require_once USMD_DIR . 'includes/class-thumbs.php';
require_once USMD_DIR . 'includes/class-packages.php';
require_once USMD_DIR . 'includes/class-plugins-page.php';
require_once USMD_DIR . 'includes/class-migrate.php';
require_once USMD_DIR . 'includes/class-vulns.php';
require_once USMD_DIR . 'includes/class-report-mail.php';
require_once USMD_DIR . 'includes/class-work.php';
require_once USMD_DIR . 'includes/class-bulk.php';
require_once USMD_DIR . 'includes/class-clients.php';
require_once USMD_DIR . 'includes/class-branding.php';
require_once USMD_DIR . 'includes/class-move.php';
require_once USMD_DIR . 'includes/class-connector-zip.php';

final class USMD_Plugin {

	const DB_VERSION = 4;

	public static function init() {
		add_action( 'rest_api_init', array( 'USMD_Receiver', 'register' ) );
		add_action( 'rest_api_init', array( 'USMD_Packages', 'register' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade_db' ) );
		USMD_Security::hooks();
		USMD_Migrate::hooks();
		USMD_Bulk::hooks();
		USMD_Clients::hooks();
		USMD_Branding::hooks();
		USMD_Move::hooks();
		USMD_Vulns::hooks();
		USMD_Report_Mail::hooks();
		USMD_Sync::hooks();
		USMD_Thumbs::hooks();
		if ( is_admin() ) {
			USMD_Admin::hooks();
			USMD_Ajax::hooks();
			USMD_Plugins_Page::hooks();
			USMD_Report::hooks();
			USMD_Work::hooks();
		}
	}

	public static function activate() {
		USMD_Sites::install();
		USMD_Migrate::run(); // Take over from FM Dashboard, if it is here.
		USMD_Keys::get();
		USMD_Sync::schedule();
		$have = (int) get_option( 'usmd_db_version' );
		if ( $have && $have < 3 ) {
			USMD_Packages::remove_legacy_files();
		}
		update_option( 'usmd_db_version', self::DB_VERSION, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'usmd_sync_tick' );
		wp_clear_scheduled_hook( 'usmd_report_tick' );
	}

	public static function maybe_upgrade_db() {
		$have = (int) get_option( 'usmd_db_version' );
		if ( $have < self::DB_VERSION ) {
			USMD_Sites::install();
			if ( $have && $have < 3 ) {
				USMD_Packages::remove_legacy_files(); // 0.15.0: plugin ZIPs are no longer kept in uploads.
			}
			update_option( 'usmd_db_version', self::DB_VERSION, false );
		}
	}
}

register_activation_hook( __FILE__, array( 'USMD_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'USMD_Plugin', 'deactivate' ) );

USMD_Plugin::init();
