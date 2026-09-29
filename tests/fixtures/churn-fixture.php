<?php
/**
 * Synthetic must-use plugin for the integration test: writes options the way careless plugins do.
 *
 * @package OptionChurn
 */

add_action(
	'init',
	function () {
		// A "heartbeat" rewritten on every request, where only the timestamp changes.
		$state         = get_option( 'acme_heartbeat', array( 'version' => '1.0' ) );
		$state['last'] = microtime( true );
		update_option( 'acme_heartbeat', $state, false );

		// Written with the same value on every request: WordPress skips the write.
		update_option( 'acme_static', 'same' );
	}
);

add_action(
	'wp_footer',
	function () {
		set_transient( 'acme_menu_cache', array( 'built' => microtime( true ) ), 60 );
	}
);
