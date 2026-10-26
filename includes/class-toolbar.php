<?php
/**
 * "Site Manager" in the top toolbar (admin bar).
 *
 * For people allowed to use the dashboard only: a link to the Sites screen with
 * the number of pending updates, and a dropdown to the other screens. It shows
 * how many sites are down or not reporting, when any are. It can be switched
 * off under Settings > Menu.
 *
 * The counts come from one small query, kept for five minutes, so the toolbar
 * stays fast on dashboards with hundreds of sites.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Toolbar {

	const CACHE = 'usmd_toolbar_counts';

	public static function hooks() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'menu' ), 80 );
		add_action( 'admin_head', array( __CLASS__, 'css' ) );
		add_action( 'wp_head', array( __CLASS__, 'css' ) );
	}

	private static function enabled() {
		return ! empty( USMD_Admin::settings()['admin_bar'] ) && is_user_logged_in() && current_user_can( USMD_Security::CAP );
	}

	/** @return array { sites, updates, problems } */
	public static function counts() {
		$c = get_transient( self::CACHE );
		if ( is_array( $c ) ) {
			return $c;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table; cached for five minutes.
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS sites, COALESCE(SUM(updates),0) AS updates, SUM(CASE WHEN last_error <> '' OR last_seen IS NULL OR last_seen < %s THEN 1 ELSE 0 END) AS problems FROM %i", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), USMD_Sites::t() ) );
		$down = 0;
		if ( USMD_Monitor::enabled() ) {
			foreach ( USMD_Monitor::states() as $s ) {
				$down += ( isset( $s['state'] ) && 'down' === $s['state'] ) ? 1 : 0;
			}
		}
		$c = array(
			'sites'    => $row ? (int) $row->sites : 0,
			'updates'  => $row ? (int) $row->updates : 0,
			'problems' => ( $row ? (int) $row->problems : 0 ) + $down,
		);
		set_transient( self::CACHE, $c, 5 * MINUTE_IN_SECONDS );
		return $c;
	}

	public static function flush() {
		delete_transient( self::CACHE );
	}

	public static function menu( $bar ) {
		if ( ! self::enabled() || USMD_Move::is_moved() ) {
			return;
		}
		$c     = self::counts();
		$page  = static function ( $slug, $args = array() ) {
			return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
		};
		$badge = $c['updates'] ? ' <span class="usmd-ab-count" aria-hidden="true">' . (int) $c['updates'] . '</span>' : '';
		$bar->add_node(
			array(
				'id'    => 'usmd',
				'title' => '<span class="ab-icon dashicons dashicons-networking" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Site Manager', 'unstoppable-site-manager' ) . '</span>' . $badge,
				'href'  => $page( USMD_Admin::SLUG ),
				'meta'  => array(
					/* translators: 1: number of sites, 2: number of updates. */
					'title' => sprintf( __( '%1$d sites, %2$d updates waiting', 'unstoppable-site-manager' ), $c['sites'], $c['updates'] ),
				),
			)
		);
		$items = array(
			/* translators: %d: number of sites. */
			'usmd-sites'    => array( sprintf( _n( 'Sites (%d)', 'Sites (%d)', $c['sites'], 'unstoppable-site-manager' ), $c['sites'] ), $page( USMD_Admin::SLUG ) ),
		);
		if ( $c['problems'] ) {
			/* translators: %d: number of sites. */
			$items['usmd-problems'] = array( sprintf( _n( '%d site needs attention', '%d sites need attention', $c['problems'], 'unstoppable-site-manager' ), $c['problems'] ), $page( USMD_Admin::SLUG, array( 'filter' => 'problem' ) ) );
		}
		if ( $c['updates'] ) {
			/* translators: %d: number of updates. */
			$items['usmd-updates'] = array( sprintf( _n( '%d update waiting', '%d updates waiting', $c['updates'], 'unstoppable-site-manager' ), $c['updates'] ), $page( USMD_Admin::SLUG, array( 'filter' => 'updates' ) ) );
		}
		$items += array(
			'usmd-plugins'  => array( __( 'Plugins', 'unstoppable-site-manager' ), $page( 'usmd-plugins' ) ),
			'usmd-clients'  => array( __( 'Clients', 'unstoppable-site-manager' ), $page( 'usmd-clients' ) ),
			'usmd-add'      => array( __( 'Add Site', 'unstoppable-site-manager' ), $page( 'usmd-add' ) ),
			'usmd-reports'  => array( __( 'Reports', 'unstoppable-site-manager' ), $page( 'usmd-reports' ) ),
			'usmd-settings' => array( __( 'Settings', 'unstoppable-site-manager' ), $page( 'usmd-settings' ) ),
		);
		foreach ( $items as $id => $it ) {
			$bar->add_node(
				array(
					'id'     => $id,
					'parent' => 'usmd',
					'title'  => esc_html( $it[0] ),
					'href'   => $it[1],
					'meta'   => 'usmd-problems' === $id ? array( 'class' => 'usmd-ab-bad' ) : array(),
				)
			);
		}
	}

	public static function css() {
		if ( ! self::enabled() || ! is_admin_bar_showing() ) {
			return;
		}
		?>
		<style id="usmd-toolbar-css">
			#wpadminbar #wp-admin-bar-usmd > .ab-item .ab-icon:before { content: "\f325"; top: 2px; }
			#wpadminbar .usmd-ab-count { display: inline-block; min-width: 18px; height: 18px; margin-left: 4px; padding: 0 5px; border-radius: 9px; background: #d63638; color: #fff; font-size: 11px; line-height: 18px; text-align: center; vertical-align: 1px; box-sizing: border-box; }
			#wpadminbar .usmd-ab-bad > .ab-item { color: #f0b849 !important; }
			@media screen and (max-width: 782px) { #wpadminbar #wp-admin-bar-usmd { display: block; } #wpadminbar #wp-admin-bar-usmd .ab-label { display: none; } #wpadminbar #wp-admin-bar-usmd > .ab-item .ab-icon:before { top: 6px; } }
		</style>
		<?php
	}
}
