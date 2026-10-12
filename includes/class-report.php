<?php
/**
 * Website Care Report: a monthly, client-facing summary built from a site's
 * activity log. Printable and saved as PDF from the browser.
 *
 * The raw log is written for us (every activate/deactivate, every update run);
 * this class turns it into what a client wants to know: one line per item that
 * was updated, the net changes to the site, and a plain verdict on its health.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Report {

	const NOTES = 'usmd_report_notes';

	public static function hooks() {
		add_action( 'admin_post_usmd_report_note', array( __CLASS__, 'handle_note' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	/** True on a report screen. */
	public static function is_report_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'], $_GET['view'] ) && USMD_Admin::SLUG === $_GET['page'] && 'report' === $_GET['view'];
	}

	/** Lets the stylesheet hide admin notices that WordPress would otherwise move into the report. */
	public static function body_class( $classes ) {
		return self::is_report_screen() ? $classes . ' usmd-report-screen' : $classes;
	}

	/** The report's accent colour, from Settings, as CSS for wp_add_inline_style(). */
	public static function accent_css() {
		$hex = sanitize_hex_color( USMD_Admin::settings()['report_color'] );
		$hex = $hex ? $hex : '#1e5aa8';
		$rgb = sscanf( strlen( $hex ) === 4 ? '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3] : $hex, '#%02x%02x%02x' );
		return sprintf(
			'.usmd-report{--usmd-r-accent:%1$s;--usmd-r-tint:rgba(%2$d,%3$d,%4$d,.08);--usmd-r-line:rgba(%2$d,%3$d,%4$d,.22)}',
			$hex,
			$rgb[0],
			$rgb[1],
			$rgb[2]
		);
	}

	/**
	 * What the report header shows: array( show logo, show name ).
	 * Falls back to the name when there is no logo, so the header is never empty.
	 */
	public static function brand( array $s ) {
		$has_logo = '' !== (string) $s['logo_url'];
		$mode     = isset( $s['report_brand'] ) ? $s['report_brand'] : 'logo';
		$logo     = $has_logo && 'name' !== $mode;
		$name     = ! $logo || 'both' === $mode;
		return array( $logo, $name );
	}

	/* ---------- the personal note, one per site and month ---------- */

	private static function note_key( $site_id, $month ) {
		return (int) $site_id . '-' . $month;
	}

	public static function get_note( $site_id, $month ) {
		$all = get_option( self::NOTES );
		$key = self::note_key( $site_id, $month );
		return ( is_array( $all ) && isset( $all[ $key ] ) ) ? (string) $all[ $key ] : '';
	}

	public static function handle_note() {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( 'usmd_report_note' );
		$site_id = isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0;
		$month   = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		if ( ! $site_id || ! USMD_Sites::get( $site_id ) || ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			wp_die( esc_html__( 'That report could not be found.', 'unstoppable-site-manager' ) );
		}
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 2000 ) : substr( $note, 0, 2000 );

		$all = get_option( self::NOTES );
		$all = is_array( $all ) ? $all : array();
		$key = self::note_key( $site_id, $month );
		if ( '' === trim( $note ) ) {
			unset( $all[ $key ] );
		} else {
			$all[ $key ] = $note;
		}
		update_option( self::NOTES, $all, false );
		wp_safe_redirect( USMD_Admin::site_url( $site_id, array( 'view' => 'report', 'month' => $month, 'noted' => 1 ) ) );
		exit;
	}

	/* ---------- turning the log into a report ---------- */

	/** "hello-test/hello-test.php" with no stored name becomes "Hello Test". */
	private static function nice_name( $row ) {
		$d = is_array( $row->details ) ? $row->details : array();
		if ( ! empty( $d['name'] ) ) {
			return (string) $d['name'];
		}
		$obj = (string) $row->object;
		if ( preg_match( '#^([^/]+)/[^/]+\.php$#', $obj, $m ) || preg_match( '#^([^/]+)\.php$#', $obj, $m ) ) {
			return ucwords( str_replace( array( '-', '_' ), ' ', $m[1] ) );
		}
		return $obj;
	}

	/** Group key for one plugin or theme, whether the log stored its file or its name. */
	private static function item_key( $row ) {
		$obj = strtolower( (string) $row->object );
		if ( preg_match( '#^([^/]+)/#', $obj, $m ) ) {
			return $row->type . ':' . $m[1];
		}
		return $row->type . ':' . sanitize_title( self::nice_name( $row ) );
	}

	/** The connector is our maintenance tool, not a change the client made or asked for. */
	private static function is_connector( $row ) {
		$name = strtolower( self::nice_name( $row ) );
		$obj  = strtolower( (string) $row->object );
		return false !== strpos( $name, 'fm connector' ) || false !== strpos( $name, 'unstoppable site connector' )
			|| 0 === strpos( $obj, 'fm-connector/' ) || 0 === strpos( $obj, 'unstoppable-site-connector/' );
	}

	/**
	 * @param object[] $rows Activity rows, oldest first.
	 * @return array updates (one per item), changes (grouped), counts.
	 */
	public static function summarise( array $rows ) {
		$updates = array();
		$counts  = array( 'total' => 0, 'plugin' => 0, 'theme' => 0, 'core' => 0 );
		$items   = array(); // key => net change state for plugins/themes.
		$users   = array( 'added' => array(), 'removed' => array() );
		$theme   = '';

		foreach ( $rows as $r ) {
			$d = is_array( $r->details ) ? $r->details : array();

			if ( 'updated' === $r->action && in_array( $r->type, array( 'plugin', 'theme', 'core' ), true ) ) {
				$key = 'core' === $r->type ? 'core' : self::item_key( $r );
				if ( ! isset( $updates[ $key ] ) ) {
					$updates[ $key ] = array(
						'type'  => $r->type,
						'name'  => 'core' === $r->type ? 'WordPress' : self::nice_name( $r ),
						'from'  => isset( $d['from'] ) ? (string) $d['from'] : '',
						'to'    => '',
						'times' => 0,
						'last'  => '',
					);
					++$counts[ $r->type ];
				}
				$updates[ $key ]['to']   = isset( $d['to'] ) ? (string) $d['to'] : $updates[ $key ]['to'];
				$updates[ $key ]['last'] = $r->created_at;
				++$updates[ $key ]['times'];
				++$counts['total'];
				continue;
			}

			if ( in_array( $r->type, array( 'plugin', 'theme' ), true ) && ! self::is_connector( $r ) ) {
				$key = self::item_key( $r );
				if ( ! isset( $items[ $key ] ) ) {
					$items[ $key ] = array(
						'type'      => $r->type,
						'name'      => self::nice_name( $r ),
						'installed' => false,
						'version'   => '',
						'deleted'   => false,
						'toggles'   => array(),
					);
				}
				$it = &$items[ $key ];
				$it['name'] = self::nice_name( $r );
				switch ( $r->action ) {
					case 'installed':
						$it['installed'] = ! $it['deleted'] || $it['installed'];
						$it['deleted']   = false;
						$it['version']   = isset( $d['to'] ) ? (string) $d['to'] : '';
						break;
					case 'deleted':
						$it['deleted'] = true;
						break;
					case 'activated':
					case 'deactivated':
						$it['toggles'][] = $r->action;
						break;
					case 'switched':
						$theme = self::nice_name( $r );
						break;
				}
				unset( $it );
				continue;
			}

			if ( 'user' === $r->type && 'created' === $r->action ) {
				$users['added'][] = $r->object . ( ! empty( $d['roles'] ) ? ' (' . implode( ', ', (array) $d['roles'] ) . ')' : '' );
			} elseif ( 'user' === $r->type && 'deleted' === $r->action ) {
				$users['removed'][] = (string) $r->object;
			}
		}

		$groups = array(
			'added'    => array(),
			'removed'  => array(),
			'on'       => array(),
			'off'      => array(),
		);
		foreach ( $items as $it ) {
			$label = $it['name'] . ( 'theme' === $it['type'] ? ' ' . __( '(theme)', 'unstoppable-site-manager' ) : '' );
			if ( $it['installed'] && $it['deleted'] ) {
				continue; // Added and removed again within the month: no net change.
			}
			if ( $it['deleted'] ) {
				$groups['removed'][] = $label;
				continue;
			}
			// Net effect of switching on and off: an even number of flips started and ended in the same state.
			$net = '';
			if ( $it['toggles'] && 1 === count( $it['toggles'] ) % 2 ) {
				$net = end( $it['toggles'] );
			}
			if ( $it['installed'] ) {
				$groups['added'][] = trim( $label . ' ' . $it['version'] );
				if ( 'deactivated' === $net ) {
					$groups['off'][] = $label;
				}
				continue;
			}
			if ( 'activated' === $net ) {
				$groups['on'][] = $label;
			} elseif ( 'deactivated' === $net ) {
				$groups['off'][] = $label;
			}
		}

		$changes = array();
		$map     = array(
			'added'   => __( 'Added', 'unstoppable-site-manager' ),
			'removed' => __( 'Removed', 'unstoppable-site-manager' ),
			'on'      => __( 'Switched on', 'unstoppable-site-manager' ),
			'off'     => __( 'Switched off', 'unstoppable-site-manager' ),
		);
		foreach ( $map as $g => $title ) {
			if ( $groups[ $g ] ) {
				$changes[] = array( 'title' => $title, 'items' => $groups[ $g ] );
			}
		}
		if ( $theme ) {
			$changes[] = array( 'title' => __( 'Theme changed to', 'unstoppable-site-manager' ), 'items' => array( $theme ) );
		}
		if ( $users['added'] ) {
			$changes[] = array( 'title' => __( 'New users', 'unstoppable-site-manager' ), 'items' => $users['added'] );
		}
		if ( $users['removed'] ) {
			$changes[] = array( 'title' => __( 'Users removed', 'unstoppable-site-manager' ), 'items' => $users['removed'] );
		}

		uasort(
			$updates,
			static function ( $a, $b ) {
				$order = array( 'core' => 0, 'theme' => 1, 'plugin' => 2 );
				return array( $order[ $a['type'] ], strtolower( $a['name'] ) ) <=> array( $order[ $b['type'] ], strtolower( $b['name'] ) );
			}
		);

		return array(
			'updates' => array_values( $updates ),
			'changes' => $changes,
			'counts'  => $counts,
		);
	}

	/**
	 * Health checklist. Each item: label, value, state (ok | warn | bad).
	 */
	public static function health( $site ) {
		$st    = USMD_Sites::status( $site );
		$ssl   = isset( $st['ssl'] ) ? $st['ssl'] : array();
		$core  = isset( $st['core']['update'] ) ? (string) $st['core']['update'] : '';
		$php   = (string) $site->php_version;
		$items = array();

		$items[] = $site->core_update
			? array( __( 'WordPress', 'unstoppable-site-manager' ), sprintf( /* translators: 1: installed version, 2: available version. */ __( '%1$s, update to %2$s available', 'unstoppable-site-manager' ), $site->wp_version, $core ? $core : '?' ), 'warn' )
			: array( __( 'WordPress', 'unstoppable-site-manager' ), sprintf( /* translators: %s: version. */ __( '%s, up to date', 'unstoppable-site-manager' ), $site->wp_version ), 'ok' );

		if ( '' === $php ) {
			$items[] = array( __( 'PHP', 'unstoppable-site-manager' ), __( 'Unknown', 'unstoppable-site-manager' ), 'warn' );
		} elseif ( version_compare( $php, '8.1', '>=' ) ) {
			$items[] = array( __( 'PHP', 'unstoppable-site-manager' ), sprintf( /* translators: %s: version. */ __( '%s, current', 'unstoppable-site-manager' ), $php ), 'ok' );
		} elseif ( version_compare( $php, '7.4', '>=' ) ) {
			$items[] = array( __( 'PHP', 'unstoppable-site-manager' ), sprintf( /* translators: %s: version. */ __( '%s, older version: ask your host about upgrading', 'unstoppable-site-manager' ), $php ), 'warn' );
		} else {
			$items[] = array( __( 'PHP', 'unstoppable-site-manager' ), sprintf( /* translators: %s: version. */ __( '%s, no longer supported', 'unstoppable-site-manager' ), $php ), 'bad' );
		}

		if ( empty( $ssl['enabled'] ) ) {
			$items[] = array( __( 'Security certificate (SSL)', 'unstoppable-site-manager' ), __( 'Not set up: visitors see "Not secure"', 'unstoppable-site-manager' ), 'bad' );
		} elseif ( ! isset( $ssl['days_left'] ) ) {
			$items[] = array( __( 'Security certificate (SSL)', 'unstoppable-site-manager' ), __( 'Could not be checked', 'unstoppable-site-manager' ), 'warn' );
		} elseif ( (int) $ssl['days_left'] < 0 ) {
			$items[] = array( __( 'Security certificate (SSL)', 'unstoppable-site-manager' ), __( 'Expired', 'unstoppable-site-manager' ), 'bad' );
		} else {
			$days    = (int) $ssl['days_left'];
			$items[] = array( __( 'Security certificate (SSL)', 'unstoppable-site-manager' ), sprintf( /* translators: %d: days. */ _n( 'Valid for %d more day', 'Valid for %d more days', $days, 'unstoppable-site-manager' ), $days ), $days < USMD_Admin::SSL_WARN_DAYS ? 'warn' : 'ok' );
		}

		$pending = (int) $site->updates;
		$items[] = $pending
			? array( __( 'Updates waiting', 'unstoppable-site-manager' ), sprintf( /* translators: %d: number of updates. */ _n( '%d update waiting', '%d updates waiting', $pending, 'unstoppable-site-manager' ), $pending ), 'warn' )
			: array( __( 'Updates waiting', 'unstoppable-site-manager' ), __( 'None, everything is current', 'unstoppable-site-manager' ), 'ok' );

		$seen    = $site->last_seen ? strtotime( $site->last_seen . ' UTC' ) : 0;
		$items[] = ( $seen && time() - $seen < 2 * DAY_IN_SECONDS )
			? array( __( 'Monitoring', 'unstoppable-site-manager' ), sprintf( /* translators: %s: date and time. */ __( 'Active, last checked %s', 'unstoppable-site-manager' ), wp_date( 'M j, g:i a', $seen ) ), 'ok' )
			: array( __( 'Monitoring', 'unstoppable-site-manager' ), $seen ? sprintf( /* translators: %s: date. */ __( 'Not heard from since %s', 'unstoppable-site-manager' ), wp_date( 'M j', $seen ) ) : __( 'Not connected yet', 'unstoppable-site-manager' ), 'warn' );

		if ( USMD_Vulns::enabled() ) {
			$vr = USMD_Vulns::for_site( $site->id );
			if ( $vr ) {
				$n       = count( $vr['items'] );
				$items[] = $n
					? array( __( 'Known vulnerabilities', 'unstoppable-site-manager' ), sprintf( /* translators: %d: number of items. */ _n( '%d item needs a security update', '%d items need security updates', $n, 'unstoppable-site-manager' ), $n ), 'bad' )
					: array( __( 'Known vulnerabilities', 'unstoppable-site-manager' ), __( 'None found', 'unstoppable-site-manager' ), 'ok' );
			}
		}

		$wc = isset( $st['integrations']['woocommerce'] ) ? $st['integrations']['woocommerce'] : null;
		if ( is_array( $wc ) && isset( $wc['orders_30d'] ) ) {
			$items[] = array( __( 'Online store', 'unstoppable-site-manager' ), sprintf( /* translators: %d: number of orders. */ _n( '%d order in the last 30 days', '%d orders in the last 30 days', (int) $wc['orders_30d'], 'unstoppable-site-manager' ), (int) $wc['orders_30d'] ), 'ok' );
		}
		return $items;
	}

	/* ---------- the page ---------- */

	/**
	 * Everything a report shows for one site and month (used by the page and the email).
	 *
	 * @return array
	 */
	public static function data( $site, $month ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) {
			$month = wp_date( 'Y-m' );
		}
		$tz    = wp_timezone();
		$start = new DateTimeImmutable( $month . '-01 00:00:00', $tz );
		$end   = $start->modify( '+1 month' );
		$utc   = new DateTimeZone( 'UTC' );

		$rows = USMD_Sites::activity(
			$site->id,
			5000,
			$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$end->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
		);
		$sum    = self::summarise( array_reverse( $rows ) ); // Oldest first.
		$work   = USMD_Work::from_rows( $rows );
		$health = self::health( $site );
		$s      = USMD_Admin::settings();
		$note   = self::get_note( $site->id, $month );
		$label  = wp_date( 'F Y', $start->getTimestamp() );
		$prev   = $start->modify( '-1 month' )->format( 'Y-m' );
		$next   = $start->modify( '+1 month' )->format( 'Y-m' );
		$is_now = wp_date( 'Y-m' ) === $month;
		$host   = (string) wp_parse_url( $site->url, PHP_URL_HOST );
		$c      = $sum['counts'];

		$states = array_count_values( array_column( $health, 2 ) );
		$bad    = isset( $states['bad'] ) ? $states['bad'] : 0;
		$warn   = isset( $states['warn'] ) ? $states['warn'] : 0;
		if ( $bad ) {
			$verdict = array( 'bad', __( 'Your website needs attention', 'unstoppable-site-manager' ) );
		} elseif ( $warn ) {
			/* translators: %d: number of items. */
			$verdict = array( 'warn', sprintf( _n( 'Your website is in good shape, with %d item to watch', 'Your website is in good shape, with %d items to watch', $warn, 'unstoppable-site-manager' ), $warn ) );
		} else {
			$verdict = array( 'ok', __( 'Your website is in good health', 'unstoppable-site-manager' ) );
		}

		$items_updated = count( $sum['updates'] );
		$change_count  = array_sum( array_map( 'count', wp_list_pluck( $sum['changes'], 'items' ) ) );
		if ( $c['total'] ) {
			$lead = sprintf(
				/* translators: 1: "In October 2026" or "So far in October 2026", 2: number of updates, 3: number of items. */
				_n( '%1$s we applied %2$d update across %3$s.', '%1$s we applied %2$d updates across %3$s.', $c['total'], 'unstoppable-site-manager' ),
				$is_now ? sprintf( /* translators: %s: month. */ __( 'So far in %s', 'unstoppable-site-manager' ), $label ) : sprintf( /* translators: %s: month. */ __( 'In %s', 'unstoppable-site-manager' ), $label ),
				$c['total'],
				/* translators: %d: number of plugins, themes or WordPress itself. */
				sprintf( _n( '%d item', '%d items', $items_updated, 'unstoppable-site-manager' ), $items_updated )
			);
		} else {
			/* translators: %s: month. */
			$lead = sprintf( __( 'No updates were applied in %s.', 'unstoppable-site-manager' ), $label );
		}
		if ( $change_count ) {
			/* translators: %d: number of changes. */
			$lead .= ' ' . sprintf( _n( 'We also recorded %d other change to the site.', 'We also recorded %d other changes to the site.', $change_count, 'unstoppable-site-manager' ), $change_count );
		}
		if ( $work ) {
			/* translators: %d: number of tasks. */
			$lead .= ' ' . sprintf( _n( 'We also completed %d task for you.', 'We also completed %d tasks for you.', count( $work ), 'unstoppable-site-manager' ), count( $work ) );
		}

		return array(
			'month'   => $month,
			'sum'     => $sum,
			'health'  => $health,
			'settings'=> $s,
			'note'    => $note,
			'label'   => $label,
			'prev'    => $prev,
			'next'    => $next,
			'is_now'  => $is_now,
			'host'    => $host,
			'verdict' => $verdict,
			'lead'    => $lead,
			'work'    => $work,
		);
	}

	const ICONS = array(
		'ok'   => '✓',
		'warn' => '!',
		'bad'  => '✕',
	);

	public static function render( $site, $month ) {
		$d       = self::data( $site, $month );
		$month   = $d['month'];
		$sum     = $d['sum'];
		$health  = $d['health'];
		$s       = $d['settings'];
		$note    = $d['note'];
		$label   = $d['label'];
		$prev    = $d['prev'];
		$next    = $d['next'];
		$is_now  = $d['is_now'];
		$host    = $d['host'];
		$c       = $sum['counts'];
		$verdict = $d['verdict'];
		$lead    = $d['lead'];
		$icon    = self::ICONS;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$noted = isset( $_GET['noted'] );
		?>
		<div class="wrap usmd-wrap usmd-report-wrap">
			<div class="usmd-report-tools">
				<a href="<?php echo esc_url( USMD_Admin::site_url( $site->id ) ); ?>">← <?php echo esc_html( $site->name ); ?></a>
				<span class="usmd-report-nav">
					<a class="button" href="<?php echo esc_url( USMD_Admin::site_url( $site->id, array( 'view' => 'report', 'month' => $prev ) ) ); ?>">‹ <?php esc_html_e( 'Previous month', 'unstoppable-site-manager' ); ?></a>
					<?php if ( ! $is_now ) : ?>
						<a class="button" href="<?php echo esc_url( USMD_Admin::site_url( $site->id, array( 'view' => 'report', 'month' => $next ) ) ); ?>"><?php esc_html_e( 'Next month', 'unstoppable-site-manager' ); ?> ›</a>
					<?php endif; ?>
					<button type="button" class="button button-primary" id="usmd-print"><?php esc_html_e( 'Print / Save as PDF', 'unstoppable-site-manager' ); ?></button>
				</span>
			</div>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$mailed = isset( $_GET['mailed'] ) ? sanitize_key( $_GET['mailed'] ) : '';
			if ( 'sent' === $mailed ) :
				?>
				<div class="usmd-report-flash is-ok"><?php echo esc_html( sprintf( /* translators: %s: email addresses. */ __( 'Report emailed to %s.', 'unstoppable-site-manager' ), ( USMD_Report_Mail::sent( $site->id, $month )['to'] ?? '' ) ) ); ?></div>
			<?php elseif ( 'test' === $mailed ) : ?>
				<div class="usmd-report-flash is-ok"><?php echo esc_html( sprintf( /* translators: %s: email address. */ __( 'Test copy sent to %s.', 'unstoppable-site-manager' ), USMD_Admin::agency_email() ) ); ?></div>
			<?php elseif ( 'error' === $mailed ) : ?>
				<div class="usmd-report-flash is-bad"><?php echo esc_html( (string) get_transient( 'usmd_mail_error_' . get_current_user_id() ) ); ?></div>
			<?php endif; ?>

			<?php
			// For you only (never printed or emailed): why the logo isn't showing, if it isn't.
			$usmd_settings_url = admin_url( 'admin.php?page=usmd-settings' );
			if ( '' === (string) $s['logo_url'] && 'name' !== $s['report_brand'] ) :
				?>
				<div class="usmd-report-flash is-warn usmd-logo-hint">
					<?php esc_html_e( 'No logo is saved, so the report shows your agency name.', 'unstoppable-site-manager' ); ?>
					<a href="<?php echo esc_url( $usmd_settings_url ); ?>"><?php esc_html_e( 'Choose your logo in Settings, then click Save Changes.', 'unstoppable-site-manager' ); ?></a>
				</div>
			<?php elseif ( '' !== (string) $s['logo_url'] && 'name' !== $s['report_brand'] ) : ?>
				<div class="usmd-report-flash is-warn usmd-logo-hint" id="usmd-logo-broken" hidden>
					<?php
					/* translators: %s: logo address. */
					echo esc_html( sprintf( __( 'Your logo couldn\'t be loaded from %s, so the report shows your agency name.', 'unstoppable-site-manager' ), $s['logo_url'] ) );
					?>
					<span id="usmd-logo-broken-why"></span>
					<a href="<?php echo esc_url( $usmd_settings_url ); ?>"><?php esc_html_e( 'Choose it again from the Media Library in Settings.', 'unstoppable-site-manager' ); ?></a>
				</div>
			<?php endif; ?>

			<?php $usmd_sent = USMD_Report_Mail::sent( $site->id, $month ); ?>
			<details class="usmd-report-notebox usmd-report-mailbox"<?php echo $mailed ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Email this report', 'unstoppable-site-manager' ); ?>
					<?php if ( $usmd_sent && ! empty( $usmd_sent['to'] ) ) : ?>
						<span class="usmd-report-saved"><?php echo esc_html( sprintf( /* translators: 1: date, 2: emails. */ __( 'Sent %1$s to %2$s', 'unstoppable-site-manager' ), wp_date( 'M j', $usmd_sent['time'] ), $usmd_sent['to'] ) ); ?></span>
					<?php endif; ?>
				</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="usmd_report_send">
					<input type="hidden" name="site" value="<?php echo (int) $site->id; ?>">
					<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
					<?php wp_nonce_field( 'usmd_report_send' ); ?>
					<label for="usmd-report-to"><?php esc_html_e( 'Send to', 'unstoppable-site-manager' ); ?></label>
					<input id="usmd-report-to" name="to" type="text" class="large-text" value="<?php echo esc_attr( $site->client_email ); ?>" placeholder="owner@clientsite.com">
					<label class="usmd-report-remember"><input type="checkbox" name="remember" value="1" checked> <?php esc_html_e( 'Save as this client\'s report email', 'unstoppable-site-manager' ); ?></label>
					<label class="usmd-report-remember"><input type="checkbox" name="report_auto" value="1" <?php checked( ! empty( $site->report_auto ) ); ?>>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: day of the month. */
								__( 'Also email last month\'s report automatically every month, on day %d', 'unstoppable-site-manager' ),
								(int) USMD_Admin::settings()['report_day']
							)
						);
						?>
					</label>
					<p>
						<?php submit_button( __( 'Send report', 'unstoppable-site-manager' ), 'primary', 'send', false ); ?>
						<?php submit_button( __( 'Send a test to me', 'unstoppable-site-manager' ), 'secondary', 'test', false ); ?>
						<span class="usmd-muted"><?php echo esc_html( sprintf( /* translators: %s: email address. */ __( 'Tests go to %s and are not recorded as sent.', 'unstoppable-site-manager' ), USMD_Admin::agency_email() ) ); ?></span>
					</p>
				</form>
			</details>

			<details class="usmd-report-notebox">
				<summary><?php echo '' === $note ? esc_html__( 'Add a personal note to this report', 'unstoppable-site-manager' ) : esc_html__( 'Edit your note', 'unstoppable-site-manager' ); ?><?php if ( $noted ) : ?> <span class="usmd-report-saved"><?php esc_html_e( 'Saved', 'unstoppable-site-manager' ); ?></span><?php endif; ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="usmd_report_note">
					<input type="hidden" name="site" value="<?php echo (int) $site->id; ?>">
					<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
					<?php wp_nonce_field( 'usmd_report_note' ); ?>
					<label for="usmd-report-note" class="screen-reader-text"><?php esc_html_e( 'Note to the client', 'unstoppable-site-manager' ); ?></label>
					<textarea id="usmd-report-note" name="note" rows="4" class="large-text" maxlength="2000" placeholder="<?php esc_attr_e( 'For example: We also refreshed the photos on your Services page and fixed the contact form.', 'unstoppable-site-manager' ); ?>"><?php echo esc_textarea( $note ); ?></textarea>
					<p><?php submit_button( __( 'Save note', 'unstoppable-site-manager' ), 'secondary', 'submit', false ); ?> <span class="usmd-muted"><?php esc_html_e( 'Shown on this month\'s report only. Leave empty to remove it.', 'unstoppable-site-manager' ); ?></span></p>
				</form>
			</details>

			<?php USMD_Work::box( $site, $month, $d['work'] ); ?>

			<article class="usmd-report">
				<header class="usmd-r-head">
					<?php list( $show_logo, $show_name ) = self::brand( $s ); ?>
					<div class="usmd-r-brand">
						<?php if ( $show_logo ) : ?>
							<img src="<?php echo esc_url( $s['logo_url'] ); ?>" alt="<?php echo esc_attr( $show_name ? '' : $s['agency_name'] ); ?>" class="usmd-r-logo">
						<?php endif; ?>
						<?php if ( $show_name ) : ?>
							<span class="usmd-r-agency"><?php echo esc_html( $s['agency_name'] ); ?></span>
						<?php else : ?>
							<span class="usmd-r-agency usmd-r-agency-fallback" hidden><?php echo esc_html( $s['agency_name'] ); ?></span>
						<?php endif; ?>
					</div>
					<div class="usmd-r-title">
						<span class="usmd-r-eyebrow"><?php esc_html_e( 'Website Care Report', 'unstoppable-site-manager' ); ?></span>
						<h1><?php echo esc_html( $label ); ?></h1>
						<p><strong><?php echo esc_html( $site->name ); ?></strong> · <?php echo esc_html( $host ); ?></p>
					</div>
				</header>

				<?php if ( $s['report_intro'] ) : ?>
					<p class="usmd-r-intro"><?php echo esc_html( $s['report_intro'] ); ?></p>
				<?php endif; ?>

				<section class="usmd-r-verdict is-<?php echo esc_attr( $verdict[0] ); ?>">
					<span class="usmd-r-mark" aria-hidden="true"><?php echo esc_html( $icon[ $verdict[0] ] ); ?></span>
					<div>
						<h2><?php echo esc_html( $verdict[1] ); ?></h2>
						<p><?php echo esc_html( $lead ); ?></p>
					</div>
				</section>

				<section class="usmd-r-kpis" aria-label="<?php esc_attr_e( 'This month in numbers', 'unstoppable-site-manager' ); ?>">
					<div><strong><?php echo (int) $c['total']; ?></strong><span><?php esc_html_e( 'Updates applied', 'unstoppable-site-manager' ); ?></span></div>
					<div><strong><?php echo (int) $c['plugin']; ?></strong><span><?php esc_html_e( 'Plugins updated', 'unstoppable-site-manager' ); ?></span></div>
					<div><strong><?php echo (int) $c['theme']; ?></strong><span><?php esc_html_e( 'Themes updated', 'unstoppable-site-manager' ); ?></span></div>
					<div><strong><?php echo $c['core'] ? esc_html__( 'Yes', 'unstoppable-site-manager' ) : esc_html__( 'No', 'unstoppable-site-manager' ); ?></strong><span><?php esc_html_e( 'WordPress updated', 'unstoppable-site-manager' ); ?></span></div>
				</section>

				<?php $thumb = USMD_Thumbs::url( $site ); ?>
				<section class="usmd-r-health<?php echo $thumb ? ' has-shot' : ''; ?>">
					<?php if ( $thumb ) : ?>
						<figure class="usmd-r-shot">
							<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: site name. */ __( 'Home page of %s', 'unstoppable-site-manager' ), $site->name ) ); ?>">
							<figcaption><?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'Your home page, %s', 'unstoppable-site-manager' ), wp_date( 'F j, Y', strtotime( $site->thumb_at . ' UTC' ) ) ) ); ?></figcaption>
						</figure>
					<?php endif; ?>
					<div>
						<h2><?php esc_html_e( 'Site health today', 'unstoppable-site-manager' ); ?></h2>
						<ul class="usmd-r-checks">
							<?php foreach ( $health as $h ) : ?>
								<li class="is-<?php echo esc_attr( $h[2] ); ?>">
									<span class="usmd-r-mark" aria-hidden="true"><?php echo esc_html( $icon[ $h[2] ] ); ?></span>
									<span class="usmd-r-check-label"><?php echo esc_html( $h[0] ); ?></span>
									<span class="usmd-r-check-value"><?php echo esc_html( $h[1] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</section>

				<?php if ( '' !== $note ) : ?>
					<section class="usmd-r-note">
						<h2><?php echo esc_html( sprintf( /* translators: %s: agency name. */ __( 'A note from %s', 'unstoppable-site-manager' ), $s['agency_name'] ) ); ?></h2>
						<?php echo wp_kses_post( wpautop( esc_html( $note ) ) ); ?>
					</section>
				<?php endif; ?>

				<?php if ( $d['work'] ) : ?>
					<section class="usmd-r-section">
						<h2><?php esc_html_e( 'Work we did for you', 'unstoppable-site-manager' ); ?></h2>
						<ul class="usmd-r-work">
							<?php foreach ( $d['work'] as $w ) : ?>
								<li>
									<span class="usmd-r-date"><?php echo esc_html( wp_date( 'M j', strtotime( $w['date'] . ' 12:00:00' ) ) ); ?></span>
									<div>
										<strong><?php echo esc_html( $w['title'] ); ?></strong>
										<?php if ( '' !== $w['desc'] ) : ?>
											<?php echo wp_kses_post( wpautop( esc_html( $w['desc'] ) ) ); ?>
										<?php endif; ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>

				<section class="usmd-r-section">
					<h2><?php esc_html_e( 'Updates applied', 'unstoppable-site-manager' ); ?></h2>
					<?php if ( $sum['updates'] ) : ?>
						<table class="usmd-r-table">
							<thead><tr><th><?php esc_html_e( 'What was updated', 'unstoppable-site-manager' ); ?></th><th><?php esc_html_e( 'Version', 'unstoppable-site-manager' ); ?></th><th><?php esc_html_e( 'Date', 'unstoppable-site-manager' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $sum['updates'] as $u ) : ?>
								<tr>
									<td>
										<?php echo esc_html( $u['name'] ); ?>
										<span class="usmd-r-tag"><?php echo esc_html( 'core' === $u['type'] ? __( 'Core', 'unstoppable-site-manager' ) : ( 'theme' === $u['type'] ? __( 'Theme', 'unstoppable-site-manager' ) : __( 'Plugin', 'unstoppable-site-manager' ) ) ); ?></span>
									</td>
									<td class="usmd-r-version">
										<?php if ( $u['from'] && $u['to'] && $u['from'] !== $u['to'] ) : ?>
											<?php echo esc_html( $u['from'] ); ?> <span aria-hidden="true">→</span><span class="screen-reader-text"><?php esc_html_e( 'to', 'unstoppable-site-manager' ); ?></span> <strong><?php echo esc_html( $u['to'] ); ?></strong>
										<?php else : ?>
											<strong><?php echo esc_html( $u['to'] ? $u['to'] : $u['from'] ); ?></strong> <span class="usmd-r-muted"><?php esc_html_e( '(reinstalled)', 'unstoppable-site-manager' ); ?></span>
										<?php endif; ?>
										<?php if ( $u['times'] > 1 ) : ?>
											<span class="usmd-r-times"><?php echo esc_html( sprintf( /* translators: %d: number of updates. */ _n( '%d update', '%d updates', $u['times'], 'unstoppable-site-manager' ), $u['times'] ) ); ?></span>
										<?php endif; ?>
									</td>
									<td class="usmd-r-date"><?php echo esc_html( wp_date( 'M j', strtotime( $u['last'] . ' UTC' ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="usmd-r-empty"><?php esc_html_e( 'No updates were applied this month.', 'unstoppable-site-manager' ); ?></p>
					<?php endif; ?>
				</section>

				<?php if ( $sum['changes'] ) : ?>
					<section class="usmd-r-section">
						<h2><?php esc_html_e( 'Other changes to your site', 'unstoppable-site-manager' ); ?></h2>
						<dl class="usmd-r-changes">
							<?php foreach ( $sum['changes'] as $g ) : ?>
								<div>
									<dt><?php echo esc_html( $g['title'] ); ?></dt>
									<dd><?php echo esc_html( implode( ', ', $g['items'] ) ); ?></dd>
								</div>
							<?php endforeach; ?>
						</dl>
					</section>
				<?php endif; ?>

				<footer class="usmd-r-foot">
					<span><?php echo esc_html( sprintf( /* translators: 1: agency name, 2: date. */ __( 'Prepared by %1$s on %2$s', 'unstoppable-site-manager' ), $s['agency_name'], wp_date( 'F j, Y' ) ) ); ?></span>
					<?php if ( $s['report_contact'] ) : ?>
						<span><?php echo esc_html( $s['report_contact'] ); ?></span>
					<?php endif; ?>
				</footer>
			</article>
		</div>
		<?php
	}
}
