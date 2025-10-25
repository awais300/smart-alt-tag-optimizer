<?php
/**
 * Integration tests for Frontend\Injector.
 *
 * @package SmartAlt\Tests
 */

namespace SmartAlt\Tests\Integration;

use SmartAlt\Frontend\Injector;
use SmartAlt\Core\AttachmentHandler;

/**
 * Integration test cases for frontend injection.
 */
class FrontendInjectorTest extends \WP_UnitTestCase {

	/**
	 * Test HTML buffer callback injects alt attributes.
	 */
	public function test_injector_adds_alt_to_missing_attribute() {
		$post_id = $this->factory->post->create( [ 'post_title' => 'Test Post' ] );

		$attachment_id = $this->factory->attachment->create_object(
			'test.jpg',
			$post_id,
			[
				'post_mime_type' => 'image/jpeg',
			]
		);

		// Set alt on attachment
		AttachmentHandler::set_alt( $attachment_id, 'Test Alt Text' );

		// Get attachment URL
		$image_url = wp_get_attachment_url( $attachment_id );

		// Create HTML with img tag missing alt
		$html = '<img src="' . $image_url . '" />';

		// Create injector and process
		$injector = Injector::instance();

		// Simulate being on the post page
		$this->go_to( get_permalink( $post_id ) );

		// Process HTML through buffer
		$result = $injector->buffer_callback( $html );

		// Check alt was injected
		$this->assertStringContainsString( 'alt=', $result );
		$this->assertStringContainsString( 'Test Alt Text', $result );
	}

	/**
	 * Test injector preserves existing alt attributes.
	 */
	public function test_injector_preserves_existing_alt() {
		$html = '<img src="https://example.com/image.jpg" alt="Existing Alt" />';

		$injector = Injector::instance();
		$result = $injector->buffer_callback( $html );

		// Should preserve original
		$this->assertStringContainsString( 'Existing Alt', $result );
	}

	/**
	 * Test injector skips non-attached images.
	 */
	public function test_injector_skips_unattached_images() {
		$html = '<img src="https://example.com/external-image.jpg" />';

		$injector = Injector::instance();
		$result = $injector->buffer_callback( $html );

		// Should not modify unattached images
		$this->assertEquals( $html, $result );
	}

	/**
	 * Test cache clearing.
	 */
	public function test_clear_cache() {
		$post_id = 123;

		// Set some cache
		wp_cache_set( 'injected_html_' . $post_id, [ 'html' => 'test' ], 'smartalt_frontend' );

		// Clear it
		Injector::clear_post_cache( $post_id );

		// Verify cleared
		$cached = wp_cache_get( 'injected_html_' . $post_id, 'smartalt_frontend' );
		$this->assertFalse( $cached );
	}
}