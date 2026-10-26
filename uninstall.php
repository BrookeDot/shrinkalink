<?php
/**
 * Shrinkalink Link Creator - Uninstall
 *
 * Removes Shrinkalink's settings, API key and scheduled sync when the plugin is deleted
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

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// only defines the class, so it's safe to load here for the meta key constants
require_once __DIR__ . '/inc/post.php';

/**
 * Remove Shrinkalink's data from the current site.
 *
 * Post meta is kept by default, so reinstalling brings back every post's
 * shortlink. Return true from shrinkalink_uninstall_delete_meta to remove it
 * too. The plugin isn't loaded during uninstall, so that filter has to be
 * added somewhere else, like a mu-plugin or the theme.
 *
 * @return void
 */
function uninstall_site() {

	delete_option( 'shrinkalink_settings' );

	// the API key lives outside the option from WordPress 7.2
	if ( function_exists( 'wp_delete_secret' ) ) {
		wp_delete_secret( 'shrinkalink/api-key' );
	}

	// ShrinkALink_Cron::EVENT; cron.php isn't loaded since it hooks itself in on load
	wp_clear_scheduled_hook( 'shrinkalink_sync_clicks' );

	// keep each post's shortlink unless asked to remove it
	if ( true !== apply_filters( 'shrinkalink_uninstall_delete_meta', false ) ) {
		return;
	}

	$keys = array(
		ShrinkALink_Post::META_URL,
		ShrinkALink_Post::META_SHORTCODE,
		ShrinkALink_Post::META_DOMAIN,
		ShrinkALink_Post::META_CLICKS,
		ShrinkALink_Post::META_BLOCKED,
		ShrinkALink_Post::META_REQUESTED,
	);

	foreach ( $keys as $key ) {
		delete_post_meta_by_key( $key );
	}
}

// settings are per site, so clean up every site on a network
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $shrinkalink_site_id ) {
		switch_to_blog( $shrinkalink_site_id );
		uninstall_site();
		restore_current_blog();
	}
} else {
	uninstall_site();
}
