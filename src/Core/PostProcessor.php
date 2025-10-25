<?php
/**
 * PostProcessor - Handles post save hooks and processing.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Core;

use SmartAlt\Logger;
use SmartAlt\Utils\Sanitize;

/**
 * Post processor singleton.
 */
class PostProcessor {

	/**
	 * Singleton instance.
	 *
	 * @var PostProcessor
	 */
	private static $instance = null;

	/**
	 * Processing queue to avoid infinite loops.
	 *
	 * @var array
	 */
	private $processing_queue = [];

	/**
	 * Get singleton instance.
	 *
	 * @return PostProcessor
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
	 * Process a post on save_post hook.
	 *
	 * Finds inline and attached images, generates/assigns alt text as needed.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return void
	 */
	public function process_post( $post_id, $post ) {
		// Skip if plugin not enabled
		if ( ! get_option( 'smartalt_enabled' ) ) {
			return;
		}

		// Skip if already processing this post (avoid infinite loops)
		if ( isset( $this->processing_queue[ $post_id ] ) ) {
			return;
		}

		// Mark as processing
		$this->processing_queue[ $post_id ] = true;

		// Skip autosaves and revisions
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			unset( $this->processing_queue[ $post_id ] );
			return;
		}

		// Skip non-public post types
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			unset( $this->processing_queue[ $post_id ] );
			return;
		}

		// Respect force_update flag
		$force_update = (bool) get_transient( "smartalt_force_update_{$post_id}" );

		// Collect all images for this post
		$images_to_process = [];

		// Get attached images
		$attached_ids = AttachmentHandler::get_attached_images( $post_id );
		foreach ( $attached_ids as $attachment_id ) {
			$images_to_process[] = [
				'type'            => 'attached',
				'attachment_id'   => $attachment_id,
				'post_id'         => $post_id,
				'force_update'    => $force_update,
			];
		}

		// Get inline images from content
		$inline_images = AttachmentHandler::extract_inline_images( $post->post_content );
		foreach ( $inline_images as $image_data ) {
			// Try to find attachment ID
			$attachment_id = AttachmentHandler::find_attachment_for_inline_image( $image_data['url'], $post_id );

			if ( $attachment_id ) {
				// Skip if already in attached list
				if ( in_array( $attachment_id, $attached_ids, true ) ) {
					continue;
				}

				$images_to_process[] = [
					'type'            => 'inline_attached',
					'attachment_id'   => $attachment_id,
					'post_id'         => $post_id,
					'force_update'    => $force_update,
					'image_url'       => $image_data['url'],
					'current_alt'     => $image_data['alt'],
				];
			} else {
				// Inline image not attached to this post
				$images_to_process[] = [
					'type'            => 'inline_unattached',
					'post_id'         => $post_id,
					'image_url'       => $image_data['url'],
					'current_alt'     => $image_data['alt'],
					'force_update'    => $force_update,
				];
			}
		}

		// Process each image
		foreach ( $images_to_process as $image ) {
			$this->process_image( $image, $post );
		}

		// Clean up transient
		delete_transient( "smartalt_force_update_{$post_id}" );
		unset( $this->processing_queue[ $post_id ] );
	}

	/**
	 * Process a single attachment on upload.
	 *
	 * @param int      $post_id Attachment post ID.
	 * @param \WP_Post $post    Attachment post object.
	 *
	 * @return void
	 */
	public function process_attachment( $post_id, $post ) {
		// Skip if plugin not enabled
		if ( ! get_option( 'smartalt_enabled' ) ) {
			return;
		}

		// Only process if this attachment is attached to a post
		if ( ! $post->post_parent ) {
			return;
		}

		// Check if alt already set
		if ( AttachmentHandler::has_alt( $post_id ) ) {
			return;
		}

		// Process this attachment
		$image_data = [
			'type'          => 'attached',
			'attachment_id' => $post_id,
			'post_id'       => $post->post_parent,
			'force_update'  => false,
		];

		$parent_post = get_post( $post->post_parent );
		if ( $parent_post ) {
			$this->process_image( $image_data, $parent_post );
		}
	}

	/**
	 * Process a single image - generate alt text based on settings.
	 *
	 * @param array    $image Image data array.
	 * @param \WP_Post $post  Parent post object.
	 *
	 * @return void
	 */
	private function process_image( $image, $post ) {
		$alt_source = get_option( 'smartalt_alt_source', 'post_title' );
		$new_alt = null;
		$old_alt = null;
		$source = 'manual';

		// For attached images, check current alt
		if ( in_array( $image['type'], [ 'attached', 'inline_attached' ], true ) ) {
			$old_alt = AttachmentHandler::get_alt( $image['attachment_id'] );

			// Skip if alt exists and not force update
			if ( $old_alt && ! $image['force_update'] ) {
				return;
			}
		}

		// Generate alt based on source setting
		if ( 'ai' === $alt_source ) {
			// Try to generate via AI
			$new_alt = $this->generate_alt_via_ai( $image, $post );
			if ( $new_alt ) {
				$source = 'ai';
			} else {
				// AI failed, fallback to post_title
				$new_alt = Sanitize::alt_text( $post->post_title );
				$source = 'post_title_fallback';
			}
		} else {
			// Use post_title
			$new_alt = Sanitize::alt_text( $post->post_title );
			$source = 'post_title';
		}

		// Ensure we have alt text
		if ( ! $new_alt ) {
			Logger::log( $old_alt, null, $image['attachment_id'] ?? null, $image['post_id'], $source, null, 'skipped', 'No alt text generated' );
			return;
		}

		// Update attachment alt
		if ( isset( $image['attachment_id'] ) ) {
			AttachmentHandler::set_alt( $image['attachment_id'], $new_alt, $image['force_update'] );

			// Log the change
			Logger::log( $old_alt, $new_alt, $image['attachment_id'], $image['post_id'], $source, null, 'success' );
		} else {
			// For inline unattached images, we'd need to update post content HTML
			// This is handled by Frontend\Injector
			Logger::log( null, $new_alt, null, $image['post_id'], $source, null, 'success', 'Inline unattached image' );
		}
	}

	/**
	 * Generate alt text via AI connector.
	 *
	 * @param array    $image Image data array.
	 * @param \WP_Post $post  Parent post object.
	 *
	 * @return string|null Generated alt text or null if failed.
	 */
	private function generate_alt_via_ai( $image, $post ) {
		// Check if AI connector is configured
		$endpoint = get_option( 'smartalt_ai_endpoint' );
		if ( ! $endpoint ) {
			return null;
		}

		// Check AI cache first
		if ( isset( $image['attachment_id'] ) && get_option( 'smartalt_cache_ai_results' ) ) {
			$cache = AttachmentHandler::get_ai_cache( $image['attachment_id'] );
			if ( $cache ) {
				// Cache hit, use existing alt
				return AttachmentHandler::get_alt( $image['attachment_id'] );
			}
		}

		try {
			// Get AI connector
			$connector = AiConnectorFactory::get_connector();
			if ( ! $connector ) {
				return null;
			}

			// Prepare context
			$context = AttachmentHandler::get_context( $image['attachment_id'] ?? null, $image['post_id'] );

			// Generate alt
			$alt = $connector->generate_alt( $image['attachment_id'] ?? 0, $context );

			if ( $alt && isset( $image['attachment_id'] ) ) {
				// Cache the AI result
				if ( get_option( 'smartalt_cache_ai_results' ) ) {
					$model = $connector->get_model_name();
					AttachmentHandler::set_ai_cache( $image['attachment_id'], $model );
				}
			}

			return $alt;
		} catch ( \Exception $e ) {
			Logger::log( null, null, $image['attachment_id'] ?? null, $image['post_id'], 'ai', null, 'error', $e->getMessage(), 'error' );
			return null;
		}
	}
}