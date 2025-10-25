<?php
/**
 * Sanitize utilities for alt text and configuration.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Utils;

/**
 * Sanitize utility class.
 */
class Sanitize {

	/**
	 * Sanitize alt text - remove HTML, limit length, trim whitespace.
	 *
	 * @param string $alt Raw alt text.
	 * @param int    $max_length Maximum length (default 125 for SEO best practice).
	 *
	 * @return string Sanitized alt text.
	 */
	public static function alt_text( $alt, $max_length = 125 ) {
		// Remove HTML tags
		$alt = wp_strip_all_tags( $alt );

		// Decode HTML entities
		$alt = html_entity_decode( $alt, ENT_QUOTES, 'UTF-8' );

		// Replace multiple spaces with single space
		$alt = preg_replace( '/\s+/', ' ', $alt );

		// Trim
		$alt = trim( $alt );

		// Truncate to max length
		if ( mb_strlen( $alt ) > $max_length ) {
			$alt = mb_substr( $alt, 0, $max_length );
			// Remove partial word at end
			$alt = preg_replace( '/\s+\S*$/', '', $alt );
		}

		return $alt;
	}

	/**
	 * Sanitize and validate an image URL.
	 *
	 * @param string $url Image URL.
	 *
	 * @return string|null Sanitized URL or null if invalid.
	 */
	public static function image_url( $url ) {
		$url = esc_url_raw( $url );
		if ( ! $url || ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * Sanitize API endpoint URL.
	 *
	 * @param string $url API endpoint URL.
	 *
	 * @return string|null Sanitized URL or null if invalid.
	 */
	public static function endpoint_url( $url ) {
		$url = esc_url_raw( $url );
		if ( ! $url || ! preg_match( '|^https?://|', $url ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * Sanitize and validate HTTP headers JSON.
	 *
	 * @param string $json JSON string of headers.
	 *
	 * @return array|null Array of headers or null if invalid.
	 */
	public static function headers_json( $json ) {
		$json = sanitize_textarea_field( $json );
		if ( empty( $json ) ) {
			return [];
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		// Ensure all values are strings
		foreach ( $decoded as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				return null;
			}
		}

		return $decoded;
	}

	/**
	 * Sanitize and validate request body template JSON.
	 *
	 * @param string $json JSON template string.
	 *
	 * @return string|null Sanitized JSON or null if invalid.
	 */
	public static function request_template( $json ) {
		$json = sanitize_textarea_field( $json );
		if ( empty( $json ) ) {
			return null;
		}

		// Validate it's valid JSON
		json_decode( $json );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		return $json;
	}

	/**
	 * Sanitize JSON path (dot notation or JSONPath).
	 *
	 * @param string $path JSON path string.
	 *
	 * @return string|null Sanitized path or null if invalid.
	 */
	public static function json_path( $path ) {
		$path = sanitize_text_field( $path );
		// Basic validation: allow alphanumeric, dots, brackets, numbers
		if ( ! preg_match( '/^[a-zA-Z0-9_.\[\]]+$/', $path ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * Sanitize and validate batch size.
	 *
	 * @param int $size Batch size.
	 *
	 * @return int Validated batch size (between 1 and 500).
	 */
	public static function batch_size( $size ) {
		$size = (int) $size;
		return max( 1, min( 500, $size ) );
	}

	/**
	 * Sanitize and validate max alt length.
	 *
	 * @param int $length Max length for alt text.
	 *
	 * @return int Validated length (between 50 and 500).
	 */
	public static function max_alt_length( $length ) {
		$length = (int) $length;
		return max( 50, min( 500, $length ) );
	}

	/**
	 * Sanitize and validate retention days.
	 *
	 * @param int $days Retention days.
	 *
	 * @return int Validated days (between 7 and 365).
	 */
	public static function retention_days( $days ) {
		$days = (int) $days;
		return max( 7, min( 365, $days ) );
	}

	/**
	 * Escape alt text for HTML attribute output.
	 *
	 * @param string $alt Alt text.
	 *
	 * @return string Escaped alt text.
	 */
	public static function escape_alt( $alt ) {
		return esc_attr( $alt );
	}
}