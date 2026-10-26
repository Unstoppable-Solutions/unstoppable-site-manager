<?php
/**
 * Storage for managed sites and their activity history.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Sites {

	public static function t() {
		global $wpdb;
		return $wpdb->prefix . 'usmd_sites';
	}

	public static function ta() {
		global $wpdb;
		return $wpdb->prefix . 'usmd_activity';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::t() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				site_uuid varchar(36) NOT NULL,
				name varchar(191) NOT NULL DEFAULT '',
				url varchar(255) NOT NULL,
				api varchar(255) NOT NULL,
				child_pubkey varchar(64) NOT NULL,
				push_enabled tinyint(1) NOT NULL DEFAULT 1,
				status longtext NULL,
				updates int(11) NOT NULL DEFAULT 0,
				plugin_updates int(11) NOT NULL DEFAULT 0,
				theme_updates int(11) NOT NULL DEFAULT 0,
				core_update tinyint(1) NOT NULL DEFAULT 0,
				ssl_days int(11) NULL,
				wp_version varchar(30) NOT NULL DEFAULT '',
				php_version varchar(30) NOT NULL DEFAULT '',
				last_seen datetime NULL,
				last_error text NULL,
				thumb_file varchar(191) NOT NULL DEFAULT '',
				thumb_at datetime NULL,
				thumb_error text NULL,
				starred tinyint(1) NOT NULL DEFAULT 0,
				client_name varchar(191) NOT NULL DEFAULT '',
				client_email varchar(255) NOT NULL DEFAULT '',
				report_auto tinyint(1) NOT NULL DEFAULT 0,
				client_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY site_uuid (site_uuid),
				KEY client_id (client_id)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::ta() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				site_id bigint(20) unsigned NOT NULL,
				remote_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				type varchar(20) NOT NULL,
				action varchar(40) NOT NULL,
				object varchar(191) NOT NULL DEFAULT '',
				actor varchar(60) NOT NULL DEFAULT '',
				details longtext NULL,
				PRIMARY KEY  (id),
				KEY site_time (site_id,created_at),
				KEY site_remote (site_id,remote_id)
			) {$c};"
		);
		// Clients that sites are grouped under (see USMD_Clients).
		dbDelta(
			'CREATE TABLE ' . USMD_Clients::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				settings longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$c};"
		);
		// Plugin ZIPs held for client sites to download (see USMD_Packages).
		dbDelta(
			'CREATE TABLE ' . USMD_Packages::table() . " (
				token char(32) NOT NULL,
				seq int(10) unsigned NOT NULL,
				data longtext NOT NULL,
				PRIMARY KEY  (token,seq)
			) {$c};"
		);
	}

	/* ---------- reads ---------- */

	public static function all() {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', self::t() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t(), (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
	}

	public static function get_by_uuid( $uuid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE site_uuid = %s', self::t(), (string) $uuid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
	}

	public static function get_by_url( $url ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE url = %s', self::t(), untrailingslashit( (string) $url ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
	}

	public static function status( $site ) {
		$s = ( $site && $site->status ) ? json_decode( $site->status, true ) : null;
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Connector version from the last report: '' when the site has never reported,
	 * '0.1' when it reported without a version (only 0.1.x did that).
	 */
	public static function connector_version( $site ) {
		$s = self::status( $site );
		if ( ! $s ) {
			return '';
		}
		return ! empty( $s['connector']['version'] ) ? (string) $s['connector']['version'] : '0.1';
	}

	/**
	 * @return string '' (current or unknown), 'push' (can be updated from the dashboard) or 'manual' (must be updated on the site).
	 */
	public static function connector_state( $site ) {
		$v = self::connector_version( $site );
		if ( '' === $v || version_compare( $v, USMD_LATEST_CONNECTOR, '>=' ) ) {
			return '';
		}
		return version_compare( $v, USMD_PUSHABLE_CONNECTOR, '>=' ) ? 'push' : 'manual';
	}

	/** The client site's own "Upload Plugin" screen. */
	public static function upload_url( $site ) {
		return trailingslashit( $site->url ) . 'wp-admin/plugin-install.php?tab=upload';
	}

	/* ---------- writes ---------- */

	public static function save_connection( array $d ) {
		global $wpdb;
		$url      = untrailingslashit( $d['url'] );
		$existing = self::get_by_url( $url );
		$row      = array(
			'site_uuid'    => $d['site_uuid'],
			'url'          => $url,
			'api'          => $d['api'],
			'child_pubkey' => $d['child_pubkey'],
			'push_enabled' => $d['push_enabled'] ? 1 : 0,
			'last_error'   => null,
		);
		if ( $existing ) {
			$wpdb->update( self::t(), $row, array( 'id' => $existing->id ) );
			return (int) $existing->id;
		}
		$row['name']       = $url;
		$row['created_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( self::t(), $row );
		return (int) $wpdb->insert_id;
	}

	public static function store_status( $id, array $status, $check_branding = true ) {
		if ( class_exists( 'USMD_Toolbar' ) ) {
			USMD_Toolbar::flush(); // Counts in the top toolbar.
		}
		global $wpdb;
		$sum = isset( $status['summary'] ) ? $status['summary'] : array();
		$wpdb->update(
			self::t(),
			array(
				'status'         => wp_json_encode( $status ),
				'name'           => ! empty( $status['site']['name'] ) ? substr( $status['site']['name'], 0, 191 ) : '',
				'updates'        => isset( $sum['total_updates'] ) ? (int) $sum['total_updates'] : 0,
				'plugin_updates' => isset( $sum['plugin_updates'] ) ? (int) $sum['plugin_updates'] : 0,
				'theme_updates'  => isset( $sum['theme_updates'] ) ? (int) $sum['theme_updates'] : 0,
				'core_update'    => ! empty( $sum['core_update'] ) ? 1 : 0,
				'ssl_days'       => isset( $sum['ssl_days_left'] ) ? (int) $sum['ssl_days_left'] : null,
				'wp_version'     => isset( $status['core']['version'] ) ? $status['core']['version'] : '',
				'php_version'    => isset( $status['environment']['php'] ) ? $status['environment']['php'] : '',
				'last_seen'      => gmdate( 'Y-m-d H:i:s' ),
				'last_error'     => null,
			),
			array( 'id' => (int) $id )
		);
		// A renamed connector reports its new API address; switch to it (same site only).
		$api = isset( $status['connector']['api'] ) ? esc_url_raw( (string) $status['connector']['api'] ) : '';
		if ( $api && preg_match( '#/uscn/v1/?$|rest_route=/uscn/v1/?$#', $api ) ) {
			$site = self::get( $id );
			$host = static function ( $u ) {
				return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) );
			};
			if ( $site && $site->api !== $api && $host( $api ) === $host( $site->url ) ) {
				$wpdb->update( self::t(), array( 'api' => $api ), array( 'id' => (int) $id ) );
			}
		}
		// The site switched to https (or added/removed www): keep its address current.
		$home = isset( $status['site']['home'] ) ? untrailingslashit( esc_url_raw( (string) $status['site']['home'] ) ) : '';
		if ( $home ) {
			$site  = isset( $site ) ? $site : self::get( $id );
			$plain = static function ( $u ) {
				$p = wp_parse_url( strtolower( (string) $u ) );
				return preg_replace( '/^www\./', '', isset( $p['host'] ) ? $p['host'] : '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . untrailingslashit( isset( $p['path'] ) ? $p['path'] : '' );
			};
			if ( $site && $site->url !== $home && $plain( $site->url ) === $plain( $home ) ) {
				$wpdb->update( self::t(), array( 'url' => $home ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
			}
		}
		USMD_Vulns::schedule( $id );
		// An empty name falls back to the URL.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET name = url WHERE id = %d AND name = ''", self::t(), (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		if ( $check_branding ) {
			USMD_Branding::check( $id, $status );
		}
	}

	/** Clear "errors" that were really refused jobs, not connection problems. */
	public static function clear_job_errors() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_error = NULL WHERE last_error LIKE %s', self::t(), '%' . $wpdb->esc_like( 'older connector' ) . '%' ) );
	}

	public static function mark_error( $id, $message ) {
		global $wpdb;
		$wpdb->update( self::t(), array( 'last_error' => substr( (string) $message, 0, 1000 ) ), array( 'id' => (int) $id ) );
	}

	public static function set_thumb( $id, $file ) {
		global $wpdb;
		$wpdb->update( self::t(), array( 'thumb_file' => $file, 'thumb_at' => gmdate( 'Y-m-d H:i:s' ), 'thumb_error' => null ), array( 'id' => (int) $id ) );
	}

	/**
	 * Client contact for reports. Emails: up to 5, comma-separated, each validated.
	 *
	 * @return string[] The emails kept.
	 */
	public static function set_client( $id, $name, $emails, $auto ) {
		global $wpdb;
		$list = self::parse_emails( $emails );
		$wpdb->update(
			self::t(),
			array(
				'client_name'  => substr( sanitize_text_field( $name ), 0, 191 ),
				'client_email' => implode( ', ', $list ),
				'report_auto'  => ( $auto && $list ) ? 1 : 0,
			),
			array( 'id' => (int) $id )
		);
		return $list;
	}

	/** "a@x.com, b@y.com" -> valid, unique addresses (max 5). */
	public static function parse_emails( $emails ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $emails ) as $e ) {
			$e = sanitize_email( $e );
			if ( $e && is_email( $e ) && ! in_array( strtolower( $e ), array_map( 'strtolower', $out ), true ) ) {
				$out[] = $e;
			}
		}
		return array_slice( $out, 0, 5 );
	}

	public static function set_starred( $id, $starred ) {
		global $wpdb;
		$wpdb->update( self::t(), array( 'starred' => $starred ? 1 : 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	/** Starred sites first, then by name. */
	public static function all_starred_first() {
		$sites = self::all();
		usort(
			$sites,
			static function ( $a, $b ) {
				return array( empty( $a->starred ), strtolower( $a->name ) ) <=> array( empty( $b->starred ), strtolower( $b->name ) );
			}
		);
		return $sites;
	}

	public static function set_thumb_error( $id, $message ) {
		global $wpdb;
		$wpdb->update( self::t(), array( 'thumb_error' => substr( (string) $message, 0, 500 ) ), array( 'id' => (int) $id ) );
	}

	/** Mark every screenshot as due, so they are all taken again. */
	public static function reset_thumbs() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET thumb_at = NULL, thumb_error = NULL', self::t() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
	}

	public static function delete( $id ) {
		global $wpdb;
		USMD_Thumbs::delete( self::get( $id ) );
		USMD_Vulns::forget_site( $id );
		$wpdb->delete( self::t(), array( 'id' => (int) $id ) );
		$wpdb->delete( self::ta(), array( 'site_id' => (int) $id ) );
	}

	/* ---------- activity ---------- */

	public static function max_remote_id( $id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(remote_id) FROM %i WHERE site_id = %d', self::ta(), (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
	}

	/**
	 * Store rows the site sent; returns the newest remote id now stored (the ack).
	 */
	/** An entry the dashboard itself records for a site (not from the site's own log). */
	public static function add_local_activity( $id, $type, $action, $object, array $details = array() ) {
		global $wpdb;
		$wpdb->insert(
			self::ta(),
			array(
				'site_id'    => (int) $id,
				'remote_id'  => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'type'       => substr( (string) $type, 0, 20 ),
				'action'     => substr( (string) $action, 0, 40 ),
				'object'     => substr( (string) $object, 0, 191 ),
				'actor'      => 'dashboard',
				'details'    => $details ? wp_json_encode( $details ) : null,
			)
		);
	}

	public static function add_activity( $id, array $rows ) {
		global $wpdb;
		$max = self::max_remote_id( $id );
		foreach ( $rows as $r ) {
			$rid = isset( $r['id'] ) ? (int) $r['id'] : 0;
			if ( $rid <= $max ) {
				continue; // Already stored (rows arrive in ascending order).
			}
			$wpdb->insert(
				self::ta(),
				array(
					'site_id'    => (int) $id,
					'remote_id'  => $rid,
					'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( (string) $r['created_at'] ) ),
					'type'       => substr( (string) $r['type'], 0, 20 ),
					'action'     => substr( (string) $r['action'], 0, 40 ),
					'object'     => substr( (string) $r['object'], 0, 191 ),
					'actor'      => substr( (string) $r['actor'], 0, 60 ),
					'details'    => isset( $r['details'] ) ? wp_json_encode( $r['details'] ) : null,
				)
			);
			$max = $rid;
		}
		return $max;
	}

	public static function activity( $id, $limit = 50, $from = null, $to = null ) {
		global $wpdb;
		$from = $from ? (string) $from : '1970-01-01 00:00:00';
		$to   = $to ? (string) $to : '9999-12-31 23:59:59';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE site_id = %d AND created_at >= %s AND created_at < %s ORDER BY created_at DESC, id DESC LIMIT %d', self::ta(), (int) $id, $from, $to, (int) $limit ) );
		foreach ( $rows as $r ) {
			$r->details = $r->details ? json_decode( $r->details, true ) : array();
		}
		return $rows;
	}
}
