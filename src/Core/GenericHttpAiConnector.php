<?php
/**
 * GenericHttpAiConnector - Generic HTTP-based AI connector.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Core;

use SmartAlt\Utils\Sanitize;
use SmartAlt\Logger;

/**
 * Generic HTTP AI connector with configurable endpoint and mapping.
 */
class GenericHttpAiConnector implements AiConnectorInterface {

	/**
	 * Circuit breaker cache key prefix.
	 *
	 * @var string
	 */
	const CIRCUIT_BREAKER_PREFIX = 'smartalt_circuit_breaker_';

	/**
	 * Max consecutive failures before circuit breaker trips.
	 *
	 * @var int
	 */
	const MAX_FAILURES = 3;

	/**
	 * Circuit breaker duration in seconds (30 minutes).
	 *
	 * @var int
	 */
	const CIRCUIT_BREAKER_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	const HTTP_TIMEOUT = 15;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Generate alt text via HTTP API.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $context       Context data.
	 *
	 * @return string|null Generated alt text or null on failure.
	 *
	 * @throws \Exception
	 */
	public function generate_alt( $attachment_id, $context ) {
		// Check circuit breaker
		if ( $this->is_circuit_broken() ) {
			throw new \Exception( 'AI connector circuit breaker is active. Please try again later.' );
		}

		// Validate configuration
		$endpoint = get_option( 'smartalt_ai_endpoint' );
		if ( ! $endpoint ) {
			throw new \Exception( 'AI endpoint not configured.' );
		}

		// Build request
		$request = $this->build_request( $context );

		// Make HTTP call (with retry logic)
		$response = $this->make_request_with_retry( $endpoint, $request );

		if ( is_wp_error( $response ) ) {
			$this->record_failure();
			throw new \Exception( 'AI API request failed: ' . $response->get_error_message() );
		}

		// Extract alt from response
		$alt = $this->extract_alt_from_response( $response );

		if ( ! $alt ) {
			$this->record_failure();
			throw new \Exception( 'No alt text in API response.' );
		}

		// Success - reset failure counter
		$this->reset_failure_counter();

		// Sanitize and return
		return Sanitize::alt_text( $alt, (int) get_option( 'smartalt_max_alt_length', 125 ) );
	}

