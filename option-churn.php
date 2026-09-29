<?php
/**
 * Plugin Name:       Option churn
 * Plugin URI:        https://github.com/hamzaahmadaslam/option-churn
 * Description:       Development plugin: counts how often each option is rewritten across requests, by request type and caller, and how often update_option() was called with an unchanged value. Stores totals, never values.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Hamza Ahmad Aslam
 * Author URI:        https://hamzaahmadaslam.com
 * License:           MIT
 * Text Domain:       option-churn
 *
 * @package OptionChurn
 */

namespace OptionChurn;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-classify.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-recorder.php';
require_once __DIR__ . '/includes/class-admin-page.php';

register_activation_hook( __FILE__, array( Store::class, 'install' ) );

// Writes made before this file loads (must-use plugins, plugins that sort earlier) are only counted when the plugin
// is loaded from a must-use plugin; see the README.
( new Recorder( new Store() ) )->start();

add_action( 'admin_menu', array( Admin_Page::class, 'register' ) );
add_action( 'admin_init', array( Store::class, 'maybe_upgrade' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';
	add_action( 'init', array( Store::class, 'maybe_upgrade' ) );
	\WP_CLI::add_command( 'option-churn', CLI::class );
}
