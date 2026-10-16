<?php
/**
 * Clients: group sites under the business they belong to.
 *
 * A client owns any number of sites. On the Sites and Plugins screens you can
 * pick a client to work on just their sites, and each client can override the
 * report branding (name, logo, colour, wording, email sender) from Settings.
 * Empty fields fall back to Settings.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Clients {

	/** Settings a client may override, with their sanitisers. */
	const FIELDS = array(
		'agency_name'       => 'text',
		'logo_url'          => 'url',
		'report_brand'      => 'brand',
		'report_color'      => 'color',
		'report_intro'      => 'textarea',
		'report_contact'    => 'text',
		'report_subject'    => 'text',
		'report_from_name'  => 'text',
		'report_from_email' => 'email',
		'report_reply_to'   => 'email',
	);

	public static function hooks() {
		add_action( 'admin_post_usmd_client_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_usmd_client_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_usmd_site_client', array( __CLASS__, 'handle_site_client' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'usmd_clients';
	}

	/* ---------- reads ---------- */

	/** @return object[] Every client, by name, each with ->settings (array) and ->sites (count). */
	public static function all() {
		global $wpdb;
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', self::table() ) );
		$counts = array();
		foreach ( USMD_Sites::all() as $s ) {
			$cid            = (int) $s->client_id;
			$counts[ $cid ] = isset( $counts[ $cid ] ) ? $counts[ $cid ] + 1 : 1;
		}
		foreach ( $rows as $r ) {
			$r->settings = self::decode( $r->settings );
			$r->sites    = isset( $counts[ (int) $r->id ] ) ? $counts[ (int) $r->id ] : 0;
		}
		$cache = $rows;
		return $rows;
	}

	public static function get( $id ) {
		foreach ( self::all() as $c ) {
			if ( (int) $c->id === (int) $id ) {
				return $c;
			}
		}
		return null;
	}

	/** id => name, for dropdowns. */
	public static function names() {
		$out = array();
		foreach ( self::all() as $c ) {
			$out[ (int) $c->id ] = $c->name;
		}
		return $out;
	}

	private static function decode( $json ) {
		$a = $json ? json_decode( $json, true ) : array();
		return is_array( $a ) ? array_intersect_key( $a, self::FIELDS ) : array();
	}

	/**
	 * Settings for one site: Settings, with its client's overrides on top.
	 *
	 * @param object|null $site
	 */
	public static function settings_for( $site ) {
		$s = USMD_Admin::settings();
		$c = ( $site && ! empty( $site->client_id ) ) ? self::get( $site->client_id ) : null;
		if ( ! $c ) {
			return $s;
		}
		foreach ( $c->settings as $k => $v ) {
			if ( '' !== (string) $v ) {
				$s[ $k ] = $v;
			}
		}
		return $s;
	}

	/* ---------- writes ---------- */

	private static function clean( $type, $v ) {
		$v = is_string( $v ) ? wp_unslash( $v ) : '';
		switch ( $type ) {
			case 'url':
				return esc_url_raw( $v );
			case 'email':
				return (string) sanitize_email( $v );
			case 'color':
				return (string) sanitize_hex_color( $v );
			case 'textarea':
				return sanitize_textarea_field( $v );
			case 'brand':
				return in_array( $v, array( 'both', 'logo', 'name' ), true ) ? $v : '';
			default:
				return sanitize_text_field( $v );
		}
	}

	/** Assign sites to a client (0 = no client). */
	public static function assign( array $site_ids, $client_id ) {
		global $wpdb;
		foreach ( array_map( 'absint', $site_ids ) as $sid ) {
			if ( $sid ) {
				$wpdb->update( USMD_Sites::t(), array( 'client_id' => (int) $client_id ), array( 'id' => $sid ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
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
		return add_query_arg( $args, admin_url( 'admin.php?page=usmd-clients' ) );
	}

	/** Add or edit a client: name, sites and report overrides. */
	public static function handle_save() {
		global $wpdb;
		self::guard( 'usmd_client_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$id   = isset( $_POST['client'] ) ? absint( $_POST['client'] ) : 0;
		$name = isset( $_POST['name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['name'] ) ), 0, 191 ) : '';
		if ( '' === $name ) {
			wp_safe_redirect( self::page_url( array( 'client' => $id ? $id : 'new', 'msg' => 'noname' ) ) );
			exit;
		}
		$settings = array();
		foreach ( self::FIELDS as $k => $type ) {
			$v = isset( $_POST['o'][ $k ] ) ? self::clean( $type, $_POST['o'][ $k ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in clean().
			if ( '' !== $v ) {
				$settings[ $k ] = $v;
			}
		}
		$data = array(
			'name'     => $name,
			'settings' => wp_json_encode( $settings ),
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		if ( $id && self::get( $id ) ) {
			$wpdb->update( self::table(), $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$wpdb->insert( self::table(), $data );
			$id = (int) $wpdb->insert_id;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( isset( $_POST['sites_shown'] ) ) {
			$chosen = isset( $_POST['sites'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['sites'] ) ) : array();
			$was    = array();
			foreach ( USMD_Sites::all() as $s ) {
				if ( (int) $s->client_id === $id ) {
					$was[] = (int) $s->id;
				}
			}
			self::assign( array_diff( $was, $chosen ), 0 );
			self::assign( $chosen, $id );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( self::page_url( array( 'client' => $id, 'msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_delete() {
		global $wpdb;
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( 'usmd_client_delete' );
		$id = isset( $_POST['client'] ) ? absint( $_POST['client'] ) : 0;
		if ( $id ) {
			// Its sites stay; they just no longer belong to a client.
			$wpdb->update( USMD_Sites::t(), array( 'client_id' => 0 ), array( 'client_id' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
			$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		}
		wp_safe_redirect( self::page_url( array( 'msg' => 'deleted' ) ) );
		exit;
	}

	/** The Client box on a site page: pick a client or start a new one. */
	public static function handle_site_client() {
		global $wpdb;
		self::guard( 'usmd_site_client' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$site = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 );
		if ( ! $site ) {
			wp_die( esc_html__( 'Site not found.', 'unstoppable-site-manager' ) );
		}
		$cid = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$new = isset( $_POST['new_client'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['new_client'] ) ), 0, 191 ) : '';
		// phpcs:enable
		if ( '' !== $new ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
				self::table(),
				array(
					'name'       => $new,
					'settings'   => '{}',
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$cid = (int) $wpdb->insert_id;
		} elseif ( $cid && ! self::get( $cid ) ) {
			$cid = 0;
		}
		self::assign( array( $site->id ), $cid );
		wp_safe_redirect( USMD_Admin::site_url( $site->id, array( 'client' => 'saved' ) ) . '#usmd-client' );
		exit;
	}

	/* ---------- screens ---------- */

	/** A <select> of clients. */
	public static function select( $name, $selected, $none_label, $attrs = '' ) {
		$out = '<select name="' . esc_attr( $name ) . '"' . $attrs . '><option value="0">' . esc_html( $none_label ) . '</option>';
		foreach ( self::names() as $id => $n ) {
			$out .= '<option value="' . (int) $id . '"' . selected( (int) $selected, $id, false ) . '>' . esc_html( $n ) . '</option>';
		}
		return $out . '</select>';
	}

	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$edit = isset( $_GET['client'] ) ? sanitize_key( wp_unslash( $_GET['client'] ) ) : '';
		$msg  = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:enable
		$msgs = array(
			'saved'   => array( 'success', __( 'Client saved.', 'unstoppable-site-manager' ) ),
			'deleted' => array( 'success', __( 'Client deleted. Its sites are still here, without a client.', 'unstoppable-site-manager' ) ),
			'noname'  => array( 'error', __( 'Give the client a name.', 'unstoppable-site-manager' ) ),
		);
		echo '<div class="wrap usmd-wrap usmd-clients">';
		if ( isset( $msgs[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $msgs[ $msg ][0] ), esc_html( $msgs[ $msg ][1] ) );
		}
		if ( 'new' === $edit || ( $edit && self::get( absint( $edit ) ) ) ) {
			self::edit_screen( 'new' === $edit ? null : self::get( absint( $edit ) ) );
		} else {
			self::list_screen();
		}
		echo '</div>';
	}

	private static function list_screen() {
		$clients    = self::all();
		$unassigned = 0;
		foreach ( USMD_Sites::all() as $s ) {
			$unassigned += empty( $s->client_id ) ? 1 : 0;
		}
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Clients', 'unstoppable-site-manager' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( self::page_url( array( 'client' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add client', 'unstoppable-site-manager' ); ?></a>
		<hr class="wp-header-end">
		<p class="usmd-muted"><?php esc_html_e( 'Group sites under the client they belong to. Then pick a client on the Sites or Plugins screen to refresh, update or install on just their sites, and give each client their own report branding.', 'unstoppable-site-manager' ); ?></p>
		<?php if ( ! $clients ) : ?>
			<div class="usmd-empty">
				<p><?php esc_html_e( 'No clients yet.', 'unstoppable-site-manager' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( self::page_url( array( 'client' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add your first client', 'unstoppable-site-manager' ); ?></a></p>
			</div>
		<?php else : ?>
			<table class="widefat striped usmd-clients-table">
				<thead><tr>
					<th><?php esc_html_e( 'Client', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Sites', 'unstoppable-site-manager' ); ?></th>
					<th><?php esc_html_e( 'Report branding', 'unstoppable-site-manager' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $clients as $c ) : ?>
					<tr>
						<td><a class="row-title" href="<?php echo esc_url( self::page_url( array( 'client' => (int) $c->id ) ) ); ?>"><?php echo esc_html( $c->name ); ?></a></td>
						<td><a href="<?php echo esc_url( add_query_arg( 'client', (int) $c->id, admin_url( 'admin.php?page=unstoppable-site-manager' ) ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: number of sites. */ _n( '%d site', '%d sites', $c->sites, 'unstoppable-site-manager' ), $c->sites ) ); ?></a></td>
						<td><?php echo $c->settings ? esc_html__( 'Own branding', 'unstoppable-site-manager' ) : '<span class="usmd-muted">' . esc_html__( 'From Settings', 'unstoppable-site-manager' ) . '</span>'; ?></td>
						<td><a href="<?php echo esc_url( self::page_url( array( 'client' => (int) $c->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'unstoppable-site-manager' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $unassigned ) : ?>
				<p class="usmd-muted">
					<?php
					/* translators: %d: number of sites. */
					echo esc_html( sprintf( _n( '%d site has no client yet.', '%d sites have no client yet.', $unassigned, 'unstoppable-site-manager' ), $unassigned ) );
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	private static function edit_screen( $c ) {
		$id    = $c ? (int) $c->id : 0;
		$o     = $c ? $c->settings : array();
		$def   = USMD_Admin::settings();
		$sites = USMD_Sites::all();
		$names = self::names();
		$field = static function ( $k ) use ( $o ) {
			return isset( $o[ $k ] ) ? (string) $o[ $k ] : '';
		};
		?>
		<p><a href="<?php echo esc_url( self::page_url() ); ?>">&larr; <?php esc_html_e( 'All clients', 'unstoppable-site-manager' ); ?></a></p>
		<h1><?php echo $c ? esc_html( $c->name ) : esc_html__( 'Add client', 'unstoppable-site-manager' ); ?></h1>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-client-edit">
			<input type="hidden" name="action" value="usmd_client_save">
			<input type="hidden" name="client" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="sites_shown" value="1">
			<?php wp_nonce_field( 'usmd_client_save' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="usmd-cl-name"><?php esc_html_e( 'Client name', 'unstoppable-site-manager' ); ?></label></th>
					<td><input id="usmd-cl-name" name="name" class="regular-text" required value="<?php echo esc_attr( $c ? $c->name : '' ); ?>" placeholder="<?php esc_attr_e( 'Acme Plumbing', 'unstoppable-site-manager' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Sites', 'unstoppable-site-manager' ); ?></th>
					<td>
						<?php if ( ! $sites ) : ?>
							<p class="usmd-muted"><?php esc_html_e( 'No sites connected yet.', 'unstoppable-site-manager' ); ?></p>
						<?php else : ?>
							<div class="usmd-cl-sites">
								<?php foreach ( $sites as $s ) : ?>
									<?php $other = ( $s->client_id && (int) $s->client_id !== $id && isset( $names[ (int) $s->client_id ] ) ) ? $names[ (int) $s->client_id ] : ''; ?>
									<label>
										<input type="checkbox" name="sites[]" value="<?php echo (int) $s->id; ?>" <?php checked( $id && (int) $s->client_id === $id ); ?>>
										<?php echo esc_html( $s->name ); ?>
										<?php if ( $other ) : ?>
											<span class="usmd-muted">
												<?php
												/* translators: %s: client name. */
												echo esc_html( sprintf( __( '(now %s)', 'unstoppable-site-manager' ), $other ) );
												?>
											</span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'A site belongs to one client. Ticking a site that belongs to another client moves it here.', 'unstoppable-site-manager' ); ?></p>
						<?php endif; ?>
					</td></tr>
			</table>

			<h2><?php esc_html_e( 'Report branding for this client', 'unstoppable-site-manager' ); ?></h2>
			<p class="usmd-muted"><?php esc_html_e( 'Leave a field empty to use what is in Settings (shown in grey).', 'unstoppable-site-manager' ); ?></p>
			<table class="form-table" role="presentation">
				<tr><th><label for="usmd-cl-agency"><?php esc_html_e( 'Agency name', 'unstoppable-site-manager' ); ?></label></th>
					<td><input id="usmd-cl-agency" name="o[agency_name]" class="regular-text" value="<?php echo esc_attr( $field( 'agency_name' ) ); ?>" placeholder="<?php echo esc_attr( $def['agency_name'] ); ?>"></td></tr>
				<tr><th><label for="usmd-cl-logo"><?php esc_html_e( 'Logo URL', 'unstoppable-site-manager' ); ?></label></th>
					<td><input id="usmd-cl-logo" name="o[logo_url]" type="url" class="large-text" value="<?php echo esc_attr( $field( 'logo_url' ) ); ?>" placeholder="<?php echo esc_attr( $def['logo_url'] ); ?>"></td></tr>
				<tr><th><label for="usmd-cl-brand"><?php esc_html_e( 'Report header shows', 'unstoppable-site-manager' ); ?></label></th>
					<td><select id="usmd-cl-brand" name="o[report_brand]">
						<option value=""><?php esc_html_e( 'As in Settings', 'unstoppable-site-manager' ); ?></option>
						<option value="logo" <?php selected( $field( 'report_brand' ), 'logo' ); ?>><?php esc_html_e( 'Logo', 'unstoppable-site-manager' ); ?></option>
						<option value="name" <?php selected( $field( 'report_brand' ), 'name' ); ?>><?php esc_html_e( 'Agency name', 'unstoppable-site-manager' ); ?></option>
						<option value="both" <?php selected( $field( 'report_brand' ), 'both' ); ?>><?php esc_html_e( 'Logo and agency name', 'unstoppable-site-manager' ); ?></option>
					</select></td></tr>
				<tr><th><label for="usmd-cl-color"><?php esc_html_e( 'Brand colour', 'unstoppable-site-manager' ); ?></label></th>
					<td><input id="usmd-cl-color" name="o[report_color]" class="code usmd-cl-color" value="<?php echo esc_attr( $field( 'report_color' ) ); ?>" placeholder="<?php echo esc_attr( $def['report_color'] ); ?>"> <span class="usmd-muted"><?php esc_html_e( 'Hex, like #1e5aa8', 'unstoppable-site-manager' ); ?></span></td></tr>
				<tr><th><label for="usmd-cl-intro"><?php esc_html_e( 'Report introduction', 'unstoppable-site-manager' ); ?></label></th>
					<td><textarea id="usmd-cl-intro" name="o[report_intro]" class="large-text" rows="3" placeholder="<?php echo esc_attr( $def['report_intro'] ); ?>"><?php echo esc_textarea( $field( 'report_intro' ) ); ?></textarea></td></tr>
				<tr><th><label for="usmd-cl-contact"><?php esc_html_e( 'Contact line', 'unstoppable-site-manager' ); ?></label></th>
					<td><input id="usmd-cl-contact" name="o[report_contact]" class="large-text" value="<?php echo esc_attr( $field( 'report_contact' ) ); ?>" placeholder="<?php echo esc_attr( $def['report_contact'] ); ?>"></td></tr>
				<tr><th><label for="usmd-cl-subject"><?php esc_html_e( 'Email subject', 'unstoppable-site-manager' ); ?></label></th>
					<td><input id="usmd-cl-subject" name="o[report_subject]" class="large-text" value="<?php echo esc_attr( $field( 'report_subject' ) ); ?>" placeholder="<?php echo esc_attr( $def['report_subject'] ); ?>"></td></tr>
				<tr><th><label for="usmd-cl-fromname"><?php esc_html_e( 'From name', 'unstoppable-site-manager' ); ?></label></th>
					<td><input id="usmd-cl-fromname" name="o[report_from_name]" class="regular-text" value="<?php echo esc_attr( $field( 'report_from_name' ) ); ?>" placeholder="<?php echo esc_attr( $def['report_from_name'] ? $def['report_from_name'] : $def['agency_name'] ); ?>"></td></tr>
				<tr><th><label for="usmd-cl-fromemail"><?php esc_html_e( 'From email', 'unstoppable-site-manager' ); ?></label></th>
					<td><input id="usmd-cl-fromemail" name="o[report_from_email]" type="email" class="regular-text" value="<?php echo esc_attr( $field( 'report_from_email' ) ); ?>" placeholder="<?php echo esc_attr( $def['report_from_email'] ? $def['report_from_email'] : USMD_Admin::agency_email() ); ?>"></td></tr>
				<tr><th><label for="usmd-cl-reply"><?php esc_html_e( 'Reply-To', 'unstoppable-site-manager' ); ?></label></th>
					<td><input id="usmd-cl-reply" name="o[report_reply_to]" type="email" class="regular-text" value="<?php echo esc_attr( $field( 'report_reply_to' ) ); ?>" placeholder="<?php echo esc_attr( $def['report_reply_to'] ); ?>"></td></tr>
			</table>
			<?php submit_button( $c ? __( 'Save client', 'unstoppable-site-manager' ) : __( 'Add client', 'unstoppable-site-manager' ) ); ?>
		</form>
		<?php if ( $c ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-cl-delete" data-ask="<?php esc_attr_e( 'Delete this client? Its sites stay connected; they just no longer belong to a client.', 'unstoppable-site-manager' ); ?>">
				<input type="hidden" name="action" value="usmd_client_delete">
				<input type="hidden" name="client" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'usmd_client_delete' ); ?>
				<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete client', 'unstoppable-site-manager' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}
}
