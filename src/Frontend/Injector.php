<?php
/**
 * Injector - Server-side HTML output buffering for alt injection.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Frontend;

use SmartAlt\Core\AttachmentHandler;
use SmartAlt\Utils\Sanitize;

/**
 * Frontend HTML injector singleton.
 */
class Injector {

	/**
	 * Singleton instance.
	 *
	 * @var Injector
	 */
	private static $instance = null;

	/**
	 * Cache group for injected HTML.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'smartalt_frontend';

	/**
	 * Cache key prefix.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'injected_html_';

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

		// Skip on admin/login pages
		if ( is_admin() || is_user_logged_in() && current_user_can( 'manage_options' ) ) {
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

				// Skip if no images attached
				$images = AttachmentHandler::get_attached_images( $post->ID );
				if ( empty( $images ) ) {
					// Also check inline images
					$inline = AttachmentHandler::extract_inline_images( $post->post_content );
					if ( empty( $inline ) ) {
						return true;
					}
				}
			}
		}

		/**
		 * Filter to allow skipping buffering on specific pages.
		 *
		 * @param bool $skip Whether to skip buffering.
		 */
		return apply_filters( 'smartalt_skip_buffering', false );
	}

	/**
	 * Output buffer callback - inject alt attributes.
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

		// Check cache first
		$cache_key = self::CACHE_PREFIX . $post->ID;
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( $cached && hash( 'md5', $post->post_content ) === $cached['content_hash'] ) {
			// Cache hit - return cached version
			return $cached['html'];
		}

		try {
			// Inject alt attributes
			$modified_buffer = $this->inject_alt_attributes( $buffer, $post );

			// Cache the result (1 week)
			wp_cache_set(
				$cache_key,
				[
					'html'          => $modified_buffer,
					'content_hash'  => hash( 'md5', $post->post_content ),
					'cached_at'     => current_time( 'timestamp' ),
				],
				self::CACHE_GROUP,
				WEEK_IN_SECONDS
			);

			return $modified_buffer;
		} catch ( \Exception $e ) {
			// On error, return original buffer
			return $buffer;
		}
	}

	/**
	 * Inject alt attributes into HTML.
	 *
	 * @param string   $html HTML buffer.
	 * @param \WP_Post $post Post object.
	 *
	 * @return string Modified HTML.
	 */
	private function inject_alt_attributes( $html, $post ) {
		// Extract img tags with regex (fast)
		$pattern = '/<img\s+(?:[^>]*?\s+)?(?!alt=)([^>]*?)>/i';

		$html = preg_replace_callback(
			$pattern,
			function( $matches ) use ( $post ) {
				$tag = $matches[0];
				$attrs = $matches[1];

				// Extract src
				if ( ! preg_match( '/src=([\'"])([^\'"]+)\1/i', $tag, $src_match ) ) {
					return $tag;
				}

				$image_url = $src_match[2];

				// Try to find attachment
				$attachment_id = AttachmentHandler::find_attachment_for_inline_image( $image_url, $post->ID );

				if ( ! $attachment_id ) {
					// Not attached, skip
					return $tag;
				}

				// Get alt text
				$alt = AttachmentHandler::get_alt( $attachment_id );
				if ( ! $alt ) {
					// No alt, generate from post title if needed
					$alt = Sanitize::alt_text( $post->post_title );
				}

				// Inject alt attribute
				if ( $alt ) {
					$escaped_alt = Sanitize::escape_alt( $alt );
					return preg_replace( '/\s*\/>$/', ' alt="' . $escaped_alt . '" />', $tag );
				}

				return $tag;
			},
			$html,
			-1,
			$count
		);

		return $html;
	}

	/**
	 * Clear all cached injected HTML.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		wp_cache_flush_group( self::CACHE_GROUP );
	}

	/**
	 * Clear cache for a specific post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public static function clear_post_cache( $post_id ) {
		wp_cache_delete( self::CACHE_PREFIX . $post_id, self::CACHE_GROUP );
	}
}