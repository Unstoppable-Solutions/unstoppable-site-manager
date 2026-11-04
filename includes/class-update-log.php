<?php
/**
 * What happened in an update, in words people can act on.
 *
 * A site reports each item it updated (plugin file, theme folder or WordPress,
 * versions before and after, result and the raw error). This turns that into
 * named items ("Elementor Pro 4.3.2 → 4.3.3"), explains failures in plain
 * English, and keeps each site's last update so its page can show exactly what
 * changed and what didn't.
 */

defined( 'ABSPATH' ) || exit;

final class USMD_Update_Log {

	const OPT = 'usmd_last_update'; // site id => array( time, by, items, error )

	/** 'plugin:file' / 'theme:slug' / 'core' => display name, from a site's report. */
	public static function names( array $status ) {
		$map = array( 'core' => 'WordPress' );
		foreach ( isset( $status['plugins'] ) && is_array( $status['plugins'] ) ? $status['plugins'] : array() as $p ) {
			if ( ! empty( $p['file'] ) ) {
				$map[ 'plugin:' . $p['file'] ] = ! empty( $p['name'] ) ? (string) $p['name'] : (string) $p['file'];
			}
		}
		foreach ( isset( $status['themes'] ) && is_array( $status['themes'] ) ? $status['themes'] : array() as $t ) {
			if ( ! empty( $t['slug'] ) ) {
				$map[ 'theme:' . $t['slug'] ] = ! empty( $t['name'] ) ? (string) $t['name'] : (string) $t['slug'];
			}
		}
		return $map;
	}

	/** A plain-English reason for a failed item, from the error the site gave. */
	public static function explain( $error ) {
		$e = strtolower( wp_strip_all_tags( (string) $error ) );
		$rules = array(
			array( array( 'licen', 'unauthori', 'package not available', 'no package', 'download link', 'invalid url', 'download failed', 'not found', '401', '403', 'forbidden' ), __( 'The site could not download this update. It is usually a paid plugin whose licence is not active on the site, or an expired download link. Activate the licence on the site, then try again.', 'unstoppable-site-manager' ) ),
			array( array( 'timed out', 'curl error 28', 'maximum execution time', 'took too long', 'timeout' ), __( 'The site\'s server ran out of time. Try again; if it keeps happening, update this one on its own.', 'unstoppable-site-manager' ) ),
			array( array( 'another update is currently in progress', 'core_updater.lock', 'update is already' ), __( 'WordPress was already running an update on this site. Wait a few minutes, then try again.', 'unstoppable-site-manager' ) ),
			array( array( 'could not create directory', 'could not copy file', 'could not remove the old', 'permission', 'not writable', 'unable to locate wordpress' ), __( 'The site could not write its files (folder permissions). Ask the host to make the plugins and themes folders writable.', 'unstoppable-site-manager' ) ),
			array( array( 'disk', 'no space', 'quota' ), __( 'The site\'s server is out of disk space. Free some up (old backups are the usual cause), then try again.', 'unstoppable-site-manager' ) ),
			array( array( 'not installed' ), __( 'It is no longer installed on the site.', 'unstoppable-site-manager' ) ),
			array( array( 'incompatible archive', 'pclzip', 'zip' ), __( 'The update file the site downloaded was damaged or incomplete. Try again.', 'unstoppable-site-manager' ) ),
			array( array( 'requires php', 'requires wordpress', 'requires at least' ), __( 'This version needs a newer PHP or WordPress than the site has. Update those first.', 'unstoppable-site-manager' ) ),
			array( array( 'fatal error', 'critical error' ), __( 'The new version caused an error on the site. Check the site still works, and look at its error log.', 'unstoppable-site-manager' ) ),
		);
		foreach ( $rules as $r ) {
			foreach ( $r[0] as $needle ) {
				if ( false !== strpos( $e, $needle ) ) {
					return $r[1];
				}
			}
		}
		return '' !== trim( $e )
			/* translators: %s: the error the site gave. */
			? sprintf( __( 'The update did not finish. The site said: %s', 'unstoppable-site-manager' ), substr( wp_strip_all_tags( (string) $error ), 0, 200 ) )
			: __( 'The update did not finish, and the site gave no reason. Try again.', 'unstoppable-site-manager' );
	}

