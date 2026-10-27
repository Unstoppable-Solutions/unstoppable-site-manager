<?php
/**
 * Talks to Unstoppable Site Connector (or FM Connector, its former name) on client sites.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Client {

	public static function decode_key( $key ) {
		$key  = preg_replace( '/\s+/', '', (string) $key );
		$data = json_decode( (string) base64_decode( $key, true ), true );
		if ( ! is_array( $data ) || empty( $data['api'] ) || empty( $data['code'] ) || empty( $data['site'] ) ) {
			return new WP_Error( 'usmd_key', "That doesn't look like a connection key. Copy it again from Tools > Unstoppable Site Connector on the client site." );
		}
		return $data;
	}

	/**
	 * Pair with a site using its connection key.
	 *
	 * @return int|WP_Error Site row id.
	 */
	public static function pair( $connection_key ) {
		$k = self::decode_key( $connection_key );
		if ( is_wp_error( $k ) ) {
			return $k;
		}

		$push = true;
		$r    = self::pair_request( $k, USMD_Receiver::push_url() );

		// The client can refuse our push URL (e.g. this dashboard is on a private
		// network or a non-standard port). The code isn't used up in that case,
		// so fall back to pull-only mode: the dashboard checks in on a schedule.
		if ( is_wp_error( $r ) && 'bad_push_url' === self::connector_code( $r ) ) {
			$push = false;
			$r    = self::pair_request( $k, '' );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}

		$id = USMD_Sites::save_connection(
			array(
				'site_uuid'    => $r['site_id'],
				'url'          => $k['site'],
				'api'          => $k['api'],
				'child_pubkey' => $r['child_pubkey'],
				'push_enabled' => $push,
			)
		);
		if ( ! empty( $r['status'] ) && is_array( $r['status'] ) ) {
			USMD_Sites::store_status( $id, $r['status'] );
		}
		self::sync_activity( USMD_Sites::get( $id ) );
		USMD_Thumbs::schedule( $id, 5 );
		return $id;
	}

	private static function pair_request( array $k, $push_url ) {
		$response = wp_remote_post(
			$k['api'] . 'pair',
			array(
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'code'             => $k['code'],
						'dashboard_pubkey' => USMD_Keys::public_key(),
						'dashboard_name'   => get_bloginfo( 'name' ),
						'push_url'         => $push_url,
					)
				),
			)
		);
		return self::parse( $response );
	}

	/**
	 * Signed call to a connected site.
	 *
	 * @return array|WP_Error
	 */
	public static function call( $site, $route, array $params = array(), $timeout = 30, $mark_error = true ) {
		if ( USMD_Move::is_moved() && ! in_array( $route, array( 'handover', 'ping' ), true ) ) {
			$m = USMD_Move::state();
			/* translators: %s: new dashboard address. */
			return new WP_Error( 'usmd_moved', sprintf( __( 'This dashboard was moved to %s. Manage this site there, or cancel the move under Add Site > Move dashboard.', 'unstoppable-site-manager' ), $m['host'] ) );
		}
		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			$params['_by'] = $user->user_login; // Shown in the site's security alerts.
		}
		$body    = wp_json_encode( (object) $params );
		$headers = USMD_Keys::sign_headers( self::route_path( $site->api, $route ), $body );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}
		$response = wp_remote_post(
			$site->api . $route,
			array(
				'timeout' => $timeout,
				'headers' => $headers + array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
			)
		);
		$r = self::parse( $response );
		if ( is_wp_error( $r ) && $mark_error && self::is_connection_error( $r ) ) {
			USMD_Sites::mark_error( $site->id, $r->get_error_message() );
		}
		return $r;
	}

	/**
	 * Only failures to reach or authenticate with the site make it "offline".
	 * A site that answers but refuses one job (old connector, no file access, a plugin
	 * that failed to install) is still online.
	 */
	public static function is_connection_error( WP_Error $e ) {
		$code = (string) $e->get_error_code();
		if ( 0 === strpos( $code, 'usmd_http' ) ) {
			return true;
		}
		if ( in_array( $code, array( 'usmd_404', 'usmd_json', 'rest_forbidden', 'rest_disabled' ), true ) ) {
			return true;
		}
		return in_array( self::connector_code( $e ), array( 'not_paired', 'unsigned', 'clock', 'nonce', 'bad_key', 'bad_signature' ), true );
	}

	/**
	 * A connector error code without its prefix: "uscn_clock" (Unstoppable Site Connector)
	 * and "fmc_clock" (FM Connector, its former name) both give "clock".
	 */
	public static function connector_code( WP_Error $e ) {
		return (string) preg_replace( '/^(uscn|fmc)_/', '', (string) $e->get_error_code() );
	}

	/**
	 * The REST route the request is signed for, from the site's API base: "/uscn/v1/status",
	 * or "/fmc/v1/status" for sites paired while the connector was still FM Connector.
	 */
	public static function route_path( $api, $route ) {
		$ns = preg_match( '#(uscn|fmc)/v1/?#', (string) $api, $m ) ? $m[1] : 'fmc';
		return '/' . $ns . '/v1/' . $route;
	}

	/**
	 * Name what refused a request, from the block page's headers and text, so the
	 * error says where to allow the dashboard. Empty when it can't tell.
	 */
	public static function blocker( $response ) {
		$h    = static function ( $name ) use ( $response ) {
			return strtolower( (string) wp_remote_retrieve_header( $response, $name ) );
		};
		$body = strtolower( substr( (string) wp_remote_retrieve_body( $response ), 0, 20000 ) );
		$srv  = $h( 'server' );
		if ( $h( 'cf-ray' ) || false !== strpos( $srv, 'cloudflare' ) ) {
			return 'Cloudflare';
		}
		if ( $h( 'x-sucuri-id' ) || $h( 'x-sucuri-block' ) || false !== strpos( $body, 'sucuri' ) ) {
			return 'Sucuri';
		}
		$known = array(
			'wordfence'           => 'Wordfence',
			'malcare'             => 'MalCare',
			'blogvault'           => 'MalCare/BlogVault',
			'imunify'             => 'Imunify360 (host firewall)',
			'mod_security'        => 'ModSecurity (host firewall)',
			'modsecurity'         => 'ModSecurity (host firewall)',
			'solid security'      => 'Solid Security',
			'ithemes security'    => 'Solid Security (iThemes)',
			'all in one wp security' => 'All In One WP Security',
			'shield security'     => 'Shield Security',
			'bulletproof'         => 'BulletProof Security',
			'sg security'         => 'SiteGround security',
			'siteground'          => 'SiteGround',
			'godaddy'             => 'GoDaddy firewall',
			'litespeed'           => 'LiteSpeed (host firewall)',
		);
		foreach ( $known as $needle => $name ) {
			if ( false !== strpos( $body, $needle ) ) {
				return $name;
			}
		}
		if ( $h( 'x-wpe-request-id' ) || false !== strpos( $body, 'wp engine' ) ) {
			return 'WP Engine';
		}
		return '';
	}

	private static function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'usmd_http', 'Could not reach the site: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			if ( is_array( $data ) && ! empty( $data['code'] ) ) {
				return new WP_Error( $data['code'], isset( $data['message'] ) ? $data['message'] : $data['code'] );
			}
			if ( 404 === $code ) {
				return new WP_Error( 'usmd_404', 'The Unstoppable Site Connector was not found on the site (HTTP 404). Is the plugin active and the REST API reachable?' );
			}
			if ( in_array( $code, array( 401, 403, 406, 429 ), true ) ) {
				$who = self::blocker( $response );
				if ( $who ) {
					return new WP_Error( 'usmd_http_' . $code, 'The site is up but ' . $who . ' blocked the dashboard (HTTP ' . $code . '). In ' . $who . ', allow /wp-json/uscn/v1/ or this dashboard\'s server IP.' );
				}
				return new WP_Error( 'usmd_http_' . $code, 'The site is up but blocked the dashboard (HTTP ' . $code . '). A security plugin, host firewall or Cloudflare is blocking /wp-json/uscn/v1/. Allow that path or this dashboard\'s server IP there.' );
			}
			return new WP_Error( 'usmd_http_' . $code, 'The site answered with HTTP ' . $code . '. A security plugin or firewall may be blocking the REST API.' );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'usmd_json', 'The site returned something that is not JSON. Another plugin may be printing output.' );
		}
		return $data;
	}

	/* ---------- higher-level operations ---------- */

	public static function refresh( $site, $force = true ) {
		$status = self::call( $site, 'status', array( 'refresh' => (bool) $force ), 60 );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		USMD_Sites::store_status( $site->id, $status );
		// Newer connectors say how far their activity log goes; skip the extra request when nothing is new.
		$last = isset( $status['connector']['activity_last_id'] ) ? (int) $status['connector']['activity_last_id'] : -1;
		if ( $last < 0 || $last > USMD_Sites::max_remote_id( $site->id ) ) {
			self::sync_activity( $site );
		}
		return $status;
	}

	public static function sync_activity( $site ) {
		if ( ! $site ) {
			return;
		}
		$since = USMD_Sites::max_remote_id( $site->id );
		for ( $page = 0; $page < 10; $page++ ) {
			$r = self::call( $site, 'activity', array( 'since_id' => $since, 'limit' => 500 ) );
			if ( is_wp_error( $r ) || empty( $r['activity'] ) ) {
				return;
			}
			$since = USMD_Sites::add_activity( $site->id, $r['activity'] );
			if ( count( $r['activity'] ) < 500 ) {
				return;
			}
		}
	}

	/**
	 * Install, activate, deactivate or delete plugins on one site.
	 *
	 * @return array|WP_Error Per-item results.
	 */
	public static function plugins( $site, $op, array $items, array $args = array() ) {
		$r = self::call(
			$site,
			'plugins',
			array(
				'op'        => $op,
				'items'     => array_values( $items ),
				'activate'  => ! empty( $args['activate'] ),
				'overwrite' => ! empty( $args['overwrite'] ),
			),
			300
		);
		if ( is_wp_error( $r ) ) {
			if ( 'rest_no_route' === $r->get_error_code() ) {
				return new WP_Error(
					'usmd_old_connector',
					sprintf(
						/* translators: %s: URL of the client site's Upload Plugin screen. */
						__( 'This site runs connector 0.1, which can\'t receive plugins from the dashboard. Update it once by hand at %s (upload the new connector ZIP, then "Replace current with uploaded"). After that, updates can be pushed from here.', 'unstoppable-site-manager' ),
						USMD_Sites::upload_url( $site )
					)
				);
			}
			return $r;
		}
		if ( ! empty( $r['status'] ) ) {
			USMD_Sites::store_status( $site->id, $r['status'] );
		}
		self::sync_activity( $site );
		return isset( $r['results'] ) ? $r['results'] : array();
	}

	/**
	 * @param array|null $items Null means "everything that has an update".
	 * @return array|WP_Error Per-item results.
	 */
	public static function update( $site, $items = null ) {
		if ( null === $items ) {
			$items  = array();
			$status = USMD_Sites::status( $site );
			if ( ! empty( $status['core']['update'] ) ) {
				$items[] = array( 'type' => 'core' );
			}
			foreach ( isset( $status['plugins'] ) ? $status['plugins'] : array() as $p ) {
				if ( ! empty( $p['update'] ) ) {
					$items[] = array( 'type' => 'plugin', 'id' => $p['file'] );
				}
			}
			foreach ( isset( $status['themes'] ) ? $status['themes'] : array() as $t ) {
				if ( ! empty( $t['update'] ) ) {
					$items[] = array( 'type' => 'theme', 'id' => $t['slug'] );
				}
			}
		}
		if ( ! $items ) {
			return array();
		}

		$r = self::call( $site, 'update', array( 'items' => array_values( $items ) ), 300 );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! empty( $r['status'] ) ) {
			USMD_Sites::store_status( $site->id, $r['status'] );
		}
		self::sync_activity( $site );
		// Fresh screenshot a minute after updates, to catch a page an update broke.
		USMD_Thumbs::schedule( $site->id, 60 );
		return isset( $r['results'] ) ? $r['results'] : array();
	}

	/**
	 * Ask the site for a one-time login link (valid 60 seconds, single use).
	 *
	 * @param string $redirect Optional wp-admin path to land on, e.g. "plugins.php".
	 * @return array|WP_Error { url, user }
	 */
	public static function login_url( $site, $redirect = '' ) {
		$r = self::call( $site, 'login', $redirect ? array( 'redirect' => $redirect ) : array(), 20, false );
		if ( is_wp_error( $r ) ) {
			if ( 'rest_no_route' === $r->get_error_code() ) {
				return new WP_Error( 'usmd_old_connector', __( 'This site runs an older connector without one-click login. Update the connector on the site to sign in automatically.', 'unstoppable-site-manager' ) );
			}
			return $r;
		}
		$url = isset( $r['url'] ) ? (string) $r['url'] : '';
		// Only follow a link back to the same site we asked.
		$host = static function ( $u ) {
			return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) );
		};
		if ( ! $url || $host( $url ) !== $host( $site->url ) ) {
			return new WP_Error( 'usmd_login_bad_url', __( 'The site returned an unexpected login link.', 'unstoppable-site-manager' ) );
		}
		return array(
			'url'  => $url,
			'user' => isset( $r['user'] ) ? (string) $r['user'] : '',
		);
	}
}
