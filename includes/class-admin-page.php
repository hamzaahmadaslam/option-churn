<?php
/**
 * Tools > Option churn: the report, read-only, plus a reset button.
 *
 * @package OptionChurn
 */

namespace OptionChurn;

/**
 * The admin screen.
 */
final class Admin_Page {

	const SLUG = 'option-churn';

	/**
	 * Registers the page.
	 */
	public static function register() {
		add_management_page( 'Option churn', 'Option churn', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_option_churn_reset', array( __CLASS__, 'reset' ) );
	}

	/**
	 * Empties the totals after a capability and nonce check.
	 */
	public static function reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'option-churn' ), 403 );
		}
		check_admin_referer( 'option_churn_reset' );
		Store::reset();
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG . '&reset=1' ) );
		exit;
	}

	/**
	 * Prints the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$report = Store::report( array( 'limit' => 100 ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'Option churn', 'option-churn' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['reset'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Recorded totals deleted.', 'option-churn' ) . '</p></div>';
		}
		$counts = array();
		foreach ( $report['requests'] as $type => $count ) {
			$counts[] = sprintf( '%s: %d', $type, $count );
		}
		echo '<p>' . esc_html__( 'Requests recorded', 'option-churn' ) . ': ' . esc_html( $counts ? implode( ', ', $counts ) : '0' ) . '</p>';
		if ( ! $report['rows'] ) {
			echo '<p>' . esc_html__( 'No option writes recorded yet.', 'option-churn' ) . '</p>';
		} else {
			$rows = array_map( array( CLI::class, 'flat' ), $report['rows'] );
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array_keys( $rows[0] ) as $column ) {
				echo '<th scope="col">' . esc_html( str_replace( '_', ' ', $column ) ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr>';
				foreach ( $row as $value ) {
					echo '<td>' . esc_html( (string) $value ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
		echo '<input type="hidden" name="action" value="option_churn_reset">';
		wp_nonce_field( 'option_churn_reset' );
		submit_button( __( 'Delete recorded totals', 'option-churn' ), 'delete', 'submit', false );
		echo '</form></div>';
	}
}