	/**
	 * Named items from a site's update results.
	 *
	 * @param array $before The site's report from before the update (for names).
	 * @return array[] type, id, key, name, from, to, result, error, reason
	 */
	public static function items( array $before, $results ) {
		$names = self::names( $before );
		$out   = array();
		foreach ( is_array( $results ) ? $results : array() as $r ) {
			if ( ! is_array( $r ) || empty( $r['type'] ) ) {
				continue;
			}
			$type  = in_array( $r['type'], array( 'plugin', 'theme', 'core' ), true ) ? $r['type'] : 'plugin';
			$id    = isset( $r['id'] ) && is_scalar( $r['id'] ) ? (string) $r['id'] : '';
			$key   = 'core' === $type ? 'core' : $type . ':' . $id;
			$res   = isset( $r['result'] ) && in_array( $r['result'], array( 'updated', 'up_to_date', 'failed' ), true ) ? $r['result'] : 'failed';
			$error = isset( $r['error'] ) && is_scalar( $r['error'] ) ? substr( (string) $r['error'], 0, 500 ) : '';
			$out[] = array(
				'type'   => $type,
				'id'     => $id,
				'key'    => $key,
				'name'   => isset( $names[ $key ] ) ? $names[ $key ] : ( '' !== $id ? $id : 'WordPress' ),
				'from'   => isset( $r['from'] ) && is_scalar( $r['from'] ) ? (string) $r['from'] : '',
				'to'     => isset( $r['to'] ) && is_scalar( $r['to'] ) ? (string) $r['to'] : '',
				'result' => $res,
				'error'  => $error,
				'reason' => 'failed' === $res ? self::explain( $error ) : '',
			);
		}
		// Failures first: they are what needs doing.
		usort(
			$out,
			static function ( $a, $b ) {
				$o = array( 'failed' => 0, 'updated' => 1, 'up_to_date' => 2 );
				return array( $o[ $a['result'] ], strtolower( $a['name'] ) ) <=> array( $o[ $b['result'] ], strtolower( $b['name'] ) );
			}
		);
		return $out;
	}

	/** Remember a site's last update (or why it couldn't run). */
	public static function record( $site_id, array $items, $error = '' ) {
		$all = get_option( self::OPT );
		$all = is_array( $all ) ? $all : array();
		$u   = wp_get_current_user();
		$all[ (int) $site_id ] = array(
			'time'   => time(),
			'by'     => ( $u && $u->exists() ) ? $u->display_name : '',
			'items'  => $items,
			'error'  => (string) $error,
			'reason' => $error ? self::explain( $error ) : '',
		);
		update_option( self::OPT, $all, false );
	}

	public static function get( $site_id ) {
		$all = get_option( self::OPT );
		return ( is_array( $all ) && isset( $all[ (int) $site_id ] ) ) ? $all[ (int) $site_id ] : null;
	}

	public static function forget( $site_id ) {
		$all = get_option( self::OPT );
		if ( is_array( $all ) && isset( $all[ (int) $site_id ] ) ) {
			unset( $all[ (int) $site_id ] );
			update_option( self::OPT, $all, false );
		}
	}

