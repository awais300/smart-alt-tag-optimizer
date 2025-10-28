<?php
/**
 * Injector - Server-side HTML output buffering for alt injection.
 *
 * Processes full-page HTML output and injects missing alt text based on:
 * - Post title (fast, no API)
 * - AI-generated (single batch API call for all images)
 *
 * @package SmartAlt
 */

namespace SmartAlt\Frontend;

use SmartAlt\Core\AttachmentHandler;
use SmartAlt\Core\AiConnectorFactory;
use SmartAlt\Utils\Sanitize;
use SmartAlt\Logger;

/**
 * Frontend HTML injector singleton - processes all page images in one pass.
 */
class Injector {

	/**
	 * Singleton instance.
	 *
	 * @var Injector
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Injector
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
	 * Start output buffering on template_redirect.
	 *
	 * @return void
	 */
	public function start_buffering() {
		// Skip buffering in certain conditions
		if ( $this->should_skip_buffering() ) {
			return;
		}

		ob_start( [ $this, 'buffer_callback' ] );
	}

	/**
	 * Check if buffering should be skipped.
	 *
	 * @return bool
	 */
	private function should_skip_buffering() {
		// Skip on feeds
		if ( is_feed() ) {
			return true;
		}

		// Skip on REST API
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		// Skip on WooCommerce dynamic pages (cart, checkout, account, etc)
		if ( $this->is_woocommerce_dynamic_page() ) {
			return true;
		}

		// Skip for non-public post types
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post ) {
				$post_type_obj = get_post_type_object( $post->post_type );
				if ( ! $post_type_obj || ! $post_type_obj->public ) {
					return true;
				}
			}
		}

		// Skip non-singular pages (archives, search, etc)
		if ( ! is_singular() ) {
			return true;
		}

		/**
		 * Filter to allow skipping buffering on specific pages.
		 *
		 * @param bool $skip Whether to skip buffering.
		 */
		return apply_filters( 'smartalt_skip_buffering', false );
	}

	/**
	 * Check if this is a WooCommerce dynamic page.
	 *
	 * @return bool
	 */
	private function is_woocommerce_dynamic_page() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// Check for cart, checkout, account pages
		if ( is_cart() || is_checkout() || is_account_page() ) {
			return true;
		}

		// Check for pages with query parameters
		if ( ! empty( $_GET ) ) {
			// Check for add-to-cart, wc-ajax, etc
			$excluded_params = [ 'add-to-cart', 'wc-ajax', 'product_cat', 'product_tag', 'min_price', 'max_price' ];
			foreach ( $excluded_params as $param ) {
				if ( isset( $_GET[ $param ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Output buffer callback - inject alt attributes for all images.
	 *
	 * Single pass processing:
	 * 1. Extract all <img> tags
	 * 2. Generate alt texts (post_title or AI batch)
	 * 3. Inject alts back into HTML
	 *
	 * @param string $buffer HTML buffer.
	 *
	 * @return string Modified HTML buffer.
	 */
	public function buffer_callback( $buffer ) {
		// Skip empty buffers
		if ( ! $buffer || strlen( $buffer ) < 100 ) {
			return $buffer;
		}

		// Get current post
		$post = get_queried_object();
		if ( ! $post || ! is_a( $post, 'WP_Post' ) ) {
			return $buffer;
		}

		try {
			// Extract all images that are missing alt text
			$images = AttachmentHandler::extract_inline_images( $buffer );
			if ( empty( $images ) ) {
				return $buffer;
			}

			// Filter to only images without alt text
			$images_without_alt = array_filter( $images, function( $image ) {
				return empty( $image['alt'] );
			} );

			if ( empty( $images_without_alt ) ) {
				return $buffer; // All images already have alt
			}

			// Get alt source setting
			$alt_source = get_option( 'smartalt_alt_source', 'post_title' );

			// Generate alt texts based on source
			if ( 'ai' === $alt_source ) {
				$alt_texts = $this->generate_alts_via_ai( $images_without_alt, $post );
			} else {
				$alt_texts = $this->generate_alts_from_post( $images_without_alt, $post );
			}

			// Inject alt attributes into HTML
			$buffer = $this->inject_alts_into_html( $buffer, $images_without_alt, $alt_texts );

			return $buffer;
		} catch ( \Exception $e ) {
			// Log error but don't break page
			Logger::log(
				null,
				null,
				null,
				$post->ID ?? null,
				'frontend',
				null,
				'error',
				'Buffer processing failed: ' . $e->getMessage(),
				'error'
			);

			// Return original buffer on error
			return $buffer;
		}
	}

	/**
	 * Generate alt texts from post data (no API needed).
	 *
	 * Uses post title and excerpt for alt text generation.
	 * Multiple images get varied alt text based on position and filename.
	 *
	 * @param array    $images Images without alt text.
	 * @param \WP_Post $post   Post object.
	 *
	 * @return array Map of image_url => alt_text.
	 */
	private function generate_alts_from_post( $images, $post ) {
		$alts = [];
		$title = Sanitize::alt_text( $post->post_title, 125 );
		$excerpt = Sanitize::alt_text( $post->post_excerpt, 125 );

		// Use excerpt if available, otherwise title
		$base_alt = $excerpt ?: $title;

		$image_count = count( $images );

		foreach ( $images as $index => $image ) {
			$image_url = $image['url'];

			// For single image, just use base alt
			if ( 1 === $image_count ) {
				$alts[ $image_url ] = $base_alt;
				continue;
			}

			// For multiple images, vary the alt text
			$alt = $base_alt;

			// Add image filename for context (if different from base)
			$filename = AttachmentHandler::get_filename_from_url( $image_url );
			if ( $filename ) {
				$filename_text = ucfirst( str_replace( [ '-', '_' ], ' ', pathinfo( $filename, PATHINFO_FILENAME ) ) );
				$additional_context = ' - ' . $filename_text;

				// Ensure we stay under 125 characters
				if ( strlen( $alt . $additional_context ) <= 125 ) {
					$alt .= $additional_context;
				}
			}

			// If still under limit, add position context
			if ( strlen( $alt ) < 110 && $image_count > 2 ) {
				$position_text = ' (Image ' . ( $index + 1 ) . ')';
				if ( strlen( $alt . $position_text ) <= 125 ) {
					$alt .= $position_text;
				}
			}

			$alts[ $image_url ] = Sanitize::alt_text( $alt, 125 );
		}

		return $alts;
	}

	/**
	 * Generate alt texts via AI (single batch API call).
	 *
	 * One API call processes all images on the page with context awareness.
	 *
	 * @param array    $images Images without alt text.
	 * @param \WP_Post $post   Post object.
	 *
	 * @return array Map of image_url => alt_text, or empty array on failure.
	 */
	private function generate_alts_via_ai( $images, $post ) {
		try {
			$connector = AiConnectorFactory::get_connector();
			if ( ! $connector ) {
				// Fallback to post title
				return $this->generate_alts_from_post( $images, $post );
			}

			// Build context for AI
			$context = [
				'post_title'   => $post->post_title,
				'post_excerpt' => $post->post_excerpt,
				'post_content' => wp_strip_all_tags( $post->post_content ),
				'images'       => $images,
				'image_count'  => count( $images ),
			];

			// Single AI call for all images on the page
			$alt_texts = $connector->generate_batch_alts( $context );

			// If AI failed, fallback to post title
			if ( empty( $alt_texts ) ) {
				return $this->generate_alts_from_post( $images, $post );
			}

			return $alt_texts;
		} catch ( \Exception $e ) {
			// Log error but don't break page
			Logger::log(
				null,
				null,
				null,
				$post->ID ?? null,
				'ai',
				null,
				'error',
				'Batch AI generation failed: ' . $e->getMessage(),
				'error'
			);

			// Fallback to post title
			return $this->generate_alts_from_post( $images, $post );
		}
	}

	/**
	 * Inject alt attributes into HTML.
	 *
	 * Replaces img tags with alt attributes.
	 *
	 * @param string $buffer    HTML buffer.
	 * @param array  $images    Image data array.
	 * @param array  $alt_texts Map of image_url => alt_text.
	 *
	 * @return string Modified HTML buffer.
	 */
	private function inject_alts_into_html( $buffer, $images, $alt_texts ) {
		foreach ( $images as $image ) {
			$url = $image['url'];

			// Skip if no alt text generated
			if ( empty( $alt_texts[ $url ] ) ) {
				continue;
			}

			$new_alt = $alt_texts[ $url ];
			$old_tag = $image['match'];

			// Escape alt for HTML attribute
			$escaped_alt = Sanitize::escape_alt( $new_alt );

			// Inject alt attribute before closing tag
			$new_tag = preg_replace(
				'/\s*\/>$/',
				' alt="' . $escaped_alt . '" />',
				$old_tag
			);

			// Replace in buffer (use strpos for performance on large pages)
			$pos = strpos( $buffer, $old_tag );
			if ( false !== $pos ) {
				$buffer = substr_replace( $buffer, $new_tag, $pos, strlen( $old_tag ) );
			}
		}

		return $buffer;
	}
}