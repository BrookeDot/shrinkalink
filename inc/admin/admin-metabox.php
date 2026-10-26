<?php
/**
 * Shrinkalink Link Creator - Admin Metabox Module
 *
 * Loads the Shrinkalink panel in the block editor
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

if ( ! class_exists( ShrinkALink_AdminMetabox::class ) ) {
	/**
	 * The Shrinkalink panel in the block editor's post sidebar.
	 *
	 * The panel (js/admin-metabox.js) reads and changes the short link through
	 * the routes in ShrinkALink_REST_API, so no post meta is exposed to core REST.
	 */
	class ShrinkALink_AdminMetabox {

		/**
		 * Hook the editor script.
		 */
		public function __construct() {
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_scripts' ) );
		}

		/**
		 * Load the Shrinkalink panel script in the block editor.
		 *
		 * Only loads for supported post types, since this hook also fires in
		 * the site and widget editors.
		 *
		 * @return void
		 */
		public function enqueue_block_scripts() {

			$screen = get_current_screen();

			// bail outside the post editor or for unsupported post types
			if ( ! $screen || ! in_array( $screen->post_type, ShrinkALink_Post::get_post_types(), true ) ) {
				return;
			}

			wp_enqueue_script(
				'shrinkalink-admin-metabox',
				plugins_url( 'js/admin-metabox.js', SHRINKALINK_DIR . 'shrinkalink.php' ),
				array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-url' ),
				SHRINKALINK_VER,
				true
			);
	wp_add_inline_script(
				'shrinkalink-admin-metabox',
				'window.shrinkalinkSettings = ' . wp_json_encode( array( 'statuses' => array_values( ShrinkALink_Post::get_statuses() ) ) ) . ';',
				'before'
			);
			wp_set_script_translations( 'shrinkalink-admin-metabox', 'shrinkalink' );
		}

		// end class
	}

	// end exists check
}

// Instantiate our class
new ShrinkALink_AdminMetabox();
