<?php
/**
 * A polite request for a WordPress.org review.
 *
 * Two parts, both only on this plugin's own screens (never the main Dashboard,
 * never other plugins' pages, never on client sites):
 *
 * - A footer line: "Enjoying Unstoppable Site Manager? Please rate it ★★★★★".
 * - One notice, once the dashboard has really been used: 14 days after it was
 *   installed and with at least 3 connected sites. "Leave a review", "Maybe
 *   later" (asks again in 30 days) or "Don't ask again". It is shown at most
 *   twice in all. Nothing is offered in return for a review.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Review {

	const OPT        = 'usmd_review';    // { later: count, next: timestamp, done: bool }
	const OPT_SINCE  = 'usmd_installed'; // When the dashboard was first used.
	const WAIT       = 14 * DAY_IN_SECONDS;
	const SNOOZE     = 30 * DAY_IN_SECONDS;
	const MAX_ASKS   = 2;
	const MIN_SITES  = 3;
	const REVIEW_URL = 'https://wordpress.org/support/plugin/unstoppable-site-manager/reviews/#new-post';
	const SUPPORT    = 'support@unstoppablesolutions.app';

	public static function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'remember_install' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer' ), 20 );
		add_action( 'admin_post_usmd_review', array( __CLASS__, 'handle' ) );
	}

	public static function remember_install() {
		if ( ! get_option( self::OPT_SINCE ) ) {
			add_option( self::OPT_SINCE, time(), '', false );
		}
	}

	/** One of this plugin's admin screens. */
	public static function on_our_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && false !== strpos( (string) $screen->id, USMD_Admin::SLUG );
	}

	private static function state() {
		$s = get_option( self::OPT );
		$s = is_array( $s ) ? $s : array();
		return array(
			'later' => isset( $s['later'] ) ? (int) $s['later'] : 0,
			'next'  => isset( $s['next'] ) ? (int) $s['next'] : 0,
			'done'  => ! empty( $s['done'] ),
		);
	}

	public static function due() {
		$s = self::state();
		if ( $s['done'] || $s['later'] >= self::MAX_ASKS ) {
			return false;
		}
		$since = (int) get_option( self::OPT_SINCE );
		$next  = $s['next'] ? $s['next'] : ( $since ? $since + self::WAIT : PHP_INT_MAX );
		return time() >= $next && count( USMD_Sites::all() ) >= self::MIN_SITES;
	}

	private static function action_url( $act ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'usmd_review', 'do' => $act ), admin_url( 'admin-post.php' ) ), 'usmd_review' );
	}

	public static function notice() {
		if ( ! current_user_can( USMD_Security::CAP ) || ! self::on_our_screen() || ! self::due() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['view'] ) && 'report' === $_GET['view'] ) {
			return; // Not on a client report being printed or saved as PDF.
		}
		?>
		<div class="notice notice-info is-dismissible usmd-review" data-later="<?php echo esc_url( self::action_url( 'later' ) ); ?>">
			<p><strong><?php esc_html_e( 'Enjoying Unstoppable Site Manager?', 'unstoppable-site-manager' ); ?></strong>
				<?php esc_html_e( 'It is free, with no paid add-ons. A short review on WordPress.org helps other agencies find it. Thank you!', 'unstoppable-site-manager' ); ?></p>
			<p class="usmd-review-actions">
				<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'review' ) ); ?>" target="_blank" rel="noopener" data-act="review"><?php esc_html_e( 'Leave a review', 'unstoppable-site-manager' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::action_url( 'later' ) ); ?>" data-act="later"><?php esc_html_e( 'Maybe later', 'unstoppable-site-manager' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( self::action_url( 'never' ) ); ?>" data-act="never"><?php esc_html_e( 'Don\'t ask again', 'unstoppable-site-manager' ); ?></a>
				<span class="usmd-muted">·
					<?php esc_html_e( 'Something not working?', 'unstoppable-site-manager' ); ?>
					<a href="<?php echo esc_url( 'mailto:' . self::SUPPORT . '?subject=' . rawurlencode( 'Unstoppable Site Manager ' . USMD_VERSION ) ); ?>"><?php esc_html_e( 'Tell us', 'unstoppable-site-manager' ); ?></a>
				</span>
			</p>
		</div>
		<?php
	}

	/** "Leave a review", "Maybe later", "Don't ask again", and the notice's close button (as "Maybe later"). */
	public static function handle() {
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-manager' ) );
		}
		check_admin_referer( 'usmd_review' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		$s  = self::state();
		if ( 'later' === $do ) {
			++$s['later'];
			$s['next'] = time() + self::SNOOZE;
		} elseif ( in_array( $do, array( 'review', 'never' ), true ) ) {
			$s['done'] = true;
		}
		update_option( self::OPT, $s, false );

		if ( 'review' === $do ) {
			wp_redirect( self::REVIEW_URL ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed WordPress.org address.
			exit;
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . USMD_Admin::SLUG ) );
		exit;
	}

	public static function footer( $text ) {
		if ( ! self::on_our_screen() || ! current_user_can( USMD_Security::CAP ) ) {
			return $text;
		}
		return sprintf(
			/* translators: 1: plugin name, 2: link with five stars. */
			esc_html__( 'Enjoying %1$s? Please rate it %2$s on WordPress.org. Thank you!', 'unstoppable-site-manager' ),
			'<strong>Unstoppable Site Manager</strong>',
			'<a href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener" class="usmd-stars" aria-label="' . esc_attr__( 'Rate it on WordPress.org', 'unstoppable-site-manager' ) . '">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
		);
	}
}
