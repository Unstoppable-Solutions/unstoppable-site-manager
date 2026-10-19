<?php
/**
 * Admin footer branding on client sites.
 *
 * Settings (and each client, if it overrides it) hold a short text and an
 * optional link, such as "Website care by Example Agency". Connector 0.7.0 and
 * newer show it at the bottom of the client site's admin screens instead of
 * "Thank you for creating with WordPress". It never appears on the public site,
 * and the site's administrators can switch it off on their side.
 *
 * Each status report from a site says what branding it has (a hash). When that
 * differs from what this dashboard wants, a background job sends the new text.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Branding {

	const MIN_CONNECTOR = '0.7.0';
	const OPT_REFUSED   = 'usmd_branding_refused';
	const HOOK          = 'usmd_branding_site';

	public static function hooks() {
		add_action( self::HOOK, array( __CLASS__, 'send' ), 10, 1 );
	}

	/** @return array { text, url, hash } this dashboard wants on the site. */
	public static function wanted( $site ) {
		$s    = USMD_Clients::settings_for( $site );
		$text = isset( $s['footer_text'] ) ? trim( (string) $s['footer_text'] ) : '';
		$url  = '' !== $text && ! empty( $s['footer_url'] ) ? (string) $s['footer_url'] : '';
		return array(
			'text' => $text,
			'url'  => $url,
			'hash' => '' === $text ? '' : md5( $text . "\n" . $url ),
		);
	}

	public static function supported( $site ) {
		$v = USMD_Sites::connector_version( $site );
		return '' !== $v && version_compare( $v, self::MIN_CONNECTOR, '>=' );
	}

	/** What the site last reported, or null if its connector doesn't support branding. */
	public static function current( $site, $status = null ) {
		$status = null === $status ? USMD_Sites::status( $site ) : $status;
		return isset( $status['connector']['branding'] ) ? (string) $status['connector']['branding'] : null;
	}

	private static function refused() {
		$r = get_option( self::OPT_REFUSED );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * After a status report: queue a job if the site's branding is out of date.
	 * Called from USMD_Sites::store_status().
	 */
	public static function check( $site_id, array $status ) {
		$site = USMD_Sites::get( $site_id );
		if ( ! $site ) {
			return;
		}
		$have = self::current( $site, $status );
		if ( null === $have ) {
			return; // Older connector.
		}
		$want    = self::wanted( $site );
		$refused = self::refused();
		if ( $have === $want['hash'] || ( isset( $refused[ $site_id ] ) && $refused[ $site_id ] === $want['hash'] ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, array( (int) $site_id ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( (int) $site_id ) );
		}
	}

	/** Send the wanted branding to one site now. */
	public static function send( $site_id ) {
		$site = USMD_Sites::get( $site_id );
		if ( ! $site || ! self::supported( $site ) ) {
			return;
		}
		$want    = self::wanted( $site );
		$r       = USMD_Client::call( $site, 'branding', array( 'text' => $want['text'], 'url' => $want['url'] ), 30, false );
		$refused = self::refused();
		if ( is_wp_error( $r ) ) {
			if ( 'not_permitted' === USMD_Client::connector_code( $r ) ) {
				$refused[ (int) $site_id ] = $want['hash']; // Switched off on the site: don't keep asking.
				update_option( self::OPT_REFUSED, $refused, false );
			}
			return;
		}
		unset( $refused[ (int) $site_id ] );
		update_option( self::OPT_REFUSED, $refused, false );
		// Record the new hash in the stored status so the site page shows it straight away.
		$status = USMD_Sites::status( $site );
		if ( isset( $status['connector'] ) && isset( $r['hash'] ) ) {
			$status['connector']['branding'] = (string) $r['hash'];
			USMD_Sites::store_status( $site->id, $status, false );
		}
	}

	/** After branding changed in Settings or for a client: update the sites it affects. */
	public static function queue_all( $client_id = null ) {
		$delay = 0;
		foreach ( USMD_Sites::all() as $site ) {
			if ( null !== $client_id && (int) $site->client_id !== (int) $client_id ) {
				continue;
			}
			if ( self::supported( $site ) && self::current( $site ) !== self::wanted( $site )['hash'] && ! wp_next_scheduled( self::HOOK, array( (int) $site->id ) ) ) {
				wp_schedule_single_event( time() + $delay, self::HOOK, array( (int) $site->id ) );
				$delay += 5;
			}
		}
	}

	/** One line for the site page: what the admin footer shows there. */
	public static function describe( $site ) {
		$want = self::wanted( $site );
		if ( ! self::supported( $site ) ) {
			return '' === $want['text'] ? '' : __( 'Admin footer branding needs Unstoppable Site Connector 0.7.0 or newer on this site.', 'unstoppable-site-manager' );
		}
		$refused = self::refused();
		if ( '' !== $want['text'] && isset( $refused[ (int) $site->id ] ) && $refused[ (int) $site->id ] === $want['hash'] ) {
			return __( 'Admin footer branding is switched off on this site by its administrator.', 'unstoppable-site-manager' );
		}
		if ( '' === $want['text'] ) {
			return '';
		}
		return self::current( $site ) === $want['hash']
			/* translators: %s: footer text. */
			? sprintf( __( 'Admin footer on this site: "%s".', 'unstoppable-site-manager' ), $want['text'] )
			/* translators: %s: footer text. */
			: sprintf( __( 'Admin footer "%s" will be applied on the next check.', 'unstoppable-site-manager' ), $want['text'] );
	}
}
