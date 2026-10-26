<?php
/**
 * Tests for the settings.
 *
 * @package Shrinkalink
 */

use BrookeDot\Shrinkalink\ShrinkALink_Settings;

require_once __DIR__ . '/class-shrinkalink-testcase.php';

/**
 * ShrinkALink_Settings tests.
 */
class Test_Shrinkalink_Settings extends Shrinkalink_TestCase {

	public function test_instance_url_must_use_https() {
		$this->assertWPError( ShrinkALink_Settings::validate_instance_url( 'http://example.com' ) );
	}

	public function test_instance_url_cannot_have_a_query_string() {
		$this->assertWPError( ShrinkALink_Settings::validate_instance_url( 'https://example.com/?a=1' ) );
	}

	public function test_instance_url_trailing_slash_is_removed() {
		$this->assertSame( 'https://example.com', ShrinkALink_Settings::validate_instance_url( 'https://example.com/' ) );
	}

	public function test_saving_settings_stores_the_url_and_key() {
		$this->assertSame( 'https://example.com', ShrinkALink_Settings::get_instance_url() );
		$this->assertSame( 'test-key', ShrinkALink_Settings::get_api_key() );
		$this->assertTrue( ShrinkALink_Settings::check_configured() );
	}

	public function test_a_blank_key_keeps_the_saved_key() {
		update_option(
			'shrinkalink_settings',
			array(
				'instance_url' => 'https://example.net',
				'api_key'      => '',
			)
		);

		$this->assertSame( 'https://example.net', ShrinkALink_Settings::get_instance_url() );
		$this->assertSame( 'test-key', ShrinkALink_Settings::get_api_key() );
	}

	public function test_a_new_key_replaces_the_saved_key() {
		update_option( 'shrinkalink_settings', array( 'api_key' => 'new-key' ) );

		$this->assertSame( 'new-key', ShrinkALink_Settings::get_api_key() );
	}

	public function test_an_invalid_url_keeps_the_saved_url() {
		update_option( 'shrinkalink_settings', array( 'instance_url' => 'http://insecure.example.com' ) );

		$this->assertSame( 'https://example.com', ShrinkALink_Settings::get_instance_url() );
	}

	public function test_the_key_is_not_kept_in_the_option_when_secrets_are_available() {
		if ( ! function_exists( 'wp_set_secret' ) ) {
			$this->markTestSkipped( 'The Secrets API needs WordPress 7.2.' );
		}

		$settings = get_option( 'shrinkalink_settings' );

		$this->assertArrayNotHasKey( 'api_key', $settings );
		$this->assertTrue( ShrinkALink_Settings::api_key_in_secrets() );
	}
}
