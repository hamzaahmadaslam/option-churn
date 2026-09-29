<?php
/**
 * Watches option writes during a request and hands the totals to the store at shutdown.
 *
 * @package OptionChurn
 */

namespace OptionChurn;

/**
 * Collects events in memory; one write to the store per request, at shutdown.
 */
final class Recorder {

	/**
	 * Aggregated writes for this request, keyed by option, operation and caller.
	 *
	 * @var array
	 */
	private $writes = array();

	/**
	 * Changed keys for this request, keyed by option then key.
	 *
	 * @var array
	 */
	private $keys = array();

	/**
	 * Directories for caller attribution.
	 *
	 * @var array
	 */
	private $dirs;

	/**
	 * Where the data goes.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Sets up the recorder.
	 *
	 * @param Store $store Storage.
	 */
	public function __construct( Store $store ) {
		$this->store = $store;
		$this->dirs  = array(
			'plugins'    => WP_PLUGIN_DIR,
			'mu_plugins' => WPMU_PLUGIN_DIR,
			'themes'     => get_theme_root(),
			'core'       => ABSPATH,
			'self'       => dirname( __DIR__ ),
		);
	}

	/**
	 * Hooks into option writes. Nothing is recorded while the store's tables are missing.
	 */
	public function start() {
		add_filter( 'pre_update_option', array( $this, 'before_update' ), PHP_INT_MAX, 3 );
		add_action( 'updated_option', array( $this, 'updated' ), 10, 3 );
		add_action( 'added_option', array( $this, 'added' ), 10, 2 );
		add_action( 'deleted_option', array( $this, 'deleted' ), 10, 1 );
		add_action( 'update_site_option', array( $this, 'updated_site' ), 10, 3 );
		add_action( 'add_site_option', array( $this, 'added_site' ), 10, 2 );
		add_action( 'delete_site_option', array( $this, 'deleted_site' ), 10, 1 );
		add_action( 'shutdown', array( $this, 'flush' ), PHP_INT_MAX );
	}

	/**
	 * Counts update_option() calls WordPress will skip because the value did not change. Runs last, so it sees
	 * the value WordPress compares.
	 *
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @return mixed The value, unchanged.
	 */
	public function before_update( $value, $option, $old_value ) {
		if ( $value === $old_value || maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
			$this->note( $option, 'noop', 0, array() );
		}
		return $value;
	}

	/**
	 * A real update.
	 */
	public function updated( $option, $old_value, $value ) {
		$this->note( $option, 'update', strlen( (string) maybe_serialize( $value ) ), Classify::changed_keys( $old_value, $value ) );
	}

	/**
	 * A new option.
	 */
	public function added( $option, $value ) {
		$this->note( $option, 'add', strlen( (string) maybe_serialize( $value ) ), array() );
	}

	/**
	 * A deleted option.
	 */
	public function deleted( $option ) {
		$this->note( $option, 'delete', 0, array() );
	}

	/**
	 * A network option update. WordPress passes the new value before the old one here.
	 */
	public function updated_site( $option, $value, $old_value ) {
		$this->note( $option, 'update-network', strlen( (string) maybe_serialize( $value ) ), Classify::changed_keys( $old_value, $value ) );
	}

	/**
	 * A new network option.
	 */
	public function added_site( $option, $value ) {
		$this->note( $option, 'add-network', strlen( (string) maybe_serialize( $value ) ), array() );
	}

	/**
	 * A deleted network option.
	 */
	public function deleted_site( $option ) {
		$this->note( $option, 'delete-network', 0, array() );
	}

	/**
	 * Adds one event to this request's totals.
	 *
	 * @param string   $option Option name.
	 * @param string   $op     Operation.
	 * @param int      $bytes  Serialized size written.
	 * @param string[] $keys   Changed keys.
	 */
	private function note( $option, $op, $bytes, array $keys ) {
		$name = Classify::option_key( (string) $option );
		if ( null === $name ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- attribution is the point of this plugin.
		$caller = Classify::caller( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ), $this->dirs );
		$id     = "{$name}\0{$op}\0{$caller['component']}\0{$caller['function']}";
		if ( ! isset( $this->writes[ $id ] ) ) {
			$this->writes[ $id ] = array(
				'option'    => $name,
				'op'        => $op,
				'component' => $caller['component'],
				'function'  => substr( $caller['function'], 0, 191 ),
				'at'        => substr( $caller['at'], 0, 191 ),
				'count'     => 0,
				'bytes'     => 0,
			);
		}
		++$this->writes[ $id ]['count'];
		$this->writes[ $id ]['bytes'] += $bytes;
		foreach ( $keys as $key ) {
			$this->keys[ $name ][ $key ] = isset( $this->keys[ $name ][ $key ] ) ? $this->keys[ $name ][ $key ] + 1 : 1;
		}
	}

	/**
	 * Saves this request's totals. Runs at shutdown, after everything else.
	 */
	public function flush() {
		$type = Classify::request_type(
			array(
				'cli'    => defined( 'WP_CLI' ) && WP_CLI,
				'cron'   => wp_doing_cron(),
				'rest'   => defined( 'REST_REQUEST' ) && REST_REQUEST,
				'ajax'   => wp_doing_ajax(),
				'xmlrpc' => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
				'admin'  => is_admin(),
			)
		);
		if ( ! Settings::should_record( $type ) ) {
			return;
		}
		$this->store->save( $type, array_values( $this->writes ), $this->keys );
		$this->writes = array();
		$this->keys   = array();
	}
}
