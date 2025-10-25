<?php
/**
 * Integration tests for PostProcessor.
 *
 * @package SmartAlt\Tests
 */

namespace SmartAlt\Tests\Integration;

use SmartAlt\Core\PostProcessor;
use SmartAlt\Core\AttachmentHandler;
use SmartAlt\Logger;

/**
 * Integration test cases for post processing.
 */
class PostProcessorIntegrationTest extends \WP_UnitTestCase {

	/**
	 * Setup before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Enable plugin
		update_option( 'smartalt_enabled', 1 );
		update_option( 'smartalt_alt_source', 'post_title' );
	}

	/**
	 * Teardown after each test.
	 */
	public function tear_down() {
		parent::tear_down();
	}

	/**
	 * Test post save with attached image generates alt.
	 */
	public function test_post_save_generates_alt_for_attached_image() {
		// Create attachment
		$attachment_id = $this->factory->attachment->create_object(
			'test.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
			]
		);

		// Create post and attach image
		$post_id = $this->factory->post->create( [ 'post_title' => 'Test Post Title' ] );
		wp_update_post( [ 'ID' => $attachment_id, 'post_parent' => $post_id ] );

		// Trigger post save
		do_action( 'save_post', $post_id, get_post( $post_id ) );

		// Check alt was generated
		$alt = AttachmentHandler::get_alt( $attachment_id );
		$this->assertNotEmpty( $alt );
		$this->assertStringContainsString( 'Test Post Title', $alt );
	}

	/**
	 * Test inline image extraction.
	 */
	public function test_extract_inline_images() {
		$content = '<img src="https://example.com/image1.jpg" alt=""> <p>Text</p> <img src="https://example.com/image2.png">';
		$images = AttachmentHandler::extract_inline_images( $content );

		$this->assertCount( 2, $images );
		$this->assertEquals( 'https://example.com/image1.jpg', $images[0]['url'] );
		$this->assertEmpty( $images[0]['alt'] );
	}

	/**
	 * Test attachment upload generates alt.
	 */
	public function test_attachment_upload_generates_alt() {
		$post_id = $this->factory->post->create( [ 'post_title' => 'Parent Post' ] );

		$attachment_id = $this->factory->attachment->create_object(
			'test.jpg',
			$post_id,
			[
				'post_mime_type' => 'image/jpeg',
			]
		);

		// Trigger attachment processing
		do_action( 'wp_insert_attachment', $attachment_id, get_post( $attachment_id ) );

		$alt = AttachmentHandler::get_alt( $attachment_id );
		$this->assertNotEmpty( $alt );
	}

	/**
	 * Test skip processing on autosave.
	 */
	public function test_skip_processing_on_autosave() {
		$post_id = $this->factory->post->create();

		// Define DOING_AUTOSAVE
		define( 'DOING_AUTOSAVE', true );

		$processor = PostProcessor::instance();
		$processor->process_post( $post_id, get_post( $post_id ) );

		// Should not have logged anything
		$stats = Logger::get_stats();
		$this->assertEquals( 0, $stats['total_logged'] );
	}

	/**
	 * Test AI cache metadata.
	 */
	public function test_ai_cache_metadata() {
		$attachment_id = $this->factory->attachment->create_object(
			'test.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
			]
		);

		// Set AI cache
		AttachmentHandler::set_ai_cache( $attachment_id, 'gpt-4-vision' );

		// Retrieve cache
		$cache = AttachmentHandler::get_ai_cache( $attachment_id );
		$this->assertNotNull( $cache );
		$this->assertEquals( 'gpt-4-vision', $cache['model'] );
	}

	/**
	 * Test AI cache expiry.
	 */
	public function test_ai_cache_expiry() {
		$attachment_id = $this->factory->attachment->create_object(
			'test.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
			]
		);

		// Set cache with past timestamp
		update_post_meta( $attachment_id, '_smartalt_ai_cached_at', '2020-01-01 00:00:00' );
		update_post_meta( $attachment_id, '_smartalt_ai_model', 'gpt-4' );

		// Cache should be expired
		$cache = AttachmentHandler::get_ai_cache( $attachment_id );
		$this->assertNull( $cache );
	}
}