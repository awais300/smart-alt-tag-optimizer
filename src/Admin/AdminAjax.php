<?php

namespace SmartAlt\Admin;

use SmartAlt\Core\AttachmentHandler;
use SmartAlt\Core\GenericHttpAiConnector;
use SmartAlt\Core\AiConnectorFactory;
use SmartAlt\Core\PostProcessor;
use SmartAlt\Logger;

class AdminAjax
{
    const SESSION_KEY = 'smartalt_bulk_job_';
    const BATCH_SIZE = 50; // Process 50 at a time for real-time progress

    public static function register()
    {
        add_action('wp_ajax_smartalt_bulk_run', [self::class, 'handle_bulk_run']);
        add_action('wp_ajax_smartalt_bulk_progress', [self::class, 'handle_bulk_progress']);
        add_action('wp_ajax_smartalt_bulk_process_batch', [self::class, 'handle_process_batch']);
        add_action('wp_ajax_smartalt_clear_ai_cache', [self::class, 'handle_clear_ai_cache']);
        add_action('wp_ajax_smartalt_test_connection', [self::class, 'handle_test_connection']);
        add_action('wp_ajax_smartalt_revert_log', [self::class, 'handle_revert_log']);
    }

    /**
     * Start bulk job - create job session and return first batch
     */
    public static function handle_bulk_run()
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'smart-alt-tag-optimizer')]);
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'smart-alt-tag-optimizer')]);
        }

        $dry_run = isset($_POST['dry_run']) && sanitize_text_field(wp_unslash($_POST['dry_run'])) === 'true';
        $scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'attached_only';
        $force_update = isset($_POST['force_update']) && sanitize_text_field(wp_unslash($_POST['force_update'])) === 'true';

        $valid_scopes = ['all_media', 'attached_only', 'attached_products'];
        if (! in_array($scope, $valid_scopes, true)) {
            wp_send_json_error(['message' => __('Invalid scope.', 'smart-alt-tag-optimizer')]);
        }

        try {
            // Get all attachments for this scope
            $attachments = self::get_attachments_for_scope($scope, $force_update);

            if (empty($attachments)) {
                wp_send_json_success([
                    'message' => __('No attachments to process.', 'smart-alt-tag-optimizer'),
                    'total' => 0,
                    'processed' => 0,
                ]);
            }

            $job_id = uniqid('smartalt_bulk_');
            $job_data = [
                'total' => count($attachments),
                'processed' => 0,
                'errors' => 0,
                'attachments' => $attachments,
                'scope' => $scope,
                'force_update' => $force_update,
                'dry_run' => $dry_run,
                'started' => current_time('timestamp'),
                'batch_index' => 0,
            ];

            set_transient(self::SESSION_KEY . $job_id, $job_data, HOUR_IN_SECONDS);

            if ($dry_run) {
                $preview = self::preview_changes(array_slice($attachments, 0, 10), $force_update);
                wp_send_json_success([
                    'job_id' => $job_id,
                    'total' => count($attachments),
                    'preview' => $preview,
                    'message' => sprintf(
                        __('Preview of first 10 items. Total to process: %d', 'smart-alt-tag-optimizer'),
                        count($attachments)
                    ),
                ]);
            } else {
                // Start first batch
                wp_send_json_success([
                    'job_id' => $job_id,
                    'total' => count($attachments),
                    'message' => __('Starting bulk update...', 'smart-alt-tag-optimizer'),
                    'batch_ready' => true,
                ]);
            }
        } catch (\Exception $e) {
            Logger::log(null, null, null, null, 'bulk', null, 'error', $e->getMessage(), 'error');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Process a single batch of attachments
     */
    public static function handle_process_batch()
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'smart-alt-tag-optimizer')]);
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'smart-alt-tag-optimizer')]);
        }

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';

        if (! $job_id || ! preg_match('/^smartalt_bulk_[a-z0-9]+$/', $job_id)) {
            wp_send_json_error(['message' => __('Invalid job ID.', 'smart-alt-tag-optimizer')]);
        }

        $job = get_transient(self::SESSION_KEY . $job_id);

        if (! $job) {
            wp_send_json_error(['message' => __('Job expired.', 'smart-alt-tag-optimizer')]);
        }

        // Get batch
        $batch_index = (int) $job['batch_index'];
        $start = $batch_index * self::BATCH_SIZE;
        $batch = array_slice($job['attachments'], $start, self::BATCH_SIZE);

        if (empty($batch)) {
            // Job complete
            delete_transient(self::SESSION_KEY . $job_id);
            Logger::log(
                null,
                null,
                null,
                null,
                'bulk',
                null,
                'success',
                sprintf('Bulk complete: %d processed, %d errors', $job['processed'], $job['errors']),
                'info'
            );
            wp_send_json_success([
                'job_id' => $job_id,
                'total' => $job['total'],
                'processed' => $job['total'],
                'errors' => $job['errors'],
                'progress' => 100,
                'complete' => true,
                'message' => __('Bulk update completed!', 'smart-alt-tag-optimizer'),
            ]);
            return;
        }

        // Process batch
        $processed_count = 0;
        $error_count = 0;

        foreach ($batch as $attachment_id) {
            try {
                self::process_single_attachment($attachment_id, $job);
                $processed_count++;
            } catch (\Exception $e) {
                $error_count++;
                Logger::log(null, null, $attachment_id, null, 'bulk', null, 'error', $e->getMessage(), 'error');
            }
        }

        // Update job
        $job['processed'] += $processed_count;
        $job['errors'] += $error_count;
        $job['batch_index']++;

        set_transient(self::SESSION_KEY . $job_id, $job, HOUR_IN_SECONDS);

        $progress = ($job['processed'] / $job['total']) * 100;

        wp_send_json_success([
            'job_id' => $job_id,
            'total' => $job['total'],
            'processed' => $job['processed'],
            'errors' => $job['errors'],
            'progress' => round($progress, 1),
            'complete' => false,
            'batch_ready' => true,
        ]);
    }

    /**
     * Check job progress (for polling)
     */
    public static function handle_bulk_progress()
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'smart-alt-tag-optimizer')]);
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'smart-alt-tag-optimizer')]);
        }

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';

        if (! $job_id) {
            wp_send_json_error(['message' => __('Invalid job ID.', 'smart-alt-tag-optimizer')]);
        }

        $job = get_transient(self::SESSION_KEY . $job_id);

        if (! $job) {
            wp_send_json_error(['message' => __('Job not found.', 'smart-alt-tag-optimizer')]);
        }

        $progress = ($job['processed'] / $job['total']) * 100;

        wp_send_json_success([
            'job_id' => $job_id,
            'total' => $job['total'],
            'processed' => $job['processed'],
            'errors' => $job['errors'],
            'progress' => round($progress, 1),
            'complete' => $job['processed'] >= $job['total'],
        ]);
    }

    /**
     * Process a single attachment
     */
    /**
     * Process a single attachment - handles both attached and orphaned
     */
    private static function process_single_attachment($attachment_id, $job)
    {
        $post = get_post($attachment_id);
        if (! $post || $post->post_type !== 'attachment') {
            throw new \Exception('Invalid attachment');
        }

        // Check if already has alt and not force updating
        $current_alt = AttachmentHandler::get_alt($attachment_id);
        if ($current_alt && ! $job['force_update']) {
            return; // Skip, already has alt
        }

        // For WooCommerce scope, skip orphaned attachments
        if ('attached_products' === $job['scope'] && ! $post->post_parent) {
            return;
        }

        // Get parent post if exists
        $parent_post = null;
        if ($post->post_parent) {
            $parent_post = get_post($post->post_parent);
        }

        // If no parent, create rich context from attachment metadata
        if (! $parent_post) {
            $filename = $post->post_name ? ucfirst(str_replace(['-', '_'], ' ', $post->post_name)) : 'Image';

            // Get attachment metadata for AI context
            $attachment_meta = wp_get_attachment_metadata($attachment_id);
            $width = isset($attachment_meta['width']) ? $attachment_meta['width'] : null;
            $height = isset($attachment_meta['height']) ? $attachment_meta['height'] : null;
            $dimensions = ($width && $height) ? "{$width}x{$height}px" : null;

            // Build richer context for orphaned images
            $post_content = $post->post_excerpt ? $post->post_excerpt : '';
            if ($dimensions) {
                $post_content .= ($post_content ? ' ' : '') . "Image dimensions: {$dimensions}";
            }

            $parent_post = (object) [
                'post_title' => $filename,
                'post_excerpt' => $post->post_excerpt ?: $filename,
                'post_content' => $post_content ?: $filename,
                'ID' => 0,
            ];
        }

        // Set force update transient if needed
        if ($job['force_update']) {
            set_transient("smartalt_force_update_{$attachment_id}", true, HOUR_IN_SECONDS);
        }

        // Process directly using PostProcessor
        PostProcessor::instance()->process_image_direct(
            $attachment_id,
            $parent_post,
            $job['force_update']
        );

        // Clean up
        delete_transient("smartalt_force_update_{$attachment_id}");
    }

    /**
     * Get attachments based on scope
     * 
     * @param string $scope 'all_media', 'attached_only', or 'attached_products'
     * @param bool $force_update If true, include attachments with existing alt
     */
    private static function get_attachments_for_scope($scope, $force_update = false)
    {
        global $wpdb;

        $query = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'";

        // Scope filtering
        if ('attached_only' === $scope) {
            $query .= " AND post_parent > 0";
        } elseif ('attached_products' === $scope) {
            // Only attachments attached to WooCommerce products
            $query .= " AND post_parent IN (
                SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product'
            )";
        }
        // 'all_media' has no additional filter - includes orphaned attachments

        // Alt text filtering
        if (! $force_update) {
            // Only process attachments WITHOUT alt text
            $query .= " AND ID NOT IN (
                SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = '_wp_attachment_image_alt' AND meta_value != ''
            )";
        }
        // If force_update is true, process ALL attachments regardless of existing alt

        $query .= " ORDER BY ID DESC LIMIT 5000";

        $attachment_ids = $wpdb->get_col($query);
        return array_map('intval', $attachment_ids);
    }

    /**
     * Preview what will be changed
     */
    private static function preview_changes($attachment_ids, $force_update)
    {
        $preview = [];

        foreach ($attachment_ids as $attachment_id) {
            $post = get_post($attachment_id);
            if (! $post) {
                continue;
            }

            $parent = $post->post_parent ? get_post($post->post_parent) : null;

            $current_alt = AttachmentHandler::get_alt($attachment_id);
            $alt_source = get_option('smartalt_alt_source', 'post_title');

            $new_alt = 'post_title' === $alt_source
                ? ($parent ? $parent->post_title : $post->post_name)
                : '(AI generated)';

            $will_change = $force_update || empty($current_alt);

            if ($will_change) {
                $preview[] = [
                    'attachment_id' => $attachment_id,
                    'title' => $post->post_title,
                    'parent' => $parent ? $parent->post_title : '(Orphaned)',
                    'current_alt' => $current_alt,
                    'new_alt' => $new_alt,
                    'will_change' => true,
                    'force_updated' => $force_update && ! empty($current_alt),
                ];
            }
        }

        return array_slice($preview, 0, 10); // Return first 10
    }

    public static function handle_clear_ai_cache()
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'smart-alt-tag-optimizer')]);
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'smart-alt-tag-optimizer')]);
        }

        try {
            AttachmentHandler::clear_all_ai_caches();
            Logger::log(null, null, null, null, 'system', null, 'success', 'AI caches cleared', 'info');
            wp_send_json_success(['message' => __('AI cache cleared!', 'smart-alt-tag-optimizer')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function handle_test_connection()
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'smart-alt-tag-optimizer')]);
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'smart-alt-tag-optimizer')]);
        }

        try {
            $connector = AiConnectorFactory::get_connector();

            if (! $connector) {
                wp_send_json_error(['message' => __('AI connector not configured.', 'smart-alt-tag-optimizer')]);
            }

            $success = $connector->test_connection();

            if ($success) {
                wp_send_json_success(['message' => __('Connection successful!', 'smart-alt-tag-optimizer')]);
            } else {
                wp_send_json_error(['message' => __('Connection failed. Check endpoint and API key.', 'smart-alt-tag-optimizer')]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function handle_revert_log()
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'smartalt_bulk_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'smart-alt-tag-optimizer')]);
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'smart-alt-tag-optimizer')]);
        }

        $log_id = isset($_POST['log_id']) ? (int) $_POST['log_id'] : 0;

        if (! $log_id) {
            wp_send_json_error(['message' => __('Invalid log ID.', 'smart-alt-tag-optimizer')]);
        }

        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'smartalt_logs';

            $log = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $log_id)
            );

            if (! $log || ! $log->attachment_id) {
                wp_send_json_error(['message' => __('Cannot revert.', 'smart-alt-tag-optimizer')]);
            }

            // If old_alt is empty, delete the meta
            if (! $log->old_alt) {
                delete_post_meta($log->attachment_id, '_wp_attachment_image_alt');
            } else {
                update_post_meta($log->attachment_id, '_wp_attachment_image_alt', $log->old_alt);
            }

            Logger::log($log->new_alt, $log->old_alt, $log->attachment_id, $log->post_id, 'revert', null, 'success');

            wp_send_json_success(['message' => __('Reverted!', 'smart-alt-tag-optimizer')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
