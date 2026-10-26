<?php
/**
 * Shared setup for Shrinkalink tests.
 *
 * @package Shrinkalink
 */

/**
 * Saves working settings and fakes Shlink's responses, so no test talks to a real server.
 */
abstract class Shrinkalink_TestCase extends WP_UnitTestCase {

	/**
	 * Requests the plugin sent, each with 'url' and 'args'.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake responses to send back, in order.
	 *
	 * @var array
	 */
	protected $responses = array();

	/**
	 * Save a Shlink URL and key, and catch every HTTP request.
	 */
	public function set_up() {
		parent::set_up();

		update_option(
			'shrinkalink_settings',
			array(
				'instance_url' => 'https://example.com',
				'api_key'      => 'test-key',
			)
		);

		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	/**
	 * Record the request and return the next queued response.
	 *
	 * @param  false|array $pre  Unused.
	 * @param  array       $args The request args.
	 * @param  string      $url  The request URL.
	 * @return array|WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		// fail loudly rather than reach a real server
		if ( empty( $this->responses ) ) {
			return new WP_Error( 'shrinkalink_test_no_response', 'No fake response queued.' );
		}

		return array_shift( $this->responses );
	}

	/**
	 * Queue a fake Shlink response.
	 *
	 * @param int        $code The HTTP status code.
	 * @param array|null $body Data to send as JSON, or null for an empty body.
	 */
	protected function queue( $code, $body = null ) {
		$this->responses[] = array(
			'headers'  => array(),
			'body'     => null === $body ? '' : wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Get the decoded JSON body of a sent request.
	 *
	 * @param  int $index Which request, from 0.
	 * @return array
	 */
	protected function request_body( $index ) {
		return json_decode( $this->requests[ $index ]['args']['body'], true );
	}
}
