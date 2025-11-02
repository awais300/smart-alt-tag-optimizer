<?php

/**
 * AttachmentHandler - Utilities for getting/setting attachment alt text and metadata.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Core;

use SmartAlt\Utils\Sanitize;

/**
 * Attachment handler for managing attachment meta and alt text.
 */
class AttachmentHandler
{

	/**
	 * Cache group for attachment data.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'smartalt_attachments';

	/**
	 * Cache key prefix for attachment URLs.
	 *
	 * @var string
	 */
	const URL_CACHE_PREFIX = 'attachment_url_id_';

	/**
	 * Get attachment alt text.
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return string Alt text, or empty string if not set.
	 */
	public static function get_alt($attachment_id)
	{
		$alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
		return (string) $alt;
	}

	/**
	 * Set attachment alt text.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $alt           Alt text to set.
	 * @param bool   $force         If true, update even if alt already set.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function set_alt($attachment_id, $alt, $force = false)
	{
		if (! $attachment_id) {
			return false;
		}

		$current_alt = self::get_alt($attachment_id);

		// Only update if alt is empty or force is true
		if (! $force && ! empty($current_alt)) {
			return false;
		}

		// Sanitize alt text
		$alt = Sanitize::alt_text($alt, (int) get_option('smartalt_max_alt_length', 125));

		if (empty($alt)) {
			return false;
		}

		// Update meta
		update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);

		// Clear cache
		wp_cache_delete($attachment_id, self::CACHE_GROUP);

		return true;
	}

	/**
	 * Get AI cache status for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return array|null Cached AI data or null if cache miss/expired.
	 */
	public static function get_ai_cache($attachment_id)
	{
		$cached_at = get_post_meta($attachment_id, '_smartalt_ai_cached_at', true);
		$model = get_post_meta($attachment_id, '_smartalt_ai_model', true);

		if (! $cached_at || ! $model) {
			return null;
		}

		// Check cache TTL (default 90 days)
		$ttl_days = (int) get_option('smartalt_ai_cache_ttl_days', 90);
		$cache_time = strtotime($cached_at);
		$expiry_time = $cache_time + ($ttl_days * DAY_IN_SECONDS);

		if (current_time('timestamp') > $expiry_time) {
			// Cache expired, delete it
			delete_post_meta($attachment_id, '_smartalt_ai_cached_at');
			delete_post_meta($attachment_id, '_smartalt_ai_model');
			return null;
		}

		return [
			'cached_at' => $cached_at,
			'model'     => $model,
		];
	}

	/**
	 * Set AI cache metadata for an attachment.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $model         AI model used to generate alt.
	 *
	 * @return bool True on success.
	 */
	public static function set_ai_cache($attachment_id, $model)
	{
		update_post_meta($attachment_id, '_smartalt_ai_cached_at', current_time('mysql'));
		update_post_meta($attachment_id, '_smartalt_ai_model', sanitize_text_field($model));
		return true;
	}

