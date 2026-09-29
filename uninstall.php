<?php
/**
 * Deleting the plugin drops its three tables.
 *
 * @package OptionChurn
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-store.php';
\OptionChurn\Store::uninstall();
