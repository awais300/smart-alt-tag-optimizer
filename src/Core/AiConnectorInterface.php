<?php
/**
 * AiConnectorInterface - Interface for pluggable AI connectors.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Core;

/**
 * Interface for AI connectors.
 */
interface AiConnectorInterface {

	/**
	 * Generate alt text for an image.
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