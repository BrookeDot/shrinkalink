<?php
/**
 * Shrinkalink Link Creator - Settings Module
 *
 * WordPress settings for Shrinkalink
 *
 * @package Shrinkalink
 */
/*
 * Copyright 2026 Brooke.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace BrookeDot\Shrinkalink;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( ShrinkALink_Settings::class ) ) {
	/**
	 * Registers the shrinkalink_settings option and reads the Shlink URL and API key.
	 *
	 * The API key is stored with the Secrets API when it's available (WordPress 7.2+),
	 * and in the option otherwise.
	 */
	class ShrinkALink_Settings {

		/**
		 * Register the setting and the API key migration.
		 */
		public function __construct() {
			add_action( 'init', array( $this, 'shrinkalink_register_settings' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_api_key' ) );
		}

		/**
		 * Register the shrinkalink_settings option.
		 *
		 * Registered on init in every context, so the sanitize callback also runs
		 * for saves from WP-CLI and code, not just the settings page.
		 *
		 * @link   https://developer.wordpress.org/reference/functions/register_setting/
		 * @return void
		 */
		public function shrinkalink_register_settings() {
			$args = array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'instance_url' => '',
					'api_key'      => '',
				),
			);
			register_setting( 'shrinkalink_settings_group', 'shrinkalink_settings', $args );
		}

		/**
		 * Sanitize the settings before they're saved.
		 *
		 * Fields missing from $input keep their saved value, and a blank API key
		 * keeps the saved key. A key is stored with the Secrets API when possible,
		 * and left out of the returned array when it is.
		 *
		 * @param  array $input The submitted settings, from the form, WP-CLI or code.
		 * @return array        The settings to save.
		 */
		public function sanitize_settings( $input ) {

			$settings  = get_option( 'shrinkalink_settings', array() );
			$sanitized = array(
				'instance_url' => $settings['instance_url'] ?? '',
			);

			if ( isset( $input['instance_url'] ) ) {
				$url = '' === trim( $input['instance_url'] ) ? '' : self::validate_instance_url( $input['instance_url'] );

				if ( is_wp_error( $url ) ) {
					if ( function_exists( 'add_settings_error' ) ) {
						add_settings_error( 'shrinkalink_settings', $url->get_error_code(), $url->get_error_message() . ' ' . __( 'The previous URL was kept.', 'shrinkalink' ) );
					}
				} else {
					$sanitized['instance_url'] = $url;
				}
			}

			$api_key = sanitize_text_field( $input['api_key'] ?? '' );

			if ( '' === $api_key ) {

				// nothing stored in plain text, so there's nothing to keep or move
				if ( empty( $settings['api_key'] ) ) {
					return $sanitized;
				}

				// move the plain-text key into a secret, and drop it from the array
				if ( function_exists( 'wp_set_secret' ) && true === wp_set_secret( 'shrinkalink/api-key', $settings['api_key'] ) ) {
					return $sanitized;
				}

				// no Secrets API (or saving it failed), so keep the plain-text key
				$sanitized['api_key'] = $settings['api_key'];
				return $sanitized;
			}

			// store the new key encrypted when possible
			if ( function_exists( 'wp_set_secret' ) && true === wp_set_secret( 'shrinkalink/api-key', $api_key ) ) {
				return $sanitized;
			}

			$sanitized['api_key'] = $api_key;
			return $sanitized;
		}

		/**
		 * Get the saved Shlink instance URL
		 *
		 * @return string The URL, or '' if not set.
		 */
		public static function get_instance_url() {
			$settings = get_option( 'shrinkalink_settings', array() );

			return $settings['instance_url'] ?? '';
		}

		/**
		 * Get the Shlink API key, from the Secrets API when stored there
		 *
		 * @return string The key, or '' if not set.
		 */
		public static function get_api_key() {

			// prefer the encrypted secret
			if ( function_exists( 'wp_get_secret' ) ) {
				$secret = wp_get_secret( 'shrinkalink/api-key' );

				if ( $secret instanceof \WP_Secret ) {
					return $secret->reveal();
				}
			}

			// fall back to the plain-text key saved before the Secrets API existed
			$settings = get_option( 'shrinkalink_settings', array() );

			return $settings['api_key'] ?? '';
		}

		/**
		 * Check whether an API key is stored, without revealing it
		 *
		 * @return bool
		 */
		public static function has_api_key() {

			if ( function_exists( 'wp_get_secret' ) && wp_get_secret( 'shrinkalink/api-key' ) instanceof \WP_Secret ) {
				return true;
			}

			$settings = get_option( 'shrinkalink_settings', array() );

			return ! empty( $settings['api_key'] );
		}

		/**
		 * Check whether the API key is stored in the Secrets API
		 *
		 * @return bool
		 */
		public static function api_key_in_secrets() {
			return function_exists( 'wp_get_secret' ) && wp_get_secret( 'shrinkalink/api-key' ) instanceof \WP_Secret;
		}


		/**
		 * Check that the Shlink URL and API key are both set
		 *
		 * @return true|\WP_Error True when ready, or an error naming what's missing.
		 */
		public static function check_configured() {

			$missing = array();

			if ( '' === self::get_instance_url() ) {
				$missing[] = __( 'Shlink URL', 'shrinkalink' );
			}

			if ( ! self::has_api_key() ) {
				$missing[] = __( 'API key', 'shrinkalink' );
			}

			if ( empty( $missing ) ) {
				return true;
			}

			return new \WP_Error(
				'shrinkalink_not_configured',
				sprintf(
					/* translators: %s: comma-separated list of missing settings */
					__( 'Shrinkalink is missing settings: %s.', 'shrinkalink' ),
					implode( ', ', $missing )
				)
			);
		}

		/**
		 * Move a plain-text API key into the Secrets API once it's available.
		 *
		 * Runs after upgrading to WordPress 7.2. Saving the settings without the
		 * key lets sanitize_settings() do the move, so the plain-text copy is only
		 * removed once the secret has been saved.
		 *
		 * @return void
		 */
		public function maybe_migrate_api_key() {

			if ( ! function_exists( 'wp_set_secret' ) ) {
				return;
			}

			$settings = get_option( 'shrinkalink_settings', array() );

			if ( empty( $settings['api_key'] ) ) {
				return;
			}

			// saving without the key lets sanitize_settings() move it into a secret
			unset( $settings['api_key'] );
			update_option( 'shrinkalink_settings', $settings );
		}

		/**
		 * Check that a Shlink instance URL is usable
		 *
		 * @param  string $url The URL to check.
		 * @return string|\WP_Error The cleaned URL, or an error explaining the problem.
		 */
		public static function validate_instance_url( $url ) {

			$url   = untrailingslashit( trim( (string) $url ) );
			$parts = wp_parse_url( $url );

			// must be a well-formed https URL with a real host
			if ( false === filter_var( $url, FILTER_VALIDATE_URL )
				|| empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] )
				|| empty( $parts['host'] )
			) {
				return new \WP_Error( 'shrinkalink_invalid_url', __( 'The Shlink URL must be a valid https URL, like https://example.com.', 'shrinkalink' ) );
			}

			// the API path is added for us, so the base URL can't carry a query or fragment
			if ( ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
				return new \WP_Error( 'shrinkalink_invalid_url', __( 'The Shlink URL should not include a query string or #fragment.', 'shrinkalink' ) );
			}

			return esc_url_raw( $url, array( 'https' ) );
		}

		// end class
	}

		// end exists check
}

// Instantiate our class
new ShrinkALink_Settings();
