<?php
/**
 * Background checks. Each site gets its own job, staggered, so one slow or
 * broken site can never hold up the rest (MainWP's "stuck queue" problem).
 * Sites that push their own reports are only polled if they go quiet.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Sync {

	const STALE_PUSH = 7200;  // A pushing site is polled if silent for 2 hours.
	const PULL_EVERY = 21600; // Pull-only sites are polled every 6 hours.

	public static function hooks() {
		add_action( 'usmd_sync_tick', array( __CLASS__, 'tick' ) );
		add_action( 'usmd_sync_site', array( __CLASS__, 'sync_site' ), 10, 1 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'usmd_sync_tick' ) ) {
			wp_schedule_event( time() + 120, 'hourly', 'usmd_sync_tick' );
		}
	}

	public static function tick() {
		USMD_Packages::cleanup(); // Delete plugin ZIPs held for client sites once they expire.
		$delay       = 0;
		$thumb_delay = 30;
		foreach ( USMD_Sites::all() as $site ) {
			if ( USMD_Thumbs::is_due( $site ) && ! USMD_Thumbs::browser_mode() ) {
				USMD_Thumbs::schedule( $site->id, $thumb_delay );
				$thumb_delay += 20;
			}
			$seen  = $site->last_seen ? strtotime( $site->last_seen . ' UTC' ) : 0;
			$limit = $site->push_enabled ? self::STALE_PUSH : self::PULL_EVERY;
			if ( time() - $seen < $limit ) {
				continue;
			}
			if ( ! wp_next_scheduled( 'usmd_sync_site', array( (int) $site->id ) ) ) {
				wp_schedule_single_event( time() + $delay, 'usmd_sync_site', array( (int) $site->id ) );
				$delay += 15;
			}
		}
	}

	public static function sync_site( $id ) {
		$site = USMD_Sites::get( $id );
		if ( $site ) {
			USMD_Client::refresh( $site, false );
		}
	}
}
