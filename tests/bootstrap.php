<?php
/**
 * Bootstrap for PHPUnit tests.
 *
 * @package SmartAlt\Tests
 */

// Define plugin file for WordPress testing
define( 'SMARTALT_PLUGIN_FILE', dirname( dirname( __FILE__ ) ) . '/smart-alt-tag-optimizer.php' );
define( 'SMARTALT_PLUGIN_DIR', dirname( dirname( __FILE__ ) ) . '/' );

// Load WordPress test suite
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin() {
	require SMARTALT_PLUGIN_FILE;
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';