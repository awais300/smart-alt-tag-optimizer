<?php

/**
 * Bootstrap class - Initializes the plugin and registers all hooks.
 *
 * @package SmartAlt
 */

namespace SmartAlt;

use SmartAlt\Admin\SettingsPage;
use SmartAlt\Admin\BulkUpdater;
use SmartAlt\Core\PostProcessor;
use SmartAlt\Core\AttachmentHandler;
use SmartAlt\Cron\Scheduler;
use SmartAlt\Frontend\Injector;
use SmartAlt\Woo\WooIntegrator;
use SmartAlt\Logger;
use SmartAlt\Admin\AdminAjax;

/**
 * Bootstrap singleton - loads all components and registers hooks.
 */
class Bootstrap
{

	/**
	 * Singleton instance.
	 *
	 * @var Bootstrap
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Bootstrap
	 */
	public static function instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - Initialize plugin on WordPress hooks.
	 */
	private function __construct()
	{
		// Hook into WordPress init to load everything
		add_action('init', [$this, 'init'], 5);
		add_action('admin_init', [$this, 'admin_init'], 5);
	}

	/**
	 * Initialize the plugin on WordPress init.
	 *
	 * @return void
	 */
	public function init()
	{
		// Load translations
		load_plugin_textdomain(
			SMARTALT_TEXT_DOMAIN,
			false,
			dirname(plugin_basename(SMARTALT_PLUGIN_FILE)) . '/languages'
		);

		// Initialize core services
		Logger::instance();
		Scheduler::instance();

		// Register AJAX endpoints
		AdminAjax::register();

		// Initialize settings page
		$settings_page = SettingsPage::instance();
		add_action('admin_menu', [$settings_page, 'register_menu']);

		// Register hooks for post processing
		$post_processor = PostProcessor::instance();
		add_action('save_post', [$post_processor, 'process_post'], 15, 2);
		add_action('wp_insert_attachment', [$post_processor, 'process_attachment'], 10, 2);

		// Register WooCommerce hooks (if active)
		if (class_exists('WooCommerce') || class_exists('woocommerce')) {
			$woo_integrator = WooIntegrator::instance();
			add_action('save_post_product', [$woo_integrator, 'process_product'], 15, 2);
			add_action('woocommerce_update_product', [$woo_integrator, 'process_product_object'], 15, 1);
			add_action('woocommerce_rest_insert_product', [$woo_integrator, 'process_product_rest'], 15, 3);
		}

		// Frontend output buffering (server-side injection only)
		if ($this->is_frontend_injection_enabled() && ! is_admin() && ! is_feed()) {
			$injector = Injector::instance();
			add_action('template_redirect', [$injector, 'start_buffering'], 1);
		}

		// Scheduled cron tasks
		if (! wp_next_scheduled('smartalt_bulk_cron') && $this->is_cron_enabled()) {
			wp_schedule_event(time(), 'daily', 'smartalt_bulk_cron');
		}
		add_action('smartalt_bulk_cron', [Scheduler::class, 'run_scheduled_bulk']);

		// Pruning cron
		if (! wp_next_scheduled('smartalt_prune_logs')) {
			wp_schedule_event(time(), 'daily', 'smartalt_prune_logs');
		}
		add_action('smartalt_prune_logs', [Logger::class, 'prune_logs']);
	}

	/**
	 * Admin initialization.
	 *
	 * @return void
	 */
	public function admin_init()
	{
		// Register settings page
		$settings_page = SettingsPage::instance();
		add_action('admin_init', [$settings_page, 'register_settings']);

		// Add dashboard widget
		add_action('wp_dashboard_setup', [$settings_page, 'add_dashboard_widget']);
	}

	/**
	 * Plugin activation hook - Create database tables and set defaults.
	 *
	 * @return void
	 */
	public static function activate()
	{
		// Create logs table
		Logger::create_table();

		// Set default options if not already set
		if (! get_option('smartalt_enabled')) {
			update_option('smartalt_enabled', 1);
			update_option('smartalt_frontend_injection_enabled', 1);
			update_option('smartalt_generation_method', 'post_title');
			update_option('smartalt_max_alt_length', 125);
			update_option('smartalt_batch_size', 100);
			update_option('smartalt_cache_ai_results', 1);
			update_option('smartalt_ai_cache_ttl_days', 90);
			update_option('smartalt_logging_enabled', 1);
			update_option('smartalt_log_retention_days', 30);
			update_option('smartalt_log_level', 'info');
		}

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate()
	{
		// Clear scheduled cron events
		wp_clear_scheduled_hook('smartalt_bulk_cron');
		wp_clear_scheduled_hook('smartalt_prune_logs');

		// Optional: Log deactivation
		Logger::log(
			null,
			null,
			null,
			null,
			'system',
			null,
			'success',
			'Plugin deactivated',
			'info'
		);
	}

	/**
	 * Check if frontend injection is enabled.
	 *
	 * @return bool
	 */
	private function is_frontend_injection_enabled()
	{
		return (bool) get_option('smartalt_enabled') && (bool) get_option('smartalt_frontend_injection_enabled', 1);
	}

	/**
	 * Check if scheduled bulk cron is enabled.
	 *
	 * @return bool
	 */
	private function is_cron_enabled()
	{
		$schedule = get_option('smartalt_bulk_schedule', 'none');
		return 'none' !== $schedule;
	}
}
