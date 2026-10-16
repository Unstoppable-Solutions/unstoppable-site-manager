<?php
/**
 * Takes over from FM Dashboard, this plugin's former name.
 *
 * Copies the dashboard key pair (client sites trust it, so they stay connected),
 * settings, sites, activity and screenshots, then switches FM Dashboard off.
 * FM Dashboard is left installed so you can delete it yourself; its own uninstall
 * routine then removes its old data.
 *
 * Runs only when FM Dashboard's data exists and this plugin has no sites of its own.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Migrate {

	const LEGACY_FILE = 'fm-dashboard/fm-dashboard.php';
	const STATE       = 'usmd_migrated';

	public static function hooks() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_run' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function maybe_run() {
		if ( ! get_option( self::STATE ) && get_option( 'fmd_keys' ) ) {
			self::run();
		}
	}

	public static function run() {
		if ( get_option( self::STATE ) ) {
			return;
		}
		$had_legacy = (bool) get_option( 'fmd_keys' );
		$copied     = array( 'sites' => 0, 'activity' => 0 );

		USMD_Sites::install();
		if ( $had_legacy && ! self::count( USMD_Sites::t() ) ) {
			// The key pair first: every client site is paired with its public key.
			foreach ( array( 'fmd_keys' => 'usmd_keys', 'fmd_settings' => 'usmd_settings' ) as $old => $new ) {
				$value = get_option( $old, null );
				if ( null !== $value ) {
					update_option( $new, $value, false );
				}
			}
			global $wpdb;
			$copied['sites']    = self::copy_table( $wpdb->prefix . 'fmd_sites', USMD_Sites::t() );
			$copied['activity'] = self::copy_table( $wpdb->prefix . 'fmd_activity', USMD_Sites::ta() );
			self::move_thumbs();
			USMD_Sites::clear_job_errors();
		}

		// FM Dashboard's background jobs would otherwise keep firing with nothing to run them.
		wp_clear_scheduled_hook( 'fmd_sync_tick' );
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( 'fmd_sync_site' );
			wp_unschedule_hook( 'fmd_thumb' );
		}

		update_option(
			self::STATE,
			array(
				'time'     => time(),
				'migrated' => $had_legacy,
				'sites'    => $copied['sites'],
				'activity' => $copied['activity'],
			),
			false
		);
		// FM Dashboard is left as it is: the notice on this plugin's screens asks the owner to deactivate and delete it.
	}

	private static function count( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- this plugin's or FM Dashboard's table.
	}

	/**
	 * Copy every row, keeping IDs. Only columns both tables have are copied, so data
	 * from any FM Dashboard version comes across.
	 */
	private static function copy_table( $old, $new ) {
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) !== $old ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 0;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-time copy from FM Dashboard's tables into this plugin's own.
		$sample_old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1', $old ), ARRAY_A );
		if ( ! $sample_old ) {
			return 0;
		}
		$new_cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $new ) );
		$cols     = array_values( array_intersect( array_keys( $sample_old ), (array) $new_cols ) );
		if ( ! $cols ) {
			return 0;
		}
		$keep = array_flip( $cols );
		for ( $offset = 0; ; $offset += 500 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $old, 500, $offset ), ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$wpdb->insert( $new, array_intersect_key( $row, $keep ) );
			}
		}
		// phpcs:enable
		return self::count( $new );
	}

	/** Screenshots: uploads/fmd-thumbs -> uploads/usmd-thumbs (file names are unchanged). */
	private static function move_thumbs() {
		$u   = wp_upload_dir( null, false );
		$old = trailingslashit( $u['basedir'] ) . 'fmd-thumbs';
		$new = trailingslashit( $u['basedir'] ) . 'usmd-thumbs';
		if ( ! is_dir( $old ) ) {
			return;
		}
		if ( ! is_dir( $new ) && @rename( $old, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return;
		}
		wp_mkdir_p( $new );
		foreach ( (array) glob( $old . '/*' ) as $file ) {
			if ( is_file( $file ) && ! file_exists( $new . '/' . basename( $file ) ) ) {
				copy( $file, $new . '/' . basename( $file ) );
			}
		}
	}

	/** After taking over, point at FM Dashboard so it can be deleted. Shown on this plugin's screens only. */
	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, USMD_Admin::SLUG ) || ! current_user_can( 'delete_plugins' ) ) {
			return;
		}
		$state = get_option( self::STATE );
		if ( ! is_array( $state ) || empty( $state['migrated'] ) || ! file_exists( WP_PLUGIN_DIR . '/' . self::LEGACY_FILE ) ) {
			return;
		}
		printf(
			'<div class="notice notice-success"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of sites. */
					_n( 'Unstoppable Site Manager has taken over from FM Dashboard: %d site, its keys, screenshots and activity came across. FM Dashboard is no longer needed: deactivate and delete it.', 'Unstoppable Site Manager has taken over from FM Dashboard: %d sites, their keys, screenshots and activity came across. FM Dashboard is no longer needed: deactivate and delete it.', (int) $state['sites'], 'unstoppable-site-manager' ),
					(int) $state['sites']
				)
			),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Go to Plugins', 'unstoppable-site-manager' )
		);
	}
}