	/**
	 * Test connection to AI endpoint.
	 *
	 * @return bool
	 */
	public function test_connection() {
		try {
			$endpoint = get_option( 'smartalt_ai_endpoint' );
			if ( ! $endpoint ) {
				return false;
			}

			$test_context = [
				'image_url'   => 'https://example.com/image.jpg',
				'post_title'  => 'Test',
				'image_filename' => 'test.jpg',
			];

			$request = $this->build_request( $test_context );
			$response = wp_remote_post( $endpoint, [
				'timeout' => self::HTTP_TIMEOUT,
				'body'    => $request,
				'headers' => $this->get_headers(),
			] );

			return ! is_wp_error( $response );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Get model name.
	 *
	 * @return string
	 */
	public function get_model_name() {
		return get_option( 'smartalt_ai_model_name', 'generic_http' );
	}

	/**
	 * Build request body from context.
	 *
	 * @param array $context Context data.
	 *
	 * @return string JSON request body.
	 */
	private function build_request( $context ) {
		$template = get_option( 'smartalt_ai_request_template' );

		if ( ! $template ) {
			// Default template
			$template = json_encode( [
				'prompt'  => 'Generate a brief alt text for an image.',
				'image_url' => '{image_url}',
			] );
		}

		// Replace placeholders
		$placeholders = [
			'{image_url}'      => $context['url'] ?? '',
			'{post_title}'     => $context['post_title'] ?? '',
			'{post_excerpt}'   => $context['post_excerpt'] ?? '',
			'{post_content}'   => substr( $context['post_content'] ?? '', 0, 500 ),
			'{image_filename}' => $context['filename'] ?? '',
			'{max_length}'     => (int) get_option( 'smartalt_max_alt_length', 125 ),
		];

		$body = $template;
		foreach ( $placeholders as $placeholder => $value ) {
			$body = str_replace( $placeholder, $value, $body );
		}

		return $body;
	}

	/**
	 * Get HTTP headers for request.
	 *
	 * @return array
	 */
	private function get_headers() {
		$headers = [
			'Content-Type' => 'application/json',
		];

		// Get custom headers from settings
		$custom_headers_json = get_option( 'smartalt_ai_headers' );
		if ( $custom_headers_json ) {
			$custom_headers = json_decode( $custom_headers_json, true );
			if ( is_array( $custom_headers ) ) {
				$headers = array_merge( $headers, $custom_headers );
			}
		}

		// Add API key if configured
		$api_key = $this->get_api_key();
		if ( $api_key ) {
			// Support both "Authorization: Bearer {key}" and custom header
			$auth_header = get_option( 'smartalt_ai_auth_header', 'Authorization' );
			$headers[ $auth_header ] = 'Bearer ' . $api_key;
		}

		return $headers;
	}

	/**
	 * Get API key from environment or database.
	 *
	 * @return string|null
	 */
	private function get_api_key() {
		// Environment variable first (most secure)
		if ( defined( 'SMARTALT_AI_KEY' ) && SMARTALT_AI_KEY ) {
			return SMARTALT_AI_KEY;
		}

		// Fall back to database
		$key = get_option( 'smartalt_ai_key' );
		if ( $key ) {
			// Decrypt if encrypted
			return $this->decrypt_api_key( $key );
		}

		return null;
	}

	/**
	 * Encrypt API key (simple base64 + wp_salt for lite encryption).
	 *
	 * @param string $key API key.
	 *
	 * @return string Encrypted key.
	 */
	public static function encrypt_api_key( $key ) {
		if ( ! $key ) {
			return '';
		}
		$salt = wp_salt( 'auth' );
		return base64_encode( $key . '|' . $salt );
	}

	/**
	 * Decrypt API key.
	 *
	 * @param string $encrypted Encrypted key.
	 *
	 * @return string Decrypted key or empty string on failure.
	 */
	private function decrypt_api_key( $encrypted ) {
		if ( ! $encrypted ) {
			return '';
		}

		try {
			$decoded = base64_decode( $encrypted );
			$salt = wp_salt( 'auth' );
			$parts = explode( '|', $decoded );

			// Verify salt matches
			if ( count( $parts ) === 2 && $parts[1] === $salt ) {
				return $parts[0];
			}

			// Fallback: assume it's the key itself (backwards compat)
			return $decoded;
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Make HTTP request with retry logic.
	 *
	 * @param string $endpoint API endpoint URL.
	 * @param string $body     Request body.
	 *
	 * @return array|string|\WP_Error Response body or WP_Error.
	 */
	private function make_request_with_retry( $endpoint, $body ) {
		$method = get_option( 'smartalt_ai_method', 'POST' );
		$method = in_array( strtoupper( $method ), [ 'GET', 'POST' ], true ) ? strtoupper( $method ) : 'POST';

		$args = [
			'method'  => $method,
			'timeout' => self::HTTP_TIMEOUT,
			'headers' => $this->get_headers(),
		];

		if ( 'POST' === $method ) {
			$args['body'] = $body;
		}

		// First attempt
		$response = wp_remote_post( $endpoint, $args );

		// Retry once on transient errors
		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();
			// Retry on timeout or connection errors
			if ( in_array( $error_code, [ 'http_request_failed', 'connect_timeout', 'operation_timed_out' ], true ) ) {
				sleep( 1 );
				$response = wp_remote_post( $endpoint, $args );
			}
		}

		return $response;
	}

	/**
	 * Extract alt text from API response.
	 *
	 * @param array $response HTTP response.
	 *
	 * @return string|null Alt text or null on failure.
	 */
	private function extract_alt_from_response( $response ) {
		$body = wp_remote_retrieve_body( $response );
		$status = wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			Logger::log( null, null, null, null, 'ai', null, 'error', "API returned status {$status}: {$body}", 'error' );
			return null;
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			Logger::log( null, null, null, null, 'ai', null, 'error', 'Invalid JSON response', 'error' );
			return null;
		}

		// Get JSON path from settings
		$json_path = get_option( 'smartalt_ai_response_path', 'text' );

		// Extract value using JSON path (dot notation)
		$alt = $this->get_json_value( $json, $json_path );

		return $alt ? (string) $alt : null;
	}

	/**
	 * Get value from nested array using dot notation.
	 *
	 * @param array  $array Array to search.
	 * @param string $path  Dot notation path (e.g., "data.result.text").
	 *
	 * @return mixed|null
	 */
	private function get_json_value( $array, $path ) {
		$keys = explode( '.', $path );

		foreach ( $keys as $key ) {
			if ( ! is_array( $array ) || ! isset( $array[ $key ] ) ) {
				return null;
			}
			$array = $array[ $key ];
		}

		return $array;
	}

	/**
	 * Check if circuit breaker is active.
	 *
	 * @return bool
	 */
	private function is_circuit_broken() {
		$failures = (int) get_transient( self::CIRCUIT_BREAKER_PREFIX . 'failures' );
		return $failures >= self::MAX_FAILURES;
	}

	/**
	 * Record a failure for circuit breaker.
	 *
	 * @return void
	 */
	private function record_failure() {
		$failures = (int) get_transient( self::CIRCUIT_BREAKER_PREFIX . 'failures' );
		$failures++;

		set_transient( self::CIRCUIT_BREAKER_PREFIX . 'failures', $failures, self::CIRCUIT_BREAKER_TTL );

		if ( $failures >= self::MAX_FAILURES ) {
			Logger::log( null, null, null, null, 'ai', null, 'error', 'Circuit breaker activated after ' . self::MAX_FAILURES . ' failures', 'error' );
		}
	}

	/**
	 * Reset failure counter.
	 *
	 * @return void
	 */
	private function reset_failure_counter() {
		delete_transient( self::CIRCUIT_BREAKER_PREFIX . 'failures' );
	}
}