	/** The Last update box on a site page. */
	public static function panel( $site ) {
		$l = self::get( $site->id );
		if ( ! $l ) {
			return;
		}
		$c = array( 'updated' => 0, 'failed' => 0, 'up_to_date' => 0 );
		foreach ( $l['items'] as $i ) {
			++$c[ $i['result'] ];
		}
		$failed = array_values( array_filter( $l['items'], static function ( $i ) { return 'failed' === $i['result']; } ) );
		?>
		<div class="usmd-panel usmd-last-update<?php echo ( $c['failed'] || $l['error'] ) ? ' has-failed' : ''; ?>" id="usmd-last-update">
			<div class="usmd-panel-head">
				<h2><?php esc_html_e( 'Last update', 'unstoppable-site-manager' ); ?>
					<span class="usmd-muted">
						<?php
						/* translators: 1: time ago, 2: person. */
						echo esc_html( $l['by'] ? sprintf( __( '%1$s ago by %2$s', 'unstoppable-site-manager' ), human_time_diff( (int) $l['time'] ), $l['by'] ) : sprintf( /* translators: %s: time ago. */ __( '%s ago', 'unstoppable-site-manager' ), human_time_diff( (int) $l['time'] ) ) );
						?>
					</span>
				</h2>
				<?php if ( $failed ) : ?>
					<button type="button" class="button button-primary usmd-update-retry" data-site="<?php echo (int) $site->id; ?>" data-items="<?php echo esc_attr( wp_json_encode( array_map( static function ( $i ) { return $i['key']; }, $failed ) ) ); ?>">
						<?php
						/* translators: %d: number of items. */
						echo esc_html( sprintf( _n( 'Try again (%d)', 'Try again (%d)', count( $failed ), 'unstoppable-site-manager' ), count( $failed ) ) );
						?>
					</button>
				<?php endif; ?>
			</div>
			<?php if ( $l['error'] ) : ?>
				<p class="usmd-upd-fail"><strong><?php esc_html_e( 'The update could not run:', 'unstoppable-site-manager' ); ?></strong> <?php echo esc_html( $l['reason'] ); ?></p>
			<?php else : ?>
				<p class="usmd-upd-sum">
					<?php
					$parts = array();
					if ( $c['updated'] ) {
						/* translators: %d: number of items. */
						$parts[] = sprintf( _n( '%d updated', '%d updated', $c['updated'], 'unstoppable-site-manager' ), $c['updated'] );
					}
					if ( $c['failed'] ) {
						/* translators: %d: number of items. */
						$parts[] = sprintf( _n( '%d failed', '%d failed', $c['failed'], 'unstoppable-site-manager' ), $c['failed'] );
					}
					if ( $c['up_to_date'] ) {
						/* translators: %d: number of items. */
						$parts[] = sprintf( _n( '%d was already up to date', '%d were already up to date', $c['up_to_date'], 'unstoppable-site-manager' ), $c['up_to_date'] );
					}
					echo esc_html( $parts ? implode( ', ', $parts ) . '.' : __( 'Nothing needed updating.', 'unstoppable-site-manager' ) );
					?>
				</p>
				<ul class="usmd-upd-items">
					<?php foreach ( $l['items'] as $i ) : ?>
						<li class="is-<?php echo esc_attr( $i['result'] ); ?>">
							<span class="usmd-upd-mark" aria-hidden="true"><?php echo 'updated' === $i['result'] ? '✓' : ( 'failed' === $i['result'] ? '✕' : '–' ); ?></span>
							<strong><?php echo esc_html( $i['name'] ); ?></strong>
							<span class="usmd-muted">
								<?php
								if ( 'updated' === $i['result'] ) {
									echo esc_html( $i['from'] . ' → ' . $i['to'] );
								} elseif ( 'up_to_date' === $i['result'] ) {
									echo esc_html( sprintf( /* translators: %s: version. */ __( '%s, already up to date', 'unstoppable-site-manager' ), $i['from'] ) );
								} else {
									echo esc_html( sprintf( /* translators: %s: version. */ __( 'still %s', 'unstoppable-site-manager' ), $i['from'] ) );
								}
								?>
							</span>
							<?php if ( 'failed' === $i['result'] ) : ?>
								<span class="usmd-upd-reason"><?php echo esc_html( $i['reason'] ); ?></span>
								<?php if ( $i['error'] && false === strpos( $i['reason'], $i['error'] ) ) : ?>
									<details class="usmd-upd-raw"><summary><?php esc_html_e( 'What the site said', 'unstoppable-site-manager' ); ?></summary><code><?php echo esc_html( $i['error'] ); ?></code></details>
								<?php endif; ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
