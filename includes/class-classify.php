<?php
/**
 * Pure functions: who called, what kind of request, what changed. No WordPress calls, so they are unit-tested
 * without a site.
 *
 * @package OptionChurn
 */

namespace OptionChurn;

/**
 * Classification helpers.
 */
final class Classify {

	/** Functions whose call site is the caller we want, from innermost outwards. */
	const WRITE_FUNCTIONS = array(
		'update_option',
		'add_option',
		'delete_option',
		'set_transient',
		'delete_transient',
		'update_site_option',
		'add_site_option',
		'delete_site_option',
		'set_site_transient',
		'delete_site_transient',
		'update_network_option',
		'add_network_option',
		'delete_network_option',
	);

	/**
	 * The component a file belongs to: "plugin:<slug>", "mu-plugin:<file>", "theme:<slug>" or "core".
	 *
	 * @param string $file       Absolute file path.
	 * @param array  $dirs       { plugins, mu_plugins, themes } absolute directories, forward slashes.
	 * @return string
	 */
	public static function component( $file, array $dirs ) {
		$file = str_replace( '\\', '/', (string) $file );
		foreach ( array( 'plugins' => 'plugin', 'mu_plugins' => 'mu-plugin', 'themes' => 'theme' ) as $key => $label ) {
			$dir = rtrim( str_replace( '\\', '/', $dirs[ $key ] ), '/' ) . '/';
			if ( 0 === strpos( $file, $dir ) ) {
				$rest = substr( $file, strlen( $dir ) );
				$slug = strtok( $rest, '/' );
				return "{$label}:{$slug}";
			}
		}
		return 'core';
	}

	/**
	 * The caller of an option write, from a debug_backtrace() without arguments: the first call site outside
	 * WordPress core and this plugin, with the function it sits in. Core when every frame is core.
	 *
	 * @param array  $frames Frames, innermost first.
	 * @param array  $dirs   { plugins, mu_plugins, themes, core (ABSPATH), self (this plugin's directory) }.
	 * @return array{component: string, function: string, at: string}
	 */
	public static function caller( array $frames, array $dirs ) {
		$abs  = rtrim( str_replace( '\\', '/', $dirs['core'] ), '/' ) . '/';
		$self = rtrim( str_replace( '\\', '/', $dirs['self'] ), '/' ) . '/';
		// Start at the outermost write function, so set_transient() is reported, not the update_option() inside it.
		$start = 0;
		foreach ( $frames as $i => $frame ) {
			if ( isset( $frame['function'] ) && empty( $frame['class'] ) && in_array( $frame['function'], self::WRITE_FUNCTIONS, true ) ) {
				$start = $i;
			}
		}
		$first_core = null;
		$count      = count( $frames );
		for ( $i = $start; $i < $count; $i++ ) {
			$file = isset( $frames[ $i ]['file'] ) ? str_replace( '\\', '/', $frames[ $i ]['file'] ) : '';
			if ( '' === $file ) {
				continue;
			}
			// Anything outside plugins, must-use plugins and themes is WordPress itself: wp-includes, wp-admin and the
			// files at the root (wp-blog-header.php, wp-settings.php, index.php).
			$in_core = 'core' === self::component( $file, $dirs ) || 0 === strpos( $file, $self );
			$outer   = isset( $frames[ $i + 1 ] ) ? $frames[ $i + 1 ] : array();
			$in      = isset( $outer['function'] ) ? ( isset( $outer['class'] ) ? $outer['class'] . '::' : '' ) . $outer['function'] : '(file scope)';
			// PHP 8.4 names closures "{closure:/absolute/path.php:12}"; the call site already says where it is.
			$in = preg_replace( '/\{closure:[^}]*\}/', '{closure}', $in );
			$here    = array(
				'component' => self::component( $file, $dirs ),
				'function'  => $in,
				'at'        => self::relative( $file, $abs ) . ':' . ( isset( $frames[ $i ]['line'] ) ? $frames[ $i ]['line'] : 0 ),
			);
			if ( null === $first_core && 0 !== strpos( $file, $self ) ) {
				$first_core = $here;
			}
			if ( ! $in_core ) {
				return $here;
			}
		}
		return $first_core ? $first_core : array(
			'component' => 'core',
			'function'  => '(unknown)',
			'at'        => '',
		);
	}

	/**
	 * The kind of request: cron, ajax, rest, cli, xmlrpc, admin or front.
	 *
	 * @param array $state { cron, ajax, rest, cli, xmlrpc, admin } booleans, read by the caller from WordPress.
	 * @return string
	 */
	public static function request_type( array $state ) {
		foreach ( array( 'cli', 'cron', 'rest', 'ajax', 'xmlrpc', 'admin' ) as $type ) {
			if ( ! empty( $state[ $type ] ) ) {
				return $type;
			}
		}
		return 'front';
	}

	/**
	 * What changed between two option values: the top-level keys whose values differ (for arrays and objects), or
	 * "(value)" for anything else. Values themselves are never returned.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 * @return string[] Changed key names, at most 20.
	 */
	public static function changed_keys( $old, $new ) {
		if ( is_object( $old ) ) {
			$old = get_object_vars( $old );
		}
		if ( is_object( $new ) ) {
			$new = get_object_vars( $new );
		}
		if ( ! is_array( $old ) || ! is_array( $new ) ) {
			return array( '(value)' );
		}
		$keys = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			$a = array_key_exists( $key, $old ) ? $old[ $key ] : null;
			$b = array_key_exists( $key, $new ) ? $new[ $key ] : null;
			if ( ! array_key_exists( $key, $old ) || ! array_key_exists( $key, $new ) || serialize( $a ) !== serialize( $b ) ) {
				$keys[] = self::key_label( $key );
			}
		}
		if ( ! $keys && array_keys( $old ) !== array_keys( $new ) ) {
			return array( '(order)' );
		}
		return array_slice( $keys, 0, 20 );
	}

	/**
	 * A key as a label: numeric keys become "[n]"; long keys are shortened; nothing that looks like a value.
	 *
	 * @param int|string $key Key.
	 * @return string
	 */
	public static function key_label( $key ) {
		if ( is_int( $key ) ) {
			return '[n]';
		}
		$key = (string) $key;
		return strlen( $key ) > 64 ? substr( $key, 0, 63 ) . '…' : $key;
	}

	/**
	 * The option name as recorded: a transient's timeout row is folded into the transient's own row.
	 *
	 * @param string $option Option name.
	 * @return string|null Null for rows that are not recorded separately.
	 */
	public static function option_key( $option ) {
		if ( preg_match( '/^_(site_)?transient_timeout_/', $option ) ) {
			return null;
		}
		return strlen( $option ) > 191 ? substr( $option, 0, 191 ) : $option;
	}

	/**
	 * A path relative to ABSPATH, with forward slashes.
	 */
	private static function relative( $file, $abs ) {
		return 0 === strpos( $file, $abs ) ? substr( $file, strlen( $abs ) ) : basename( $file );
	}
}
