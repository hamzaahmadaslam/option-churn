<?php
/**
 * Settings come from constants in wp-config.php, never from options (an option this plugin wrote would be counted).
 *
 * @package OptionChurn
 */

namespace OptionChurn;

/**
 * Constants:
 *   OPTION_CHURN_RECORD  false stops recording (the report stays available). Default true.
 *   OPTION_CHURN_SAMPLE  share of requests recorded, 0 to 1. Default 1.
 *   OPTION_CHURN_SKIP    comma-separated request types not recorded, for example "cli,cron". Default none.
 */
final class Settings {

	/**
	 * Set while one of this plugin's own commands runs, so reading or resetting the totals is not recorded.
	 *
	 * @var bool
	 */
	private static $paused = false;

	/**
	 * Stops recording for the rest of this request.
	 */
	public static function pause() {
		self::$paused = true;
	}

	/**
	 * Whether this request is recorded.
	 *
	 * @param string $type Request type.
	 * @return bool
	 */
	public static function should_record( $type ) {
		if ( self::$paused ) {
			return false;
		}
		if ( defined( 'OPTION_CHURN_RECORD' ) && ! OPTION_CHURN_RECORD ) {
			return false;
		}
		if ( defined( 'OPTION_CHURN_SKIP' ) && in_array( $type, array_map( 'trim', explode( ',', (string) OPTION_CHURN_SKIP ) ), true ) ) {
			return false;
		}
		$sample = defined( 'OPTION_CHURN_SAMPLE' ) ? (float) OPTION_CHURN_SAMPLE : 1.0;
		if ( $sample >= 1 ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- sampling, not security.
		return $sample > 0 && mt_rand() / mt_getrandmax() < $sample;
	}
}
