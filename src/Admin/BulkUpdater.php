<?php
/**
 * BulkUpdater - Handles bulk update AJAX requests and batching.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Admin;

use SmartAlt\Core\AttachmentHandler;
use SmartAlt\Core\PostProcessor;
use SmartAlt\Logger;

/**
 * Bulk updater for handling AJAX bulk requests.
 */
class BulkUpdater {

	/**
	 * Session key for bulk job tracking.
	 *
	 * @var string
	 */
	const SESSION_KEY = 'smartalt_bulk_job_';

	/**
	 * Max batch size per AJAX call.
	 *
	 * @var int
	 */
	const MAX_BATCH = 100;

	/**
	 * Handle AJAX bulk run request.
	 *
	 * @return void
	 */
	public static function ajax_bulk_run() {
		check_ajax_referer( 'smartalt_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ] );
		}

		$dry_run = isset( $_POST['dry_run'] ) && $_POST['dry_run'];
		$scope = sanitize_text_field( $_POST['scope'] ?? 'attached_only' );
		$force_update = isset( $_POST['force_update'] ) && $_POST['force_update'];

		try {
			// Get attachments to process
			$attachments = self::get_attachments_for_scope( $scope );

			if ( empty( $attachments ) ) {
				wp_send_json_success( [
					'message' => __( 'No attachments to process.', SMARTALT_TEXT_DOMAIN ),
					'total'   => 0,
					'processed' => 0,
				] );
			}

			// Store job in transient for progress tracking
			$job_id = uniqid( 'smartalt_bulk_' );
			set_transient(
				self::SESSION_KEY . $job_id,
				[
					'total'       => count( $attachments ),
					'processed'   => 0,
					'errors'      => 0,
					'attachments' => $attachments,
					'scope'       => $scope,
					'force_update' => $force_update,
					'dry_run'     => $dry_run,
					'started'     => current_time( 'timestamp' ),
				],
				HOUR_IN_SECONDS
			);

			if ( $dry_run ) {
				// Preview mode - show what would be changed
				$preview = self::preview_changes( array_slice( $attachments, 0, 10 ), $force_update );
				wp_send_json_success( [
					'job_id'   => $job_id,
					'total'    => count( $attachments ),
					'preview'  => $preview,
					'message'  => sprintf(
						__( 'Dry run preview of first 10 items. Total to process: %d', SMARTALT_TEXT_DOMAIN ),
						count( $attachments )
					),
				] );
			} else {
				// Start async processing
				wp_send_json_success( [
					'job_id'   => $job_id,
					'total'    => count( $attachments ),
					'message'  => __( 'Bulk update started. Processing...', SMARTALT_TEXT_DOMAIN ),
				] );

				// Trigger background processing via wp_remote_post
				self::process_batch_async( $job_id, 0 );
			}
		} catch ( \Exception $e ) {
			Logger::log( null, null, null, null, 'bulk', null, 'error', $e->getMessage(), 'error' );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Handle AJAX progress check.
	 *
	 * @return void
	 */
	public static function ajax_bulk_progress() {
		check_ajax_referer( 'smartalt_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ] );
		}

		$job_id = sanitize_text_field( $_POST['job_id'] ?? '' );

		if ( ! $job_id ) {
			wp_send_json_error( [ 'message' => 'Invalid job ID' ] );
		}

		$job = get_transient( self::SESSION_KEY . $job_id );

		if ( ! $job ) {
			wp_send_json_error( [ 'message' => 'Job not found or expired' ] );
		}

		$progress = ( $job['processed'] / $job['total'] ) * 100;

		wp_send_json_success( [
			'job_id'     => $job_id,
			'total'      => $job['total'],
			'processed'  => $job['processed'],
			'errors'     => $job['errors'],
			'progress'   => round( $progress, 1 ),
			'complete'   => $job['processed'] >= $job['total'],
		] );
	}

	/**
	 * Process a batch asynchronously.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $batch_num Batch number.
	 *
	 * @return void
	 */
	private static function process_batch_async( $job_id, $batch_num ) {
		$job = get_transient( self::SESSION_KEY . $job_id );

		if ( ! $job ) {
			return;
		}

		$batch_size = (int) get_option( 'smartalt_batch_size', 100 );
		$start = $batch_num * $batch_size;
		$batch = array_slice( $job['attachments'], $start, $batch_size );

		if ( empty( $batch ) ) {
			// Job complete
			Logger::log(
				null,
				null,
				null,
				null,
				'bulk',
				null,
				'success',
				sprintf( 'Bulk complete: %d processed, %d errors', $job['processed'], $job['errors'] ),
				'info'
			);
			delete_transient( self::SESSION_KEY . $job_id );
			return;
		}

		// Process this batch
		foreach ( $batch as $attachment_id ) {
			$result = self::process_single_attachment( $attachment_id, $job['force_update'] );
			$job['processed']++;

			if ( ! $result ) {
				$job['errors']++;
			}
		}

		// Update job progress
		set_transient( self::SESSION_KEY . $job_id, $job, HOUR_IN_SECONDS );

		// Schedule next batch
		wp_schedule_single_event( time() + 1, 'smartalt_process_batch', [ $job_id, $batch_num + 1 ] );
	}

	/**
	 * Process single attachment for bulk.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force_update Force update.
	 *
	 * @return bool Success.
	 */
	private static function process_single_attachment( $attachment_id, $force_update = false ) {
		try {
			if ( $force_update ) {
				set_transient( "smartalt_force_update_{$attachment_id}", true, HOUR_IN_SECONDS );
			}

			// Trigger post processing
			$post = get_post( $attachment_id );
			if ( ! $post ) {
				return false;
			}

			do_action( 'save_post', $attachment_id, $post );

			return true;
		} catch ( \Exception $e ) {
			Logger::log(
				null,
				null,
				$attachment_id,
				null,
				'bulk',
				null,
				'error',
				$e->getMessage(),
				'error'
			);
			return false;
		}
	}

	/**
	 * Get attachments for a given scope.
	 *
	 * @param string $scope Scope: all_media, attached_only, attached_products.
	 *
	 * @return array Array of attachment IDs.
	 */
	private static function get_attachments_for_scope( $scope ) {
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

		// Only those without alt or with empty alt (unless force update)
		$query .= " AND ID NOT IN (
			SELECT post_id FROM {$wpdb->postmeta}
			WHERE meta_key = '_wp_attachment_image_alt' AND meta_value != ''
		)";

		$query .= " ORDER BY ID DESC";

		$attachment_ids = $wpdb->get_col( $query );

		return array_map( 'intval', $attachment_ids );
	}

	/**
	 * Preview what changes would be made.
	 *
	 * @param array $attachment_ids Attachment IDs.
	 * @param bool  $force_update Force update.
	 *
	 * @return array Preview data.
	 */
	private static function preview_changes( $attachment_ids, $force_update ) {
		$preview = [];

		foreach ( $attachment_ids as $attachment_id ) {
			$post = get_post( $attachment_id );
			if ( ! $post || ! $post->post_parent ) {
				continue;
			}

			$parent = get_post( $post->post_parent );
			if ( ! $parent ) {
				continue;
			}

			$current_alt = AttachmentHandler::get_alt( $attachment_id );
			$alt_source = get_option( 'smartalt_alt_source', 'post_title' );
			$new_alt = 'post_title' === $alt_source ? $parent->post_title : '(AI generated)';

			$preview[] = [
				'attachment_id' => $attachment_id,
				'title'         => $post->post_title,
				'parent'        => $parent->post_title,
				'current_alt'   => $current_alt,
				'new_alt'       => $new_alt,
				'will_change'   => $force_update || empty( $current_alt ),
			];
		}

		return $preview;
	}
}