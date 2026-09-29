<?php
/**
 * Unit tests for the parts that need no WordPress: php tests/run.php
 *
 * @package OptionChurn
 */

require __DIR__ . '/../includes/class-classify.php';

use OptionChurn\Classify;

$failures = 0;
$count    = 0;

/**
 * Runs one test.
 */
function test( $name, $fn ) {
	global $failures, $count;
	++$count;
	try {
		$fn();
		echo "ok {$count} - {$name}\n";
	} catch ( Throwable $e ) {
		++$failures;
		echo "not ok {$count} - {$name}\n  " . $e->getMessage() . "\n";
	}
}

/**
 * Fails unless the two values are identical.
 */
function same( $expected, $actual ) {
	if ( $expected !== $actual ) {
		throw new Exception( 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$dirs = array(
	'plugins'    => '/srv/wp/wp-content/plugins',
	'mu_plugins' => '/srv/wp/wp-content/mu-plugins',
	'themes'     => '/srv/wp/wp-content/themes',
	'core'       => '/srv/wp/',
	'self'       => '/srv/wp/wp-content/plugins/option-churn',
);

test(
	'components from file paths, Windows separators included',
	function () use ( $dirs ) {
		same( 'plugin:acme', Classify::component( '/srv/wp/wp-content/plugins/acme/src/Sync.php', $dirs ) );
		same( 'mu-plugin:tweaks.php', Classify::component( '/srv/wp/wp-content/mu-plugins/tweaks.php', $dirs ) );
		same( 'theme:twentytwentyfive', Classify::component( '/srv/wp/wp-content/themes/twentytwentyfive/functions.php', $dirs ) );
		same( 'core', Classify::component( '/srv/wp/wp-includes/cron.php', $dirs ) );
		$win = array_map(
			function ( $d ) {
				return str_replace( '/', '\\', str_replace( '/srv/wp', 'C:/sites/wp', $d ) );
			},
			$dirs
		);
		same( 'plugin:acme', Classify::component( 'C:\\sites\\wp\\wp-content\\plugins\\acme\\acme.php', $win ) );
	}
);

test(
	'the caller is the first call site outside core, with the function around it',
	function () use ( $dirs ) {
		// Innermost first, as debug_backtrace() returns them: our hook, do_action, update_option, the plugin.
		$frames = array(
			array( 'file' => '/srv/wp/wp-includes/class-wp-hook.php', 'line' => 324, 'function' => 'note', 'class' => 'OptionChurn\\Recorder' ),
			array( 'file' => '/srv/wp/wp-includes/plugin.php', 'line' => 517, 'function' => 'apply_filters', 'class' => 'WP_Hook' ),
			array( 'file' => '/srv/wp/wp-includes/option.php', 'line' => 1030, 'function' => 'do_action' ),
			array( 'file' => '/srv/wp/wp-content/plugins/acme/src/Sync.php', 'line' => 42, 'function' => 'update_option' ),
			array( 'file' => '/srv/wp/wp-content/plugins/acme/acme.php', 'line' => 10, 'function' => 'tick', 'class' => 'Acme\\Sync' ),
		);
		same(
			array( 'component' => 'plugin:acme', 'function' => 'Acme\\Sync::tick', 'at' => 'wp-content/plugins/acme/src/Sync.php:42' ),
			Classify::caller( $frames, $dirs )
		);
	}
);

test(
	'set_transient is attributed to whoever called set_transient, not to core',
	function () use ( $dirs ) {
		$frames = array(
			array( 'file' => '/srv/wp/wp-includes/plugin.php', 'line' => 517, 'function' => 'do_action' ),
			array( 'file' => '/srv/wp/wp-includes/option.php', 'line' => 1600, 'function' => 'update_option' ),
			array( 'file' => '/srv/wp/wp-content/themes/shop/functions.php', 'line' => 88, 'function' => 'set_transient' ),
			array( 'file' => '/srv/wp/wp-includes/class-wp-hook.php', 'line' => 324, 'function' => 'shop_cache_menu' ),
		);
		same( 'theme:shop', Classify::caller( $frames, $dirs )['component'] );
		same( 'shop_cache_menu', Classify::caller( $frames, $dirs )['function'] );
	}
);

test(
	'closures are named {closure}, without the absolute path PHP 8.4 adds',
	function () use ( $dirs ) {
		$frames = array(
			array( 'file' => '/srv/wp/wp-content/mu-plugins/tweaks.php', 'line' => 14, 'function' => 'update_option' ),
			array( 'file' => '/srv/wp/wp-includes/class-wp-hook.php', 'line' => 324, 'function' => '{closure:/srv/wp/wp-content/mu-plugins/tweaks.php:10}' ),
		);
		same( '{closure}', Classify::caller( $frames, $dirs )['function'] );
		same( 'wp-content/mu-plugins/tweaks.php:14', Classify::caller( $frames, $dirs )['at'] );
	}
);

test(
	'a write made by core alone is attributed to core',
	function () use ( $dirs ) {
		$frames = array(
			array( 'file' => '/srv/wp/wp-includes/plugin.php', 'line' => 517, 'function' => 'do_action' ),
			array( 'file' => '/srv/wp/wp-includes/cron.php', 'line' => 1203, 'function' => 'update_option' ),
			array( 'file' => '/srv/wp/wp-includes/cron.php', 'line' => 222, 'function' => '_set_cron_array' ),
		);
		same( 'core', Classify::caller( $frames, $dirs )['component'] );
		same( '_set_cron_array', Classify::caller( $frames, $dirs )['function'] );
	}
);

test(
	'the files at the WordPress root count as core, not as the caller',
	function () use ( $dirs ) {
		$frames = array(
			array( 'file' => '/srv/wp/wp-includes/class-wp-rewrite.php', 'line' => 1861, 'function' => 'update_option' ),
			array( 'file' => '/srv/wp/wp-includes/class-wp-rewrite.php', 'line' => 1540, 'function' => 'refresh_rewrite_rules', 'class' => 'WP_Rewrite' ),
			array( 'file' => '/srv/wp/wp-blog-header.php', 'line' => 16, 'function' => 'wp' ),
			array( 'file' => '/srv/wp/index.php', 'line' => 17, 'function' => 'require' ),
		);
		$caller = Classify::caller( $frames, $dirs );
		same( 'core', $caller['component'] );
		same( 'WP_Rewrite::refresh_rewrite_rules', $caller['function'] );
		same( 'wp-includes/class-wp-rewrite.php:1861', $caller['at'] );
	}
);

test(
	'request types, with CLI and cron before admin',
	function () {
		same( 'front', Classify::request_type( array() ) );
		same( 'admin', Classify::request_type( array( 'admin' => true ) ) );
		same( 'ajax', Classify::request_type( array( 'admin' => true, 'ajax' => true ) ) );
		same( 'cron', Classify::request_type( array( 'cron' => true, 'admin' => true ) ) );
		same( 'cli', Classify::request_type( array( 'cli' => true, 'cron' => true ) ) );
		same( 'rest', Classify::request_type( array( 'rest' => true ) ) );
	}
);

test(
	'changed keys: arrays, objects, scalars, key order; values are never returned',
	function () {
		same( array( 'last' ), Classify::changed_keys( array( 'last' => 1, 'v' => 2 ), array( 'last' => 5, 'v' => 2 ) ) );
		same( array( 'b', 'c' ), Classify::changed_keys( array( 'a' => 1, 'b' => 2 ), array( 'a' => 1, 'b' => 3, 'c' => 4 ) ) );
		same( array( '(value)' ), Classify::changed_keys( 'x', 'y' ) );
		same( array( 'n' ), Classify::changed_keys( (object) array( 'n' => 1 ), (object) array( 'n' => 2 ) ) );
		same( array( '(order)' ), Classify::changed_keys( array( 'a' => 1, 'b' => 2 ), array( 'b' => 2, 'a' => 1 ) ) );
		same( array( '[n]' ), Classify::changed_keys( array( 1, 2 ), array( 1, 3 ) ) );
		same( array( 'nested' ), Classify::changed_keys( array( 'nested' => array( 'x' => 1 ) ), array( 'nested' => array( 'x' => 2 ) ) ) );
	}
);

test(
	'transient timeout rows are folded into their transient',
	function () {
		same( null, Classify::option_key( '_transient_timeout_acme' ) );
		same( null, Classify::option_key( '_site_transient_timeout_acme' ) );
		same( '_transient_acme', Classify::option_key( '_transient_acme' ) );
		same( 191, strlen( Classify::option_key( str_repeat( 'a', 300 ) ) ) );
	}
);

echo "# tests {$count}\n# fail {$failures}\n";
exit( $failures ? 1 : 0 );
