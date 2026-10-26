<?php
/**
 * Tests for creating shortlinks when posts are published.
 *
 * @package Shrinkalink
 */

use BrookeDot\Shrinkalink\ShrinkALink_Post;

require_once __DIR__ . '/class-shrinkalink-testcase.php';

/**
 * ShrinkALink_Publish tests.
 */
class Test_Shrinkalink_Publish extends Shrinkalink_TestCase {

	/**
	 * Queue a successful create response for a short code.
	 *
	 * @param string $code The short code Shlink assigns.
	 */
	private function queue_created( $code ) {
		$this->queue(
			200,
			array(
				'shortUrl'  => 'https://example.com/' . $code,
				'shortCode' => $code,
			)
		);
	}

	public function test_publishing_a_post_creates_a_shortlink() {
		$this->queue_created( 'abc' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://example.com/abc', get_post_meta( $post_id, ShrinkALink_Post::META_URL, true ) );
		$this->assertSame( 'abc', get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE, true ) );
		$this->assertSame( 'example.com', get_post_meta( $post_id, ShrinkALink_Post::META_DOMAIN, true ) );
	}

	public function test_drafts_do_not_get_a_shortlink() {
		self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertEmpty( $this->requests );
	}

	public function test_blocked_posts_do_not_get_a_shortlink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		ShrinkALink_Post::set_blocked( $post_id );

		wp_publish_post( $post_id );

		$this->assertEmpty( $this->requests );
	}

	public function test_updating_a_post_keeps_its_shortlink() {
		$this->queue_created( 'abc' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'A new title',
			)
		);

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'abc', get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE, true ) );
	}

	public function test_turning_off_create_on_save_skips_publishing() {
		add_filter( 'shrinkalink_create_on_save', '__return_false' );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertEmpty( $this->requests );
	}

	public function test_a_requested_short_code_is_used_on_publish() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		// saving the request asks Shlink if it's free; a 404 means it is
		$this->queue( 404, array( 'status' => 404 ) );
		update_post_meta( $post_id, ShrinkALink_Post::META_REQUESTED, 'hello' );

		$this->queue_created( 'hello' );
		wp_publish_post( $post_id );

		$this->assertSame( 'hello', $this->request_body( 1 )['customSlug'] );
		$this->assertSame( 'hello', get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE, true ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, ShrinkALink_Post::META_REQUESTED ) );
	}

	public function test_a_taken_short_code_falls_back_to_a_random_one() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->queue( 404, array( 'status' => 404 ) );
		update_post_meta( $post_id, ShrinkALink_Post::META_REQUESTED, 'hello' );

		// taken by the time the post is published, then the fallback succeeds
		$this->queue(
			400,
			array(
				'detail' => 'Provided slug is already in use.',
				'status' => 400,
			)
		);
		$this->queue_created( 'xyz' );
		wp_publish_post( $post_id );

		$this->assertCount( 3, $this->requests );
		$this->assertArrayNotHasKey( 'customSlug', $this->request_body( 2 ) );
		$this->assertSame( 'xyz', get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE, true ) );
	}
}
