<?php
/**
 * AiConnectorInterface - Interface for pluggable AI connectors.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Core;

/**
 * Interface for AI connectors with single-image and batch processing.
 */
interface AiConnectorInterface {

	/**
	 * Generate alt text for a single image.
	 *
	 * @param int   $attachment_id Attachment ID (can be 0 if no attachment).
	 * @param array $context       Context array with image/post data.
	 *
	 * @return string|null Generated alt text or null on failure.
	 *
	 * @throws \Exception On connector errors.
	 */
	public function generate_alt( $attachment_id, $context );

	/**
	 * Generate alt text for multiple images in a single API call (batch processing).
	 *
	 * This is the preferred method for frontend injection to minimize API calls.
	 *
	 * @param array $context Context array containing:
	 *                       - post_title: string
	 *                       - post_excerpt: string
	 *                       - post_content: string (stripped HTML)
	 *                       - images: array of image data (url, alt, match, filename, position)
	 *                       - image_count: int
	 *
	 * @return array Map of image_url => alt_text or empty array on failure.
	 *
	 * @throws \Exception On connector errors.
	 */
	public function generate_batch_alts( $context );

	/**
	 * Get the model name/identifier.
	 *
	 * @return string Model name.
	 */
	public function get_model_name();

	/**
	 * Test the connection to the AI service.
	 *
	 * @return bool True if connection successful, false otherwise.
	 */
	public function test_connection();
}