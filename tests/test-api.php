<?php
/**
 * Tests for the Shlink API wrapper.
 *
 * @package Shrinkalink
 */

use BrookeDot\Shrinkalink\ShrinkALink_API;

require_once __DIR__ . '/class-shrinkalink-testcase.php';

/**
 * ShrinkALink_API tests.
 */
class Test_Shrinkalink_API extends Shrinkalink_TestCase {

	public function test_generate_shlink_needs_a_url() {
		$result = ShrinkALink_API::generate_shlink( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'shrinkalink_no_url', $result->get_error_code() );
		$this->assertEmpty( $this->requests );
	}

	public function test_generate_shlink_sends_the_link_to_shlink() {
		$this->queue(
			200,
			array(
				'shortUrl'  => 'https://example.com/abc',
				'shortCode' => 'abc',
			)
		);

		$result = ShrinkALink_API::generate_shlink( 'https://example.org/hello', array( 'title' => 'Hello' ) );

		$this->assertSame( 'https://example.com/abc', $result['shortUrl'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://example.com/rest/v3/short-urls', $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( 'test-key', $this->requests[0]['args']['headers']['X-Api-Key'] );

		$body = $this->request_body( 0 );
		$this->assertSame( 'https://example.org/hello', $body['longUrl'] );
		$this->assertSame( 'Hello', $body['title'] );
		$this->assertTrue( $body['findIfExists'] );
		$this->assertContains( wp_parse_url( home_url(), PHP_URL_HOST ), $body['tags'] );
	}

	public function test_generate_shlink_rejects_a_slug_that_cleans_to_nothing() {
		$result = ShrinkALink_API::generate_shlink( 'https://example.org/hello', array( 'customSlug' => '///' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'shrinkalink_invalid_slug', $result->get_error_code() );
		$this->assertEmpty( $this->requests );
	}

	public function test_shlink_errors_use_the_detail_message() {
		$this->queue(
			400,
			array(
				'detail' => 'Provided slug is already in use.',
				'status' => 400,
			)
		);

		$result = ShrinkALink_API::generate_shlink( 'https://example.org/hello', array( 'customSlug' => 'taken' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'Provided slug is already in use.', $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_delete_shlink_accepts_an_empty_204() {
		$this->queue( 204 );

		$this->assertTrue( ShrinkALink_API::delete_shlink( 'abc' ) );
		$this->assertSame( 'DELETE', $this->requests[0]['args']['method'] );
		$this->assertStringContainsString( 'short-urls/abc', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'domain=example.com', $this->requests[0]['url'] );
	}

	public function test_requests_stop_when_not_configured() {
		delete_option( 'shrinkalink_settings' );

		$result = ShrinkALink_API::get_shlink( 'abc' );

		$this->assertWPError( $result );
		$this->assertSame( 'shrinkalink_not_configured', $result->get_error_code() );
		$this->assertEmpty( $this->requests );
	}

	public function test_list_shlinks_follows_pagination() {
		$this->queue(
			200,
			array(
				'shortUrls' => array(
					'data'       => array( array( 'shortCode' => 'one' ) ),
					'pagination' => array( 'pagesCount' => 2 ),
				),
			)
		);
		$this->queue(
			200,
			array(
				'shortUrls' => array(
					'data'       => array( array( 'shortCode' => 'two' ) ),
					'pagination' => array( 'pagesCount' => 2 ),
				),
			)
		);

		$links = ShrinkALink_API::list_shlinks();

		$this->assertSame( array( 'one', 'two' ), wp_list_pluck( $links, 'shortCode' ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_prepare_shortcode_removes_slashes_by_default() {
		$this->assertSame( 'ab', ShrinkALink_API::prepare_shortcode( 'a/b' ) );
	}

	public function test_prepare_shortcode_keeps_slashes_for_multi_segment_slugs() {
		add_filter( 'shrinkalink_multi_segment_slugs', '__return_true' );

		$this->assertSame( 'a/b', ShrinkALink_API::prepare_shortcode( 'a/b' ) );
	}

	public function test_prepare_shortcode_applies_the_keyword_pattern() {
		add_filter(
			'shrinkalink_keyword_pattern',
			function () {
				return '/[^a-z]/';
			}
		);

		$this->assertSame( 'ab', ShrinkALink_API::prepare_shortcode( 'a-b1' ) );
	}
}