	/**
	 * Clear all AI caches for all attachments.
	 *
	 * @return void
	 */
	public static function clear_all_ai_caches()
	{
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->postmeta}
			WHERE meta_key IN ('_smartalt_ai_cached_at', '_smartalt_ai_model')"
		);
		wp_cache_flush_group(self::CACHE_GROUP);
	}

	/**
	 * Get attachment ID from URL with caching.
	 *
	 * Caches result with wp_cache to avoid repeated attachment_url_to_postid() calls.
	 *
	 * @param string $url Attachment URL.
	 *
	 * @return int|null Attachment ID or null if not found.
	 */
	public static function get_id_from_url($url)
	{
		if (! $url) {
			return null;
		}

		// Create cache key
		$cache_key = self::URL_CACHE_PREFIX . md5($url);

		// Check cache first
		$cached = wp_cache_get($cache_key, self::CACHE_GROUP);
		if (false !== $cached) {
			return 'not_found' === $cached ? null : (int) $cached;
		}

		// Sanitize URL
		$url = esc_url_raw($url);
		if (! $url) {
			return null;
		}

		// Call WordPress core function
		$attachment_id = attachment_url_to_postid($url);

		// Cache result (24 hour TTL for found, 12 hours for not found to be more cautious)
		if ($attachment_id) {
			wp_cache_set($cache_key, $attachment_id, self::CACHE_GROUP, 24 * HOUR_IN_SECONDS);
		} else {
			wp_cache_set($cache_key, 'not_found', self::CACHE_GROUP, 12 * HOUR_IN_SECONDS);
		}

		return $attachment_id ? (int) $attachment_id : null;
	}

	/**
	 * Get all images attached to a post.
	 *
	 * Returns both featured image and gallery images.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array Array of attachment IDs.
	 */
	public static function get_attached_images($post_id)
	{
		$images = [];

		// Featured image
		$featured = get_post_thumbnail_id($post_id);
		if ($featured) {
			$images[] = (int) $featured;
		}

		// Attached images
		$attached = get_attached_media('image', $post_id);
		foreach ($attached as $attachment) {
			if (! in_array($attachment->ID, $images, true)) {
				$images[] = (int) $attachment->ID;
			}
		}

		return array_unique($images);
	}

	/**
	 * Extract image URLs from post content HTML.
	 *
	 * Robustly handles various img tag formats and detects missing/empty alt attributes.
	 *
	 * @param string $content Post content HTML.
	 *
	 * @return array Array of image data: [ 'url' => ..., 'alt' => ..., 'match' => ... ].
	 */
	public static function extract_inline_images($content)
	{
		if (!$content) {
			return [];
		}

		$images = [];

		// Use DOMDocument for robust HTML parsing
		$dom = new \DOMDocument();

		// Suppress warnings for malformed HTML
		libxml_use_internal_errors(true);

		// Wrap content in HTML tags for proper parsing
		$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

		libxml_clear_errors();

		// Get all img tags
		$img_tags = $dom->getElementsByTagName('img');

		if (!$img_tags || $img_tags->length === 0) {
			return [];
		}

		foreach ($img_tags as $img) {
			// Get src attribute
			$src = $img->getAttribute('src');

			if (!$src) {
				continue; // Skip img tags without src
			}

			// Get alt attribute
			$alt = $img->getAttribute('alt');

			// Check if alt is missing OR empty
			$has_alt = $img->hasAttribute('alt') && !empty($alt);

			// Reconstruct the original img tag from DOM
			$full_tag = $dom->saveHTML($img);

			// Clean up the reconstructed tag (remove XML declaration if present)
			$full_tag = preg_replace('/<\?xml[^>]*\?>/', '', $full_tag);

			$images[] = [
				'url'      => esc_url_raw($src),
				'alt'      => $alt,
				'match'    => trim($full_tag),
				'has_alt'  => $has_alt,
			];
		}

		return $images;
	}

	/**
	 * Find attachment ID for an inline image URL.
	 *
	 * Tries to match URL to attached media, handling image sizes.
	 *
	 * @param string $image_url Image URL.
	 * @param int    $post_id   Post ID for context.
	 *
	 * @return int|null Attachment ID or null if not found.
	 */
	public static function find_attachment_for_inline_image($image_url, $post_id)
	{
		if (! $image_url) {
			return null;
		}

		// Try direct URL to attachment ID lookup
		$attachment_id = self::get_id_from_url($image_url);
		if ($attachment_id) {
			return $attachment_id;
		}

		// Try to match by filename (handles resized versions)
		$filename = wp_basename($image_url);
		if (! $filename) {
			return null;
		}

		global $wpdb;

		// Search for attachment with matching filename
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'attachment'
				AND post_parent = %d
				AND guid LIKE %s
				LIMIT 1",
				$post_id,
				'%' . $wpdb->esc_like($filename) . '%'
			)
		);

		return $attachment_id ? (int) $attachment_id : null;
	}

	/**
	 * Get image filename from URL.
	 *
	 * @param string $url Image URL.
	 *
	 * @return string Filename only.
	 */
	public static function get_filename_from_url($url)
	{
		return wp_basename($url);
	}

	/**
	 * Check if attachment already has alt text.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	public static function has_alt($attachment_id)
	{
		return ! empty(self::get_alt($attachment_id));
	}

	/**
	 * Get attachment data for context (for AI generation).
	 *
	 * Returns useful metadata for AI alt generation.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $post_id       Post ID (for context).
	 *
	 * @return array Attachment context data.
	 */
	public static function get_context($attachment_id, $post_id = null)
	{
		$post = get_post($attachment_id);
		$context = [
			'attachment_id' => $attachment_id,
			'filename'      => $post ? $post->post_name : '',
			'alt_text'      => $post ? $post->post_excerpt : '',
			'description'   => $post ? $post->post_content : '',
			'title'         => $post ? $post->post_title : '',
			'url'           => wp_get_attachment_url($attachment_id),
		];

		// Add post context if provided
		if ($post_id) {
			$post_obj = get_post($post_id);
			if ($post_obj) {
				$context['post_title']    = $post_obj->post_title;
				$context['post_excerpt']  = $post_obj->post_excerpt;
				$context['post_content']  = wp_strip_all_tags($post_obj->post_content);
			}
		}

		return $context;
	}
}
