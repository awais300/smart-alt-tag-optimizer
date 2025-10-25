<?php
/**
 * Scheduler - Manages scheduled bulk updates via WP-Cron.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Cron;

use SmartAlt\Logger;

/**
 * Cron scheduler singleton.
 */
class Scheduler {

	/**
	 * Singleton instance.
	 *
	 * @var Scheduler
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Scheduler
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Run scheduled bulk update.
	 *
	 * Called by WP-Cron hook smartalt_bulk_cron.
	 *
	 * @return void
	 */
	public static function run_scheduled_bulk() {
		// Check if bulk is enabled
		$schedule = get_option( 'smartalt_bulk_schedule', 'none' );
		if ( 'none' === $schedule ) {
			return;
		}

		// Get bulk settings
		$scope = get_option( 'smartalt_bulk_scope', 'attached_only' );
		$force_update = (bool) get_option( 'smartalt_bulk_force_update' );
		$batch_size = (int) get_option( 'smartalt_batch_size', 100 );

		// Get attachments to process
		$attachments = self::get_attachments_to_process( $scope );

		if ( empty( $attachments ) ) {
			Logger::log( null, null, null, null, 'cron', null, 'success', 'No attachments to process' );
			return;
		}

		// Process in batches
		$batch_count = ceil( count( $attachments ) / $batch_size );
		Logger::log( null, null, null, null, 'cron', null, 'success', "Starting bulk update: {$batch_count} batches", 'info' );

		$processed = 0;
		$errors = 0;

		foreach ( $attachments as $attachment_id ) {
			$result = self::process_single_attachment( $attachment_id, $force_update );
			if ( $result ) {
				$processed++;
			} else {
				$errors++;
			}
		}

		Logger::log( null, null, null, null, 'cron', null, 'success', "Bulk update complete: {$processed} processed, {$errors} errors", 'info' );
	}

	/**
	 * Get attachments to process based on scope.
	 *
	 * @param string $scope Scope: 'all_media', 'attached_only', 'attached_products'.
	 *
	 * @return array Array of attachment IDs.
	 */
	private static function get_attachments_to_process( $scope ) {
		global $wpdb;

		$query = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'";

		if ( 'attached_only' === $scope || 'attached_products' === $scope ) {
			$query .= " AND post_parent > 0";
		}

		if ( 'attached_products' === $scope ) {
			$query .= " AND post_parent IN (
				SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product'
			)";
		}

		// Only process attachments without alt text
		$query .= " AND ID NOT IN (
			SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_image_alt' AND meta_value != ''
		)";

		$query .= " LIMIT 500"; // Limit per cron run to avoid timeout

		$attachment_ids = $wpdb->get_col( $query );

		return array_map( 'intval', $attachment_ids );
	}

	/**
	 * Process a single attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force_update  Force update even if alt exists.
	 *
	 * @return bool True if processed successfully.
	 */
	private static function process_single_attachment( $attachment_id, $force_update = false ) {
		if ( $force_update ) {
			set_transient( "smartalt_force_update_{$attachment_id}", true, HOUR_IN_SECONDS );
		}

		// Trigger save_post hook via wp_update_post
		$result = wp_update_post( [
			'ID' => $attachment_id,
		], false );

		return ! is_wp_error( $result );
	}
}