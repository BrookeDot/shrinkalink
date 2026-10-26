<?php
/**
 * Shrinkalink Link Creator - Post Meta Module
 *
 * Reads the Shlink data stored on posts
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

if ( ! class_exists( ShrinkALink_Post::class ) ) {
	/**
	 * Which posts Shrinkalink handles, and the Shlink data stored on them.
	 */
	class ShrinkALink_Post {

		/**
		 * Post meta keys.
		 */
		const META_URL       = '_shrinkalink_url';
		const META_SHORTCODE = '_shrinkalink_shortcode';
		const META_DOMAIN    = '_shrinkalink_domain';
		const META_CLICKS    = '_shrinkalink_clicks';
		const META_BLOCKED   = '_shrinkalink_blocked';
		const META_REQUESTED = '_shrinkalink_requested';

		/**
		 * Get a Shrinkalink post meta value.
		 *
		 * @param  int    $post_id  The post ID.
		 * @param  string $key      One of the META_* keys. Default META_URL.
		 * @param  mixed  $fallback Returned when the value is empty. Default false.
		 * @return mixed            The stored value, or $fallback.
		 */
		public static function get_post_meta( $post_id = 0, $key = self::META_URL, $fallback = false ) {

			// get my item
			$item = get_post_meta( $post_id, $key, true );

			// return the item if there
			if ( ! empty( $item ) ) {
				return $item;
			}

			// return either empty or fallback
			return isset( $fallback ) ? $fallback : false;
		}


		/**
		 * Check whether a post is blocked from getting a shortlink automatically.
		 *
		 * Stored as present or absent rather than true/false, since WordPress
		 * saves false as '' and get_post_meta() returns '' for a missing key too.
		 *
		 * @param  int $post_id The post ID.
		 * @return bool
		 */
		public static function is_blocked( $post_id ) {
			return metadata_exists( 'post', $post_id, self::META_BLOCKED );
		}

		/**
		 * Block or unblock a post from getting a shortlink automatically.
		 *
		 * @param  int  $post_id The post ID.
		 * @param  bool $blocked True to block, false to unblock. Default true.
		 * @return void
		 */
		public static function set_blocked( $post_id, $blocked = true ) {
			if ( $blocked ) {
				update_post_meta( $post_id, self::META_BLOCKED, 1 );
			} else {
				delete_post_meta( $post_id, self::META_BLOCKED );
			}
		}


		/**
		 * Store a short URL's details on a post.
		 *
		 * @param  int   $post_id The post ID.
		 * @param  array $data    A short URL response from the Shlink API (shortUrl, shortCode, ...).
		 * @return string         The stored short URL.
		 */
		public static function save_link( $post_id, array $data ) {

			// the shortlink's domain (Shlink returns null for its default domain, so read it from the URL)
			$domain = wp_parse_url( $data['shortUrl'], PHP_URL_HOST );

			// get my short URL
			$shorturl = esc_url_raw( $data['shortUrl'] );

			// update the post meta, using the short code Shlink actually assigned
			update_post_meta( $post_id, self::META_URL, $shorturl );
			update_post_meta( $post_id, self::META_SHORTCODE, sanitize_text_field( $data['shortCode'] ) );
			update_post_meta( $post_id, self::META_DOMAIN, sanitize_text_field( $domain ) );

			// keep any count migrated from YOURLS
			add_post_meta( $post_id, self::META_CLICKS, 0, true );

			return $shorturl;
		}

		/**
		 * Create a shortlink for a post and store it.
		 *
		 * Sends the post's permalink and raw title (get_the_title adds HTML
		 * entities). Doesn't check for an existing link or a block; callers
		 * decide that. A successful create unblocks the post.
		 *
		 * @param  int   $post_id The post ID.
		 * @param  array $args    Optional Shlink fields, e.g. customSlug or findIfExists.
		 * @return string|WP_Error The stored short URL, or an error.
		 */
		public static function create_link( $post_id, array $args = array() ) {

			$args = wp_parse_args(
				$args,
				array(
					'title' => sanitize_text_field( get_post_field( 'post_title', $post_id ) ),
				)
			);

			$data = ShrinkALink_API::generate_shlink( get_permalink( $post_id ), $args );

			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$shorturl = self::save_link( $post_id, $data );
			self::set_blocked( $post_id, false );

			// any requested code has been used, or replaced by whatever this link got
			delete_post_meta( $post_id, self::META_REQUESTED );

			/**
			 * Fires after a shortlink is created and stored on a post.
			 *
			 * @param int    $post_id  The post ID.
			 * @param string $shorturl The stored short URL.
			 */
			do_action( 'shrinkalink_after_url_save', $post_id, $shorturl );

			return $shorturl;
		}


		/**
		 * Remove a post's shortlink, and block a new one being created automatically.
		 *
		 * Only clears the post meta; deleting the link from Shlink is up to the caller.
		 *
		 * @param  int $post_id The post ID.
		 * @return void
		 */
		public static function remove_link( $post_id ) {

			foreach ( array( self::META_URL, self::META_SHORTCODE, self::META_DOMAIN, self::META_CLICKS ) as $key ) {
				delete_post_meta( $post_id, $key );
			}

			self::set_blocked( $post_id );
		}

		/**
		 * Check whether a short code is free on Shlink for this post.
		 *
		 * The post's own current code counts as available. Expects a code already
		 * cleaned by ShrinkALink_API::prepare_shortcode().
		 *
		 * @param  int    $post_id   The post ID.
		 * @param  string $shortcode The cleaned short code.
		 * @return bool|WP_Error     True if free, false if taken, or an error if Shlink couldn't be asked.
		 */
		public static function is_shortcode_available( $post_id, $shortcode ) {

			// the post's own code isn't taken by someone else
			if ( self::get_post_meta( $post_id, self::META_SHORTCODE ) === $shortcode ) {
				return true;
			}

			$data = ShrinkALink_API::get_shlink( $shortcode );

			if ( ! is_wp_error( $data ) ) {
				return false;
			}

			// Shlink answers 404 for unused short codes
			$details = $data->get_error_data();

			if ( is_array( $details ) && 404 === (int) ( $details['status'] ?? 0 ) ) {
				return true;
			}

			return $data;
		}

		/**
		 * Get the post types that Shrinkalink creates links for.
		 *
		 * Posts and pages, plus public custom post types unless
		 * shrinkalink_include_public_post_types returns false.
		 *
		 * @link https://github.com/norcross/yourls-link-creator/blob/2d321c83677bf116bbe5c77d446209219e6fc3e3/lib/helper.php#L89 Modified from YOURLSCreator_Helper::get_yourls_types()
		 *
		 * @return array Post type names, keyed by name.
		 */
		public static function get_post_types() {

			// include public custom post types unless turned off
			$public = apply_filters( 'shrinkalink_include_public_post_types', true ) ? self::get_public_post_types() : array();

			// fetch any custom post types and merge with the built in
			$custom = apply_filters( 'shrinkalink_post_types', $public );
			$built  = apply_filters(
				'shrinkalink_built_in_post_types',
				array(
					'post' => 'post',
					'page' => 'page',
				)
			);

			// return the full array
			return ! empty( $custom ) ? array_merge( $custom, $built ) : $built;
		}

		/**
		 * Get the public custom post types.
		 *
		 * @link https://developer.wordpress.org/reference/functions/get_post_types/#comment-929 Modified from the get_post_types() docs
		 *
		 * @return array Post type names, keyed by name.
		 */
		public static function get_public_post_types() {

			// public and not built in, so posts, pages and attachments are excluded
			$args = array(
				'public'   => true,
				'_builtin' => false,
			);

			return get_post_types( $args, 'names', 'and' );
		}

		/**
		 * Get the post statuses links can be created for.
		 *
		 * Named get_statuses() rather than get_post_status() so it isn't mistaken
		 * for core's get_post_status().
		 *
		 * @link https://github.com/norcross/yourls-link-creator/blob/2d321c83677bf116bbe5c77d446209219e6fc3e3/lib/helper.php#L103 Copied from YOURLSCreator_Helper::get_yourls_status()
		 *
		 * @param  string $action 'save' for statuses allowed when saving (publish only),
		 *                        or '' for the default list (publish and future).
		 * @return array          Post statuses, filterable via shrinkalink_post_status.
		 */
		public static function get_statuses( $action = '' ) {

			// return only publish for saving
			if ( 'save' === $action ) {
				return apply_filters( 'shrinkalink_post_status', array( 'publish' ), $action );
			}

			// return the default
			return apply_filters( 'shrinkalink_post_status', array( 'publish', 'future' ), $action );
		}

		/**
		 * Check whether a URL is a plain permalink, like ?p=123 or ?page_id=123.
		 *
		 * Scheduled posts get one until they're published, so a link created
		 * early points at it.
		 *
		 * @param  string $url The URL to check.
		 * @return bool
		 */
		public static function is_plain_permalink( $url ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

			return ! empty( $query['p'] ) || ! empty( $query['page_id'] );
		}


		// end class
	}
	// end exists check
}
