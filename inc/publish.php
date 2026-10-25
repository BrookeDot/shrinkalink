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
			// add_action( 'save_post', array( $this, 'yourls_keyword' ) );
			add_action( 'wp_after_insert_post', array( $this, 'add_shlink_on_save' ), 10, 4 );
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

			// get my post URL and raw title (get_the_title adds HTML entities)
			$url   = get_permalink( $post_id );
			$title = get_post_field( 'post_title', $post_id );

			// and optional short code, unslashed since WordPress slashes $_POST (generate_shlink cleans it)
			$shortcode = ! empty( $_POST['shrinkalink-shortcode'] ) ? trim( wp_unslash( $_POST['shrinkalink-shortcode'] ) ) : '';

			// set my args for the API call (longUrl is set by generate_shlink)
			$args = array(
				'title' => sanitize_text_field( $title ),
			);

			// only send a slug when one was requested, otherwise Shlink generates one
			if ( '' !== $shortcode ) {
				$args['customSlug'] = $shortcode;
			}

			// make the API call
			$build = ShrinkALink_API::generate_shlink( $url, $args );

			// bail if an error was received
			if ( is_wp_error( $build ) ) {
				return;
			}

			// store the short URL, code and domain on the post
			$shorturl = ShrinkALink_Post::save_link( $post_id, $build );

			// do the action after saving
			do_action( 'shrinkalink_after_url_save', $post_id, $shorturl );
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
