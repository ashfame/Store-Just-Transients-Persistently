<?php

/*
Plugin Name: Store Just Transients Persistently
Plugin URI: https://github.com/ashfame/Store-Just-Transients-Persistently/
Description: Store Just Transients Persistently in memcached, activate it as a regular plugin. Improves transient read speed!
Author: Ashfame
Version: 0.1
Author URI: http://ashfame.com
*/

class Ashfame_Store_Just_Transients_Persistently {
	var $mc;
	var $cache;
	var $debug = false;
	var $debug_log_file;

	public function __construct() {
		// Don't do anything if object-cache.php file is in use, because this plugin is meant to store transients in memcached only when object caching can't be enabled for some reason
		// (Eg: Wishlist plugin updating rows in option table directly, so object cache becomes stale and doesn't even know it)
		if ( wp_using_ext_object_cache() ) {
			return;
		}

		// switch debug ON if WP_DEBUG is true
		$this->debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

		// define log file url in tmp directory with a unique name as per the site's url
		$this->$debug_log_file = trailingslashit( sys_get_temp_dir() ) . 'sjtp-' . str_replace( array( 'http://', 'https://' ), array( '', '' ), home_url() ) . '.log';

		// override WP-CLI transient delete-all command
		if ( defined( 'WP_CLI' ) and WP_CLI ) {
			include_once 'override-wp-cli.php';
		}

		// plug itself into WP *_transient()
		add_action( 'setted_transient', array( $this, 'save_transient_persistently' ), 10, 3 );
		add_action( 'deleted_transient', array( $this, 'delete_persistent_transient' ) );
		add_action( 'init', array( $this, 'add_all_pre_get_transients_filter' ) );

		$this->add_memcached_servers();
	}

	public function save_transient_persistently( $transient, $value, $expiration ) {
		$transient = substr( $transient, strlen( '_transient_' ) ); // get actual transient name

		// save transient persistently now
		$this->set( $transient, $value, $expiration );

		// When a transient is saved, we save the transient's name so that we know a transient by this name exists,
		// Its used to plug into filter "pre_transient_$transient" in get_transient()
		// This is necessary since there isn't a catch-all "pre_transient" filter
		$all_transients_known = get_option( 'all_transients_known', array() );
		if ( ! in_array( $transient, $all_transients_known ) ) {
			$all_transients_known[] = $transient;
			update_option( 'all_transients_known', $all_transients_known );
		}
	}

	public function delete_persistent_transient( $transient ) {
		$this->delete( $transient );
		$all_transients_known = get_option( 'all_transients_known', array() );
		if ( $key = array_search( $transient, $all_transients_known ) ) {
			unset( $all_transients_known[ $key ] );
			update_option( 'all_transients_known', $all_transients_known );
		}
	}

	public function __call( $name, $args ) {
		// Its only suppose to catch pre_transient_$transient filter callbacks
		if ( strpos( $name, 'pre_transient_' ) !== 0 ) {
			return;
		}

		$transient = substr( $name, strlen( 'pre_transient_' ) );

		if ( strlen( $transient ) > 0 ) {
			// See if we have the value of this transient in memcached, if so, return it
			// In memcache, expired records are removed when they are called for, and then null is returned as if they never existed
			// If we don't have the value of the transient, we must return false to let the regular retrieval process take over
			// $this->get() already returns false when a transient wasn't found so we return that directly
			return $this->get( $transient );
		}
	}

	public function add_all_pre_get_transients_filter() {
		// This list can be stored in memcached too, but lets keep it in options table for now
		$all_transients_known = get_option( 'all_transients_known', array() );
		foreach ( $all_transients_known as $transient ) {
			add_filter( 'pre_transient_' . $transient, array( $this, 'pre_transient_' . $transient ) );
		}
	}

	public function add_memcached_servers() {
		global $memcached_servers;

		if ( isset( $memcached_servers ) ) {
			$buckets = $memcached_servers;
		} else {
			$buckets = array( '127.0.0.1' );
		}
		reset( $buckets );
		if ( is_int( key( $buckets ) ) ) {
			$buckets = array( 'default' => $buckets );
		}
		foreach ( $buckets as $bucket => $servers ) {
			$this->mc[ $bucket ] = new Memcache();
			foreach ( $servers as $server ) {
				list ( $node, $port ) = explode( ':', $server );
				if ( !$port )
					$port = ini_get( 'memcache.default_port' );
				$port = intval( $port );
				if ( !$port )
					$port = 11211;
				$this->mc[ $bucket ]->addServer( $node, $port, true, 1, 1, 15, true, array( $this, 'failure_callback' ) );
				$this->mc[ $bucket ]->setCompressThreshold( 20000, 0.2 );
			}
		}
	}

	public function get_mc() {
		return $this->mc[ 'default' ];
	}

	public function failure_callback( $host, $port ) {
		if ( $this->debug ) {
			error_log( "Connection failure for $host:$port\n", 3, $this->debug_log_file );
		}
	}

	/* set() sets value whatever we ask it to */
	public function set( $id, $data, $expire = 0 ) {
		if ( $this->debug ) {
			error_log( __METHOD__ . ' call for ' . $id . ' value = ' . $data . PHP_EOL, 3, $this->debug_log_file );
		}
		if ( is_object( $data ) ) {
			$data = clone $data;
		}
		if ( $response = $this->mc['default']->set( $id, $data, false, $expire ) ) {
			$this->cache[ $id ] = $data;
		}

		return $response;
	}

	/* add() adds the value only if it doesn't already exist */
	public function add( $id, $data, $expire = 0 ) {
		if ( $this->debug ) {
			error_log( __METHOD__ . ' call for ' . $id . ' value = ' . $data . PHP_EOL, 3, $this->debug_log_file );
		}
		if ( is_object( $data ) ) {
			$data = clone $data;
		}
		if ( isset( $this->cache[ $id ] ) && $this->cache[ $id ] ) {
			return false;
		} else {
			if ( $response = $this->mc['default']->add( $id, $data, false, $expire ) ) {
				// only add to local cache if True-ish status was returned from memcache
				$this->cache[ $id ] = $data;
			}

			return $response;
		}
	}

	public function delete( $id ) {
		if ( $this->debug ) {
			error_log( __METHOD__ . ' call for ' . $id . PHP_EOL, 3, $this->debug_log_file );
		}
		if ( isset( $this->cache[ $id ] ) ) {
			unset( $this->cache[ $id ] );
		}
		return $this->mc[ 'default' ]->delete( $id );
	}

	public function get( $id ) {
		if ( $this->debug ) {
			error_log( __METHOD__ . ' call for ' . $id . PHP_EOL, 3, $this->debug_log_file );
		}
		if ( isset( $this->cache[ $id ] ) ) {
			if ( is_object( $this->cache[ $id ] ) ) {
				$value = clone $this->cache[ $id ];
			} else {
				$value = $this->cache[ $id ];
			}
		} else {
			$value = $this->mc[ 'default' ]->get( $id );
			$this->cache[ $id ] = $value;
		}
		if ( NULL === $value ) {
			$value = false;
		}

		return $value;
	}

	public function close() {
		if ( $this->debug ) {
			error_log( __METHOD__ . ' call' . PHP_EOL, 3, $this->debug_log_file );
		}
		$this->mc[ 'default' ]->close();
	}

	public function flush() {
		$this->mc[ 'default' ]->flush();
	}
}

$Ashfame_Store_Just_Transients_Persistently = new Ashfame_Store_Just_Transients_Persistently();