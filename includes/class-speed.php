<?php
/**
 * Page speed: a weekly Google PageSpeed Insights check of each site's home page.
 *
 * Off until you turn it on and add a free Google API key in Settings (Google
 * refuses requests without a key once its shared allowance runs out). When on,
 * the dashboard sends each client site's home page address, and nothing else,
 * to Google's PageSpeed Insights API, which loads the page the way a phone
 * visitor would and returns a performance score from 0 to 100 and timings.
 *
 * Each site is checked about once a week, a few sites an hour, so a dashboard
 * with hundreds of sites spreads the work out. The score shows on the site's
 * card and page, in client reports, and in the daily email when it drops.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Speed {

	const OPT       = 'usmd_speed';     // site id => result (see check()).
	const HOOK      = 'usmd_speed_tick';
	const API       = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const EVERY     = WEEK_IN_SECONDS;
	const RETRY     = DAY_IN_SECONDS;   // After a failed check.
	const PER_TICK  = 6;                // Most sites checked in one hourly run.
	const TIMEOUT   = 90;               // Google takes 10 to 40 seconds a page.
	const KEEP      = 12;               // Past scores kept per site.
	const DROP      = 15;               // Points lost since the last check that count as a drop.

	public static function hooks() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'wp_ajax_usmd_speed_check', array( __CLASS__, 'ajax_check' ) );
	}

	/* ---------- settings ---------- */

	public static function key() {
		return trim( (string) USMD_Admin::settings()['speed_key'] );
	}

	/** On, with a key. */
	public static function enabled() {
		return ! empty( USMD_Admin::settings()['speed_check'] ) && '' !== self::key();
	}

	/** Hourly while on; nothing scheduled while off. */
	public static function schedule() {
		$next = wp_next_scheduled( self::HOOK );
		if ( self::enabled() && ! $next ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		} elseif ( ! self::enabled() && $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/* ---------- results ---------- */

	/** @var array|null */
	private static $all = null;

	public static function all() {
		if ( null === self::$all ) {
			$a         = get_option( self::OPT );
			self::$all = is_array( $a ) ? $a : array();
		}
		return self::$all;
	}

	/** @return array|null The last result for a site. */
	public static function get( $site_id ) {
		$a = self::all();
		return isset( $a[ (int) $site_id ] ) ? $a[ (int) $site_id ] : null;
	}

	private static function put( $site_id, array $r ) {
		$a                    = self::all();
		$a[ (int) $site_id ]  = $r;
		self::$all            = $a;
		update_option( self::OPT, $a, false );
	}

	/** Forget a removed site. */
	public static function forget( $site_id ) {
		$a = self::all();
		unset( $a[ (int) $site_id ] );
		self::$all = $a;
		update_option( self::OPT, $a, false );
	}

	/** good (90+), ok (50-89) or bad (under 50), as Google colours it. */
	public static function grade( $score ) {
		return $score >= 90 ? 'good' : ( $score >= 50 ? 'ok' : 'bad' );
	}

	public static function grade_label( $score ) {
		$g = self::grade( $score );
		return 'good' === $g ? __( 'good', 'unstoppable-site-manager' ) : ( 'ok' === $g ? __( 'needs improvement', 'unstoppable-site-manager' ) : __( 'poor', 'unstoppable-site-manager' ) );
	}

	/** Google's full report for a site, to open in a browser. */
	public static function report_url( $url ) {
		return 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $url ) . '&form_factor=mobile';
	}

	/** A new score that fell a lot since the check before it, or into "poor". */
	public static function dropped( $r ) {
		// Only a new score counts: a drop is mentioned in the daily email for two days, not all week.
		if ( ! $r || ! isset( $r['score'] ) || ! empty( $r['error'] ) || time() - (int) ( isset( $r['scored'] ) ? $r['scored'] : 0 ) > 2 * DAY_IN_SECONDS ) {
			return false;
		}
		$prev = isset( $r['prev'] ) ? $r['prev'] : null;
		if ( null === $prev ) {
			return false;
		}
		return ( $prev - $r['score'] ) >= self::DROP || ( $r['score'] < 50 && $prev >= 50 );
	}

	/* ---------- checking ---------- */

	private static function request_url( $url ) {
		return add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => 'mobile',
				'category' => 'performance',
				'key'      => rawurlencode( self::key() ),
			),
			/** Filters the PageSpeed Insights endpoint (for testing against a stand-in). */
			apply_filters( 'usmd_speed_api', self::API )
		);
	}

	/**
	 * Read Google's answer.
	 *
	 * @return array score, lcp, fcp, tbt, cls, si (display strings) or error.
	 */
	public static function parse( $code, $body ) {
		$d = json_decode( (string) $body, true );
		if ( 200 !== (int) $code || ! is_array( $d ) ) {
			$msg = is_array( $d ) && isset( $d['error']['message'] ) ? (string) $d['error']['message'] : '';
			if ( 429 === (int) $code ) {
				return array( 'error' => __( 'Google\'s daily allowance for this API key is used up. Checks continue tomorrow.', 'unstoppable-site-manager' ) );
			}
			if ( 400 === (int) $code && false !== stripos( $msg, 'API key' ) ) {
				return array( 'error' => __( 'Google did not accept the API key. Check it in Settings > Page speed.', 'unstoppable-site-manager' ) );
			}
			if ( 403 === (int) $code ) {
				return array( 'error' => __( 'Google refused the API key: make sure the PageSpeed Insights API is enabled for it.', 'unstoppable-site-manager' ) );
			}
			if ( false !== stripos( $msg, 'FAILED_DOCUMENT_REQUEST' ) || false !== stripos( $msg, 'ERRORED_DOCUMENT_REQUEST' ) ) {
				return array( 'error' => __( 'Google could not load the home page (the site was down, blocked Google, or redirected too many times).', 'unstoppable-site-manager' ) );
			}
			/* translators: 1: HTTP status, 2: Google's message. */
			return array( 'error' => trim( sprintf( __( 'Google answered with HTTP %1$d. %2$s', 'unstoppable-site-manager' ), (int) $code, wp_strip_all_tags( substr( $msg, 0, 200 ) ) ) ) );
		}
		$lr    = isset( $d['lighthouseResult'] ) ? $d['lighthouseResult'] : array();
		$score = isset( $lr['categories']['performance']['score'] ) ? $lr['categories']['performance']['score'] : null;
		if ( null === $score ) {
			$why = isset( $lr['runtimeError']['message'] ) ? (string) $lr['runtimeError']['message'] : '';
			return array( 'error' => $why ? wp_strip_all_tags( substr( $why, 0, 200 ) ) : __( 'Google returned no score for this page.', 'unstoppable-site-manager' ) );
		}
		$audit = static function ( $id ) use ( $lr ) {
			return isset( $lr['audits'][ $id ]['displayValue'] ) ? wp_strip_all_tags( (string) $lr['audits'][ $id ]['displayValue'] ) : '';
		};
		return array(
			'score' => (int) round( (float) $score * 100 ),
			'lcp'   => $audit( 'largest-contentful-paint' ),
			'fcp'   => $audit( 'first-contentful-paint' ),
			'tbt'   => $audit( 'total-blocking-time' ),
			'cls'   => $audit( 'cumulative-layout-shift' ),
			'si'    => $audit( 'speed-index' ),
		);
	}

	/** Store a result, keeping the score before it and a short history. */
	private static function record( $site_id, array $res ) {
		$old  = self::get( $site_id );
		$hist = ( $old && isset( $old['history'] ) && is_array( $old['history'] ) ) ? $old['history'] : array();
		$r    = $res + array( 'checked' => time() );
		if ( isset( $res['score'] ) ) {
			$r['prev']    = ( $old && isset( $old['score'] ) ) ? (int) $old['score'] : null;
			$hist[]       = array( time(), (int) $res['score'] );
			$r['history'] = array_slice( $hist, -self::KEEP );
		} else {
			// A failed check keeps the last good score on show.
			foreach ( array( 'score', 'lcp', 'fcp', 'tbt', 'cls', 'si', 'prev', 'scored' ) as $k ) {
				if ( $old && isset( $old[ $k ] ) ) {
					$r[ $k ] = $old[ $k ];
				}
			}
			$r['history'] = $hist;
		}
		if ( isset( $res['score'] ) ) {
			$r['scored'] = time();
		}
		self::put( $site_id, $r );
		return $r;
	}

	/** Check one site now (Check now on the site page). */
	public static function check( $site ) {
		$res = wp_remote_get( self::request_url( $site->url ), array( 'timeout' => self::TIMEOUT ) );
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error message. */
			return self::record( $site->id, array( 'error' => sprintf( __( 'Could not reach Google: %s', 'unstoppable-site-manager' ), $res->get_error_message() ) ) );
		}
		return self::record( $site->id, self::parse( wp_remote_retrieve_response_code( $res ), wp_remote_retrieve_body( $res ) ) );
	}

	/** Sites whose turn it is, oldest check first. */
	public static function due() {
		$out = array();
		foreach ( USMD_Sites::all() as $s ) {
			$r    = self::get( $s->id );
			$wait = ( $r && ! empty( $r['error'] ) ) ? self::RETRY : self::EVERY;
			if ( ! $r || time() - (int) $r['checked'] >= $wait ) {
				$out[ (int) $s->id ] = $r ? (int) $r['checked'] : 0;
			}
		}
		asort( $out );
		return array_keys( $out );
	}

	/** Hourly: check the next few sites, three at a time. */
	public static function tick() {
		if ( ! self::enabled() ) {
			return;
		}
		$n     = count( USMD_Sites::all() );
		$batch = min( self::PER_TICK, max( 1, (int) ceil( $n / ( 7 * 24 ) ) + 1 ) );
		$ids   = array_slice( self::due(), 0, $batch );
		foreach ( array_chunk( $ids, 3 ) as $chunk ) {
			$reqs = array();
			foreach ( $chunk as $id ) {
				$site = USMD_Sites::get( $id );
				if ( $site ) {
					$reqs[ $id ] = array(
						'url'     => self::request_url( $site->url ),
						'type'    => 'GET',
						'headers' => array(),
						'data'    => array(),
						'options' => array( 'timeout' => self::TIMEOUT, 'connect_timeout' => 15 ),
					);
				}
			}
			if ( ! $reqs ) {
				continue;
			}
			$answers = \WpOrg\Requests\Requests::request_multiple( $reqs, array( 'timeout' => self::TIMEOUT ) );
			foreach ( $answers as $id => $a ) {
				if ( $a instanceof \WpOrg\Requests\Response ) {
					self::record( $id, self::parse( $a->status_code, $a->body ) );
				} else {
					/* translators: %s: error message. */
					self::record( $id, array( 'error' => sprintf( __( 'Could not reach Google: %s', 'unstoppable-site-manager' ), is_object( $a ) && method_exists( $a, 'getMessage' ) ? $a->getMessage() : '?' ) ) );
				}
			}
		}
	}

	public static function ajax_check() {
		check_ajax_referer( 'usmd', 'nonce' );
		if ( ! current_user_can( USMD_Security::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'unstoppable-site-manager' ) ), 403 );
		}
		$site = USMD_Sites::get( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( ! $site ) {
			wp_send_json_error( array( 'message' => __( 'Site not found.', 'unstoppable-site-manager' ) ) );
		}
		if ( '' === self::key() ) {
			wp_send_json_error( array( 'message' => __( 'Add a Google API key in Settings > Page speed first.', 'unstoppable-site-manager' ) ) );
		}
		$r = self::check( $site );
		ob_start();
		self::panel( $site );
		wp_send_json_success( array( 'panel' => ob_get_clean(), 'error' => isset( $r['error'] ) ? $r['error'] : '' ) );
	}

	/* ---------- display ---------- */

	/** "Speed 87" for a card's line of details. */
	public static function card_item( $site ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$r = self::get( $site->id );
		if ( ! $r || ! isset( $r['score'] ) ) {
			return '';
		}
		$g = self::grade( $r['score'] );
		/* translators: 1: score, 2: grade. */
		$title = sprintf( __( 'Page speed (mobile): %1$d out of 100, %2$s', 'unstoppable-site-manager' ), $r['score'], self::grade_label( $r['score'] ) );
		return '<li class="usmd-speed is-' . esc_attr( $g ) . '" title="' . esc_attr( $title ) . '">' . esc_html__( 'Speed', 'unstoppable-site-manager' ) . ' ' . (int) $r['score'] . '</li>';
	}

	/** The Page speed box on a site page. */
	public static function panel( $site ) {
		if ( empty( USMD_Admin::settings()['speed_check'] ) ) {
			return;
		}
		$r = self::get( $site->id );
		?>
		<div class="usmd-panel usmd-speed-panel" id="usmd-speed" data-site="<?php echo (int) $site->id; ?>">
			<h2><?php esc_html_e( 'Page speed', 'unstoppable-site-manager' ); ?> <span class="usmd-muted"><?php esc_html_e( '(mobile)', 'unstoppable-site-manager' ); ?></span></h2>
			<?php if ( '' === self::key() ) : ?>
				<p class="usmd-muted"><?php esc_html_e( 'Needs a free Google API key.', 'unstoppable-site-manager' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=usmd-settings#usmd-speed-settings' ) ); ?>"><?php esc_html_e( 'Add it in Settings', 'unstoppable-site-manager' ); ?></a></p>
			<?php elseif ( ! $r ) : ?>
				<p class="usmd-muted"><?php esc_html_e( 'Not checked yet. Each site is checked about once a week.', 'unstoppable-site-manager' ); ?></p>
			<?php else : ?>
				<?php if ( isset( $r['score'] ) ) : ?>
					<div class="usmd-speed-score is-<?php echo esc_attr( self::grade( $r['score'] ) ); ?>">
						<span class="usmd-speed-num"><?php echo (int) $r['score']; ?></span>
						<span class="usmd-speed-of">
							<?php echo esc_html( ucfirst( self::grade_label( $r['score'] ) ) ); ?>
							<?php if ( isset( $r['prev'] ) && null !== $r['prev'] && (int) $r['prev'] !== (int) $r['score'] ) : ?>
								<br><span class="usmd-muted"><?php echo esc_html( sprintf( /* translators: %d: earlier score. */ __( 'was %d', 'unstoppable-site-manager' ), (int) $r['prev'] ) ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<dl class="usmd-dl usmd-speed-metrics">
						<?php
						$m = array(
							'lcp' => __( 'Largest content shown', 'unstoppable-site-manager' ),
							'fcp' => __( 'First content shown', 'unstoppable-site-manager' ),
							'tbt' => __( 'Blocked by scripts', 'unstoppable-site-manager' ),
							'cls' => __( 'Layout shift', 'unstoppable-site-manager' ),
							'si'  => __( 'Speed index', 'unstoppable-site-manager' ),
						);
						foreach ( $m as $k => $label ) {
							if ( ! empty( $r[ $k ] ) ) {
								echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $r[ $k ] ) . '</dd>';
							}
						}
						?>
					</dl>
					<?php if ( ! empty( $r['history'] ) && count( $r['history'] ) > 1 ) : ?>
						<p class="usmd-speed-history usmd-muted">
							<?php esc_html_e( 'Recent scores:', 'unstoppable-site-manager' ); ?>
							<?php echo esc_html( implode( ' → ', array_map( static function ( $h ) { return (int) $h[1]; }, array_slice( $r['history'], -6 ) ) ) ); ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! empty( $r['error'] ) ) : ?>
					<p class="usmd-speed-error"><?php echo esc_html( $r['error'] ); ?></p>
				<?php endif; ?>
				<p class="usmd-muted">
					<?php
					/* translators: %s: time ago. */
					echo esc_html( sprintf( __( 'Checked %s ago.', 'unstoppable-site-manager' ), human_time_diff( (int) $r['checked'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<p class="usmd-speed-actions">
				<?php if ( '' !== self::key() ) : ?>
					<button type="button" class="button button-small usmd-speed-check"><?php esc_html_e( 'Check now', 'unstoppable-site-manager' ); ?></button>
				<?php endif; ?>
				<a class="button button-small" href="<?php echo esc_url( self::report_url( $site->url ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Full report ↗', 'unstoppable-site-manager' ); ?></a>
			</p>
		</div>
		<?php
	}

	/** A line for the client report's health list, or null. */
	public static function report_item( $site ) {
		if ( ! self::enabled() ) {
			return null;
		}
		$r = self::get( $site->id );
		if ( ! $r || ! isset( $r['score'] ) ) {
			return null;
		}
		$g = self::grade( $r['score'] );
		return array(
			__( 'Page speed (mobile)', 'unstoppable-site-manager' ),
			/* translators: 1: score, 2: grade. */
			sprintf( __( '%1$d out of 100, %2$s', 'unstoppable-site-manager' ), $r['score'], self::grade_label( $r['score'] ) ),
			'good' === $g ? 'ok' : ( 'ok' === $g ? 'warn' : 'bad' ),
		);
	}
}
