<?php
/**
 * WooIntegrator - WooCommerce product image handling.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Woo;

use SmartAlt\Core\AttachmentHandler;
use SmartAlt\Core\PostProcessor;

/**
 * WooCommerce integrator singleton.
 */
class WooIntegrator {

	/**
	 * Singleton instance.
	 *
	 * @var WooIntegrator
	 */
	private static $instance = null;

	/**
	 * Deduplication transient key.
	 *
	 * @var string
	 */
	const DEDUP_PREFIX = 'smartalt_woo_dedup_';

	/**
	 * Get singleton instance.
	 *
	 * @return WooIntegrator
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
	 * Process product on save_post_product hook.
	 *
	 * @param int      $post_id Product ID.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return void
	 */
	public function process_product( $post_id, $post ) {
		$this->process_product_images( $post_id );
	}

	/**
	 * Process product on woocommerce_update_product hook.
	 *
	 * @param \WC_Product $product Product object.
	 *
	 * @return void
	 */
	public function process_product_object( $product ) {
		$this->process_product_images( $product->get_id() );
	}

	/**
	 * Process product on REST API hook.
	 *
	 * @param \WC_Product $product Product object.
	 * @param \WP_REST_Request $request REST request.
	 * @param bool $creating Whether this is a creation vs update.
	 *
	 * @return void
	 */
	public function process_product_rest( $product, $request, $creating ) {
		$this->process_product_images( $product->get_id() );
	}

	/**
	 * Process all images for a product.
	 *
	 * Uses deduplication to avoid triple-processing.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return void
	 */
	private function process_product_images( $product_id ) {
		// Skip if plugin not enabled
		if ( ! get_option( 'smartalt_enabled' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		// Deduplication: create hash of current gallery IDs
		$gallery_ids = array_merge(
			$product->get_gallery_image_ids(),
			[ $product->get_image_id() ]
		);
		$dedup_hash = md5( wp_json_encode( $gallery_ids ) );
		$dedup_key = self::DEDUP_PREFIX . $product_id;

		// Check if we already processed this exact combo recently
		$last_hash = get_transient( $dedup_key );
		if ( $last_hash === $dedup_hash ) {
			return; // Already processed, skip
		}

		// Set deduplication transient (1 second TTL)
		set_transient( $dedup_key, $dedup_hash, 1 );

		// Process featured image
		$featured_id = $product->get_image_id();
		if ( $featured_id ) {
			$this->process_single_image( $featured_id, $product );
		}

		// Process gallery images
		foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
			$this->process_single_image( $gallery_id, $product );
		}

		// Process inline images in description
		$description = $product->get_description();
		if ( $description ) {
			$inline_images = AttachmentHandler::extract_inline_images( $description );
			foreach ( $inline_images as $image ) {
				$attachment_id = AttachmentHandler::find_attachment_for_inline_image( $image['url'], $product_id );
				if ( $attachment_id ) {
					$this->process_single_image( $attachment_id, $product );
				}
			}
		}
	}

	/**
	 * Process a single image for the product.
	 *
	 * @param int            $attachment_id Attachment ID.
	 * @param \WC_Product $product Product object.
	 *
	 * @return void
	 */
	private function process_single_image( $attachment_id, $product ) {
		// Check if already has alt
		if ( AttachmentHandler::has_alt( $attachment_id ) ) {
			return;
		}

		// Use product name as alt
		$alt = $product->get_name();

		if ( $alt ) {
			AttachmentHandler::set_alt( $attachment_id, $alt );
		}
	}
}