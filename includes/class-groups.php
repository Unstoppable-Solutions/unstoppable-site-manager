<?php
/**
 * Groups: sort sites into named, coloured groups ("Churches", "Hosting plan A").
 *
 * Each site is in one group or none. The Sites screen shows Starred first, then
 * one section per group, then sites with no group. Each section can be folded
 * away, and refreshed or updated on its own. "Group by" can also show one
 * section per client, or no sections at all; that choice is kept per user.
 *
 * Groups are only names and colours, kept in one option. A site's group is the
 * group_id column of the sites table.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Groups {

	const OPT     = 'usmd_groups';   // array( id => array( name, color ) )
	const META_BY = 'usmd_group_by'; // user meta: group | client | none
	const MAX     = 100;

	/** Colours offered for new groups, in turn. */
	const COLORS = array( '#2271b1', '#00a32a', '#d63638', '#dba617', '#8c5fc7', '#e26f1f', '#1d9ba5', '#c2185b', '#5b6b7b', '#6f8f2f' );

	public static function hooks() {
		add_action( 'admin_post_usmd_groups_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_usmd_site_group', array( __CLASS__, 'handle_site_group' ) );
		add_action( 'wp_ajax_usmd_group_move', array( __CLASS__, 'ajax_move' ) );
		add_action( 'wp_ajax_usmd_group_by', array( __CLASS__, 'ajax_group_by' ) );
	}

	/** @var array|null Groups loaded in this request. */
	private static $cache = null;

	/* ---------- reads ---------- */

	/** @return array id => array( 'name' => string, 'color' => '#rrggbb' ), by name. */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$g   = get_option( self::OPT );
		$out = array();
		foreach ( is_array( $g ) ? $g : array() as $id => $row ) {
			if ( (int) $id > 0 && is_array( $row ) && isset( $row['name'] ) && '' !== (string) $row['name'] ) {
				$out[ (int) $id ] = array(
					'name'  => (string) $row['name'],
					'color' => sanitize_hex_color( isset( $row['color'] ) ? $row['color'] : '' ) ? $row['color'] : self::COLORS[0],
				);
			}
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		self::$cache = $out;
		return $out;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/** id => number of sites in it. */
	public static function counts() {
		$n = array();
		foreach ( USMD_Sites::all() as $s ) {
			$g       = (int) $s->group_id;
			$n[ $g ] = isset( $n[ $g ] ) ? $n[ $g ] + 1 : 1;
		}
		return $n;
	}

	/** How the Sites screen is split up for this user: group, client or none. */
	public static function group_by() {
		$v = (string) get_user_meta( get_current_user_id(), self::META_BY, true ); // Cached by WordPress.
		if ( in_array( $v, array( 'group', 'client', 'none' ), true ) ) {
			return $v;
		}
		return self::all() ? 'group' : 'none';
	}

	/**
	 * The section a site's card goes in, for this user's "Group by":
	 * "g<id>", "c<id>" (0 = none of them), or "all".
	 */
	public static function section_of( $site ) {
		switch ( self::group_by() ) {
			case 'group':
				$id = (int) $site->group_id;
				return 'g' . ( $id && self::get( $id ) ? $id : 0 );
			case 'client':
				$id = (int) $site->client_id;
				return 'c' . ( $id && USMD_Clients::get( $id ) ? $id : 0 );
		}
		return 'all';
	}

	/**
	 * The sections of the Sites screen below Starred, in order: key, title, colour.
	 *
	 * @return array[]
	 */
	public static function sections() {
		$out = array();
		switch ( self::group_by() ) {
			case 'group':
				foreach ( self::all() as $id => $g ) {
					$out[] = array( 'key' => 'g' . $id, 'title' => $g['name'], 'color' => $g['color'], 'group' => $id );
				}
				$out[] = array( 'key' => 'g0', 'title' => __( 'No group', 'unstoppable-site-manager' ), 'color' => '', 'group' => 0 );
				break;
			case 'client':
				foreach ( USMD_Clients::names() as $id => $name ) {
					$out[] = array( 'key' => 'c' . $id, 'title' => $name, 'color' => '', 'group' => null );
				}
				$out[] = array( 'key' => 'c0', 'title' => __( 'No client', 'unstoppable-site-manager' ), 'color' => '', 'group' => null );
				break;
			default:
				$out[] = array( 'key' => 'all', 'title' => __( 'All other sites', 'unstoppable-site-manager' ), 'color' => '', 'group' => null );
		}
		return $out;
	}

	/** A <select> of groups. */
	public static function select( $name, $selected, $none_label, $attrs = '' ) {
		$out = '<select name="' . esc_attr( $name ) . '"' . $attrs . '><option value="0">' . esc_html( $none_label ) . '</option>';
		foreach ( self::all() as $id => $g ) {
			$out .= '<option value="' . (int) $id . '"' . selected( (int) $selected, $id, false ) . '>' . esc_html( $g['name'] ) . '</option>';
		}
		return $out . '</select>';
	}

	/** A small coloured dot. */
	public static function dot( $color ) {
		return $color ? '<span class="usmd-group-dot" style="background:' . esc_attr( $color ) . '" aria-hidden="true"></span>' : '';
	}

	/* ---------- writes ---------- */

	private static function store( array $groups ) {
		update_option( self::OPT, $groups, false );
		self::$cache = null;
	}

	/** Add a group and return its id (an existing group of that name is reused). */
	public static function create( $name, $color = '' ) {
		$name = substr( trim( sanitize_text_field( $name ) ), 0, 80 );
		if ( '' === $name ) {
			return 0;
		}
		$all = self::all();
		foreach ( $all as $id => $g ) {
			if ( 0 === strcasecmp( $g['name'], $name ) ) {
				return $id;
			}
		}
		if ( count( $all ) >= self::MAX ) {
			return 0;
		}
		$id          = $all ? max( array_keys( $all ) ) + 1 : 1;
		$color       = sanitize_hex_color( $color );
		$all[ $id ]  = array(
			'name'  => $name,
			'color' => $color ? $color : self::COLORS[ ( $id - 1 ) % count( self::COLORS ) ],
		);
		self::store( $all );
		return $id;
	}

	/** Put sites in a group (0 = no group). */
	public static function assign( array $site_ids, $group_id ) {
		global $wpdb;
		$group_id = (int) $group_id;
		if ( $group_id && ! self::get( $group_id ) ) {
			$group_id = 0;
		}
		foreach ( array_map( 'absint', $site_ids ) as $sid ) {
			if ( $sid ) {
				$wpdb->update( USMD_Sites::t(), array( 'group_id' => $group_id ), array( 'id' => $sid ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
			}
		}
	}

	private static function guard( $action ) {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( $action );
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => USMD_Admin::SLUG, 'view' => 'groups' ), $args ), admin_url( 'admin.php' ) );
	}

	/** The Groups screen: rename, recolour, delete, and add. */
	public static function handle_save() {
		global $wpdb;
		self::guard( 'usmd_groups_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$names  = isset( $_POST['name'] ) && is_array( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each one sanitised below.
		$colors = isset( $_POST['color'] ) && is_array( $_POST['color'] ) ? wp_unslash( $_POST['color'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each one sanitised below.
		$delete = isset( $_POST['delete'] ) ? absint( $_POST['delete'] ) : 0;
		$new    = isset( $_POST['new_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_name'] ) ) : '';
		$newc   = isset( $_POST['new_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['new_color'] ) ) : '';
		// phpcs:enable
		$all = self::all();
		if ( $delete && isset( $all[ $delete ] ) ) {
			unset( $all[ $delete ] );
			self::store( $all );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
			$wpdb->update( USMD_Sites::t(), array( 'group_id' => 0 ), array( 'group_id' => $delete ), array( '%d' ), array( '%d' ) );
			wp_safe_redirect( self::page_url( array( 'msg' => 'deleted' ) ) );
			exit;
		}
		foreach ( $all as $id => $g ) {
			$n = isset( $names[ $id ] ) ? substr( trim( sanitize_text_field( (string) $names[ $id ] ) ), 0, 80 ) : '';
			$c = isset( $colors[ $id ] ) ? sanitize_hex_color( (string) $colors[ $id ] ) : '';
			if ( '' !== $n ) {
				$all[ $id ]['name'] = $n;
			}
			if ( $c ) {
				$all[ $id ]['color'] = $c;
			}
		}
		self::store( $all );
		if ( '' !== trim( $new ) ) {
			self::create( $new, $newc );
		}
		wp_safe_redirect( self::page_url( array( 'msg' => 'saved' ) ) );
		exit;
	}

	/** The Group box on a site page: pick a group or start a new one. */
	public static function handle_site_group() {
		self::guard( 'usmd_site_group' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$site = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 );
		if ( ! $site ) {
			wp_die( esc_html__( 'Site not found.', 'unstoppable-site-manager' ) );
		}
		$gid = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
		$new = isset( $_POST['new_group'] ) ? sanitize_text_field( wp_unslash( $_POST['new_group'] ) ) : '';
		// phpcs:enable
		if ( '' !== trim( $new ) ) {
			$gid = self::create( $new );
		}
		self::assign( array( $site->id ), $gid );
		wp_safe_redirect( USMD_Admin::site_url( $site->id, array( 'group' => 'saved' ) ) . '#usmd-group' );
		exit;
	}

	/** Sites screen: move the ticked sites into a group. Answers with their new cards. */
	public static function ajax_move() {
		check_ajax_referer( 'usmd', 'nonce' );
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'unstoppable-site-manager' ) ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$ids = isset( $_POST['sites'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['sites'] ) ) ) ) ) : array();
		$gid = isset( $_POST['group'] ) ? absint( $_POST['group'] ) : 0;
		$new = isset( $_POST['new_group'] ) ? sanitize_text_field( wp_unslash( $_POST['new_group'] ) ) : '';
		// phpcs:enable
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'No sites chosen.', 'unstoppable-site-manager' ) ) );
		}
		if ( '' !== trim( $new ) ) {
			$gid = self::create( $new );
			if ( ! $gid ) {
				wp_send_json_error( array( 'message' => __( 'Could not add that group.', 'unstoppable-site-manager' ) ) );
			}
		}
		self::assign( $ids, $gid );
		wp_send_json_success( array( 'moved' => count( $ids ) ) );
	}

	/** Remember this user's "Group by". */
	public static function ajax_group_by() {
		check_ajax_referer( 'usmd', 'nonce' );
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$v = isset( $_POST['by'] ) ? sanitize_key( wp_unslash( $_POST['by'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( ! in_array( $v, array( 'group', 'client', 'none' ), true ) ) {
			wp_send_json_error();
		}
		update_user_meta( get_current_user_id(), self::META_BY, $v );
		wp_send_json_success();
	}

	/* ---------- screens ---------- */

	/** The Group box on a site page. */
	public static function site_panel( $site ) {
		$g = self::get( $site->group_id );
		?>
		<div class="usmd-panel usmd-group-panel" id="usmd-group">
			<h2><?php esc_html_e( 'Group', 'unstoppable-site-manager' ); ?>
				<?php if ( $g ) : ?>
					<span class="usmd-group-tag"><?php echo self::dot( $g['color'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in dot(). ?><?php echo esc_html( $g['name'] ); ?></span>
				<?php endif; ?>
			</h2>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			if ( isset( $_GET['group'] ) && 'saved' === $_GET['group'] ) : ?>
				<p class="usmd-saved-note"><?php esc_html_e( 'Saved.', 'unstoppable-site-manager' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-group-form">
				<input type="hidden" name="action" value="usmd_site_group">
				<input type="hidden" name="site" value="<?php echo (int) $site->id; ?>">
				<?php wp_nonce_field( 'usmd_site_group' ); ?>
				<label for="usmd-site-group" class="screen-reader-text"><?php esc_html_e( 'Group', 'unstoppable-site-manager' ); ?></label>
				<?php echo self::select( 'group_id', (int) $site->group_id, __( 'No group', 'unstoppable-site-manager' ), ' id="usmd-site-group" class="widefat"' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in select(). ?>
				<div class="usmd-inline">
					<label for="usmd-new-group" class="screen-reader-text"><?php esc_html_e( 'New group name', 'unstoppable-site-manager' ); ?></label>
					<input type="text" id="usmd-new-group" name="new_group" maxlength="80" placeholder="<?php esc_attr_e( 'or new group name', 'unstoppable-site-manager' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Set', 'unstoppable-site-manager' ); ?></button>
				</div>
				<p class="usmd-muted"><a href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Manage groups', 'unstoppable-site-manager' ); ?></a></p>
			</form>
		</div>
		<?php
	}

	/** Groups screen. */
	public static function page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$msg    = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$all    = self::all();
		$counts = self::counts();
		$next   = self::COLORS[ count( $all ) % count( self::COLORS ) ];
		?>
		<div class="wrap usmd-wrap usmd-groups">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . USMD_Admin::SLUG ) ); ?>">← <?php esc_html_e( 'All sites', 'unstoppable-site-manager' ); ?></a></p>
			<h1><?php esc_html_e( 'Groups', 'unstoppable-site-manager' ); ?></h1>
			<p class="usmd-muted"><?php esc_html_e( 'Sort sites into groups, such as "Churches" or "Hosting plan A". The Sites screen shows each group as its own section, which you can fold away, refresh or update on its own. A site is in one group or none. Put sites in a group from their site page, or tick several on the Sites screen with "Select sites".', 'unstoppable-site-manager' ); ?></p>
			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Groups saved.', 'unstoppable-site-manager' ); ?></p></div>
			<?php elseif ( 'deleted' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Group deleted. Its sites are still here, with no group.', 'unstoppable-site-manager' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="usmd_groups_save">
				<?php wp_nonce_field( 'usmd_groups_save' ); ?>
				<table class="widefat striped usmd-table usmd-groups-table">
					<thead><tr><th><?php esc_html_e( 'Colour', 'unstoppable-site-manager' ); ?></th><th><?php esc_html_e( 'Name', 'unstoppable-site-manager' ); ?></th><th><?php esc_html_e( 'Sites', 'unstoppable-site-manager' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $all as $id => $g ) : ?>
							<tr>
								<td><input type="color" name="color[<?php echo (int) $id; ?>]" value="<?php echo esc_attr( $g['color'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: group name. */ __( 'Colour of %s', 'unstoppable-site-manager' ), $g['name'] ) ); ?>"></td>
								<td><input type="text" class="regular-text" name="name[<?php echo (int) $id; ?>]" value="<?php echo esc_attr( $g['name'] ); ?>" maxlength="80" aria-label="<?php esc_attr_e( 'Group name', 'unstoppable-site-manager' ); ?>"></td>
								<td><?php echo (int) ( isset( $counts[ $id ] ) ? $counts[ $id ] : 0 ); ?></td>
								<td><button type="submit" class="button-link usmd-link-danger" name="delete" value="<?php echo (int) $id; ?>" data-ask="<?php echo esc_attr( sprintf( /* translators: %s: group name. */ __( 'Delete the group "%s"? Its sites stay, with no group.', 'unstoppable-site-manager' ), $g['name'] ) ); ?>"><?php esc_html_e( 'Delete', 'unstoppable-site-manager' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
						<tr class="usmd-groups-new">
							<td><input type="color" name="new_color" value="<?php echo esc_attr( $next ); ?>" aria-label="<?php esc_attr_e( 'Colour of the new group', 'unstoppable-site-manager' ); ?>"></td>
							<td><input type="text" class="regular-text" name="new_name" maxlength="80" placeholder="<?php esc_attr_e( 'New group name', 'unstoppable-site-manager' ); ?>"<?php echo $all ? '' : ' autofocus'; ?>></td>
							<td colspan="2"><span class="usmd-muted"><?php esc_html_e( 'Fill in to add a group.', 'unstoppable-site-manager' ); ?></span></td>
						</tr>
					</tbody>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save groups', 'unstoppable-site-manager' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
