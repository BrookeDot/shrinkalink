<?php
/**
 * Shrinkalink Link Creator - REST API Module
 *
 * WordPress REST API for Shrinkalink
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
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( ShrinkALink_REST_API::class ) ) {
	/**
	 * REST routes for creating and refreshing a post's short link.
	 *
	 * Used by the row actions on the post list screens, and later the meta box.
	 * Loaded in every context, since REST requests are not is_admin().
	 */
	class ShrinkALink_REST_API {

		/**
		 * REST API version for all Shrinkalink routes.
		 *
		 * @var int
		 */
		const VERSION = 1;

		/**
		 * REST namespace for all Shrinkalink routes, e.g. shrinkalink/v1.
		 *
		 * @var string
		 */
		const NAMESPACE = 'shrinkalink/v' . self::VERSION;

		/**
		 * Register the routes once the REST API starts.
		 */
		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Register REST API routes.
		 *
		 * POST /shrinkalink/v1/posts/{id}/link     Create a short link for the post.
		 * POST /shrinkalink/v1/posts/{id}/refresh  Sync visits, and fix a plain-permalink long URL.
		 *
		 * @return void
		 */
		public function register_routes() {

			// both routes take a post ID and need permission to edit that post
			$args = array(
				'id' => array(
					'description'       => __( 'The post ID.', 'shrinkalink' ),
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			);

			register_rest_route(
				self::NAMESPACE,
				'/posts/(?P<id>\d+)/link',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_link' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => $args,
				)
			);

			register_rest_route(
				self::NAMESPACE,
				'/posts/(?P<id>\d+)/refresh',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh_link' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => $args,
				)
			);
		}

		/**
		 * Permission check for both routes: only users who can edit the post may change its short link.
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return bool
		 */
		public function can_edit_post( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', $request['id'] );
		}

		/**
		 * Create a short link for a post that doesn't have one.
		 *
		 * Allowed for scheduled posts too; their plain permalink is fixed later by refresh_link().
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return array|WP_Error           The post ID, short URL and clicks, or an error.
		 */
		public function create_link( WP_REST_Request $request ) {

			$post_id = $request['id'];
			$check   = self::check_post( $post_id );

			if ( is_wp_error( $check ) ) {
				return $check;
			}

			// one link per post; changing it is the meta box's job
			if ( false !== ShrinkALink_Post::get_post_meta( $post_id ) ) {
				return new WP_Error( 'shrinkalink_link_exists', __( 'This post already has a short link.', 'shrinkalink' ), array( 'status' => 409 ) );
			}

			// same request as publishing: the permalink and raw title (get_the_title adds HTML entities)
			$data = ShrinkALink_API::generate_shlink(
				get_permalink( $post_id ),
				array( 'title' => sanitize_text_field( get_post_field( 'post_title', $post_id ) ) )
			);

			if ( is_wp_error( $data ) ) {
				return self::shlink_error( $data );
			}

			$shorturl = ShrinkALink_Post::save_link( $post_id, $data );

			/** This action is documented in inc/publish.php */
			do_action( 'shrinkalink_after_url_save', $post_id, $shorturl );

			return self::response( $post_id );
		}

		/**
		 * Refresh a post's short link from Shlink.
		 *
		 * Copies the visit count into the clicks column, and re-points a link
		 * created while the post was scheduled (a plain ?p=123 long URL) once the
		 * post has a real permalink. Each part can be turned off with the
		 * shrinkalink_refresh_visits and shrinkalink_refresh_long_url filters.
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return array|WP_Error           The post ID, short URL and clicks, or an error.
		 */
		public function refresh_link( WP_REST_Request $request ) {

			$post_id   = $request['id'];
			$shortcode = ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE );

			if ( false === $shortcode ) {
				return new WP_Error( 'shrinkalink_no_link', __( 'This post does not have a short link yet.', 'shrinkalink' ), array( 'status' => 404 ) );
			}

			// look the code up on the domain it was created on
			$args = array( 'domain' => ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_DOMAIN, '' ) );
			$data = ShrinkALink_API::get_shlink( $shortcode, $args );

			if ( is_wp_error( $data ) ) {
				return self::shlink_error( $data );
			}

			// re-point links created while the post was scheduled, once it has a real permalink
			$permalink = get_permalink( $post_id );

			if ( apply_filters( 'shrinkalink_refresh_long_url', true, $post_id )
				&& ShrinkALink_Post::is_plain_permalink( $data['longUrl'] ?? '' )
				&& ! ShrinkALink_Post::is_plain_permalink( $permalink )
			) {
				$updated = ShrinkALink_API::update_shlink( $shortcode, array( 'longUrl' => $permalink ), $args );

				if ( is_wp_error( $updated ) ) {
					return self::shlink_error( $updated );
				}
			}

			// copy Shlink's visit count into the clicks column
			if ( apply_filters( 'shrinkalink_refresh_visits', true, $post_id ) ) {
				update_post_meta( $post_id, ShrinkALink_Post::META_CLICKS, absint( $data['visitsSummary']['total'] ?? 0 ) );
			}

			return self::response( $post_id );
		}

		/**
		 * Check that a post exists and is one Shrinkalink handles.
		 *
		 * @param  int $post_id The post ID.
		 * @return true|WP_Error True, or an error with a 404 or 400 status.
		 */
		private static function check_post( $post_id ) {

			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'shrinkalink_no_post', __( 'Post not found.', 'shrinkalink' ), array( 'status' => 404 ) );
			}

			if ( ! in_array( get_post_type( $post_id ), ShrinkALink_Post::get_post_types(), true )
				|| ! in_array( get_post_status( $post_id ), ShrinkALink_Post::get_statuses(), true )
			) {
				return new WP_Error( 'shrinkalink_unsupported_post', __( 'Short links can only be created for published or scheduled posts of a supported type.', 'shrinkalink' ), array( 'status' => 400 ) );
			}

			return true;
		}

		/**
		 * Give an error from the Shlink API an HTTP status, so REST doesn't report it as a 500.
		 *
		 * @param  WP_Error $error The error from ShrinkALink_API.
		 * @return WP_Error        The same error, with a 502 status (Shlink failed, not WordPress).
		 */
		private static function shlink_error( WP_Error $error ) {
			return new WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => 502 ) );
		}

		/**
		 * The response for both routes: the post's current short link details.
		 *
		 * @param  int $post_id The post ID.
		 * @return array
		 */
		private static function response( $post_id ) {
			return array(
				'post_id'  => $post_id,
				'shortUrl' => ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_URL, '' ),
				'clicks'   => absint( ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_CLICKS, 0 ) ),
			);
		}

		// end class
	}

	// end exists check
}

// Instantiate our class
new ShrinkALink_REST_API();
