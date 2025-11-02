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
class Injector
{

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
	public static function instance()
	{
		if (null === self::$instance) {
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
	public function start_buffering()
	{
		// Skip buffering in certain conditions
		if ($this->should_skip_buffering()) {
			return;
		}

		ob_start([$this, 'buffer_callback']);
	}

	/**
	 * Check if buffering should be skipped.
	 *
	 * @return bool
	 */
	private function should_skip_buffering()
	{
		// Skip on feeds
		if (is_feed()) {
			return true;
		}

		// Skip on REST API
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return true;
		}

		// Skip on WooCommerce dynamic pages (cart, checkout, account, etc)
		if ($this->is_woocommerce_dynamic_page()) {
			return true;
		}

		// Skip for non-public post types
		if (is_singular()) {
			$post = get_queried_object();
			if ($post) {
				$post_type_obj = get_post_type_object($post->post_type);
				if (! $post_type_obj || ! $post_type_obj->public) {
					return true;
				}
			}
		}

		// Skip non-singular pages (archives, search, etc)
		if (! is_singular()) {
			return true;
		}

		/**
		 * Filter to allow skipping buffering on specific pages.
		 *
		 * @param bool $skip Whether to skip buffering.
		 */
		return apply_filters('smartalt_skip_buffering', false);
	}

	/**
	 * Check if this is a WooCommerce dynamic page.
	 *
	 * @return bool
	 */
	private function is_woocommerce_dynamic_page()
	{
		if (! class_exists('WooCommerce')) {
			return false;
		}

		// Check for cart, checkout, account pages
		if (is_cart() || is_checkout() || is_account_page()) {
			return true;
		}

		// Check for pages with query parameters
		if (! empty($_GET)) {
			// Check for add-to-cart, wc-ajax, etc
			$excluded_params = ['add-to-cart', 'wc-ajax', 'product_cat', 'product_tag', 'min_price', 'max_price'];
			foreach ($excluded_params as $param) {
				if (isset($_GET[$param])) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Output buffer callback - inject alt attributes for all images.
	 *
	 * @param string $buffer HTML buffer.
	 *
	 * @return string Modified HTML buffer.
	 */
	public function buffer_callback($buffer)
	{
		// Skip empty buffers
		if (!$buffer || strlen($buffer) < 100) {
			return $buffer;
		}

		// Get current post/page/archive context
		$post = get_queried_object();

		// Build context for alt generation
		$context = $this->build_context($post);

		if (!$context) {
			return $buffer; // No context available
		}

		try {
			// Extract all images
			$images = AttachmentHandler::extract_inline_images($buffer);
			if (empty($images)) {
				return $buffer;
			}

			// Filter to only images without alt text (missing or empty)
			$images_without_alt = array_filter($images, function ($image) {
				return !$image['has_alt']; // Use the new has_alt flag
			});

			if (empty($images_without_alt)) {
				return $buffer; // All images already have alt
			}

			// Get generation method setting
			$generation_method = get_option('smartalt_generation_method', 'post_title');

			// Generate alt texts based on method
			if ('ai' === $generation_method) {
				$alt_texts = $this->generate_alts_via_ai($images_without_alt, $context);
			} else {
				$alt_texts = $this->generate_alts_from_post($images_without_alt, $context);
			}

			// Inject alt attributes into HTML
			$buffer = $this->inject_alts_into_html($buffer, $images_without_alt, $alt_texts);

			return $buffer;
		} catch (\Exception $e) {
			// Log error but don't break page
			Logger::log(
				null,
				null,
				null,
				$context['post_id'] ?? null,
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
	 * Build context for alt generation from various page types.
	 *
	 * Handles: Posts, Pages, Archives, Categories, Tags, Search, etc.
	 *
	 * @param mixed $queried_object Result from get_queried_object().
	 *
	 * @return array|null Context array or null if unable to build.
	 */
	private function build_context($queried_object)
	{
		// If it's a WP_Post object (post, page, custom post type)
		if (is_a($queried_object, 'WP_Post')) {
			return (array) $queried_object + [
				'post_id'   => $queried_object->ID,
				'post_type' => $queried_object->post_type,
				'source'    => 'post',
			];
		}

		// If it's a WP_Term object (category, tag, custom taxonomy)
		if (is_a($queried_object, 'WP_Term')) {
			return [
				'post_id'       => 0,
				'post_type'     => 'term',
				'post_title'    => $queried_object->name,
				'post_excerpt'  => $queried_object->description,
				'post_content'  => $queried_object->description,
				'source'        => 'term',
			];
		}

		// Fallback: Try to extract from current URL
		$url_context = $this->build_context_from_url();
		if ($url_context) {
			return $url_context;
		}

		return null;
	}

	/**
	 * Build context from URL when no WP_Post or WP_Term available.
	 *
	 * Used for: search pages, 404, custom pages, etc.
	 *
	 * @return array|null Context array or null.
	 */
	private function build_context_from_url()
	{
		// Get current URL path (without query string)
		$url_path = wp_parse_url(home_url(add_query_arg([])), PHP_URL_PATH);
		$current_path = wp_parse_url(home_url($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

		// Remove home path from current path
		$relative_path = str_replace($url_path, '', $current_path);

		// Remove trailing slash and query string
		$relative_path = trim($relative_path, '/');
		$relative_path = explode('?', $relative_path)[0];

		if (!$relative_path) {
			return null; // Likely homepage
		}

		// Get last URL segment (no slashes)
		$segments = explode('/', trim($relative_path, '/'));
		$last_segment = end($segments);

		if (!$last_segment) {
			return null;
		}

		// Convert URL slug to readable text
		$title = ucfirst(str_replace(['-', '_'], ' ', $last_segment));

		// Determine page type
		$page_type = 'page';
		if (is_search()) {
			$page_type = 'search';
			$title = 'Search: ' . get_search_query();
		} elseif (is_404()) {
			$page_type = '404';
			$title = 'Page Not Found';
		}

		return [
			'post_id'       => 0,
			'post_type'     => $page_type,
			'post_title'    => $title,
			'post_excerpt'  => $title,
			'post_content'  => $title,
			'source'        => 'url',
		];
	}

	/**
	 * Generate alt texts from post/page/context data (no API needed).
	 *
	 * Handles both attached and external/orphaned images.
	 *
	 * @param array $images Images without alt text.
	 * @param array $context Context array with post data.
	 *
	 * @return array Map of image_url => alt_text.
	 */
	private function generate_alts_from_post($images, $context)
	{
		$alts = [];
		$title = Sanitize::alt_text($context['post_title'] ?? '', 125);
		$excerpt = Sanitize::alt_text($context['post_excerpt'] ?? '', 125);

		// Use excerpt if available, otherwise title
		$base_alt = $excerpt ?: $title;

		if (!$base_alt) {
			$base_alt = 'Image'; // Absolute fallback
		}

		// Generate alt for each image
		foreach ($images as $image) {
			$image_url = $image['url'];

			// Start with base alt
			$alt = $base_alt;

			// Add image filename for additional context
			$filename = AttachmentHandler::get_filename_from_url($image_url);
			if ($filename) {
				$filename_text = ucfirst(str_replace(['-', '_'], ' ', pathinfo($filename, PATHINFO_FILENAME)));
				$additional_context = ' - ' . $filename_text;

				// Ensure we stay under 125 characters
				if (strlen($alt . $additional_context) <= 125) {
					$alt .= $additional_context;
				}
			}

			$alts[$image_url] = Sanitize::alt_text($alt, 125);
		}

		return $alts;
	}

	/**
	 * Generate alt texts via AI (single batch API call).
	 *
	 * One API call processes all images on the page with context awareness.
	 *
	 * @param array $images Images without alt text.
	 * @param array $context Context array with page data.
	 *
	 * @return array Map of image_url => alt_text, or empty array on failure.
	 */
	private function generate_alts_via_ai($images, $context)
	{
		try {
			$connector = AiConnectorFactory::get_connector();
			if (!$connector) {
				// Fallback to post title
				return $this->generate_alts_from_post($images, $context);
			}

			// Build context for AI
			$ai_context = [
				'post_title'   => $context['post_title'] ?? '',
				'post_excerpt' => $context['post_excerpt'] ?? '',
				'post_content' => wp_strip_all_tags($context['post_content'] ?? ''),
				'images'       => $images,
				'image_count'  => count($images),
			];

			// Single AI call for all images on the page
			$alt_texts = $connector->generate_batch_alts($ai_context);

			// If AI failed, fallback to post title
			if (empty($alt_texts)) {
				return $this->generate_alts_from_post($images, $context);
			}

			return $alt_texts;
		} catch (\Exception $e) {
			// Log error but don't break page
			Logger::log(
				null,
				null,
				null,
				$context['post_id'] ?? null,
				'ai',
				null,
				'error',
				'Batch AI generation failed: ' . $e->getMessage(),
				'error'
			);

			// Fallback to post title
			return $this->generate_alts_from_post($images, $context);
		}
	}

	/**
	 * Inject alt attributes into HTML.
	 *
	 * Handles various img tag formats robustly.
	 *
	 * @param string $buffer    HTML buffer.
	 * @param array  $images    Image data array.
	 * @param array  $alt_texts Map of image_url => alt_text.
	 *
	 * @return string Modified HTML buffer.
	 */
	private function inject_alts_into_html($buffer, $images, $alt_texts)
	{
		foreach ($images as $image) {
			$url = $image['url'];

			// Skip if no alt text generated
			if (empty($alt_texts[$url])) {
				continue;
			}

			$new_alt = $alt_texts[$url];
			$old_tag = $image['match'];
			$escaped_alt = Sanitize::escape_alt($new_alt);

			// Check if alt attribute already exists
			if (preg_match('/\salt=[\'"]?[^\s\'">;]*[\'"]?/', $old_tag)) {
				// Replace existing alt attribute (handles: alt="", alt='', alt=value)
				$new_tag = preg_replace(
					'/\salt=[\'"]?[^\s\'">;]*[\'"]?/',
					' alt="' . $escaped_alt . '"',
					$old_tag
				);
			} else {
				// Add alt attribute before closing > or />
				if (preg_match('/\s*\/>$/', $old_tag)) {
					// Self-closing tag
					$new_tag = preg_replace('/\s*\/>$/', ' alt="' . $escaped_alt . '" />', $old_tag);
				} else {
					// Regular closing tag
					$new_tag = preg_replace('/\s*>$/', ' alt="' . $escaped_alt . '">', $old_tag);
				}
			}

			// Replace in buffer
			$buffer = str_replace($old_tag, $new_tag, $buffer);
		}

		return $buffer;
	}
}
