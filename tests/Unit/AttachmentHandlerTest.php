<?php
/**
 * Unit tests for AttachmentHandler.
 *
 * @package SmartAlt\Tests
 */

namespace SmartAlt\Tests\Unit;

use SmartAlt\Core\AttachmentHandler;

/**
 * Test cases for AttachmentHandler.
 */
class AttachmentHandlerTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Test extracting inline images from HTML.
	 */
	public function test_extract_inline_images() {
		$html = '<img src="https://example.com/image1.jpg" alt="Test"> <img src="https://example.com/image2.png">';
		$images = AttachmentHandler::extract_inline_images( $html );

		$this->assertCount( 2, $images );
		$this->assertEquals( 'https://example.com/image1.jpg', $images[0]['url'] );
		$this->assertEquals( 'Test', $images[0]['alt'] );
		$this->assertEquals( 'https://example.com/image2.png', $images[1]['url'] );
		$this->assertEmpty( $images[1]['alt'] );
	}

	/**
	 * Test extracting images with no alt.
	 */
	public function test_extract_images_no_alt() {
		$html = '<img src="https://example.com/image.jpg" />';
		$images = AttachmentHandler::extract_inline_images( $html );

		$this->assertCount( 1, $images );
		$this->assertEmpty( $images[0]['alt'] );
	}

	/**
	 * Test filename extraction from URL.
	 */
	public function test_get_filename_from_url() {
		$url = 'https://example.com/uploads/2025/01/my-image.jpg';
		$filename = AttachmentHandler::get_filename_from_url( $url );
		$this->assertEquals( 'my-image.jpg', $filename );
	}

	/**
	 * Test attachment context data.
	 */
	public function test_get_context() {
		$context = AttachmentHandler::get_context( 0, 0 );

		$this->assertIsArray( $context );
		$this->assertArrayHasKey( 'attachment_id', $context );
		$this->assertArrayHasKey( 'filename', $context );
		$this->assertArrayHasKey( 'url', $context );
	}

	/**
	 * Test handling empty content.
	 */
	public function test_extract_images_empty_content() {
		$images = AttachmentHandler::extract_inline_images( '' );
		$this->assertEmpty( $images );

		$images = AttachmentHandler::extract_inline_images( null );
		$this->assertEmpty( $images );
	}

	/**
	 * Test extracting images with malformed HTML.
	 */
	public function test_extract_images_malformed_html() {
		$html = '<img src="https://example.com/image.jpg" alt="Test" <img src="https://example.com/image2.jpg">';
		$images = AttachmentHandler::extract_inline_images( $html );

		// Should still find at least one image
		$this->assertGreaterThanOrEqual( 1, count( $images ) );
	}
}