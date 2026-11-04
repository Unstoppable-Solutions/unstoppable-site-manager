<?php
/**
 * Users and Posts & Pages across all sites (read-only).
 *
 * Built from the reports sites already send (connector 0.12.0 and later), so
 * these screens make no extra requests to client sites. Sites share staff
 * accounts (anyone who can write posts) and their 40 most recently changed
 * posts and pages; subscribers and shop customers are only counted. A site's
 * admin can stop sharing under Tools > Unstoppable Site Connector > Security.
 *
 * A new administrator on any site is logged on that site's activity, flagged on
 * its card and listed in the daily email for a week.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Content {

	const USERS   = 'usmd-users';
	const POSTS   = 'usmd-content';
	const NEW_FOR = 7 * DAY_IN_SECONDS; // How long a new administrator is flagged.
	const MIN_CON = '0.12.0';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
	}

	public static function menu() {
		add_submenu_page( USMD_Admin::SLUG, __( 'Users across sites', 'unstoppable-site-manager' ), __( 'Users', 'unstoppable-site-manager' ), USMD_Security::CAP, self::USERS, array( __CLASS__, 'page_users' ), 3 );
		add_submenu_page( USMD_Admin::SLUG, __( 'Posts and pages across sites', 'unstoppable-site-manager' ), __( 'Posts & Pages', 'unstoppable-site-manager' ), USMD_Security::CAP, self::POSTS, array( __CLASS__, 'page_posts' ), 4 );
	}

	/* ---------- data ---------- */

	/** A site's shared content, or null when it doesn't share (old connector, or switched off there). */
	public static function of( $site ) {
		$st = USMD_Sites::status( $site );
		return ( isset( $st['content']['shared'] ) && true === $st['content']['shared'] ) ? $st['content'] : null;
	}

	private static function str( $v, $len = 200 ) {
		return is_scalar( $v ) ? substr( (string) $v, 0, $len ) : '';
	}

	/** Logins of a content block's administrators. */
	public static function admin_logins( $content ) {
		$out = array();
		foreach ( isset( $content['users'] ) && is_array( $content['users'] ) ? $content['users'] : array() as $u ) {
			if ( is_array( $u ) && isset( $u['roles'] ) && is_array( $u['roles'] ) && in_array( 'administrator', $u['roles'], true ) && isset( $u['login'] ) && is_scalar( $u['login'] ) ) {
				$out[] = (string) $u['login'];
			}
		}
		return $out;
	}

	/**
	 * Called with each new report: log administrators that weren't there before.
	 * Nothing is flagged on a site's first report with this data.
	 */
	public static function watch_admins( $site_id, $old_status, $new_status ) {
		$before = ( isset( $old_status['content']['shared'] ) && true === $old_status['content']['shared'] ) ? $old_status['content'] : null;
		$after  = ( isset( $new_status['content']['shared'] ) && true === $new_status['content']['shared'] ) ? $new_status['content'] : null;
		if ( ! $before || ! $after ) {
			return;
		}
		$added = array_diff( self::admin_logins( $after ), self::admin_logins( $before ) );
		foreach ( $added as $login ) {
			$u = null;
			foreach ( $after['users'] as $cand ) {
				if ( isset( $cand['login'] ) && (string) $cand['login'] === $login ) {
					$u = $cand;
					break;
				}
			}
			USMD_Sites::add_local_activity(
				$site_id,
				'security',
				'new_admin',
				self::str( $login, 60 ),
				array(
					'name'  => self::str( isset( $u['name'] ) ? $u['name'] : '' ),
					'email' => self::str( isset( $u['email'] ) ? $u['email'] : '' ),
				)
			);
		}
	}

	/** @var array|null site id => array of [ login, time ] for administrators added in the last week. */
	private static $new_admins = null;

	public static function new_admins() {
		if ( null === self::$new_admins ) {
			global $wpdb;
			self::$new_admins = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table; one small query per request.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT site_id, object, created_at FROM %i WHERE type = %s AND action = %s AND created_at >= %s ORDER BY created_at DESC', USMD_Sites::ta(), 'security', 'new_admin', gmdate( 'Y-m-d H:i:s', time() - self::NEW_FOR ) ) );
			foreach ( $rows as $r ) {
				self::$new_admins[ (int) $r->site_id ][] = array( (string) $r->object, strtotime( $r->created_at . ' UTC' ) );
			}
		}
		return self::$new_admins;
	}

	/** "New admin" on a site's card. */
	public static function pill( $site ) {
		$n = self::new_admins();
		if ( empty( $n[ (int) $site->id ] ) ) {
			return '';
		}
		$names = implode( ', ', array_map( static function ( $x ) { return $x[0]; }, $n[ (int) $site->id ] ) );
		/* translators: %s: user names. */
		$title = sprintf( __( 'New administrator this week: %s', 'unstoppable-site-manager' ), $names );
		return '<a class="usmd-pill usmd-pill-bad usmd-pill-admin" href="' . esc_url( add_query_arg( array( 'page' => self::USERS, 'site' => (int) $site->id, 'role' => 'administrator' ), admin_url( 'admin.php' ) ) ) . '" title="' . esc_attr( $title ) . '">' . esc_html__( 'New admin', 'unstoppable-site-manager' ) . '</a>';
	}

	/** Links from a site's page to its users and posts. */
	public static function site_links( $site ) {
		$c = self::of( $site );
		if ( ! $c ) {
			return;
		}
		$staff = isset( $c['users'] ) && is_array( $c['users'] ) ? count( $c['users'] ) : 0;
		$posts = 0;
		foreach ( array( 'post', 'page' ) as $t ) {
			$posts += isset( $c['posts'][ $t ]['publish'] ) ? (int) $c['posts'][ $t ]['publish'] : 0;
		}
		echo '<p class="usmd-content-links">'
			. '<a href="' . esc_url( add_query_arg( array( 'page' => self::USERS, 'site' => (int) $site->id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( sprintf( /* translators: %d: number of accounts. */ _n( '%d staff account', '%d staff accounts', $staff, 'unstoppable-site-manager' ), $staff ) ) . '</a>'
			. ' · <a href="' . esc_url( add_query_arg( array( 'page' => self::POSTS, 'site' => (int) $site->id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( sprintf( /* translators: %d: number of posts and pages. */ _n( '%d published post or page', '%d published posts and pages', $posts, 'unstoppable-site-manager' ), $posts ) ) . '</a>'
			. '</p>';
	}

	/* ---------- screens ---------- */

	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return array(
			's'      => isset( $_GET['s'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '',
			'role'   => isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : '',
			'type'   => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'new'    => ! empty( $_GET['new'] ),
			'site'   => isset( $_GET['site'] ) ? absint( $_GET['site'] ) : 0,
			'client' => isset( $_GET['client'] ) ? absint( $_GET['client'] ) : 0,
			'group'  => isset( $_GET['group'] ) ? absint( $_GET['group'] ) : 0,
		);
		// phpcs:enable
	}

	/** Is this site within the chosen site, client and group? */
	private static function in_scope( $site, array $f ) {
		return ( ! $f['site'] || (int) $site->id === $f['site'] )
			&& ( ! $f['client'] || (int) $site->client_id === $f['client'] )
			&& ( ! $f['group'] || (int) $site->group_id === $f['group'] );
	}

	/** Site, client and group pickers for the filter bar. */
	private static function scope_fields( array $sites, array $f ) {
		$list = array();
		foreach ( $sites as $s ) {
			if ( self::of( $s ) ) {
				$list[ (int) $s->id ] = $s->name;
			}
		}
		natcasesort( $list );
		?>
		<label class="screen-reader-text" for="usmd-content-site"><?php esc_html_e( 'Site', 'unstoppable-site-manager' ); ?></label>
		<select name="site" id="usmd-content-site">
			<option value="0"><?php esc_html_e( 'All sites', 'unstoppable-site-manager' ); ?></option>
			<?php foreach ( $list as $id => $name ) : ?>
				<option value="<?php echo (int) $id; ?>" <?php selected( $f['site'], $id ); ?>><?php echo esc_html( $name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( USMD_Clients::names() ) : ?>
			<label class="screen-reader-text" for="usmd-content-client"><?php esc_html_e( 'Client', 'unstoppable-site-manager' ); ?></label>
			<?php echo USMD_Clients::select( 'client', $f['client'], __( 'All clients', 'unstoppable-site-manager' ), ' id="usmd-content-client"' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in select(). ?>
		<?php endif; ?>
		<?php if ( USMD_Groups::all() ) : ?>
			<label class="screen-reader-text" for="usmd-content-group"><?php esc_html_e( 'Group', 'unstoppable-site-manager' ); ?></label>
			<?php echo USMD_Groups::select( 'group', $f['group'], __( 'All groups', 'unstoppable-site-manager' ), ' id="usmd-content-group"' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in select(). ?>
		<?php endif; ?>
		<?php
	}

	/** Has any filter been chosen? */
	private static function filtered( array $f ) {
		return $f['s'] || $f['role'] || $f['new'] || $f['type'] || $f['status'] || $f['site'] || $f['client'] || $f['group'];
	}

	private static function site_matches( $site, $q ) {
		return '' === $q || false !== strpos( strtolower( $site->name . ' ' . $site->url ), $q );
	}

	private static function ago( $t ) {
		$t = (int) $t;
		if ( $t <= 0 ) {
			return '—';
		}
		/* translators: %s: time, e.g. "5 days". */
		return $t > time() ? sprintf( __( 'in %s', 'unstoppable-site-manager' ), human_time_diff( $t ) ) : human_time_diff( $t ) . ' ' . __( 'ago', 'unstoppable-site-manager' );
	}

	/** A link that opens a page in a site's wp-admin, already signed in. */
	private static function admin_link( $site, $path, $label ) {
		return '<a class="usmd-login button button-small" data-site="' . (int) $site->id . '" data-redirect="' . esc_attr( $path ) . '" href="' . esc_url( trailingslashit( $site->url ) . 'wp-admin/' . $path ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . ' ↗</a>';
	}

	/** Sites that can't show this yet, for the note under each screen. */
	private static function not_sharing( array $sites ) {
		$old = 0;
		$off = 0;
		foreach ( $sites as $s ) {
			$st = USMD_Sites::status( $s );
			if ( isset( $st['content']['shared'] ) && false === $st['content']['shared'] ) {
				++$off;
			} elseif ( ! isset( $st['content'] ) ) {
				++$old;
			}
		}
		return array( $old, $off );
	}

	private static function sharing_note( array $sites ) {
		list( $old, $off ) = self::not_sharing( $sites );
		if ( ! $old && ! $off ) {
			return;
		}
		echo '<p class="usmd-muted usmd-content-note">';
		if ( $old ) {
			/* translators: 1: number of sites, 2: connector version. */
			echo esc_html( sprintf( _n( '%1$d site isn\'t shown yet: update its Unstoppable Site Connector to %2$s or later, then refresh it.', '%1$d sites aren\'t shown yet: update their Unstoppable Site Connector to %2$s or later, then refresh them.', $old, 'unstoppable-site-manager' ), $old, self::MIN_CON ) ) . ' ';
		}
		if ( $off ) {
			/* translators: %d: number of sites. */
			echo esc_html( sprintf( _n( '%d site\'s administrator has switched off sharing this with the dashboard.', '%d sites\' administrators have switched off sharing this with the dashboard.', $off, 'unstoppable-site-manager' ), $off ) );
		}
		echo '</p>';
	}

	private static function role_label( $role ) {
		$names = array(
			'administrator' => __( 'Administrator', 'unstoppable-site-manager' ),
			'editor'        => __( 'Editor', 'unstoppable-site-manager' ),
			'author'        => __( 'Author', 'unstoppable-site-manager' ),
			'contributor'   => __( 'Contributor', 'unstoppable-site-manager' ),
			'shop_manager'  => __( 'Shop manager', 'unstoppable-site-manager' ),
		);
		return isset( $names[ $role ] ) ? $names[ $role ] : ucwords( str_replace( array( '_', '-' ), ' ', $role ) );
	}

	public static function page_users() {
		$f      = self::filters();
		$sites  = USMD_Sites::all();
		$rows   = array();
		$roles  = array();
		$admins = 0;
		$admin_sites = array();
		$fresh  = 0;
		$others = 0;
		$news   = self::new_admins();
		foreach ( $sites as $site ) {
			$c = self::of( $site );
			if ( ! $c || ! self::in_scope( $site, $f ) ) {
				continue;
			}
			foreach ( isset( $c['roles'] ) && is_array( $c['roles'] ) ? $c['roles'] : array() as $r => $n ) {
				if ( in_array( (string) $r, array( 'subscriber', 'customer' ), true ) ) {
					$others += (int) $n;
				}
			}
			$new_here = isset( $news[ (int) $site->id ] ) ? array_map( static function ( $x ) { return $x[0]; }, $news[ (int) $site->id ] ) : array();
			foreach ( isset( $c['users'] ) && is_array( $c['users'] ) ? $c['users'] : array() as $u ) {
				if ( ! is_array( $u ) || empty( $u['login'] ) ) {
					continue;
				}
				$ur = isset( $u['roles'] ) && is_array( $u['roles'] ) ? array_map( 'strval', $u['roles'] ) : array();
				foreach ( $ur as $r ) {
					$roles[ $r ] = isset( $roles[ $r ] ) ? $roles[ $r ] + 1 : 1;
				}
				$is_admin = in_array( 'administrator', $ur, true );
				$is_new   = $is_admin && in_array( (string) $u['login'], $new_here, true );
				if ( $is_admin ) {
					++$admins;
					$admin_sites[ $site->id ] = true;
				}
				$fresh += $is_new ? 1 : 0;
				if ( ! self::site_matches( $site, $f['s'] ) && false === strpos( strtolower( self::str( $u['login'] ) . ' ' . self::str( isset( $u['name'] ) ? $u['name'] : '' ) . ' ' . self::str( isset( $u['email'] ) ? $u['email'] : '' ) ), $f['s'] ) ) {
					continue;
				}
				if ( $f['role'] && ! in_array( $f['role'], $ur, true ) ) {
					continue;
				}
				if ( $f['new'] && ! $is_new ) {
					continue;
				}
				$rows[] = array( $site, $u, $ur, $is_new );
			}
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( ! $a[3], - (int) ( isset( $a[1]['registered'] ) ? $a[1]['registered'] : 0 ) ) <=> array( ! $b[3], - (int) ( isset( $b[1]['registered'] ) ? $b[1]['registered'] : 0 ) );
			}
		);
		$total = count( $rows );
		$rows  = array_slice( $rows, 0, 1000 );
		ksort( $roles );
		$base  = admin_url( 'admin.php?page=' . self::USERS );
		$scope = array_filter( array( 'site' => $f['site'], 'client' => $f['client'], 'group' => $f['group'] ) );
		$base  = $scope ? add_query_arg( $scope, $base ) : $base;
		?>
		<div class="wrap usmd-wrap usmd-content-screen">
			<h1><?php esc_html_e( 'Users across sites', 'unstoppable-site-manager' ); ?></h1>
			<p class="usmd-muted"><?php esc_html_e( 'Everyone who can write or manage content on your sites: administrators, editors, authors and the like. Subscribers and shop customers are only counted. A new administrator on any site is flagged here, on the site\'s card and in the daily email for a week.', 'unstoppable-site-manager' ); ?></p>
			<div class="usmd-stats usmd-content-stats">
				<a class="usmd-stat<?php echo 'administrator' === $f['role'] && ! $f['new'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'role', 'administrator', $base ) ); ?>">
					<strong><?php echo (int) $admins; ?></strong><span><?php esc_html_e( 'Administrators', 'unstoppable-site-manager' ); ?></span>
					<small><?php echo esc_html( sprintf( /* translators: %d: number of sites. */ _n( 'on %d site', 'on %d sites', count( $admin_sites ), 'unstoppable-site-manager' ), count( $admin_sites ) ) ); ?></small>
				</a>
				<a class="usmd-stat<?php echo $fresh ? ' is-bad' : ''; ?><?php echo $f['new'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'new', 1, $base ) ); ?>">
					<strong><?php echo (int) $fresh; ?></strong><span><?php esc_html_e( 'New administrators', 'unstoppable-site-manager' ); ?></span><small><?php esc_html_e( 'in the last 7 days', 'unstoppable-site-manager' ); ?></small>
				</a>
				<a class="usmd-stat" href="<?php echo esc_url( $base ); ?>">
					<strong><?php echo (int) array_sum( $roles ); ?></strong><span><?php esc_html_e( 'Staff accounts', 'unstoppable-site-manager' ); ?></span>
				</a>
				<div class="usmd-stat">
					<strong><?php echo esc_html( number_format_i18n( $others ) ); ?></strong><span><?php esc_html_e( 'Subscribers and customers', 'unstoppable-site-manager' ); ?></span><small><?php esc_html_e( 'counted, not listed', 'unstoppable-site-manager' ); ?></small>
				</div>
			</div>
			<form method="get" class="usmd-content-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::USERS ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" placeholder="<?php esc_attr_e( 'Search names and emails…', 'unstoppable-site-manager' ); ?>" class="regular-text">
				<?php self::scope_fields( $sites, $f ); ?>
				<select name="role">
					<option value=""><?php esc_html_e( 'All roles', 'unstoppable-site-manager' ); ?></option>
					<?php foreach ( $roles as $r => $n ) : ?>
						<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $f['role'], $r ); ?>><?php echo esc_html( self::role_label( $r ) . ' (' . $n . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><input type="checkbox" name="new" value="1" <?php checked( $f['new'] ); ?>> <?php esc_html_e( 'New administrators only', 'unstoppable-site-manager' ); ?></label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'unstoppable-site-manager' ); ?></button>
				<?php if ( self::filtered( $f ) ) : ?><a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'unstoppable-site-manager' ); ?></a><?php endif; ?>
			</form>
			<table class="widefat striped usmd-table usmd-content-table">
				<thead><tr>
					<th><?php esc_html_e( 'Site', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'User', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Email', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Role', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Added', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Last sign-in', 'unstoppable-site-manager' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7" class="usmd-muted"><?php esc_html_e( 'No users match.', 'unstoppable-site-manager' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php list( $site, $u, $ur, $is_new ) = $row; ?>
					<tr class="<?php echo $is_new ? 'is-new-admin' : ''; ?>">
						<td><a href="<?php echo esc_url( USMD_Admin::site_url( $site->id ) ); ?>"><?php echo esc_html( $site->name ); ?></a></td>
						<td>
							<strong><?php echo esc_html( self::str( isset( $u['name'] ) ? $u['name'] : '' ) ); ?></strong>
							<?php if ( $is_new ) : ?><span class="usmd-pill usmd-pill-bad"><?php esc_html_e( 'New admin', 'unstoppable-site-manager' ); ?></span><?php endif; ?>
							<br><span class="usmd-muted"><?php echo esc_html( self::str( $u['login'], 60 ) ); ?></span>
						</td>
						<td><?php echo esc_html( self::str( isset( $u['email'] ) ? $u['email'] : '' ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_map( array( __CLASS__, 'role_label' ), $ur ) ) ); ?></td>
						<td><?php echo esc_html( ! empty( $u['registered'] ) ? wp_date( get_option( 'date_format' ), (int) $u['registered'] ) : '—' ); ?></td>
						<td><?php echo esc_html( ! empty( $u['last_login'] ) ? self::ago( (int) $u['last_login'] ) : __( 'Not since tracking began', 'unstoppable-site-manager' ) ); ?></td>
						<td><?php echo self::admin_link( $site, 'user-edit.php?user_id=' . (int) ( isset( $u['id'] ) ? $u['id'] : 0 ), __( 'Open', 'unstoppable-site-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in admin_link(). ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $total > count( $rows ) ) : ?>
				<p class="usmd-muted"><?php echo esc_html( sprintf( /* translators: 1: shown, 2: total. */ __( 'Showing %1$d of %2$d. Search or filter to narrow it down.', 'unstoppable-site-manager' ), count( $rows ), $total ) ); ?></p>
			<?php endif; ?>
			<?php self::sharing_note( $sites ); ?>
		</div>
		<?php
	}

	public static function page_posts() {
		$f      = self::filters();
		$sites  = USMD_Sites::all();
		$rows   = array();
		$tot    = array( 'future' => 0, 'pending' => 0, 'draft' => 0, 'week' => 0 );
		$week   = time() - WEEK_IN_SECONDS;
		foreach ( $sites as $site ) {
			$c = self::of( $site );
			if ( ! $c || ! self::in_scope( $site, $f ) ) {
				continue;
			}
			foreach ( array( 'post', 'page' ) as $t ) {
				foreach ( array( 'future', 'pending', 'draft' ) as $st ) {
					$tot[ $st ] += isset( $c['posts'][ $t ][ $st ] ) ? (int) $c['posts'][ $t ][ $st ] : 0;
				}
			}
			foreach ( isset( $c['recent'] ) && is_array( $c['recent'] ) ? $c['recent'] : array() as $p ) {
				if ( ! is_array( $p ) || empty( $p['id'] ) ) {
					continue;
				}
				$tot['week'] += ( ! empty( $p['modified'] ) && (int) $p['modified'] >= $week ) ? 1 : 0;
				$type   = self::str( isset( $p['type'] ) ? $p['type'] : '', 20 );
				$status = self::str( isset( $p['status'] ) ? $p['status'] : '', 20 );
				if ( $f['type'] && $f['type'] !== $type ) {
					continue;
				}
				if ( $f['status'] && $f['status'] !== $status ) {
					continue;
				}
				if ( ! self::site_matches( $site, $f['s'] ) && false === strpos( strtolower( self::str( isset( $p['title'] ) ? $p['title'] : '' ) . ' ' . self::str( isset( $p['author'] ) ? $p['author'] : '' ) ), $f['s'] ) ) {
					continue;
				}
				$rows[] = array( $site, $p, $type, $status );
			}
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) ( isset( $b[1]['modified'] ) ? $b[1]['modified'] : 0 ) <=> (int) ( isset( $a[1]['modified'] ) ? $a[1]['modified'] : 0 );
			}
		);
		$total    = count( $rows );
		$rows     = array_slice( $rows, 0, 500 );
		$statuses = array(
			'publish' => __( 'Published', 'unstoppable-site-manager' ),
			'future'  => __( 'Scheduled', 'unstoppable-site-manager' ),
			'pending' => __( 'Pending review', 'unstoppable-site-manager' ),
			'draft'   => __( 'Draft', 'unstoppable-site-manager' ),
			'private' => __( 'Private', 'unstoppable-site-manager' ),
		);
		$base  = admin_url( 'admin.php?page=' . self::POSTS );
		$scope = array_filter( array( 'site' => $f['site'], 'client' => $f['client'], 'group' => $f['group'] ) );
		$base  = $scope ? add_query_arg( $scope, $base ) : $base;
		?>
		<div class="wrap usmd-wrap usmd-content-screen">
			<h1><?php esc_html_e( 'Posts and pages across sites', 'unstoppable-site-manager' ); ?></h1>
			<p class="usmd-muted"><?php esc_html_e( 'The 40 most recently changed posts and pages on each site, with drafts, scheduled posts and posts waiting for review. Edit opens it in that site\'s editor, already signed in.', 'unstoppable-site-manager' ); ?></p>
			<div class="usmd-stats usmd-content-stats">
				<a class="usmd-stat<?php echo 'future' === $f['status'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'status', 'future', $base ) ); ?>"><strong><?php echo (int) $tot['future']; ?></strong><span><?php esc_html_e( 'Scheduled', 'unstoppable-site-manager' ); ?></span></a>
				<a class="usmd-stat<?php echo $tot['pending'] ? ' has-updates' : ''; ?><?php echo 'pending' === $f['status'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'status', 'pending', $base ) ); ?>"><strong><?php echo (int) $tot['pending']; ?></strong><span><?php esc_html_e( 'Pending review', 'unstoppable-site-manager' ); ?></span></a>
				<a class="usmd-stat<?php echo 'draft' === $f['status'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'status', 'draft', $base ) ); ?>"><strong><?php echo (int) $tot['draft']; ?></strong><span><?php esc_html_e( 'Drafts', 'unstoppable-site-manager' ); ?></span></a>
				<a class="usmd-stat" href="<?php echo esc_url( $base ); ?>"><strong><?php echo (int) $tot['week']; ?></strong><span><?php esc_html_e( 'Changed this week', 'unstoppable-site-manager' ); ?></span></a>
			</div>
			<form method="get" class="usmd-content-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::POSTS ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" placeholder="<?php esc_attr_e( 'Search titles and authors…', 'unstoppable-site-manager' ); ?>" class="regular-text">
				<?php self::scope_fields( $sites, $f ); ?>
				<select name="type">
					<option value=""><?php esc_html_e( 'Posts and pages', 'unstoppable-site-manager' ); ?></option>
					<option value="post" <?php selected( $f['type'], 'post' ); ?>><?php esc_html_e( 'Posts', 'unstoppable-site-manager' ); ?></option>
					<option value="page" <?php selected( $f['type'], 'page' ); ?>><?php esc_html_e( 'Pages', 'unstoppable-site-manager' ); ?></option>
				</select>
				<select name="status">
					<option value=""><?php esc_html_e( 'Any status', 'unstoppable-site-manager' ); ?></option>
					<?php foreach ( $statuses as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['status'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'unstoppable-site-manager' ); ?></button>
				<?php if ( self::filtered( $f ) ) : ?><a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'unstoppable-site-manager' ); ?></a><?php endif; ?>
			</form>
			<table class="widefat striped usmd-table usmd-content-table">
				<thead><tr>
					<th><?php esc_html_e( 'Site', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Title', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Type', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Author', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Last changed', 'unstoppable-site-manager' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7" class="usmd-muted"><?php esc_html_e( 'Nothing matches.', 'unstoppable-site-manager' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php list( $site, $p, $type, $status ) = $row; ?>
					<tr>
						<td><a href="<?php echo esc_url( USMD_Admin::site_url( $site->id ) ); ?>"><?php echo esc_html( $site->name ); ?></a></td>
						<td><strong><?php echo esc_html( '' !== self::str( isset( $p['title'] ) ? $p['title'] : '' ) ? self::str( $p['title'] ) : __( '(no title)', 'unstoppable-site-manager' ) ); ?></strong></td>
						<td><?php echo esc_html( 'page' === $type ? __( 'Page', 'unstoppable-site-manager' ) : __( 'Post', 'unstoppable-site-manager' ) ); ?></td>
						<td><span class="usmd-post-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ); ?></span>
							<?php if ( 'future' === $status && ! empty( $p['date'] ) ) : ?><br><span class="usmd-muted"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $p['date'] ) ); ?></span><?php endif; ?>
						</td>
						<td><?php echo esc_html( self::str( isset( $p['author'] ) ? $p['author'] : '' ) ); ?></td>
						<td><?php echo esc_html( self::ago( isset( $p['modified'] ) ? (int) $p['modified'] : 0 ) ); ?></td>
						<td class="usmd-content-actions">
							<?php echo self::admin_link( $site, 'post.php?post=' . (int) $p['id'] . '&action=edit', __( 'Edit', 'unstoppable-site-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in admin_link(). ?>
							<?php
							$link = isset( $p['link'] ) ? esc_url_raw( (string) $p['link'], array( 'http', 'https' ) ) : '';
							// Only a link on the site itself.
							if ( $link && preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) ) ) !== preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $site->url, PHP_URL_HOST ) ) ) ) {
								$link = '';
							}
							?>
							<?php if ( $link ) : ?><a class="button button-small" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'unstoppable-site-manager' ); ?> ↗</a><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $total > count( $rows ) ) : ?>
				<p class="usmd-muted"><?php echo esc_html( sprintf( /* translators: 1: shown, 2: total. */ __( 'Showing %1$d of %2$d. Search or filter to narrow it down.', 'unstoppable-site-manager' ), count( $rows ), $total ) ); ?></p>
			<?php endif; ?>
			<?php self::sharing_note( $sites ); ?>
		</div>
		<?php
	}
}
