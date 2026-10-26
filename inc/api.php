<?php
/**
 * Shrinkalink Link Creator - Shlink API Module
 *
 * Interface to the Shlink API
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

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( ShrinkALink_API::class ) ) {
	/**
	 * Talks to the Shlink REST API. All methods are static.
	 */
	class ShrinkALink_API {

		/**
		 * Default Shlink REST API version, filterable via shrinkalink_api_version.
		 *
		 * @var int
		 */
		const API_VERSION = 3;

		/**
		 * Filtered API version, cached after first lookup
		 *
		 * @var int|null
		 */
		private static $api_version = null;

		/**
		 * Get the Shlink API version, filterable via shrinkalink_api_version
		 *
		 * @return int
		 */
		public static function get_api_version() {

			// run the filter once and cache it
			if ( null === self::$api_version ) {
				self::$api_version = absint( apply_filters( 'shrinkalink_api_version', self::API_VERSION ) );
			}

			return self::$api_version;
		}

		/**
		 * Get the Shlink API key from the plugin settings.
		 *
		 * @return string The key, or '' if not set.
		 */
		public static function get_api_key() {
			return ShrinkALink_Settings::get_api_key();
		}

		/**
		 * Get the Shlink instance URL from the plugin settings.
		 *
		 * @return string The URL, or '' if not set.
		 */
		public static function get_api_url() {
			return ShrinkALink_Settings::get_instance_url();
		}

		/**
		 * Get the tags added to every link this site creates.
		 *
		 * Defaults to the site's host, so a Shlink instance
		 * shared by several sites can be filtered per site.
		 *
		 * @param  string $long_url Optional. The URL being shortened, for the filter.
		 * @param  array  $args     Optional. The request args, for the filter.
		 * @return array            Tag names.
		 */
		public static function get_site_tags( $long_url = '', $args = array() ) {
			return array_values(
				array_filter(
					(array) apply_filters(
						'shrinkalink_link_tags',
						array( wp_parse_url( home_url(), PHP_URL_HOST ) ),
						$long_url,
						$args
					)
				)
			);
		}


		/**
		 * Create a short URL, or return the existing one for the same long URL.
		 *
		 * @param  string $long_url The URL to shorten.
		 * @param  array  $args     Optional Shlink fields, e.g. title, customSlug, tags.
		 *                          A customSlug is cleaned with prepare_shortcode().
		 *                          Tags are merged with the site tag from shrinkalink_link_tags.
		 * @return array|WP_Error   The Shlink response (shortUrl, shortCode, ...), or an error.
		 */
		public static function generate_shlink( $long_url = '', $args = array() ) {

			// bail without a URL to shorten
			if ( empty( $long_url ) ) {
				return new WP_Error( 'shrinkalink_no_url', __( 'No URL provided.', 'shrinkalink' ) );
			}

			// clean any requested short code here, so every caller gets the same rules
			if ( isset( $args['customSlug'] ) ) {
				$args['customSlug'] = self::prepare_shortcode( $args['customSlug'] );

				if ( '' === $args['customSlug'] ) {
					return new WP_Error( 'shrinkalink_invalid_slug', __( 'The short code is empty after removing invalid characters.', 'shrinkalink' ) );
				}
			}

			// tag every link with this site, so a shared Shlink instance can be filtered per site
			$site_tags = self::get_site_tags( $long_url, $args );

			// keep any tags the caller passed in, without duplicates or empty values
			$args['tags'] = array_values( array_unique( array_filter( array_merge( (array) ( $args['tags'] ?? array() ), (array) $site_tags ) ) ) );

			if ( empty( $args['tags'] ) ) {
				unset( $args['tags'] );
			}

			// build the request body, letting $args add or override fields
			$body = wp_parse_args(
				$args,
				array(
					'longUrl'      => esc_url_raw( $long_url ),
					'findIfExists' => (bool) apply_filters( 'shrinkalink_find_if_exists', true ),
				)
			);

			$data = self::request( 'POST', 'short-urls', $body );

			// bail on a request or API failure
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			// a success without a short URL is still a failure
			if ( empty( $data['shortUrl'] ) ) {
				return new WP_Error( 'shrinkalink_api_error', __( 'Shlink did not return a short URL.', 'shrinkalink' ), $data );
			}

			return $data;
		}

		/**
		 * Clean up a requested short code before sending it to Shlink.
		 *
		 * @param  string $string The requested short code.
		 * @return string         The cleaned short code, or '' if nothing is left.
		 */
		public static function prepare_shortcode( $string = '' ) {

			// strip tags, line breaks and extra whitespace, but keep emoji and other unicode
			$string = trim( sanitize_text_field( $string ) );

			// slashes only make sense when multi-segment slugs are enabled in Shlink
			if ( ! apply_filters( 'shrinkalink_multi_segment_slugs', false ) ) {
				$string = str_replace( '/', '', $string );
			}

			// optional pattern for sites that want a stricter character set
			$pattern = apply_filters( 'shrinkalink_keyword_pattern', '' );

			return ! empty( $pattern ) ? (string) preg_replace( $pattern, '', $string ) : (string) $string;
		}

		/**
		 * Get the details of a short URL.
		 *
		 * @param  string $shortcode The short code to look up.
		 * @param  array  $args      Optional query args, e.g. array( 'domain' => 'example.com' ).
		 * @return array|WP_Error    The Shlink response (shortUrl, longUrl, title, visitsSummary, ...), or an error.
		 */
		public static function get_shlink( $shortcode = '', $args = array() ) {

			// bail without a short code to look up
			if ( empty( $shortcode ) ) {
				return new WP_Error( 'shrinkalink_no_shortcode', __( 'No short code provided.', 'shrinkalink' ) );
			}

			$data = self::request( 'GET', self::short_url_path( $shortcode, $args ) );

			// bail on a request or API failure
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			// a success without a short URL is still a failure
			if ( empty( $data['shortUrl'] ) ) {
				return new WP_Error( 'shrinkalink_api_error', __( 'Shlink did not return a short URL.', 'shrinkalink' ), $data );
			}

			// the full response, so callers get longUrl, title, visits and so on
			return $data;
		}

		/**
		 * Update an existing short URL
		 *
		 * @param  string $shortcode The short code to update.
		 * @param  array  $fields    Fields to change, e.g. longUrl, title, tags, validUntil.
		 * @param  array  $args      Optional query args, e.g. array( 'domain' => 'example.com' ).
		 * @return array|WP_Error    The updated Shlink response (shortUrl, longUrl, ...), or an error.
		 */
		public static function update_shlink( $shortcode = '', $fields = array(), $args = array() ) {

			// bail without a short code to update
			if ( empty( $shortcode ) ) {
				return new WP_Error( 'shrinkalink_no_shortcode', __( 'No short code provided.', 'shrinkalink' ) );
			}

			// bail without anything to change
			if ( empty( $fields ) ) {
				return new WP_Error( 'shrinkalink_no_fields', __( 'No fields provided to update.', 'shrinkalink' ) );
			}

			$data = self::request( 'PATCH', self::short_url_path( $shortcode, $args ), $fields );

			// bail on a request or API failure
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			// a success without a short URL is still a failure
			if ( empty( $data['shortUrl'] ) ) {
				return new WP_Error( 'shrinkalink_api_error', __( 'Shlink did not return a short URL.', 'shrinkalink' ), $data );
			}

			return $data;
		}

		/**
		 * Delete a short URL
		 *
		 * @param  string $shortcode The short code to delete.
		 * @param  array  $args      Optional query args, e.g. array( 'domain' => 'example.com' ).
		 * @return true|WP_Error     True on success, or an error.
		 */
		public static function delete_shlink( $shortcode = '', $args = array() ) {

			// bail without a short code to delete
			if ( empty( $shortcode ) ) {
				return new WP_Error( 'shrinkalink_no_shortcode', __( 'No short code provided.', 'shrinkalink' ) );
			}

			$data = self::request( 'DELETE', self::short_url_path( $shortcode, $args ) );

			// bail on a request or API failure
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			// Shlink returns an empty 204 on success
			return true;
		}

		/**
		 * Build the path for a single short URL, limited to our domain
		 *
		 * @param  string $shortcode The short code.
		 * @param  array  $args      Query args, which override the default domain.
		 * @return string            Path relative to /rest/v{version}/.
		 */
		private static function short_url_path( $shortcode, $args = array() ) {

			// limit to our domain, filterable for multi-domain setups
			$args = wp_parse_args(
				$args,
				array(
					'domain' => apply_filters( 'shrinkalink_domain', wp_parse_url( self::get_api_url(), PHP_URL_HOST ) ),
				)
			);

			// drop empty args so a filter can return '' to use the default domain
			return add_query_arg( array_filter( $args ), 'short-urls/' . rawurlencode( $shortcode ) );
		}

		/**
		 * Send a request to the Shlink REST API
		 *
		 * @param  string     $method HTTP method.
		 * @param  string     $path   Path after /rest/v{version}/, including any query string.
		 * @param  array|null $body   Data to send as JSON, or null for no body.
		 * @return array|WP_Error     Decoded response data, or an error.
		 */
		private static function request( $method, $path, $body = null ) {

			// bail before calling Shlink if the URL or key is missing
			$configured = ShrinkALink_Settings::check_configured();

			if ( is_wp_error( $configured ) ) {
				return $configured;
			}

			$endpoint = trailingslashit( self::get_api_url() ) . 'rest/v' . self::get_api_version() . '/' . ltrim( $path, '/' );

			$request = array(
				'method'  => $method,
				'headers' => array(
					'Accept'    => 'application/json',
					'X-Api-Key' => self::get_api_key(),
				),
				'timeout' => 10,
			);

			// only send a JSON body when we have one
			if ( null !== $body ) {
				$request['headers']['Content-Type'] = 'application/json';
				$request['body']                    = wp_json_encode( $body );
			}

			$response = wp_remote_request( $endpoint, $request );

			// bail on a connection failure
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			// Shlink returns a 2xx on success, with problem details in the body otherwise
			if ( $code < 200 || $code >= 300 ) {
				$message = ! empty( $data['detail'] ) ? $data['detail'] : __( 'Unknown Shlink error.', 'shrinkalink' );
				return new WP_Error( 'shrinkalink_api_error', $message, $data );
			}

			// empty bodies (e.g. a 204 from a delete) come back as an empty array
			return is_array( $data ) ? $data : array();
		}

		/**
		 * Check that a URL points at a healthy Shlink instance
		 *
		 * Uses the unversioned, unauthenticated /rest/health endpoint, so it works
		 * before an API key is saved.
		 *
		 * @param  string $url Optional. The instance URL to check. Defaults to the saved one.
		 * @return array|WP_Error The health response (status, version), or an error.
		 */
		public static function health_check( $url = '' ) {

			$url = '' !== $url ? $url : self::get_api_url();

			// bail without a URL to check
			if ( '' === $url ) {
				return new WP_Error( 'shrinkalink_no_url', __( 'No Shlink URL to check.', 'shrinkalink' ) );
			}

			$response = wp_remote_get(
				trailingslashit( $url ) . 'rest/health',
				array(
					'headers' => array( 'Accept' => 'application/json' ),
					'timeout' => 5,
				)
			);

			// bail on a connection failure (DNS, TLS, timeout)
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			// not JSON with a status, so probably not a Shlink server at all
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				return new WP_Error( 'shrinkalink_not_shlink', __( 'That URL did not respond like a Shlink server.', 'shrinkalink' ) );
			}

			// Shlink answered but reported a problem, usually its database
			if ( 'pass' !== $data['status'] ) {
				return new WP_Error( 'shrinkalink_unhealthy', __( 'Shlink reported that it is not healthy.', 'shrinkalink' ), $data );
			}

			return $data;
		}

		/**
		 * List short URLs, following Shlink's pagination.
		 *
		 * @param  array $args Optional list filters, e.g. array( 'tags' => array( 'example.com' ) ).
		 *                     Also accepts searchTerm, orderBy and itemsPerPage.
		 * @return array|WP_Error The short URLs from every page, or an error.
		 */
		public static function list_shlinks( $args = array() ) {

			$args  = wp_parse_args( $args, array( 'itemsPerPage' => 100 ) );
			$page  = 1;
			$links = array();

			// stop runaway paging if Shlink's pagination data is ever wrong
			$max_pages = (int) apply_filters( 'shrinkalink_list_max_pages', 50 );

			do {
				$data = self::request( 'GET', add_query_arg( array_merge( $args, array( 'page' => $page ) ), 'short-urls' ) );

				if ( is_wp_error( $data ) ) {
					return $data;
				}

				$links = array_merge( $links, $data['shortUrls']['data'] ?? array() );
				$pages = absint( $data['shortUrls']['pagination']['pagesCount'] ?? 1 );

				++$page;
			} while ( $page <= $pages && $page <= $max_pages );

			return $links;
		}



		// end class
	}

		// end exists check
}
