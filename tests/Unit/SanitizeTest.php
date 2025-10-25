<?php
/**
 * Unit tests for Sanitize utility class.
 *
 * @package SmartAlt\Tests
 */

namespace SmartAlt\Tests\Unit;

use SmartAlt\Utils\Sanitize;

/**
 * Test cases for Sanitize utilities.
 */
class SanitizeTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Test alt text sanitization.
	 */
	public function test_alt_text_sanitization() {
		// Basic sanitization
		$alt = Sanitize::alt_text( '<b>Bold text</b> image' );
		$this->assertEquals( 'Bold text image', $alt );

		// Trim whitespace
		$alt = Sanitize::alt_text( '  spaces around  ' );
		$this->assertEquals( 'spaces around', $alt );

		// Replace multiple spaces
		$alt = Sanitize::alt_text( 'too    many    spaces' );
		$this->assertEquals( 'too many spaces', $alt );
	}

	/**
	 * Test alt text truncation.
	 */
	public function test_alt_text_truncation() {
		$long_text = str_repeat( 'word ', 50 );
		$alt = Sanitize::alt_text( $long_text, 50 );
		$this->assertLessThanOrEqual( 50, mb_strlen( $alt ) );
	}

	/**
	 * Test image URL sanitization.
	 */
	public function test_image_url_validation() {
		$valid_url = 'https://example.com/image.jpg';
		$this->assertEquals( $valid_url, Sanitize::image_url( $valid_url ) );

		$invalid_url = 'not a url';
		$this->assertNull( Sanitize::image_url( $invalid_url ) );
	}

	/**
	 * Test batch size validation.
	 */
	public function test_batch_size_validation() {
		$this->assertEquals( 100, Sanitize::batch_size( 100 ) );
		$this->assertEquals( 1, Sanitize::batch_size( 0 ) );
		$this->assertEquals( 500, Sanitize::batch_size( 1000 ) );
	}

	/**
	 * Test max alt length validation.
	 */
	public function test_max_alt_length_validation() {
		$this->assertEquals( 125, Sanitize::max_alt_length( 125 ) );
		$this->assertEquals( 50, Sanitize::max_alt_length( 30 ) );
		$this->assertEquals( 500, Sanitize::max_alt_length( 600 ) );
	}

	/**
	 * Test headers JSON validation.
	 */
	public function test_headers_json_validation() {
		$json = '{"Authorization": "Bearer token123"}';
		$headers = Sanitize::headers_json( $json );
		$this->assertIsArray( $headers );
		$this->assertArrayHasKey( 'Authorization', $headers );
	}

	/**
	 * Test invalid headers JSON.
	 */
	public function test_invalid_headers_json() {
		$json = 'not valid json';
		$headers = Sanitize::headers_json( $json );
		$this->assertNull( $headers );
	}

	/**
	 * Test JSON path validation.
	 */
	public function test_json_path_validation() {
		$valid_path = 'data.result.text';
		$this->assertEquals( $valid_path, Sanitize::json_path( $valid_path ) );

		$invalid_path = 'data..invalid';
		$this->assertNull( Sanitize::json_path( $invalid_path ) );
	}

	/**
	 * Test escape alt for HTML.
	 */
	public function test_escape_alt() {
		$alt = 'Image with "quotes" and \'apostrophes\'';
		$escaped = Sanitize::escape_alt( $alt );
		$this->assertStringContainsString( 'Image', $escaped );
		// Verify it's properly escaped
		$this->assertFalse( strpos( $escaped, '<' ) );
	}
}