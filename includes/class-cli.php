<?php
/**
 * `wp option-churn`.
 *
 * @package OptionChurn
 */

namespace OptionChurn;

use WP_CLI;

/**
 * Reports how often each option is rewritten, per request type and caller.
 */
final class CLI {

	/**
	 * The plugin's own commands are not recorded.
	 */
	public function __construct() {
		Settings::pause();
	}

	/**
	 * Lists the options written most often, per request type.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Only this request type: front, admin, ajax, rest, cron, cli or xmlrpc.
	 *
	 * [--limit=<n>]
	 * : How many rows.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp option-churn report --type=front
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function report( $args, $assoc_args ) {
		$report = Store::report(
			array(
				'type'  => isset( $assoc_args['type'] ) ? $assoc_args['type'] : '',
				'limit' => (int) $assoc_args['limit'],
			)
		);
		$format = $assoc_args['format'];
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( ! $report['rows'] ) {
			WP_CLI::line( 'No option writes recorded yet.' );
			return;
		}
		$rows = array_map( array( __CLASS__, 'flat' ), $report['rows'] );
		WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Shows whether recording is on and how many requests have been recorded.
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Unused.
	 */
	public function status( $args, $assoc_args ) {
		global $wpdb;
		$missing = array();
		foreach ( Store::tables() as $table ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$missing[] = $table;
			}
		}
		$recording = ! ( defined( 'OPTION_CHURN_RECORD' ) && ! OPTION_CHURN_RECORD );
		WP_CLI::line( 'Recording: ' . ( $recording ? 'on' : 'off (OPTION_CHURN_RECORD is false)' ) );
		WP_CLI::line( 'Sample: ' . ( defined( 'OPTION_CHURN_SAMPLE' ) ? (float) OPTION_CHURN_SAMPLE : 1 ) );
		WP_CLI::line( 'Skipped request types: ' . ( defined( 'OPTION_CHURN_SKIP' ) && OPTION_CHURN_SKIP ? OPTION_CHURN_SKIP : 'none' ) );
		if ( $missing ) {
			WP_CLI::warning( 'Missing tables (deactivate and activate the plugin): ' . implode( ', ', $missing ) );
			return;
		}
		foreach ( Store::report()['requests'] as $type => $count ) {
			WP_CLI::line( "Requests recorded ({$type}): {$count}" );
		}
	}

	/**
	 * Creates the tables. Activation does this; run it when the plugin is loaded from a must-use plugin.
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Unused.
	 */
	public function install( $args, $assoc_args ) {
		Store::install();
		WP_CLI::success( 'Tables ready: ' . implode( ', ', Store::tables() ) );
	}

	/**
	 * Empties the recorded totals.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function reset( $args, $assoc_args ) {
		WP_CLI::confirm( 'Delete every recorded total?', $assoc_args );
		Store::reset();
		WP_CLI::success( 'Recorded totals deleted.' );
	}

	/**
	 * One report row as flat columns for table, CSV and YAML output.
	 *
	 * @param array $row Report row.
	 * @return array
	 */
	public static function flat( array $row ) {
		$keys = array();
		$sum  = array_sum( $row['changed_keys'] );
		foreach ( $row['changed_keys'] as $key => $count ) {
			$keys[] = $sum ? sprintf( '%s %d%%', $key, round( 100 * $count / max( 1, $row['writes'] ) ) ) : $key;
		}
		return array(
			'option'       => $row['option'],
			'type'         => $row['type'],
			'written_on'   => sprintf( '%d of %d requests (%s%%)', $row['write_requests'], $row['requests'], round( 100 * $row['share'], 1 ) ),
			'writes'       => $row['writes'],
			'noop_calls'   => $row['noop_calls'],
			'bytes_each'   => $row['writes'] ? (int) round( $row['bytes'] / $row['writes'] ) : 0,
			'autoload'     => $row['autoload'],
			'top_caller'   => $row['callers'] ? (string) array_key_first( $row['callers'] ) : '',
			'changed_keys' => implode( ', ', $keys ),
		);
	}
}
