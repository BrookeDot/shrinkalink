<?php
/**
 * Shrinkalink Link Creator - Admin Columns Module
 *
 * Adds the clicks column and shortlink row actions to post list screens
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

if ( ! class_exists( ShrinkALink_AdminColumns::class ) ) {
	/**
	 * The clicks column and Create/Refresh Shlink row actions on post list screens.
	 *
	 * Works for posts, pages and supported custom post types. The row actions
	 * call the REST routes in ShrinkALink_REST_API through js/admin-columns.js.
	 */
	class ShrinkALink_AdminColumns {

		/**
		 * Hook the row actions, column setup and script.
		 */
		public function __construct() {
			add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
			add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
			add_action( 'load-edit.php', array( $this, 'add_column_hooks' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		}

		/**
		 * Load the row action script on post list screens.
		 *
		 * @param  string $hook The current admin page's hook suffix.
		 * @return void
		 */
		public function enqueue_scripts( $hook ) {

			if ( 'edit.php' !== $hook ) {
				return;
			}

			wp_enqueue_script(
				'shrinkalink-admin-columns',
				plugins_url( 'js/admin-columns.js', SHRINKALINK_DIR . 'shrinkalink.php' ),
				array( 'wp-api-fetch', 'wp-i18n' ),
				SHRINKALINK_VER,
				true
			);

			wp_set_script_translations( 'shrinkalink-admin-columns', 'shrinkalink' );
		}


		/**
		 * Add the clicks column hooks for the post type being listed.
		 *
		 * Runs on load-edit.php, once post types are registered, so custom post
		 * types work the same as posts and pages.
		 *
		 * @return void
		 */
		public function add_column_hooks() {

			$post_type = get_current_screen()->post_type;

			// only for types Shrinkalink creates links for
			if ( ! in_array( $post_type, ShrinkALink_Post::get_post_types(), true ) ) {
				return;
			}

			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'register_columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'display_columns' ), 10, 2 );
		}
		/**
		 * Add the shortlink and clicks columns.
		 *
		 * Either can be turned off with the shrinkalink_show_url_column or
		 * shrinkalink_show_clicks_column filter, which also get the post type.
		 *
		 * @param  array $columns The list table's columns, keyed by ID.
		 * @return array          The columns with shrinkalink-url and shrinkalink-clicks added.
		 */
		public function register_columns( $columns ) {

			$post_type = get_current_screen()->post_type;

			// the short URL itself, as a text column
			if ( apply_filters( 'shrinkalink_show_url_column', true, $post_type ) ) {
				$columns['shrinkalink-url'] = __( 'Shortlink', 'shrinkalink' );
			}

			// icon with a hover tooltip, plus a text label for screen readers like the Comments column
			if ( apply_filters( 'shrinkalink_show_clicks_column', true, $post_type ) ) {
				$label = __( 'Shortlink clicks', 'shrinkalink' );

				$columns['shrinkalink-clicks'] = '<span title="' . esc_attr( $label ) . '">' . self::get_icon() . '</span>'
				. '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
			}

			return $columns;
		}



		/**
		 * Output the shortlink and click count columns.
		 *
		 * The click count is the one stored on the post, which Refresh Shlink updates from Shlink.
		 *
		 * @param  string $column  The column ID being output.
		 * @param  int    $post_id The post ID for the row.
		 * @return void
		 */
		public function display_columns( $column, $post_id ) {

			// start my column output
			switch ( $column ) {
				case 'shrinkalink-url':
					$url = ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_URL );

					if ( false === $url ) {
						// same placeholder core uses for empty cells
						echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No shortlink', 'shrinkalink' ) . '</span>';
					} else {
						printf( '<a href="%1$s" rel="noopener">%2$s</a>', esc_url( $url ), esc_html( $url ) );
					}

					break;

				case 'shrinkalink-clicks':
					echo '<span>' . absint( ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_CLICKS, '0' ) ) . '</span>';

					break;

				// end all case breaks
			}
		}
		/**
		 * Add a Create Shlink or Refresh Shlink link to the row actions.
		 *
		 * Create for published or scheduled posts without a shortlink, Refresh for
		 * posts that have one. Hooked to both post_row_actions and page_row_actions,
		 * so it covers hierarchical and non-hierarchical post types.
		 *
		 * @param  array    $actions The row's action links, keyed by ID.
		 * @param  \WP_Post $post    The post for the row.
		 * @return array             The actions, with ours added when the post is supported.
		 */
		public function row_action( $actions, $post ) {

			// make sure we're working with an approved post type
			if ( ! in_array( $post->post_type, ShrinkALink_Post::get_post_types(), true ) ) {
				return $actions;
			}

			// bail if we aren't working with a published or scheduled post
			if ( ! in_array( get_post_status( $post->ID ), ShrinkALink_Post::get_statuses(), true ) ) {
				return $actions;
			}

			// check for existing and add our new action
			if ( false === ShrinkALink_Post::get_post_meta( $post->ID ) ) {
				$actions['shrinkalink-create'] = self::row_action_link( $post->ID, 'link', __( 'Create Shlink', 'shrinkalink' ) );
			} else {
				$actions['shrinkalink-refresh'] = self::row_action_link( $post->ID, 'refresh', __( 'Refresh Shlink', 'shrinkalink' ) );
			}

			// return the actions
			return $actions;
		}

		/**
		 * Build a row action link that calls a Shrinkalink REST route.
		 *
		 * @param  int    $post_id The post ID.
		 * @param  string $route   'link' to create, or 'refresh'.
		 * @param  string $label   The link text.
		 * @return string          The link markup.
		 */
		private static function row_action_link( $post_id, $route, $label ) {
			return sprintf(
				'<a href="#" class="shrinkalink-row-link" data-post-id="%d" data-route="%s">%s</a>',
				absint( $post_id ),
				esc_attr( $route ),
				esc_html( $label )
			);
		}

		/**
		 * The Shrinkalink icon as inline SVG, coloured by the surrounding text.
		 *
		 * @return string SVG markup.
		 */
		public static function get_icon() {
			return '<svg class="shrinkalink-icon" width="20" height="20" viewBox="0 0 512 512" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><g fill="currentColor">'
				. '<path d="M23.71 85.08C17.22 49.81 49.44 14.86 85.08 18.12c33.75 1.09 60.64 35.21 54.37 68.25 16.19 15.93 31.87 32.46 48.42 47.99 10.45-22.63 20.97-45.24 31.7-67.74 6.48-12.78 23.9-17.88 36.16-10.35 8.03 5.83 14.61 13.42 21.52 20.48 9.03 9.86 8.47 26.14-.94 35.56-52.93 53.06-105.93 106.06-158.96 159.03-9.63 9.65-26.34 9.91-36.24.52-6.72-6.92-14.29-13.17-19.87-21.09-7.52-12.25-2.39-29.7 10.4-36.15 22.47-10.75 45.08-21.24 67.69-31.71-15.52-16.55-32.03-32.23-47.96-48.42-31.17 5.79-64-17.71-67.66-49.41Z"/>'
				. '<path d="M205.21 201.23c20.11-19.87 55.67-20.12 75.93-.37 18.11 17.89 36.23 35.79 53.96 54.07 21.63 23.08 16.91 63.77-9.07 81.63-5.96-6.09-12.3-11.91-17.91-18.28 15.74-8.89 20.64-32.1 8.51-45.89-14.9-15.44-30.33-30.36-45.39-45.64-6.75-7.1-14.44-14.75-24.87-15.23-21.72-2.88-40.85 21.84-31.88 42.06 6.6 13.23 19.73 21.54 29.13 32.66-3.19 9.72-5.53 19.89-5.33 30.22-12.74-12.17-25.13-24.73-37.4-37.37-19.92-21.52-17.79-58.64 4.32-77.86Z"/>'
				. '<path d="M273.9 352.07c-21.62-23.08-16.92-63.76 9.06-81.61 5.97 6.08 12.3 11.9 17.92 18.26-15.74 8.9-20.65 32.1-8.5 45.89 14.89 15.44 30.32 30.35 45.37 45.64 6.76 7.1 14.45 14.75 24.89 15.23 21.71 2.89 40.85-21.84 31.87-42.06-6.59-13.24-19.73-21.54-29.13-32.66 3.18-9.72 5.53-19.9 5.33-30.22 12.74 12.16 25.13 24.73 37.4 37.37 19.92 21.52 17.79 58.64-4.33 77.86-20.1 19.87-55.65 20.12-75.92.37-18.11-17.89-36.26-35.77-53.96-54.07Z"/>'
				. '<path d="M422.11 403.83c9.85-9.76 19.49-19.77 29.55-29.32 9.24 9.23 20.23 18.19 23.23 31.6 5.27 23.86 9.19 48.02 13.87 72.01 1.24 5.29-4.29 10.17-9.41 8.51-24.69-5.11-49.8-8.51-74.21-14.79-11.97-3.87-19.94-14.09-28.59-22.57 9.84-9.78 19.58-19.67 29.51-29.36 10.31 13.54 29.68-5.91 16.05-16.08Z"/>'
				. '</g></svg>';
		}
	}
}
		new ShrinkALink_AdminColumns();
