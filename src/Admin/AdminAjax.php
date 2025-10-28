<?php

/**
 * AdminAjax - AJAX endpoints with proper nonce verification and security.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Admin;

use SmartAlt\Core\AttachmentHandler;
use SmartAlt\Core\GenericHttpAiConnector;
use SmartAlt\Core\AiConnectorFactory;
use SmartAlt\Logger;

/**
 * Admin AJAX endpoints handler.
 */
class AdminAjax
{

	/**
	 * Register AJAX endpoints.
	 *
	 * @return void
	 */
	public static function register()
	{
		// Bulk operations (requires manage_options)
		add_action('wp_ajax_smartalt_bulk_run', [self::class, 'handle_bulk_run']);
		add_action('wp_ajax_smartalt_bulk_progress', [self::class, 'handle_bulk_progress']);
		add_action('wp_ajax_smartalt_clear_ai_cache', [self::class, 'handle_clear_ai_cache']);
		add_action('wp_ajax_smartalt_test_connection', [self::class, 'handle_test_connection']);
		add_action('wp_ajax_smartalt_revert_log', [self::class, 'handle_revert_log']);
	}

	/**
	 * Handle bulk run AJAX request.
	 *
	 * @return void
	 */
	public static function handle_bulk_run()
	{
		// Verify nonce using correct logic: ! isset || ! verify (not && )
		if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
			wp_send_json_error(['message' => __('Security check failed. Please refresh and try again.', SMARTALT_TEXT_DOMAIN)]);
		}

		// Capability check
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', SMARTALT_TEXT_DOMAIN)]);
		}

		// Sanitize input
		$dry_run = isset($_POST['dry_run']) && sanitize_text_field(wp_unslash($_POST['dry_run'])) === 'true';
		$scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'attached_only';
		$force_update = isset($_POST['force_update']) && sanitize_text_field(wp_unslash($_POST['force_update'])) === 'true';

		// Validate scope
		$valid_scopes = ['all_media', 'attached_only', 'attached_products'];
		if (! in_array($scope, $valid_scopes, true)) {
			wp_send_json_error(['message' => __('Invalid scope.', SMARTALT_TEXT_DOMAIN)]);
		}

		try {
			$bulk_updater = new BulkUpdater();
			$result = $bulk_updater->start_bulk_job($scope, $force_update, $dry_run);
			wp_send_json_success($result);
		} catch (\Exception $e) {
			Logger::log(null, null, null, null, 'bulk', null, 'error', $e->getMessage(), 'error');
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}

	/**
	 * Handle bulk progress AJAX request.
	 *
	 * @return void
	 */
	public static function handle_bulk_progress()
	{
		if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
			wp_send_json_error(['message' => __('Security check failed.', SMARTALT_TEXT_DOMAIN)]);
		}

		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', SMARTALT_TEXT_DOMAIN)]);
		}

		$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';

		if (! $job_id || ! preg_match('/^smartalt_bulk_[a-f0-9]+$/', $job_id)) {
			wp_send_json_error(['message' => __('Invalid job ID.', SMARTALT_TEXT_DOMAIN)]);
		}

		try {
			$bulk_updater = new BulkUpdater();
			$progress = $bulk_updater->get_job_progress($job_id);
			wp_send_json_success($progress);
		} catch (\Exception $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}

	/**
	 * Handle clear AI cache AJAX request.
	 *
	 * @return void
	 */
	public static function handle_clear_ai_cache()
	{
		if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
			wp_send_json_error(['message' => __('Security check failed.', SMARTALT_TEXT_DOMAIN)]);
		}

		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', SMARTALT_TEXT_DOMAIN)]);
		}

		try {
			AttachmentHandler::clear_all_ai_caches();
			Logger::log(null, null, null, null, 'system', null, 'success', 'AI caches cleared by ' . wp_get_current_user()->user_email, 'info');
			wp_send_json_success(['message' => __('AI cache cleared successfully.', SMARTALT_TEXT_DOMAIN)]);
		} catch (\Exception $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}

	/**
	 * Handle test connection AJAX request.
	 *
	 * Tests connection with batch processing (like frontend would use).
	 *
	 * @return void
	 */
	public static function handle_test_connection()
	{
		if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
			wp_send_json_error(['message' => __('Security check failed.', SMARTALT_TEXT_DOMAIN)]);
		}

		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', SMARTALT_TEXT_DOMAIN)]);
		}

		try {
			$connector = AiConnectorFactory::get_connector();

			if (! $connector) {
				wp_send_json_error(['message' => __('AI connector not configured.', SMARTALT_TEXT_DOMAIN)]);
			}

			$success = $connector->test_connection();

			if ($success) {
				wp_send_json_success(['message' => __('Connection successful! Your API endpoint is reachable.', SMARTALT_TEXT_DOMAIN)]);
			} else {
				wp_send_json_error(['message' => __('Connection failed. Check your endpoint URL and API key.', SMARTALT_TEXT_DOMAIN)]);
			}
		} catch (\Exception $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}

	/**
	 * Handle revert log entry AJAX request.
	 *
	 * @return void
	 */
	public static function handle_revert_log()
	{
		if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
			wp_send_json_error(['message' => __('Security check failed.', SMARTALT_TEXT_DOMAIN)]);
		}

		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', SMARTALT_TEXT_DOMAIN)]);
		}

		$log_id = isset($_POST['log_id']) ? (int) $_POST['log_id'] : 0;

		if (! $log_id) {
			wp_send_json_error(['message' => __('Invalid log ID.', SMARTALT_TEXT_DOMAIN)]);
		}

		try {
			global $wpdb;
			$table_name = $wpdb->prefix . 'smartalt_logs';

			// Get log entry
			$log = $wpdb->get_row(
				$wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $log_id)
			);

			if (! $log || ! $log->attachment_id || ! $log->old_alt) {
				wp_send_json_error(['message' => __('Cannot revert this entry.', SMARTALT_TEXT_DOMAIN)]);
			}

			// Revert alt text
			update_post_meta($log->attachment_id, '_wp_attachment_image_alt', $log->old_alt);

			// Log the revert
			Logger::log($log->new_alt, $log->old_alt, $log->attachment_id, $log->post_id, 'revert', null, 'success', 'Reverted by ' . wp_get_current_user()->user_email);

			wp_send_json_success(['message' => __('Alt text reverted successfully.', SMARTALT_TEXT_DOMAIN)]);
		} catch (\Exception $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}
}
