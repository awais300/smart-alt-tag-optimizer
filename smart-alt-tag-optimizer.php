<?php
/**
 * Smart Alt Tag Optimizer
 *
 * @package     SmartAlt
 * @author      SmartAlt Team
 * @license     GPL-2.0-or-later
 * @copyright   2025 SmartAlt
 *
 * Plugin Name:       Smart Alt Tag Optimizer
 * Plugin URI:        https://github.com/smartalt/plugin
 * Description:       SEO-optimized automatic alt text generation with AI integration, server-side injection, and WooCommerce support.
 * Version:           1.0.0
 * Author:            SmartAlt Team
 * Author URI:        https://smartalt.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-alt-tag-optimizer
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires WP:       5.9
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'SMARTALT_PLUGIN_FILE', __FILE__ );
define( 'SMARTALT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMARTALT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SMARTALT_PLUGIN_VERSION', '1.0.0' );
define( 'SMARTALT_TEXT_DOMAIN', 'smart-alt-tag-optimizer' );

// Load Composer autoloader
require_once SMARTALT_PLUGIN_DIR . 'vendor/autoload.php';

// Initialize the plugin
SmartAlt\Bootstrap::instance();

// Register activation/deactivation hooks
register_activation_hook( __FILE__, [ 'SmartAlt\Bootstrap', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'SmartAlt\Bootstrap', 'deactivate' ] );