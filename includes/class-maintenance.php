<?php
/**
 * Maintenance mode for a client site, switched from its page on the dashboard.
 *
 * The connector (0.9.0+) shows visitors a short "back soon" page with a 503
 * status while signed-in editors still see the site. The dashboard sends a plain
 * heading and message (escaped on the site) and an optional number of hours
 * after which the site switches maintenance mode off by itself. The site's own
 * administrators can always switch it off, and can stop the dashboard using it.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Maintenance {

	const MIN_CONNECTOR = '0.9.0';
	const HOURS         = array( 1, 2, 4, 8, 24, 0 ); // 0 = until switched off.

	/** template => [ label, default heading, default message, default hours ]. */
	public static function templates() {
		return array(
			'maintenance' => array(
				'label'   => __( 'Maintenance', 'unstoppable-site-manager' ),
				'title'   => __( 'Down for maintenance', 'unstoppable-site-manager' ),
				'message' => __( 'We are making some improvements and will be back shortly. Thank you for your patience.', 'unstoppable-site-manager' ),
				'hours'   => 2,
				'note'    => __( 'Short work on a live site. Tells search engines it is temporary.', 'unstoppable-site-manager' ),
			),
			'coming_soon' => array(
				'label'   => __( 'Coming soon', 'unstoppable-site-manager' ),
				'title'   => __( 'Coming soon', 'unstoppable-site-manager' ),
				'message' => __( 'We are working on our new website. Stay tuned!', 'unstoppable-site-manager' ),
				'hours'   => 0,
				'note'    => __( 'A site that hasn\'t launched yet. Kept out of search results.', 'unstoppable-site-manager' ),
			),
		);
	}

	public static function label( $template ) {
		$t = self::templates();
		return isset( $t[ $template ] ) ? $t[ $template ]['label'] : $t['maintenance']['label'];
	}

	public static function hooks() {
		add_action( 'wp_ajax_usmd_maintenance', array( __CLASS__, 'ajax' ) );
	}

	/** @return array { on, template, since, until } from the site's last report. */
	public static function state( $site ) {
		$s = USMD_Sites::status( $site );
		$m = isset( $s['connector']['maintenance'] ) && is_array( $s['connector']['maintenance'] ) ? $s['connector']['maintenance'] : array();
		$on    = ! empty( $m['on'] );
		$until = isset( $m['until'] ) ? (int) $m['until'] : 0;
		if ( $on && $until && $until <= time() ) {
			$on = false; // It has switched itself off since the last report.
		}
		return array(
			'on'       => $on,
			'template' => isset( $m['template'] ) && 'coming_soon' === $m['template'] ? 'coming_soon' : 'maintenance',
			'since' => isset( $m['since'] ) ? (int) $m['since'] : 0,
			'until' => $until,
		);
	}

	public static function supported( $site ) {
		$v = USMD_Sites::connector_version( $site );
		return '' !== $v && version_compare( $v, self::MIN_CONNECTOR, '>=' );
	}

	private static function allowed( $site ) {
		$s = USMD_Sites::status( $site );
		return ! isset( $s['security']['perms']['maintenance'] ) || $s['security']['perms']['maintenance'];
	}

	private static function when( $ts ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/** Site page sidebar panel. */
	public static function panel( $site ) {
		$m  = self::state( $site );
		$id = (int) $site->id;
		?>
		<div class="usmd-panel usmd-maint<?php echo $m['on'] ? ' is-on' : ''; ?>" id="usmd-maintenance">
			<h2><?php esc_html_e( 'Maintenance mode', 'unstoppable-site-manager' ); ?>
				<span class="usmd-pill <?php echo $m['on'] ? 'usmd-pill-maint is-' . esc_attr( $m['template'] ) : 'usmd-pill-ok'; ?>"><?php echo $m['on'] ? esc_html( self::label( $m['template'] ) ) : esc_html__( 'Off', 'unstoppable-site-manager' ); ?></span>
			</h2>
			<?php if ( ! self::supported( $site ) ) : ?>
				<p class="usmd-muted">
					<?php
					/* translators: %s: connector version. */
					echo esc_html( sprintf( __( 'Needs Unstoppable Site Connector %s or newer on this site. Update it like any other plugin.', 'unstoppable-site-manager' ), self::MIN_CONNECTOR ) );
					?>
				</p>
			<?php elseif ( ! self::allowed( $site ) ) : ?>
				<p class="usmd-muted"><?php esc_html_e( 'This site has switched off "Turn maintenance mode on and off" for the dashboard. Only an administrator of the site can switch it back on.', 'unstoppable-site-manager' ); ?></p>
			<?php elseif ( $m['on'] ) : ?>
				<p>
					<?php
					echo esc_html(
						$m['since']
							/* translators: 1: template name, 2: date and time. */
							? sprintf( __( 'Visitors see the %1$s page since %2$s.', 'unstoppable-site-manager' ), strtolower( self::label( $m['template'] ) ), self::when( $m['since'] ) )
							/* translators: %s: template name. */
							: sprintf( __( 'Visitors see the %s page.', 'unstoppable-site-manager' ), strtolower( self::label( $m['template'] ) ) )
					);
					echo ' ';
					echo esc_html(
						$m['until']
							/* translators: %s: date and time. */
							? sprintf( __( 'It switches off by itself at %s.', 'unstoppable-site-manager' ), self::when( $m['until'] ) )
							: __( 'It stays on until you switch it off.', 'unstoppable-site-manager' )
					);
					?>
				</p>
				<p class="usmd-muted"><?php esc_html_e( 'You and other signed-in editors see the site as usual.', 'unstoppable-site-manager' ); ?></p>
				<button type="button" class="button button-primary usmd-maint-go" data-site="<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput -- int. ?>" data-on="0"><?php esc_html_e( 'Turn off', 'unstoppable-site-manager' ); ?></button>
			<?php else : ?>
				<p class="usmd-muted"><?php esc_html_e( 'Show visitors a placeholder page while you work on the site. Signed-in editors still see the site; wp-admin keeps working.', 'unstoppable-site-manager' ); ?></p>
				<form class="usmd-maint-form" data-site="<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput -- int. ?>">
					<div class="usmd-maint-templates" role="radiogroup" aria-label="<?php esc_attr_e( 'Template', 'unstoppable-site-manager' ); ?>">
						<?php foreach ( self::templates() as $key => $t ) : ?>
							<label class="usmd-maint-tpl is-<?php echo esc_attr( $key ); ?>">
								<input type="radio" name="template" value="<?php echo esc_attr( $key ); ?>" <?php checked( 'maintenance', $key ); ?>
									data-title="<?php echo esc_attr( $t['title'] ); ?>" data-message="<?php echo esc_attr( $t['message'] ); ?>" data-hours="<?php echo (int) $t['hours']; ?>">
								<span class="usmd-maint-swatch" aria-hidden="true"></span>
								<strong><?php echo esc_html( $t['label'] ); ?></strong>
								<small><?php echo esc_html( $t['note'] ); ?></small>
							</label>
						<?php endforeach; ?>
					</div>
					<label for="usmd-maint-title-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput -- int. ?>"><?php esc_html_e( 'Heading', 'unstoppable-site-manager' ); ?></label>
					<input type="text" id="usmd-maint-title-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput -- int. ?>" name="title" class="widefat" maxlength="80" placeholder="<?php esc_attr_e( 'Down for maintenance', 'unstoppable-site-manager' ); ?>">
					<label for="usmd-maint-message-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput -- int. ?>"><?php esc_html_e( 'Message', 'unstoppable-site-manager' ); ?></label>
					<textarea id="usmd-maint-message-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput -- int. ?>" name="message" class="widefat" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'We are making some improvements and will be back shortly. Thank you for your patience.', 'unstoppable-site-manager' ); ?>"></textarea>
					<label for="usmd-maint-hours-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput -- int. ?>"><?php esc_html_e( 'Switch off by itself after', 'unstoppable-site-manager' ); ?></label>
					<select id="usmd-maint-hours-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput -- int. ?>" name="hours">
						<?php foreach ( self::HOURS as $h ) : ?>
							<option value="<?php echo (int) $h; ?>" <?php selected( 2, $h ); ?>>
								<?php
								/* translators: %d: number of hours. */
								echo esc_html( $h ? sprintf( _n( '%d hour', '%d hours', $h, 'unstoppable-site-manager' ), $h ) : __( 'Never (I\'ll switch it off)', 'unstoppable-site-manager' ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p><button type="submit" class="button usmd-maint-go" data-site="<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput -- int. ?>" data-on="1"><?php esc_html_e( 'Turn on', 'unstoppable-site-manager' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Card pill on the Sites screen. */
	public static function pill( $site ) {
		$m = self::state( $site );
		if ( ! $m['on'] ) {
			return '';
		}
		return '<span class="usmd-pill usmd-pill-maint is-' . esc_attr( $m['template'] ) . '" title="' . esc_attr__( 'Visitors see a placeholder page', 'unstoppable-site-manager' ) . '">' . esc_html( self::label( $m['template'] ) ) . '</span>';
	}

	public static function ajax() {
		check_ajax_referer( 'usmd', 'nonce' );
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'unstoppable-site-manager' ) ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$site = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 );
		if ( ! $site ) {
			wp_send_json_error( array( 'message' => __( 'Site not found.', 'unstoppable-site-manager' ) ), 404 );
		}
		if ( ! self::supported( $site ) ) {
			/* translators: %s: connector version. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Needs Unstoppable Site Connector %s or newer on this site.', 'unstoppable-site-manager' ), self::MIN_CONNECTOR ) ) );
		}
		$on     = ! empty( $_POST['on'] );
		$hours  = isset( $_POST['hours'] ) ? absint( $_POST['hours'] ) : 0;
		$tpl    = isset( $_POST['template'] ) && 'coming_soon' === $_POST['template'] ? 'coming_soon' : 'maintenance';
		$params = array(
			'on'       => $on,
			'template' => $tpl,
			'title'   => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'message' => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '',
			'hours'   => in_array( $hours, self::HOURS, true ) ? $hours : 0,
		);
		// phpcs:enable
		$r = USMD_Client::call( $site, 'maintenance', $params, 30, false );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		if ( ! empty( $r['status'] ) ) {
			USMD_Sites::store_status( $site->id, $r['status'], false );
		}
		USMD_Client::sync_activity( $site );
		$now = ! empty( $r['maintenance']['on'] );
		wp_send_json_success(
			array(
				'on'      => $now,
				/* translators: %s: template name. */
				'message' => $now ? sprintf( __( '%s page is on. Visitors see it instead of the site.', 'unstoppable-site-manager' ), self::label( $tpl ) ) : __( 'Placeholder page is off. Visitors see the site again.', 'unstoppable-site-manager' ),
			)
		);
	}
}
