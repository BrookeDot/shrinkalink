<?php
/**
 * Shrinkalink Link Creator - Publish Module
 *
 * Handles link creation on publishing.
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

if ( ! class_exists( ShrinkALink_Publish::class ) ) {
	/**
	 * Creates short URLs when posts are published.
	 */
	class ShrinkALink_Publish {

		/**
		 * Hook link creation into post saves.
		 */
		public function __construct() {
			add_action( 'init', array( $this, 'register_requested_meta' ), 20 );
			add_action( 'wp_after_insert_post', array( $this, 'add_shlink_on_save' ), 10, 4 );
			add_action( 'added_post_meta', array( $this, 'clear_taken_request' ), 10, 4 );
			add_action( 'updated_post_meta', array( $this, 'clear_taken_request' ), 10, 4 );
		}

		/**
		 * Register the requested short code meta for every supported post type.
		 *
		 * Lets the editor panel save a custom code with a draft; add_shlink_on_save()
		 * uses it when the post is published. Runs at priority 20 so custom post
		 * types registered on init are included.
		 *
		 * @return void
		 */
		public function register_requested_meta() {

			foreach ( ShrinkALink_Post::get_post_types() as $post_type ) {

				// core REST only exposes meta for post types that support custom-fields
				add_post_type_support( $post_type, 'custom-fields' );

				register_post_meta(
					$post_type,
					ShrinkALink_Post::META_REQUESTED,
					array(
						'show_in_rest'      => true,
						'single'            => true,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( ShrinkALink_API::class, 'prepare_shortcode' ),
						'auth_callback'     => array( $this, 'can_request_shortcode' ),
					)
				);
			}
		}

		/**
		 * Only users who may change a post's shortlink can request a code for it.
		 *
		 * Uses the same shrinkalink_edit_cap filter as the REST routes.
		 *
		 * @param  bool   $allowed  Whether the user can edit the meta key. Unused.
		 * @param  string $meta_key The meta key. Unused.
		 * @param  int    $post_id  The post ID.
		 * @return bool
		 */
		public function can_request_shortcode( $allowed, $meta_key, $post_id ) {
			return current_user_can( apply_filters( 'shrinkalink_edit_cap', 'edit_post', $post_id ), $post_id );
		}

		/**
		 * Clear a requested short code that's already in use on Shlink.
		 *
		 * Runs only when the request is added or changed, so Shlink is asked once
		 * per new code rather than on every save. If Shlink can't be reached the
		 * request is kept, and publishing falls back to a random code if needed.
		 *
		 * @param  int    $meta_id    The meta row ID. Unused.
		 * @param  int    $post_id    The post ID.
		 * @param  string $meta_key   The meta key.
		 * @param  mixed  $meta_value The new value, already cleaned by prepare_shortcode().
		 * @return void
		 */
		public function clear_taken_request( $meta_id, $post_id, $meta_key, $meta_value ) {

			if ( ShrinkALink_Post::META_REQUESTED !== $meta_key || '' === (string) $meta_value ) {
				return;
			}

			if ( false === ShrinkALink_Post::is_shortcode_available( $post_id, $meta_value ) ) {
				delete_post_meta( $post_id, ShrinkALink_Post::META_REQUESTED );
			}
		}

		/**
		 * Create a short URL when a post is published, if it doesn't have one.
		 *
		 * Runs on wp_after_insert_post, so it also covers scheduled posts going
		 * live through cron. Can be turned off with shrinkalink_create_on_save,
		 * or for scheduled posts only with shrinkalink_create_on_schedule.
		 *
		 * @link https://github.com/norcross/yourls-link-creator/blob/2d321c83677bf116bbe5c77d446209219e6fc3e3/lib/admin.php#L183 Modified from YOURLSCreator_Admin::yourls_on_save()
		 *
		 * @param  int           $post_id     The post ID.
		 * @param  \WP_Post      $post        The post as saved.
		 * @param  bool          $update      Whether this is an update of an existing post.
		 * @param  \WP_Post|null $post_before The post before this save, or null for a new post.
		 * @return void
		 */
		public function add_shlink_on_save( $post_id, $post, $update, $post_before ) {

			// bail without a post ID
			if ( empty( $post_id ) ) {
				return;
			}
			// bail if this is an import since it'll potentially mess up the process
			if ( ! empty( $_POST['import_id'] ) ) {
				return;
			}

			// run various checks to make sure we aren't doing anything weird
			if ( self::meta_save_check( $post_id ) ) {
				return;
			}

			// bail if we aren't working with a published post
			if ( ! in_array( get_post_status( $post_id ), ShrinkALink_Post::get_statuses( 'save' ), true ) ) {
				return;
			}

			// make sure we're working with an approved post type
			if ( ! in_array( get_post_type( $post_id ), ShrinkALink_Post::get_post_types(), true ) ) {
				return;
			}

			// bail if the Shlink URL or API key have not been entered
			if ( is_wp_error( ShrinkALink_Settings::check_configured() ) ) {
				return;
			}

			// bail if creating links on save has been turned off
			if ( false === apply_filters( 'shrinkalink_create_on_save', true, $post_id, $post ) ) {
				return;
			}

			// bail if the post is blocked from getting a shortlink automatically
			if ( ShrinkALink_Post::is_blocked( $post_id ) ) {
				return;
			}

			// scheduled posts going live can be turned off separately
			if ( $post_before && 'future' === $post_before->post_status
				&& false === apply_filters( 'shrinkalink_create_on_schedule', true, $post_id, $post )
			) {
				return;
			}

			// check for a link and bail if one exists
			if ( false !== ShrinkALink_Post::get_post_meta( $post_id ) ) {
				return;
			}
			// a custom code requested in the editor panel, already cleaned by prepare_shortcode()
			$requested = ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_REQUESTED, '' );

			// only send a slug when one was requested, otherwise Shlink generates one
			$args   = '' !== $requested ? array( 'customSlug' => $requested ) : array();
			$result = ShrinkALink_Post::create_link( $post_id, $args );

			// a taken or invalid code shouldn't leave the post without a link, so fall back to a random one
			if ( is_wp_error( $result ) && '' !== $requested ) {
				ShrinkALink_Post::create_link( $post_id );
			}
		}

		/**
		 * Check whether this save should be skipped.
		 *
		 * Skips autosaves. Cron (scheduled posts going live) and admin-ajax
		 * (Quick Edit) are allowed through.
		 *
		 * @link https://github.com/norcross/yourls-link-creator/blob/2d321c83677bf116bbe5c77d446209219e6fc3e3/lib/helper.php#L830 Modified from YOURLSCreator_Helper::meta_save_check()
		 *
		 * @param  int    $post_id The post ID. Currently unused.
		 * @param  string $cap     A capability. Currently unused.
		 * @return bool            True to skip, false to continue.
		 */
		public static function meta_save_check( $post_id = 0, $cap = 'edit_post' ) {

			// Bail out if running an autosave
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return true;
			}

			// return false
			return false;
		}


		// end class
	}

	// end exists check
}

// Instantiate our class
new ShrinkALink_Publish();
