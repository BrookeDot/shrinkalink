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
	 * REST routes for reading, creating, refreshing, deleting and blocking a post's short link.
	 *
	 * Used by the row actions on the post list screens and the editor panel.
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
		 * GET    /shrinkalink/v1/posts/{id}/link     Get the post's short link details.
		 * POST   /shrinkalink/v1/posts/{id}/link     Create a short link, or replace it with a custom short code.
		 * DELETE /shrinkalink/v1/posts/{id}/link     Remove the link, and optionally delete it from Shlink.
		 * POST   /shrinkalink/v1/posts/{id}/refresh  Sync visits, and fix a plain-permalink long URL.
		 * POST   /shrinkalink/v1/posts/{id}/block    Block or unblock the post from getting a link on save.
		 * GET    /shrinkalink/v1/posts/{id}/check    Check whether a short code is free on Shlink.
		 *
		 * Every route returns the same short link details; see response().
		 *
		 * @return void
		 */
		public function register_routes() {

			// every route takes a post ID
			$args = array(
				'id' => array(
					'description'       => __( 'The post ID.', 'shrinkalink' ),
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			);

			// creating also takes an optional short code, and whether to delete the old link
			$create_args = array_merge(
				$args,
				array(
					'shortcode' => array(
						'description' => __( 'A custom short code for the new link.', 'shrinkalink' ),
						'type'        => 'string',
						'default'     => '',
					),
					'replace'   => array(
						'description' => __( 'Delete the current link from Shlink once the new one is saved.', 'shrinkalink' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				)
			);

			// deleting can also remove the link from Shlink
			$delete_args = array_merge(
				$args,
				array(
					'shlink' => array(
						'description' => __( 'Also delete the link from Shlink.', 'shrinkalink' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				)
			);

			// blocking takes whether the post should be blocked
			$block_args = array_merge(
				$args,
				array(
					'blocked' => array(
						'description' => __( 'True to stop a link being created on save, false to allow it.', 'shrinkalink' ),
						'type'        => 'boolean',
						'required'    => true,
					),
				)
			);

			// checking takes the short code to look up
			$check_args = array_merge(
				$args,
				array(
					'shortcode' => array(
						'description' => __( 'The short code to check.', 'shrinkalink' ),
						'type'        => 'string',
						'required'    => true,
					),
				)
			);


			register_rest_route(
				self::NAMESPACE,
				'/posts/(?P<id>\d+)/link',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_link' ),
						'permission_callback' => array( $this, 'can_edit_post' ),
						'args'                => $args,
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'create_link' ),
						'permission_callback' => array( $this, 'can_change_link' ),
						'args'                => $create_args,
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this, 'delete_link' ),
						'permission_callback' => array( $this, 'can_change_link' ),
						'args'                => $delete_args,
					),
				)
			);

			register_rest_route(
				self::NAMESPACE,
				'/posts/(?P<id>\d+)/refresh',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh_link' ),
					'permission_callback' => array( $this, 'can_change_link' ),
					'args'                => $args,
				)
			);

			register_rest_route(
				self::NAMESPACE,
				'/posts/(?P<id>\d+)/block',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'block_link' ),
					'permission_callback' => array( $this, 'can_change_link' ),
					'args'                => $block_args,
				)
			);

			register_rest_route(
				self::NAMESPACE,
				'/posts/(?P<id>\d+)/check',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'check_shortcode' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => $check_args,
				)
			);

		}

		/**
		 * Get a post's current short link details.
		 *
		 * Doesn't run check_post(), so drafts get an empty shortUrl instead of an error.
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return array                    The post's short link details; see response().
		 */
		public function get_link( WP_REST_Request $request ) {
			return self::response( $request['id'] );
		}

		/**
		 * Create a short link for a post, or replace its current one with a custom short code.
		 *
		 * Shlink can't rename a short code, so replacing creates a new link. The old
		 * one keeps working unless replace is set, in which case it is deleted from
		 * Shlink after the new one is saved. Creating a link unblocks the post.
		 * Allowed for scheduled posts too; their plain permalink is fixed later by
		 * refresh_link().
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return array|WP_Error           The post's short link details (see response()), plus
		 *                                  oldDeleted when replace was set, or an error.
		 */
		public function create_link( WP_REST_Request $request ) {

			$post_id = $request['id'];
			$check   = self::check_post( $post_id );

			if ( is_wp_error( $check ) ) {
				return $check;
			}

			$current   = ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE );
			$requested = trim( (string) $request['shortcode'] );

			// one link per post, unless a different short code was asked for
			if ( false !== $current && ( '' === $requested || $requested === $current ) ) {
				return new WP_Error( 'shrinkalink_link_exists', __( 'This post already has this short link.', 'shrinkalink' ), array( 'status' => 409 ) );
			}

			$args = '' !== $requested ? array( 'customSlug' => $requested ) : array();

			// read the old domain before create_link() overwrites it
			$old_domain = ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_DOMAIN, '' );
			$shorturl   = ShrinkALink_Post::create_link( $post_id, $args );

			if ( is_wp_error( $shorturl ) ) {
				return self::shlink_error( $shorturl );
			}

			$response = self::response( $post_id );

			// only delete the old link once the new one is saved, and never the link Shlink just returned
			if ( false !== $current && $request['replace'] && $current !== $response['shortCode'] ) {
				$deleted                = ShrinkALink_API::delete_shlink( $current, array( 'domain' => $old_domain ) );
				$response['oldDeleted'] = ! is_wp_error( $deleted );
			}

			return $response;
		}

		/**
		 * Remove a post's short link, and block it from being recreated on save.
		 *
		 * With shlink set, also deletes the link from Shlink first. If that fails
		 * nothing is changed, so the post never points at a half-deleted link.
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return array|WP_Error           The post's (now empty) short link details, or an error.
		 */
		public function delete_link( WP_REST_Request $request ) {

			$post_id   = $request['id'];
			$shortcode = ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE );

			if ( false === $shortcode ) {
				return new WP_Error( 'shrinkalink_no_link', __( 'This post does not have a short link yet.', 'shrinkalink' ), array( 'status' => 404 ) );
			}

			if ( $request['shlink'] ) {
				$args    = array( 'domain' => ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_DOMAIN, '' ) );
				$deleted = ShrinkALink_API::delete_shlink( $shortcode, $args );

				if ( is_wp_error( $deleted ) ) {
					return self::shlink_error( $deleted );
				}
			}

			ShrinkALink_Post::remove_link( $post_id );

			return self::response( $post_id );
		}

		/**
		 * Block or unblock a post from getting a short link when it's saved.
		 *
		 * Doesn't touch an existing link; it only affects add_shlink_on_save()
		 * and wp shrinkalink generate-missing.
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return array                    The post's short link details; see response().
		 */
		public function block_link( WP_REST_Request $request ) {

			ShrinkALink_Post::set_blocked( $request['id'], $request['blocked'] );

			return self::response( $request['id'] );
		}


		/**
		 * Check whether a short code is free on Shlink.
		 *
		 * Only called when the panel's Check button is clicked, so typing never
		 * reaches Shlink. The post's own current code counts as available.
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return array|WP_Error           The cleaned short code and whether it's available, or an error.
		 */
		public function check_shortcode( WP_REST_Request $request ) {

			// clean it the same way generate_shlink() will
			$shortcode = ShrinkALink_API::prepare_shortcode( $request['shortcode'] );

			if ( '' === $shortcode ) {
				return new WP_Error( 'shrinkalink_invalid_slug', __( 'The short code is empty after removing invalid characters.', 'shrinkalink' ), array( 'status' => 400 ) );
			}

			$available = ShrinkALink_Post::is_shortcode_available( $request['id'], $shortcode );

			if ( is_wp_error( $available ) ) {
				return self::shlink_error( $available );
			}

			return array(
				'shortcode' => $shortcode,
				'available' => $available,
			);
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
		 * @return array|WP_Error           The post's short link details (see response()), or an error.
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
		 * The response for all routes: the post's current short link details.
		 *
		 * @param  int $post_id The post ID.
		 * @return array {
		 *     @type int    $post_id   The post ID.
		 *     @type string $shortUrl  The short URL, or '' without a link.
		 *     @type string $shortCode The short code, or '' without a link.
		 *     @type int    $clicks    The last synced visit count.
		 *     @type bool   $blocked   Whether a link is kept from being created on save.
		 * }
		 */
		private static function response( $post_id ) {
			return array(
				'post_id'   => $post_id,
				'shortUrl'  => ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_URL, '' ),
				'shortCode' => ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE, '' ),
				'clicks'    => absint( ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_CLICKS, 0 ) ),
				'blocked'   => ShrinkALink_Post::is_blocked( $post_id ),
			);
		}

		/**
		 * Permission check for reading: only users who can edit the post may see its short link details.
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return bool
		 */
		public function can_edit_post( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', $request['id'] );
		}

		/**
		 * Permission check for changing a post's short link: creating, refreshing, deleting or blocking it.
		 *
		 * Defaults to edit_post; change it with the shrinkalink_edit_cap filter.
		 *
		 * @param  WP_REST_Request $request The request.
		 * @return bool
		 */
		public function can_change_link( WP_REST_Request $request ) {
			return current_user_can( apply_filters( 'shrinkalink_edit_cap', 'edit_post', $request['id'] ), $request['id'] );
		}

		// end class
	}

	// end exists check
}

// Instantiate our class
new ShrinkALink_REST_API();
