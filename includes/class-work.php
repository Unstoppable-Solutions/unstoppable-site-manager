<?php
/**
 * Work notes: work you did for a client outside of updates (a new page, a form
 * fix, photo changes...). Each note has a date, a short task name and an
 * optional description, and appears on that month's client report. Time spent
 * (minutes) is optional and stays on the dashboard unless "Show time on client
 * reports" is on in Settings; the Clients screen totals it per client per month.
 *
 * Notes are stored as activity rows of type "work" on the dashboard, so they
 * fall into the right month like everything else on the report.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Work {

	const TYPE      = 'work';
	const ACTION    = 'note';
	const MAX_TITLE = 60;
	const MAX_DESC  = 2000;
	const MAX_MIN   = 6000; // 100 hours on one task.

	public static function hooks() {
		add_action( 'admin_post_usmd_work_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_usmd_work_delete', array( __CLASS__, 'handle_delete' ) );
	}

	/** Work notes among a month's activity rows, oldest first. */
	public static function from_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			if ( self::TYPE === $r->type && self::ACTION === $r->action ) {
				$d     = is_array( $r->details ) ? $r->details : array();
				$out[] = array(
					'id'    => (int) $r->id,
					'date'  => wp_date( 'Y-m-d', strtotime( $r->created_at . ' UTC' ) ),
					'title' => (string) $r->object,
					'desc'  => isset( $d['desc'] ) ? (string) $d['desc'] : '',
					'min'   => isset( $d['min'] ) ? (int) $d['min'] : 0,
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['date'], $a['id'] ) <=> array( $b['date'], $b['id'] );
			}
		);
		return $out;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( $action );
	}

	/** Noon on the chosen day in the site's time zone, stored as UTC. */
	private static function to_utc( $ymd ) {
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $ymd . ' 12:00:00', wp_timezone() );
		return $dt ? $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '';
	}

	private static function row( $id, $site_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND site_id = %d AND type = %s AND action = %s', USMD_Sites::ta(), $id, $site_id, self::TYPE, self::ACTION ) );
	}

	public static function handle_save() {
		self::guard( 'usmd_work_save' );
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$site  = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 );
		$id    = isset( $_POST['work_id'] ) ? absint( $_POST['work_id'] ) : 0;
		$title = isset( $_POST['title'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['title'] ) ) ) : '';
		$date  = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$desc  = isset( $_POST['desc'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) ) : '';
		$month = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$mins  = ( isset( $_POST['hours'] ) ? absint( $_POST['hours'] ) * 60 : 0 ) + ( isset( $_POST['minutes'] ) ? absint( $_POST['minutes'] ) : 0 );
		// phpcs:enable
		$mins = min( self::MAX_MIN, $mins );
		if ( ! $site ) {
			wp_die( esc_html__( 'Site not found.', 'unstoppable-site-manager' ) );
		}
		$utc = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? self::to_utc( $date ) : '';
		if ( '' === $title || ! $utc ) {
			set_transient( 'usmd_work_error_' . get_current_user_id(), __( 'Enter a task name and a date.', 'unstoppable-site-manager' ), 120 );
			wp_safe_redirect( USMD_Admin::site_url( $site->id, array( 'view' => 'report', 'month' => $month ) ) . '#usmd-work' );
			exit;
		}
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, self::MAX_TITLE ) : substr( $title, 0, self::MAX_TITLE );
		$desc  = function_exists( 'mb_substr' ) ? mb_substr( $desc, 0, self::MAX_DESC ) : substr( $desc, 0, self::MAX_DESC );
		$data  = array(
			'created_at' => $utc,
			'object'     => $title,
			'details'    => wp_json_encode( $mins ? array( 'desc' => $desc, 'min' => $mins ) : array( 'desc' => $desc ) ),
		);
		if ( $id && self::row( $id, $site->id ) ) {
			$wpdb->update( $wpdb->prefix . 'usmd_activity', $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
				$wpdb->prefix . 'usmd_activity',
				$data + array(
					'site_id'   => (int) $site->id,
					'remote_id' => 0,
					'type'      => self::TYPE,
					'action'    => self::ACTION,
					'actor'     => wp_get_current_user()->user_login,
				)
			);
		}
		// Show the report for the month the work was done in.
		wp_safe_redirect(
			USMD_Admin::site_url(
				$site->id,
				array(
					'view'  => 'report',
					'month' => substr( $date, 0, 7 ),
					'work'  => 'saved',
				)
			) . '#usmd-work'
		);
		exit;
	}

	public static function handle_delete() {
		self::guard( 'usmd_work_delete' );
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$site  = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 );
		$id    = isset( $_POST['work_id'] ) ? absint( $_POST['work_id'] ) : 0;
		$month = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		// phpcs:enable
		if ( $site && $id && self::row( $id, $site->id ) ) {
			$wpdb->delete( $wpdb->prefix . 'usmd_activity', array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		}
		wp_safe_redirect(
			USMD_Admin::site_url(
				$site ? $site->id : 0,
				array(
					'view'  => 'report',
					'month' => $month,
					'work'  => 'deleted',
				)
			) . '#usmd-work'
		);
		exit;
	}

	/** 90 -> "1 h 30 min". */
	public static function fmt( $minutes ) {
		$minutes = (int) $minutes;
		$h       = intdiv( $minutes, 60 );
		$m       = $minutes % 60;
		if ( $h && $m ) {
			/* translators: 1: hours, 2: minutes. */
			return sprintf( __( '%1$d h %2$d min', 'unstoppable-site-manager' ), $h, $m );
		}
		/* translators: %d: hours. */
		return $h ? sprintf( __( '%d h', 'unstoppable-site-manager' ), $h ) : sprintf( /* translators: %d: minutes. */ __( '%d min', 'unstoppable-site-manager' ), $m );
	}

	/**
	 * Minutes of work per site in a period (site time zone month, as UTC bounds).
	 *
	 * @return array site id => minutes.
	 */
	public static function minutes_by_site( $from_utc, $to_utc ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT site_id, details FROM %i WHERE type = %s AND action = %s AND created_at >= %s AND created_at < %s', USMD_Sites::ta(), self::TYPE, self::ACTION, $from_utc, $to_utc ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$d = json_decode( (string) $r->details, true );
			if ( ! empty( $d['min'] ) ) {
				$out[ (int) $r->site_id ] = ( isset( $out[ (int) $r->site_id ] ) ? $out[ (int) $r->site_id ] : 0 ) + (int) $d['min'];
			}
		}
		return $out;
	}

	/** UTC bounds of a month in the site's time zone ("Y-m"). */
	public static function month_bounds( $month ) {
		$start = new DateTimeImmutable( $month . '-01 00:00:00', wp_timezone() );
		$end   = $start->modify( '+1 month' );
		$utc   = new DateTimeZone( 'UTC' );
		return array( $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	/** The "Work you did" box above the report (screen only, never printed). */
	public static function box( $site, $month, array $work ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$edit_id = isset( $_GET['edit_work'] ) ? absint( $_GET['edit_work'] ) : 0;
		$flash   = isset( $_GET['work'] ) ? sanitize_key( $_GET['work'] ) : '';
		// phpcs:enable
		$error = get_transient( 'usmd_work_error_' . get_current_user_id() );
		delete_transient( 'usmd_work_error_' . get_current_user_id() );
		$edit = null;
		foreach ( $work as $w ) {
			if ( $w['id'] === $edit_id ) {
				$edit = $w;
			}
		}
		$today = wp_date( 'Y-m-d' );
		$day   = ( substr( $today, 0, 7 ) === $month ) ? $today : $month . '-01';
		$open  = $edit || $error || $flash;
		?>
		<details class="usmd-report-workbox" id="usmd-work"<?php echo $open ? ' open' : ''; ?>>
			<summary>
				<?php esc_html_e( 'Work you did this month', 'unstoppable-site-manager' ); ?>
				<span class="usmd-muted">
					<?php
					$total = array_sum( wp_list_pluck( $work, 'min' ) );
					echo $work
						/* translators: %d: number of tasks. */
						? esc_html( sprintf( _n( '%d task', '%d tasks', count( $work ), 'unstoppable-site-manager' ), count( $work ) ) . ( $total ? ' · ' . self::fmt( $total ) : '' ) )
						: esc_html__( 'Add tasks that aren\'t updates', 'unstoppable-site-manager' );
					?>
				</span>
				<?php if ( 'saved' === $flash || 'deleted' === $flash ) : ?>
					<span class="usmd-report-saved"><?php echo 'saved' === $flash ? esc_html__( 'Saved', 'unstoppable-site-manager' ) : esc_html__( 'Deleted', 'unstoppable-site-manager' ); ?></span>
				<?php endif; ?>
			</summary>
			<p class="usmd-muted"><?php esc_html_e( 'Describe work you did for this client outside of updates, such as a new page, a form fix or new photos. Each task appears on the report for the month of its date.', 'unstoppable-site-manager' ); ?></p>

			<?php if ( $work ) : ?>
				<ul class="usmd-work-list">
					<?php foreach ( $work as $w ) : ?>
						<li>
							<span class="usmd-work-date"><?php echo esc_html( wp_date( 'M j', strtotime( $w['date'] . ' 12:00:00' ) ) ); ?></span>
							<span class="usmd-work-text"><strong><?php echo esc_html( $w['title'] ); ?></strong><?php if ( $w['min'] ) : ?> <span class="usmd-work-time"><?php echo esc_html( self::fmt( $w['min'] ) ); ?></span><?php endif; ?><?php if ( '' !== $w['desc'] ) : ?><span><?php echo esc_html( $w['desc'] ); ?></span><?php endif; ?></span>
							<span class="usmd-work-actions">
								<a href="<?php echo esc_url( USMD_Admin::site_url( $site->id, array( 'view' => 'report', 'month' => $month, 'edit_work' => $w['id'] ) ) . '#usmd-work' ); ?>"><?php esc_html_e( 'Edit', 'unstoppable-site-manager' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-work-delete">
									<input type="hidden" name="action" value="usmd_work_delete">
									<input type="hidden" name="site" value="<?php echo (int) $site->id; ?>">
									<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
									<input type="hidden" name="work_id" value="<?php echo (int) $w['id']; ?>">
									<?php wp_nonce_field( 'usmd_work_delete' ); ?>
									<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'unstoppable-site-manager' ); ?></button>
								</form>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $error ) : ?>
				<p class="usmd-work-error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="usmd-work-form">
				<input type="hidden" name="action" value="usmd_work_save">
				<input type="hidden" name="site" value="<?php echo (int) $site->id; ?>">
				<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
				<input type="hidden" name="work_id" value="<?php echo $edit ? (int) $edit['id'] : 0; ?>">
				<?php wp_nonce_field( 'usmd_work_save' ); ?>
				<h3><?php echo $edit ? esc_html__( 'Edit task', 'unstoppable-site-manager' ) : esc_html__( 'Add a task', 'unstoppable-site-manager' ); ?></h3>
				<div class="usmd-work-row">
					<p class="usmd-work-title">
						<label for="usmd-work-title"><?php esc_html_e( 'Task name', 'unstoppable-site-manager' ); ?> <span class="usmd-muted" id="usmd-work-count" aria-live="polite"></span></label>
						<input type="text" id="usmd-work-title" name="title" class="large-text" maxlength="<?php echo (int) self::MAX_TITLE; ?>" required value="<?php echo esc_attr( $edit ? $edit['title'] : '' ); ?>" placeholder="<?php esc_attr_e( 'For example: Added a new Services page', 'unstoppable-site-manager' ); ?>">
					</p>
					<p class="usmd-work-day">
						<label for="usmd-work-date"><?php esc_html_e( 'Date', 'unstoppable-site-manager' ); ?></label>
						<input type="date" id="usmd-work-date" name="date" required value="<?php echo esc_attr( $edit ? $edit['date'] : $day ); ?>">
					</p>
					<p class="usmd-work-spent">
						<label for="usmd-work-hours"><?php esc_html_e( 'Time spent (optional)', 'unstoppable-site-manager' ); ?></label>
						<span>
							<input type="number" id="usmd-work-hours" name="hours" min="0" max="100" step="1" value="<?php echo $edit && $edit['min'] ? (int) intdiv( $edit['min'], 60 ) : ''; ?>" placeholder="0" aria-label="<?php esc_attr_e( 'Hours', 'unstoppable-site-manager' ); ?>"> <?php esc_html_e( 'h', 'unstoppable-site-manager' ); ?>
							<input type="number" id="usmd-work-minutes" name="minutes" min="0" max="59" step="5" value="<?php echo $edit && $edit['min'] ? (int) ( $edit['min'] % 60 ) : ''; ?>" placeholder="0" aria-label="<?php esc_attr_e( 'Minutes', 'unstoppable-site-manager' ); ?>"> <?php esc_html_e( 'min', 'unstoppable-site-manager' ); ?>
						</span>
					</p>
				</div>
				<p>
					<label for="usmd-work-desc"><?php esc_html_e( 'Task description (optional)', 'unstoppable-site-manager' ); ?></label>
					<textarea id="usmd-work-desc" name="desc" rows="3" class="large-text" maxlength="<?php echo (int) self::MAX_DESC; ?>" placeholder="<?php esc_attr_e( 'What you did and why, in a sentence or two your client will understand.', 'unstoppable-site-manager' ); ?>"><?php echo esc_textarea( $edit ? $edit['desc'] : '' ); ?></textarea>
				</p>
				<p>
					<?php submit_button( $edit ? __( 'Save task', 'unstoppable-site-manager' ) : __( 'Add task', 'unstoppable-site-manager' ), 'primary', 'submit', false ); ?>
					<?php if ( $edit ) : ?>
						<a class="button" href="<?php echo esc_url( USMD_Admin::site_url( $site->id, array( 'view' => 'report', 'month' => $month ) ) . '#usmd-work' ); ?>"><?php esc_html_e( 'Cancel', 'unstoppable-site-manager' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</details>
		<?php
	}
}
