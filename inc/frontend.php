<?php
/**
 * Shrinkalink Link Creator - Front End Module
 *
 * Contains front end functions
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

if ( ! class_exists( ShrinkALink_Frontend::class ) ) {

	/**
	 * Swaps WordPress's default shortlink for the stored Shlink URL.
	 */
	class ShrinkALink_Frontend {

		/**
		 * Hook into the shortlink lookup.
		 */
		/**
		 * Constructor.
		 */
		public function __construct() {
			add_filter( 'pre_get_shortlink', array( $this, 'shortlink_meta' ), 10, 4 );
			add_action( 'admin_bar_menu', array( $this, 'admin_bar_shortlink' ), 90 );
		}

		/**
		 * Return the stored Shlink URL in place of the default shortlink.
		 *
		 * Covers everywhere wp_get_shortlink() is used: the <link rel="shortlink">
		 * tag, the Link HTTP header, the admin bar and the_shortlink().
		 *
		 * @param  string|false $shortlink   The shortlink so far; false lets core build its own.
		 * @param  int          $id          A post ID, or 0 for the current post.
		 * @param  string       $context     'post' for a specific post, 'query' for the current page.
		 * @param  bool         $allow_slugs Whether slugs are allowed. Unused.
		 * @return string|false              The Shlink URL, or $shortlink unchanged.
		 */
		public function shortlink_meta( $shortlink, $id = 0, $context = 'post', $allow_slugs = true ) {

			// check options to see if it's enabled
			if ( false === apply_filters( 'shrinkalink_shortlinks', true ) ) {
				return $shortlink;
			}

			// 'query' means the current page, which only has a post on singular views
			if ( 'query' === $context ) {
				if ( ! is_singular() ) {
					return $shortlink;
				}
				$id = get_queried_object_id();
			}

			// use the passed ID, falling back to the current post
			$post_id = ! empty( $id ) ? absint( $id ) : get_the_ID();

			// bail without a post ID
			if ( empty( $post_id ) ) {
				return $shortlink;
			}

			// check existing postmeta for a stored link.
			$stored = ShrinkALink_Post::get_post_meta( $post_id );

			return false !== $stored ? $stored : $shortlink;
		}


		/**
		 * Add core's shortlink item to the admin bar, if turned on.
		 *
		 * Core still has the item but no longer adds it. Off by default; turn it
		 * on with the shrinkalink_admin_bar_shortlink filter.
		 *
		 * @param  \WP_Admin_Bar $wp_admin_bar The admin bar.
		 * @return void
		 */
		public function admin_bar_shortlink( $wp_admin_bar ) {
			if ( apply_filters( 'shrinkalink_admin_bar_shortlink', false ) ) {
				wp_admin_bar_shortlink_menu( $wp_admin_bar );
			}
		}

		// end class
	}

	// end exists check
}

// Instantiate our class
new ShrinkALink_Frontend();
