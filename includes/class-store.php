<?php
/**
 * Three small tables of running totals. Nothing here stores an option value.
 *
 * @package OptionChurn
 */

namespace OptionChurn;

/**
 * Table creation, the per-request save, the report query and reset.
 */
final class Store {

	/** Bumped whenever schema() changes; maybe_upgrade() then runs dbDelta() once. */
	const SCHEMA_VERSION = '1';

	/**
	 * Table names.
	 *
	 * @return array{requests: string, writes: string, keys: string}
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'requests' => $wpdb->base_prefix . 'option_churn_requests',
			'writes'   => $wpdb->base_prefix . 'option_churn_writes',
			'keys'     => $wpdb->base_prefix . 'option_churn_keys',
		);
	}

	/**
	 * The CREATE TABLE statements, written so a second dbDelta() call changes nothing on MySQL and MariaDB.
	 *
	 * @return string[]
	 */
	public static function schema() {
		global $wpdb;
		$t       = self::tables();
		$collate = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE {$t['requests']} (
  row_key char(40) NOT NULL,
  blog_id bigint(20) unsigned NOT NULL DEFAULT '0',
  request_type varchar(20) NOT NULL DEFAULT '',
  requests bigint(20) unsigned NOT NULL DEFAULT '0',
  requests_with_writes bigint(20) unsigned NOT NULL DEFAULT '0',
  first_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (row_key)
) {$collate};",
			"CREATE TABLE {$t['writes']} (
  row_key char(40) NOT NULL,
  blog_id bigint(20) unsigned NOT NULL DEFAULT '0',
  option_name varchar(191) NOT NULL DEFAULT '',
  op varchar(20) NOT NULL DEFAULT '',
  request_type varchar(20) NOT NULL DEFAULT '',
  component varchar(191) NOT NULL DEFAULT '',
  caller_function varchar(191) NOT NULL DEFAULT '',
  last_at varchar(191) NOT NULL DEFAULT '',
  calls bigint(20) unsigned NOT NULL DEFAULT '0',
  requests bigint(20) unsigned NOT NULL DEFAULT '0',
  bytes bigint(20) unsigned NOT NULL DEFAULT '0',
  last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (row_key),
  KEY blog_option (blog_id,option_name)
) {$collate};",
			"CREATE TABLE {$t['keys']} (
  row_key char(40) NOT NULL,
  blog_id bigint(20) unsigned NOT NULL DEFAULT '0',
  request_type varchar(20) NOT NULL DEFAULT '',
  option_name varchar(191) NOT NULL DEFAULT '',
  key_name varchar(191) NOT NULL DEFAULT '',
  changes bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY  (row_key),
  KEY blog_option (blog_id,option_name)
) {$collate};",
		);
	}

	/**
	 * Creates or updates the tables.
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );
	}

	/**
	 * Runs install() when the stored schema version differs. Called on admin and WP-CLI requests only, so the
	 * front end never pays for the check.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'option_churn_schema' ) !== self::SCHEMA_VERSION ) {
			self::install();
			update_option( 'option_churn_schema', self::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Drops the tables.
	 */
	public static function uninstall() {
		global $wpdb;
		delete_option( 'option_churn_schema' );
		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		}
	}

	/**
	 * Empties the tables.
	 */
	public static function reset() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		}
	}

	/**
	 * Adds one request's totals. Errors are suppressed: a missing table must never break the site.
	 *
	 * @param string $type   Request type.
	 * @param array  $writes Aggregated writes from the recorder.
	 * @param array  $keys   Changed keys per option.
	 */
	public function save( $type, array $writes, array $keys ) {
		global $wpdb;
		$t        = self::tables();
		$blog     = get_current_blog_id();
		$now      = current_time( 'mysql', true );
		$suppress = $wpdb->suppress_errors( true );

		$real = array_filter(
			$writes,
			function ( $w ) {
				return 'noop' !== $w['op'];
			}
		);
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$t['requests']}` (row_key, blog_id, request_type, requests, requests_with_writes, first_seen, last_seen) VALUES (%s, %d, %s, 1, %d, %s, %s)
				ON DUPLICATE KEY UPDATE requests = requests + 1, requests_with_writes = requests_with_writes + %d, last_seen = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
				sha1( "{$blog}|{$type}" ),
				$blog,
				$type,
				$real ? 1 : 0,
				$now,
				$now,
				$real ? 1 : 0,
				$now
			)
		);

		// One row per option, operation, request type and caller; plus one "*" row per option with real writes, so
		// the report can say on how many requests each option was written.
		$per_option = array();
		foreach ( $writes as $w ) {
			$this->upsert_write( $blog, $type, $w['option'], $w['op'], $w['component'], $w['function'], $w['at'], $w['count'], $w['bytes'], $now );
			if ( 'noop' !== $w['op'] ) {
				$per_option[ $w['option'] ] = isset( $per_option[ $w['option'] ] ) ? $per_option[ $w['option'] ] : array( 0, 0 );
				$per_option[ $w['option'] ][0] += $w['count'];
				$per_option[ $w['option'] ][1] += $w['bytes'];
			}
		}
		foreach ( $per_option as $option => $totals ) {
			$this->upsert_write( $blog, $type, $option, '*', '*', '*', '', $totals[0], $totals[1], $now );
		}
		foreach ( $keys as $option => $changed ) {
			foreach ( $changed as $key => $count ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO `{$t['keys']}` (row_key, blog_id, request_type, option_name, key_name, changes) VALUES (%s, %d, %s, %s, %s, %d)
						ON DUPLICATE KEY UPDATE changes = changes + %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
						sha1( "{$blog}|{$type}|{$option}|{$key}" ),
						$blog,
						$type,
						$option,
						$key,
						$count,
						$count
					)
				);
			}
		}
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * One write row: adds the calls and bytes, and counts one more request.
	 */
	private function upsert_write( $blog, $type, $option, $op, $component, $function, $at, $calls, $bytes, $now ) {
		global $wpdb;
		$t = self::tables();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$t['writes']}` (row_key, blog_id, option_name, op, request_type, component, caller_function, last_at, calls, requests, bytes, last_seen) VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %d, 1, %d, %s)
				ON DUPLICATE KEY UPDATE calls = calls + %d, requests = requests + 1, bytes = bytes + %d, last_at = %s, last_seen = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
				sha1( "{$blog}|{$type}|{$option}|{$op}|{$component}|{$function}" ),
				$blog,
				$option,
				$op,
				$type,
				$component,
				$function,
				$at,
				$calls,
				$bytes,
				$now,
				$calls,
				$bytes,
				$at,
				$now
			)
		);
	}

	/**
	 * The report rows for the current site: one per option and request type, most frequently written first.
	 *
	 * @param array $args { type, limit }.
	 * @return array{requests: array<string,int>, rows: array[]}
	 */
	public static function report( array $args = array() ) {
		global $wpdb;
		$t    = self::tables();
		$blog = get_current_blog_id();

		$requests = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT request_type, requests, requests_with_writes FROM `{$t['requests']}` WHERE blog_id = %d", $blog ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			$requests[ $r['request_type'] ] = (int) $r['requests'];
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t['writes']}` WHERE blog_id = %d", $blog ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$keys = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT request_type, option_name, key_name, changes FROM `{$t['keys']}` WHERE blog_id = %d ORDER BY changes DESC", $blog ), ARRAY_A ) as $k ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			$keys[ $k['option_name'] ][ $k['request_type'] ][ $k['key_name'] ] = (int) $k['changes'];
		}

		$by = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $args['type'] ) && $r['request_type'] !== $args['type'] ) {
				continue;
			}
			$id = $r['option_name'] . '|' . $r['request_type'];
			if ( ! isset( $by[ $id ] ) ) {
				$by[ $id ] = array(
					'option'         => $r['option_name'],
					'type'           => $r['request_type'],
					'requests'       => isset( $requests[ $r['request_type'] ] ) ? $requests[ $r['request_type'] ] : 0,
					'write_requests' => 0,
					'writes'         => 0,
					'noop_calls'     => 0,
					'bytes'          => 0,
					'callers'        => array(),
				);
			}
			if ( '*' === $r['op'] ) {
				$by[ $id ]['write_requests'] = (int) $r['requests'];
				$by[ $id ]['writes']         = (int) $r['calls'];
				$by[ $id ]['bytes']          = (int) $r['bytes'];
			} else {
				if ( 'noop' === $r['op'] ) {
					$by[ $id ]['noop_calls'] += (int) $r['calls'];
				}
				$label                          = $r['component'] . ' ' . $r['caller_function'];
				$by[ $id ]['callers'][ $label ] = ( isset( $by[ $id ]['callers'][ $label ] ) ? $by[ $id ]['callers'][ $label ] : 0 ) + (int) $r['calls'];
			}
		}

		$options  = array_unique( array_column( $by, 'option' ) );
		$autoload = array();
		if ( $options ) {
			$placeholders = implode( ',', array_fill( 0, count( $options ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name IN ($placeholders)", $options ), ARRAY_A ) as $o ) {
				$autoload[ $o['option_name'] ] = $o['autoload'];
			}
		}
		$loaded = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );

		$out = array();
		foreach ( $by as $row ) {
			arsort( $row['callers'] );
			$changed = isset( $keys[ $row['option'] ][ $row['type'] ] ) ? $keys[ $row['option'] ][ $row['type'] ] : array();
			$row    += array(
				'autoload'     => isset( $autoload[ $row['option'] ] ) ? $autoload[ $row['option'] ] : '',
				'autoloaded'   => isset( $autoload[ $row['option'] ] ) && in_array( $autoload[ $row['option'] ], $loaded, true ),
				'share'        => $row['requests'] ? round( $row['write_requests'] / $row['requests'], 4 ) : 0,
				'changed_keys' => array_slice( $changed, 0, 5, true ),
			);
			$out[] = $row;
		}
		usort(
			$out,
			function ( $a, $b ) {
				return ( $b['write_requests'] <=> $a['write_requests'] ) ?: ( $b['noop_calls'] <=> $a['noop_calls'] ) ?: strcmp( $a['option'], $b['option'] );
			}
		);
		if ( ! empty( $args['limit'] ) ) {
			$out = array_slice( $out, 0, (int) $args['limit'] );
		}
		return array(
			'requests' => $requests,
			'rows'     => $out,
		);
	}
}
