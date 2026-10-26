<?php
/**
 * Shrinkalink Link Creator - Cron Module
 *
 * Syncs click counts from Shlink in batches
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( ShrinkALink_Cron::class ) ) {
	/**
	 * Keeps stored click counts in step with Shlink.
	 *
	 * Lists this site's links by tag in a few requests, rather than one request
	 * per post, and runs on a WP-Cron schedule (daily by default).
	 */
	class ShrinkALink_Cron {

		/**
		 * The WP-Cron event that runs the sync.
		 *
		 * @var string
		 */
		const EVENT = 'shrinkalink_sync_clicks';

		/**
		 * Hook the sync to its event, and keep the event scheduled.
		 */
		public function __construct() {
			add_action( self::EVENT, array( __CLASS__, 'sync_clicks' ) );
			add_action( 'init', array( __CLASS__, 'schedule' ) );
		}

		/**
		 * Schedule the sync, or reschedule it if the interval has changed.
		 *
		 * The interval comes from shrinkalink_sync_interval: any WP-Cron
		 * schedule name ('hourly', 'twicedaily', 'daily', ...), or false to
		 * turn the scheduled sync off.
		 *
		 * @return void
		 */
		public static function schedule() {

			$interval = apply_filters( 'shrinkalink_sync_interval', 'daily' );
			$current  = wp_get_schedule( self::EVENT );

			// already scheduled as wanted
			if ( $current === $interval ) {
				return;
			}

			// interval changed or turned off, so clear the old event first
			if ( false !== $current ) {
				self::unschedule();
			}

			// only schedule known intervals, so a typo can't create a broken event
			if ( false !== $interval && array_key_exists( $interval, wp_get_schedules() ) ) {
				wp_schedule_event( time(), $interval, self::EVENT );
			}
		}

		/**
		 * Remove the scheduled sync, e.g. on plugin deactivation.
		 *
		 * @return void
		 */
		public static function unschedule() {
			wp_clear_scheduled_hook( self::EVENT );
		}

		/**
		 * Update stored click counts from Shlink, in one batch.
		 *
		 * Lists this site's links by tag (a few requests in total), then matches
		 * them to posts by short code and domain, so links from other sites on a
		 * shared Shlink instance are never touched.
		 *
		 * @return array|WP_Error Counts: posts checked, updated, and not found in Shlink; or an error.
		 */
		public static function sync_clicks() {

			$links = ShrinkALink_API::list_shlinks( array( 'tags' => ShrinkALink_API::get_site_tags() ) );

			if ( is_wp_error( $links ) ) {
				return $links;
			}

			// visit counts keyed by "domain/code", the same pair save_link() stores
			$visits = array();

			foreach ( $links as $link ) {
				$key            = wp_parse_url( $link['shortUrl'], PHP_URL_HOST ) . '/' . $link['shortCode'];
				$visits[ $key ] = absint( $link['visitsSummary']['total'] ?? 0 );
			}

			$result = array(
				'checked' => 0,
				'updated' => 0,
				'missing' => 0,
			);

			foreach ( self::get_linked_posts() as $row ) {
				++$result['checked'];

				$key = $row->domain . '/' . $row->code;

				// untagged (older) links and deleted links aren't in the list
				if ( ! isset( $visits[ $key ] ) ) {
					++$result['missing'];
					continue;
				}

				// only write when the count has changed
				if ( (int) $row->clicks !== $visits[ $key ] ) {
					update_post_meta( $row->post_id, ShrinkALink_Post::META_CLICKS, $visits[ $key ] );
					++$result['updated'];
				}
			}

			return $result;
		}

		/**
		 * Get every post with a shortlink, with its code, domain and stored clicks.
		 *
		 * Works through the posts in batches (200 by default, filterable via
		 * shrinkalink_sync_batch_size), loading each batch's meta in one query.
		 *
		 * @return \Generator Rows with post_id, code, domain and clicks.
		 */
		private static function get_linked_posts() {

			$batch = max( 1, (int) apply_filters( 'shrinkalink_sync_batch_size', 200 ) );
			$page  = 1;

			do {
				// IDs of posts with a short code, in a stable order so paging can't skip any
				$post_ids = get_posts(
					array(
						'post_type'              => array_values( ShrinkALink_Post::get_post_types() ),
						'post_status'            => array_values( ShrinkALink_Post::get_statuses() ),
						'posts_per_page'         => $batch,
						'paged'                  => $page,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'fields'                 => 'ids',
						'meta_key'               => ShrinkALink_Post::META_SHORTCODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
					)
				);

				// load this batch's meta in one query
				update_meta_cache( 'post', $post_ids );

				foreach ( $post_ids as $post_id ) {
					yield (object) array(
						'post_id' => $post_id,
						'code'    => get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE, true ),
						'domain'  => get_post_meta( $post_id, ShrinkALink_Post::META_DOMAIN, true ),
						'clicks'  => get_post_meta( $post_id, ShrinkALink_Post::META_CLICKS, true ),
					);
				}

				++$page;

				// a short batch means that was the last one
			} while ( count( $post_ids ) === $batch );
		}



		// end class
	}

	// end exists check
}

// Instantiate our class
new ShrinkALink_Cron();
