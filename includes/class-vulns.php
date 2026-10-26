<?php
/**
 * Known-vulnerability checks, using the free WPVulnerability database
 * (https://www.wpvulnerability.com/).
 *
 * Off until switched on in Settings, because it sends plugin and theme names
 * (never site addresses) to an outside service. Each plugin, theme or WordPress
 * version is looked up once and shared by every site that has it; answers are
 * kept for 12 hours. Matching against installed versions happens here.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Vulns {

	const API     = 'https://www.wpvulnerability.net/';
	const RESULTS = 'usmd_vulns';
	const CACHE   = 12 * HOUR_IN_SECONDS;
	const RETRY   = HOUR_IN_SECONDS; // After a failed lookup, don't try that item again for an hour.
	const BATCH   = 12;              // Lookups fetched at the same time.
	const TIMEOUT = 8;
	const HOOK    = 'usmd_vuln_site';

	/** @var array|null Results for all sites, loaded once per request. */
	private static $results = null;

	public static function hooks() {
		add_action( self::HOOK, array( __CLASS__, 'check_site_id' ) );
	}

	public static function enabled() {
		return ! empty( USMD_Admin::settings()['vuln_scan'] );
	}

	/* ---------- stored results ---------- */

	private static function all() {
		if ( null === self::$results ) {
			$r             = get_option( self::RESULTS );
			self::$results = is_array( $r ) ? $r : array();
		}
		return self::$results;
	}

	/**
	 * @return array|null { checked: int, items: [ {type, slug, name, version, vulns: [...] } ] } or null if never checked.
	 */
	public static function for_site( $site_id ) {
		$all = self::all();
		return isset( $all[ (int) $site_id ] ) ? $all[ (int) $site_id ] : null;
	}

	/** Number of vulnerable items (plugins, themes, WordPress) on a site. */
	public static function count( $site_id ) {
		$r = self::for_site( $site_id );
		return $r ? count( $r['items'] ) : 0;
	}

	/** Highest severity on a site: critical | high | medium | low | unrated | ''. */
	public static function worst( $site_id ) {
		$r = self::for_site( $site_id );
		if ( ! $r || ! $r['items'] ) {
			return '';
		}
		$rank = array_flip( array( 'unrated', 'low', 'medium', 'high', 'critical' ) );
		$best = 'unrated';
		foreach ( $r['items'] as $it ) {
			foreach ( $it['vulns'] as $v ) {
				if ( $rank[ $v['severity'] ] > $rank[ $best ] ) {
					$best = $v['severity'];
				}
			}
		}
		return $best;
	}

	private static function save( $site_id, $result ) {
		// Re-read just before writing: other sites may have been checked at the same time
		// (bulk refresh runs several sites at once), and their results must not be lost.
		wp_cache_delete( self::RESULTS, 'options' );
		self::$results = null;
		$all           = self::all();
		if ( null === $result ) {
			unset( $all[ (int) $site_id ] );
		} else {
			$all[ (int) $site_id ] = $result;
		}
		self::$results = $all;
		update_option( self::RESULTS, $all, false );
	}

	public static function forget_site( $site_id ) {
		self::save( $site_id, null );
	}

	/** Check soon in the background (after a site reports in). */
	public static function schedule( $site_id ) {
		if ( self::enabled() && ! wp_next_scheduled( self::HOOK, array( (int) $site_id ) ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK, array( (int) $site_id ) );
		}
	}

	public static function check_site_id( $site_id ) {
		$site = USMD_Sites::get( (int) $site_id );
		if ( $site ) {
			self::check_site( $site );
		}
	}

	/* ---------- checking ---------- */

	/**
	 * Check one site's installed versions against the database.
	 *
	 * @return array|WP_Error The stored result.
	 */
	public static function check_site( $site ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'usmd_vulns_off', __( 'Vulnerability checks are turned off in Settings.', 'unstoppable-site-manager' ) );
		}
		$status = USMD_Sites::status( $site );
		if ( ! $status ) {
			return new WP_Error( 'usmd_vulns_nodata', __( 'This site has not reported its plugins yet.', 'unstoppable-site-manager' ) );
		}

		$targets = array();
		foreach ( isset( $status['plugins'] ) ? (array) $status['plugins'] : array() as $p ) {
			$slug = self::slug_from_file( isset( $p['file'] ) ? $p['file'] : '' );
			if ( $slug && ! empty( $p['version'] ) ) {
				$targets[] = array( 'plugin', $slug, isset( $p['name'] ) ? $p['name'] : $slug, (string) $p['version'] );
			}
		}
		foreach ( isset( $status['themes'] ) ? (array) $status['themes'] : array() as $t ) {
			if ( ! empty( $t['slug'] ) && ! empty( $t['version'] ) ) {
				$targets[] = array( 'theme', sanitize_key( $t['slug'] ), isset( $t['name'] ) ? $t['name'] : $t['slug'], (string) $t['version'] );
			}
		}
		if ( ! empty( $status['core']['version'] ) ) {
			$targets[] = array( 'core', '', 'WordPress', (string) $status['core']['version'] );
		}

		self::prefetch( $targets );

		$items  = array();
		$failed = 0;
		foreach ( $targets as $t ) {
			list( $type, $slug, $name, $version ) = $t;
			$known = self::lookup( $type, 'core' === $type ? $version : $slug );
			if ( is_wp_error( $known ) ) {
				++$failed;
				continue;
			}
			$hits = array();
			foreach ( $known as $v ) {
				if ( self::affects( $v, $version ) ) {
					$hits[] = $v;
				}
			}
			if ( $hits ) {
				usort(
					$hits,
					static function ( $a, $b ) {
						return $b['score'] <=> $a['score'];
					}
				);
				$items[] = array(
					'type'    => $type,
					'slug'    => $slug,
					'name'    => html_entity_decode( wp_strip_all_tags( $name ), ENT_QUOTES, 'UTF-8' ),
					'version' => $version,
					'vulns'   => $hits,
				);
			}
		}

		$prev = self::for_site( $site->id );
		if ( $failed && $failed === count( $targets ) ) {
			// The service didn't answer at all: keep what we knew.
			return new WP_Error( 'usmd_vulns_service', __( 'The vulnerability database could not be reached. Try again later.', 'unstoppable-site-manager' ) );
		}
		$result = array(
			'checked' => time(),
			'items'   => $items,
			'partial' => $failed > 0,
		);
		self::save( $site->id, $result );

		// Log newly found problems in the dashboard's activity for this site.
		$before = array();
		foreach ( $prev ? $prev['items'] : array() as $it ) {
			$before[ $it['type'] . ':' . $it['slug'] . ':' . $it['version'] ] = true;
		}
		foreach ( $items as $it ) {
			if ( ! isset( $before[ $it['type'] . ':' . $it['slug'] . ':' . $it['version'] ] ) ) {
				USMD_Sites::add_local_activity( $site->id, 'security', 'vulnerable', $it['name'] . ' ' . $it['version'], array( 'count' => count( $it['vulns'] ), 'severity' => $it['vulns'][0]['severity'] ) );
			}
		}
		return $result;
	}

	/**
	 * Known vulnerabilities an update fixed: they affected the old version and not the new one.
	 * Looks items up (cached for 12 hours, a dozen at a time) when they aren't cached yet.
	 *
	 * @param array $updates [ [ type, slug, from, to ], ... ] with type plugin|theme|core.
	 * @return array Same keys => list of fixed vulnerabilities (normalised), only those with fixes.
	 */
	public static function fixed_by_updates( array $updates ) {
		if ( ! self::enabled() ) {
			return array();
		}
		$targets = array();
		foreach ( $updates as $u ) {
			if ( '' === $u[2] || '' === $u[3] || ( 'core' !== $u[0] && '' === $u[1] ) ) {
				continue;
			}
			$targets[] = array( $u[0], $u[1], '', 'core' === $u[0] ? $u[2] : '' );
		}
		self::prefetch( $targets );
		$out = array();
		foreach ( $updates as $key => $u ) {
			list( $type, $slug, $from, $to ) = $u;
			if ( '' === $from || '' === $to || version_compare( $to, $from, '<=' ) ) {
				continue;
			}
			$known = self::lookup( $type, 'core' === $type ? $from : $slug );
			if ( is_wp_error( $known ) ) {
				continue;
			}
			$fixed = array();
			foreach ( $known as $k ) {
				if ( self::affects( $k, $from ) && ! self::affects( $k, $to ) ) {
					$fixed[] = $k;
				}
			}
			if ( $fixed ) {
				usort(
					$fixed,
					static function ( $a, $b ) {
						return $b['score'] <=> $a['score'];
					}
				);
				$out[ $key ] = $fixed;
			}
		}
		return $out;
	}

	/** "elementor/elementor.php" -> "elementor"; single-file plugins use their file name. */
	public static function slug_from_file( $file ) {
		$file = (string) $file;
		$slug = false !== strpos( $file, '/' ) ? dirname( $file ) : preg_replace( '/\.php$/', '', $file );
		return preg_match( '/^[a-z0-9][a-z0-9._-]{0,100}$/i', $slug ) ? strtolower( $slug ) : '';
	}

	/**
	 * Known vulnerabilities for one plugin / theme / WordPress version, from cache or the API.
	 *
	 * @return array|WP_Error Normalised list.
	 */
	private static function key( $type, $id ) {
		return 'usmd_wpv_' . md5( $type . ':' . $id );
	}

	private static function url( $type, $id ) {
		return self::API . $type . '/' . rawurlencode( $id ) . '/';
	}

	/**
	 * Known vulnerabilities for one plugin / theme / WordPress version, from cache or the API.
	 *
	 * @return array|WP_Error Normalised list.
	 */
	public static function lookup( $type, $id ) {
		$cached = get_transient( self::key( $type, $id ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( get_transient( self::key( $type, $id ) . '_f' ) ) {
			return new WP_Error( 'usmd_vulns_service', 'Lookup failed recently' );
		}
		$res = wp_safe_remote_get(
			self::url( $type, $id ),
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => self::agent(),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		return self::store( $type, $id, $code, $code ? wp_remote_retrieve_body( $res ) : '' );
	}

	private static function agent() {
		return 'Unstoppable-Site-Manager/' . USMD_VERSION . '; ' . home_url( '/' );
	}

	/** Turn an API answer into a cached list (or a briefly cached failure). */
	private static function store( $type, $id, $code, $body ) {
		$key = self::key( $type, $id );
		if ( 404 === $code ) {
			// Not in the database (for example a development version of WordPress): nothing known.
			set_transient( $key, array(), self::CACHE );
			return array();
		}
		$data = 200 === $code ? json_decode( (string) $body, true ) : null;
		if ( ! is_array( $data ) || ! empty( $data['error'] ) ) {
			set_transient( $key . '_f', 1, self::RETRY );
			return new WP_Error( 'usmd_vulns_service', 'Lookup failed' );
		}
		$list = array();
		$raw  = isset( $data['data']['vulnerability'] ) && is_array( $data['data']['vulnerability'] ) ? $data['data']['vulnerability'] : array();
		foreach ( $raw as $v ) {
			$list[] = self::normalise( $v );
		}
		set_transient( $key, $list, self::CACHE );
		return $list;
	}

	/**
	 * Fetch every item not cached yet, several at a time, so a site with 30 plugins
	 * takes about as long as one lookup instead of thirty in a row.
	 */
	private static function prefetch( array $targets ) {
		$todo = array();
		foreach ( $targets as $t ) {
			$id  = 'core' === $t[0] ? $t[3] : $t[1];
			$key = self::key( $t[0], $id );
			if ( ! is_array( get_transient( $key ) ) && ! get_transient( $key . '_f' ) ) {
				$todo[ $key ] = array( $t[0], $id );
			}
		}
		if ( ! $todo ) {
			return;
		}
		_wp_http_get_object(); // Loads the Requests library.
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			$class = '\WpOrg\Requests\Requests';
		} elseif ( class_exists( 'Requests' ) ) {
			$class = 'Requests';
		} else {
			return; // Each item is then looked up one by one.
		}
		$options = array(
			'timeout'         => self::TIMEOUT,
			'connect_timeout' => 5,
			'useragent'       => self::agent(),
			'verify'          => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
		);
		foreach ( array_chunk( $todo, self::BATCH, true ) as $chunk ) {
			$requests = array();
			foreach ( $chunk as $key => $item ) {
				$requests[ $key ] = array(
					'url'  => self::url( $item[0], $item[1] ),
					'type' => 'GET',
				);
			}
			try {
				$responses = call_user_func( array( $class, 'request_multiple' ), $requests, $options );
			} catch ( Exception $e ) {
				return;
			}
			foreach ( $chunk as $key => $item ) {
				$r    = isset( $responses[ $key ] ) ? $responses[ $key ] : null;
				$ok   = is_object( $r ) && isset( $r->status_code );
				self::store( $item[0], $item[1], $ok ? (int) $r->status_code : 0, $ok ? $r->body : '' );
			}
		}
	}

	/** Keep only what we show and match on. */
	private static function normalise( array $v ) {
		$op     = isset( $v['operator'] ) && is_array( $v['operator'] ) ? $v['operator'] : array();
		$impact = isset( $v['impact'] ) && is_array( $v['impact'] ) ? $v['impact'] : array();
		$cvss   = isset( $impact['cvss'] ) && is_array( $impact['cvss'] ) ? $impact['cvss'] : array();
		$sev    = array(
			'c' => 'critical',
			'h' => 'high',
			'm' => 'medium',
			'l' => 'low',
		);
		$sources = isset( $v['source'] ) && is_array( $v['source'] ) ? $v['source'] : array();
		$title   = '';
		$link    = '';
		$cve     = '';
		$best    = -99;
		foreach ( $sources as $s ) {
			$name = isset( $s['name'] ) ? html_entity_decode( (string) $s['name'], ENT_QUOTES, 'UTF-8' ) : '';
			if ( ! $cve && ! empty( $s['id'] ) && 0 === strpos( (string) $s['id'], 'CVE-' ) ) {
				$cve = (string) $s['id'];
			}
			if ( '' === $name ) {
				continue;
			}
			// Prefer an advisory title that says what the problem is ("… - Cross-Site Scripting")
			// over a bare CVE number or just the plugin's name.
			$score = ( false !== strpos( $name, ' - ' ) ? 3 : 0 ) + ( preg_match( '/vulnerab|injection|scripting|xss|upload|bypass|disclosure|forgery|csrf|redirect|execution|escalation|traversal|deserial/i', $name ) ? 2 : 0 ) - ( 0 === strpos( $name, 'CVE-' ) ? 5 : 0 );
			if ( $score > $best ) {
				$best  = $score;
				$title = $name;
				$link  = isset( $s['link'] ) ? (string) $s['link'] : $link;
			}
		}
		// Still only a name? Add the kind of weakness.
		$cwe = isset( $impact['cwe'][0]['name'] ) ? (string) $impact['cwe'][0]['name'] : '';
		if ( $best <= 0 && '' !== $cwe ) {
			$cwe   = preg_replace( "/^.*\\('([^']+)'\\).*$/", '$1', $cwe ); // "Improper Neutralization… ('Cross-site Scripting')" -> "Cross-site Scripting".
			$title = ( $title ? $title . ': ' : '' ) . $cwe;
		}
		$max_op = isset( $op['max_operator'] ) ? (string) $op['max_operator'] : '';
		$max    = isset( $op['max_version'] ) ? (string) $op['max_version'] : '';
		$unfix  = ! empty( $op['unfixed'] ) && '0' !== (string) $op['unfixed'];
		return array(
			// Plain text, escaped when shown. Not run through strip_tags: titles such as "Plugin <= 5.8.3 - …" contain "<".
			'title'    => trim( preg_replace( '/\s+/', ' ', html_entity_decode( $title ? $title : ( isset( $v['name'] ) ? (string) $v['name'] : '' ), ENT_QUOTES, 'UTF-8' ) ) ),
			'cve'      => $cve,
			'link'     => esc_url_raw( $link ),
			'min'      => isset( $op['min_version'] ) ? (string) $op['min_version'] : '',
			'min_op'   => isset( $op['min_operator'] ) ? (string) $op['min_operator'] : '',
			'max'      => $max,
			'max_op'   => $max_op,
			'unfixed'  => $unfix,
			'fixed_in' => ( ! $unfix && 'lt' === $max_op ) ? $max : '',
			'severity' => isset( $cvss['severity'], $sev[ $cvss['severity'] ] ) ? $sev[ $cvss['severity'] ] : 'unrated',
			'score'    => isset( $cvss['score'] ) ? (float) $cvss['score'] : 0.0,
		);
	}

	/** Does this vulnerability apply to the installed version? */
	public static function affects( array $v, $version ) {
		$map = array(
			'lt' => '<',
			'le' => '<=',
			'eq' => '==',
			'ge' => '>=',
			'gt' => '>',
		);
		if ( '' === $v['min'] && '' === $v['max'] ) {
			return (bool) $v['unfixed']; // No range given: only when it's marked as affecting every version.
		}
		if ( '' !== $v['min'] && isset( $map[ $v['min_op'] ] ) && ! version_compare( $version, $v['min'], $map[ $v['min_op'] ] ) ) {
			return false;
		}
		if ( '' !== $v['max'] && isset( $map[ $v['max_op'] ] ) && ! version_compare( $version, $v['max'], $map[ $v['max_op'] ] ) ) {
			return false;
		}
		return true;
	}
}
