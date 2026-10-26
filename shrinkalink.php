<?php
/**
 * Plugin Name:     Shrinkalink
 * Plugin URI:      https://brooke.codes/shrinkalink
 * Description:     Adds basic Shlink support for WordPress
 * Author:          Brooke.
 * Author URI:      https://broo.ke
 * Text Domain:     shrinkalink
 * Domain Path:     /languages
 * Version:         0.1.1
 * Requires at least: 7.1
 * Requires PHP:    8.4
 * License:         GPLv3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package         Shrinkalink
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

if ( ! defined( 'SHRINKALINK_BASE' ) ) {
	define( 'SHRINKALINK_BASE', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'SHRINKALINK_DIR' ) ) {
	define( 'SHRINKALINK_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'SHRINKALINK_VER' ) ) {
	define( 'SHRINKALINK_VER', '0.1.1' );
}

/**
 * Main plugin class: loads the text domain and the plugin's files.
 */
class ShrinkALink {

	/**
	 * The single instance of this class.
	 *
	 * @var ShrinkALink|false
	 */
	private static $instance = false;

	/**
	 * Hook text domain and file loading into plugins_loaded.
	 *
	 * @return void
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'load_files' ) );
	}

	/**
	 * Get the plugin instance, creating it on first call.
	 *
	 * @return ShrinkALink
	 */
	public static function getInstance() {

		// load an instance if not already initalized
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		// return the instance
		return self::$instance;
	}

	/**
	 * Load the plugin's files.
	 *
	 * Admin and front end files load only in their context; the API, publish,
	 * post meta and settings files load everywhere, since cron, REST and
	 * WP-CLI all need them.
	 *
	 * @return void
	 */
	public function load_files() {

		// Load our back end.
		if ( is_admin() ) {
			require_once SHRINKALINK_DIR . 'inc/admin/admin-columns.php';
			require_once SHRINKALINK_DIR . 'inc/admin/admin-metabox.php';
			require_once SHRINKALINK_DIR . 'inc/admin/admin-settings.php';
		}

		// Load our front end.
		if ( ! is_admin() ) {
			require_once SHRINKALINK_DIR . 'inc/frontend.php';
		}

		if ( class_exists( 'WP_CLI' ) ) {
			require_once SHRINKALINK_DIR . 'cli/shrinkalink.php';
		}

		require_once SHRINKALINK_DIR . 'inc/api.php';
		require_once SHRINKALINK_DIR . 'inc/cron.php';
		require_once SHRINKALINK_DIR . 'inc/publish.php';
		require_once SHRINKALINK_DIR . 'inc/post.php';
		require_once SHRINKALINK_DIR . 'inc/restapi.php';
		require_once SHRINKALINK_DIR . 'inc/settings.php';
	}

	/**
	 * Load the plugin's translations.
	 *
	 * @return void
	 */
	public function textdomain() {
		load_plugin_textdomain( 'shrinkalink', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
}


// clear the click sync event when the plugin is deactivated
register_deactivation_hook(
	__FILE__,
	function () {
		require_once SHRINKALINK_DIR . 'inc/cron.php';
		ShrinkALink_Cron::unschedule();
	}
);

// Instantiate our class.
ShrinkALink::getInstance();
