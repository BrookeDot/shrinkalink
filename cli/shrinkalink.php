<?php
/**
 * Shrinkalink Link Creator - CLI Module
 *
 * CLI commands for Shrinkalink
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

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( ShrinkALink_Commands::class ) ) {
	/**
	 * Manage Shrinkalink settings and Shlink short URLs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shrinkalink status
	 *     wp shrinkalink generate-url https://example.com
	 */
	class ShrinkALink_Commands {

		/**
		 * Set the Shlink instance URL.
		 *
		 * ## OPTIONS
		 *
		 * <url>
		 * : The https URL of your Shlink install.
		 *
		 * [--skip-check]
		 * : Save the URL without checking that Shlink responds.
		 *
		 * ## EXAMPLES
		 *
		 *     wp shrinkalink set-url https://example.com
		 *     wp shrinkalink set-url https://example.com --skip-check
		 *
		 * @subcommand set-url
		 */
		public function set_url( $args, $assoc_args ) {
			$url = ShrinkALink_Settings::validate_instance_url( $args[0] );

			if ( is_wp_error( $url ) ) {
				WP_CLI::error( $url->get_error_message() );
			}

			// make sure it's really a Shlink server before saving
			if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-check', false ) ) {
				$health = ShrinkALink_API::health_check( $url );

				if ( is_wp_error( $health ) ) {
					WP_CLI::error( $health->get_error_message() . ' Use --skip-check to save it anyway.' );
				}

				WP_CLI::log( WP_CLI::colorize( '%cFound Shlink:%n' . ' Version ' . ( $health['version'] ?? '(unknown version)' ) ) );
			}

			// only send the URL; sanitize_settings() keeps the saved key
			update_option( 'shrinkalink_settings', array( 'instance_url' => $url ) );

			WP_CLI::success( "Shlink URL set to {$url}." );
		}

		/**
		 * Show the saved Shlink instance URL.
		 *
		 * ## EXAMPLES
		 *
		 *     wp shrinkalink get-url
		 *
		 * @subcommand get-url
		 */
		public function get_url( $args, $assoc_args ) {

			$url = ShrinkALink_Settings::get_instance_url();

			if ( '' === $url ) {
				WP_CLI::error( 'No Shlink URL is set. Use wp shrinkalink set-url to add one.' );
			}

			// plain output so it can be used in scripts
			WP_CLI::line( $url );
		}


		/**
		 * Set the Shlink API key. Prompts for it, or reads it from STDIN.
		 *
		 * ## EXAMPLES
		 *
		 *     wp shrinkalink set-key
		 *     wp shrinkalink set-key < key.txt
		 *
		 * @subcommand set-key
		 */
		public function set_key( $args, $assoc_args ) {

			// piped in, or typed at a hidden prompt
			$key = function_exists( 'posix_isatty' ) && posix_isatty( STDIN )
			? \cli\prompt( 'Shlink API key', false, ': ', true )
			: trim( file_get_contents( 'php://stdin' ) );

			if ( '' === trim( (string) $key ) ) {
				WP_CLI::error( 'No API key provided.' );
			}

			update_option( 'shrinkalink_settings', array( 'api_key' => $key ) );

			WP_CLI::success(
				ShrinkALink_Settings::api_key_in_secrets()
				? 'API key saved to WordPress Secrets.'
				: 'API key saved (unencrypted until WordPress 7.2).'
			);
		}


		/**
		 * Show whether Shrinkalink is configured, without revealing the key.
		 *
		 * Checks that Shlink responds, and shows where the API key is stored.
		 *
		 * ## EXAMPLES
		 *
		 *     wp shrinkalink status
		 */
		public function status( $args, $assoc_args ) {

			$url = ShrinkALink_Settings::get_instance_url();

			if ( '' !== $url ) {
				$health = ShrinkALink_API::health_check();

				if ( is_wp_error( $health ) ) {
					self::status_line( 'Shlink', $health->get_error_message(), '%r' );
				} else {
					self::status_line( 'Shlink', 'healthy, version ' . ( $health['version'] ?? '?' ), '%g' );
				}
			}

			if ( '' !== $url ) {
				self::status_line( 'URL', $url, '%g' );
			} else {
				self::status_line( 'URL', '(not set)', '%r' );
			}

			if ( ShrinkALink_Settings::api_key_in_secrets() ) {
				self::status_line( 'API key', 'stored in WordPress Secrets', '%g' );
			} elseif ( ShrinkALink_Settings::has_api_key() ) {
				self::status_line( 'API key', 'stored unencrypted in the settings', '%y' );
			} else {
				self::status_line( 'API key', '(not set)', '%r' );
			}

			$configured = ShrinkALink_Settings::check_configured();

			if ( is_wp_error( $configured ) ) {
				WP_CLI::warning( $configured->get_error_message() );
			} else {
				WP_CLI::success( 'Ready to create shortlinks.' );
			}
		}

		/**
		 * Print a status line with a cyan label and a coloured value
		 *
		 * @param string $label The label, e.g. 'URL'.
		 * @param string $value The value to show.
		 * @param string $color A colorize() code for the value: %g good, %y warning, %r problem.
		 */
		private static function status_line( $label, $value, $color ) {

			// escape % so values like URLs can't be read as colour codes
			$value = str_replace( '%', '%%', $value );

			WP_CLI::line( WP_CLI::colorize( "%c{$label}:%n {$color}{$value}%n" ) );
		}


		/**
		 * Generate a short URL using Shlink.
		 *
		 * ## OPTIONS
		 *
		 * <url>
		 * : The URL to shorten with Shlink.
		 *
		 * [--slug=<slug>]
		 * : A custom short code. Shlink generates one when omitted.
		 *
		 * [--title=<title>]
		 * : A title to store with the short URL in Shlink.
		 *
		 * [--porcelain]
		 * : Output just the short URL.
		 *
		 * [--field=<field>]
		 * : Show just one field of the response, e.g. shortCode.
		 *
		 * [--format=<format>]
		 * : Show the full response in this format instead of a success message.
		 * ---
		 * options:
		 *   - table
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp shrinkalink generate-url https://example.com
		 *     wp shrinkalink generate-url https://example.com --slug=example --title="Example"
		 *     wp shrinkalink generate-url https://example.com --porcelain
		 *     wp shrinkalink generate-url https://example.com --format=json
		 *
		 * @subcommand generate-url
		 */
		public function generate_url( $args, $assoc_args ) {

			$long_url = trim( $args[0] );
			$scheme   = strtolower( (string) wp_parse_url( $long_url, PHP_URL_SCHEME ) );

			// Shlink rejects anything that isn't a full http(s) URL, so catch it here first
			if ( false === filter_var( $long_url, FILTER_VALIDATE_URL ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				WP_CLI::error( 'The URL to shorten must be a full http or https URL.' );
			}

			$request = array();

			// generate_shlink() cleans the slug and errors if nothing is left
			if ( isset( $assoc_args['slug'] ) ) {
				$request['customSlug'] = $assoc_args['slug'];
			}

			if ( isset( $assoc_args['title'] ) ) {
				$request['title'] = sanitize_text_field( $assoc_args['title'] );
			}

			// returns the full Shlink response, or a WP_Error (including missing settings)
			$data = ShrinkALink_API::generate_shlink( $long_url, $request );

			if ( is_wp_error( $data ) ) {
				WP_CLI::error( $data->get_error_message() );
			}

			if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
				WP_CLI::line( $data['shortUrl'] );
				return;
			}

			// full response only when asked for, otherwise a one-line message
			if ( isset( $assoc_args['format'] ) || isset( $assoc_args['field'] ) ) {
				self::display_short_url( $data, $assoc_args );
				return;
			}

			WP_CLI::success( "Short URL: {$data['shortUrl']}" );
		}

		/**
		 * Show the details of a short URL from Shlink.
		 *
		 * ## OPTIONS
		 *
		 * <short>
		 * : The short code, or the full short URL.
		 *
		 * [--field=<field>]
		 * : Show just one field, e.g. longUrl.
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp shrinkalink get-short-url abc123
		 *     wp shrinkalink get-short-url https://example.com/abc123 --field=longUrl
		 *
		 * @subcommand get-short-url
		 */
		public function get_short_url( $args, $assoc_args ) {

			list( $shortcode, $request ) = self::parse_short_url( $args[0] );

			$data = ShrinkALink_API::get_shlink( $shortcode, $request );

			if ( is_wp_error( $data ) ) {
				WP_CLI::error( $data->get_error_message() );
			}

			self::display_short_url( $data, $assoc_args );
		}

		/**
		 * Display a Shlink short URL response with WP-CLI's formatter.
		 *
		 * @param  array $data       A short URL response from the Shlink API.
		 * @param  array $assoc_args The command's options, for --field and --format.
		 * @return void
		 */
		private static function display_short_url( $data, $assoc_args ) {

			// flatten the parts worth showing; tags is a list, visits is nested
			$item = array(
				'shortUrl'    => $data['shortUrl'],
				'shortCode'   => $data['shortCode'] ?? '',
				'longUrl'     => $data['longUrl'] ?? '',
				'title'       => $data['title'] ?? '',
				'tags'        => implode( ', ', $data['tags'] ?? array() ),
				'dateCreated' => $data['dateCreated'] ?? '',
				'visits'      => $data['visitsSummary']['total'] ?? 0,
			);

			// the formatter needs a format, so default to a table
			$assoc_args = wp_parse_args( $assoc_args, array( 'format' => 'table' ) );

			$formatter = new \WP_CLI\Formatter( $assoc_args, array_keys( $item ) );
			$formatter->display_item( $item );
		}

		/**
		 * Remove a short URL from Shlink.
		 *
		 * ## OPTIONS
		 *
		 * <short>
		 * : The short code, or the full short URL.
		 *
		 * [--yes]
		 * : Skip the confirmation prompt.
		 *
		 * ## EXAMPLES
		 *
		 *     wp shrinkalink remove-url abc123
		 *     wp shrinkalink remove-url https://example.com/abc123 --yes
		 *
		 * @subcommand remove-url
		 * @alias delete-url
		 */
		public function remove_url( $args, $assoc_args ) {

			list( $shortcode, $request ) = self::parse_short_url( $args[0] );

			// deleting can't be undone, so ask unless --yes was passed
			WP_CLI::confirm( "Delete the short URL \"{$shortcode}\" from Shlink? This cannot be undone.", $assoc_args );

			$deleted = ShrinkALink_API::delete_shlink( $shortcode, $request );

			if ( is_wp_error( $deleted ) ) {
				WP_CLI::error( $deleted->get_error_message() );
			}

			WP_CLI::success( "Deleted short URL \"{$shortcode}\"." );
		}

		/**
		 * Replace a post's shortlink with a new one.
		 *
		 * Shlink can't rename a short code, so this creates a new link and stores
		 * it on the post. The old link keeps working unless --delete is passed.
		 * A post without a shortlink just gets one.
		 *
		 * ## OPTIONS
		 *
		 * <post_id>
		 * : The post to update.
		 *
		 * [--slug=<slug>]
		 * : A custom short code for the new link. Shlink generates one when omitted.
		 *
		 * [--delete]
		 * : Delete the old link from Shlink once the new one is saved.
		 *
		 * [--yes]
		 * : Skip the confirmation prompt for --delete.
		 *
		 * ## EXAMPLES
		 *
		 *     wp shrinkalink update-url 123 --slug=hello
		 *     wp shrinkalink update-url 123 --slug=hello --delete --yes
		 *
		 * @subcommand update-url
		 */
		public function update_url( $args, $assoc_args ) {

			$post_id = absint( $args[0] );

			if ( ! get_post( $post_id ) ) {
				WP_CLI::error( "Post #{$post_id} not found." );
			}

			if ( ! in_array( get_post_type( $post_id ), ShrinkALink_Post::get_post_types(), true )
				|| ! in_array( get_post_status( $post_id ), ShrinkALink_Post::get_statuses(), true )
			) {
				WP_CLI::error( 'Shortlinks can only be created for published or scheduled posts of a supported type.' );
			}

			$current = ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE );
			$domain  = ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_DOMAIN, '' );
			$slug    = trim( (string) ( $assoc_args['slug'] ?? '' ) );
			$delete  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'delete', false );

			if ( false !== $current && $slug === $current ) {
				WP_CLI::error( "Post #{$post_id} already uses the short code \"{$current}\"." );
			}

			// generate_shlink() cleans the slug and errors if nothing is left
			$request = '' !== $slug ? array( 'customSlug' => $slug ) : array();

			// without a slug, Shlink would hand back the existing link for the same long URL
			if ( false !== $current && '' === $slug ) {
				$request['findIfExists'] = false;
			}

			// deleting can't be undone, so ask before changing anything
			if ( false !== $current && $delete ) {
				WP_CLI::confirm( "Delete the current short URL \"{$current}\" from Shlink once the new one is saved? This cannot be undone.", $assoc_args );
			}

			$shorturl = ShrinkALink_Post::create_link( $post_id, $request );

			if ( is_wp_error( $shorturl ) ) {
				WP_CLI::error( $shorturl->get_error_message() );
			}

			WP_CLI::log( "#{$post_id} " . get_the_title( $post_id ) . " → {$shorturl}" );

			// only delete once the new link is saved, and never the link Shlink just returned
			$new_code = ShrinkALink_Post::get_post_meta( $post_id, ShrinkALink_Post::META_SHORTCODE );

			if ( false !== $current && $delete && $current !== $new_code ) {
				$deleted = ShrinkALink_API::delete_shlink( $current, array( 'domain' => $domain ) );

				if ( is_wp_error( $deleted ) ) {
					WP_CLI::warning( "The new link is saved, but \"{$current}\" could not be deleted: " . $deleted->get_error_message() );
				} else {
					WP_CLI::log( "Deleted short URL \"{$current}\"." );
				}
			}

			WP_CLI::success( "Short URL: {$shorturl}" );
		}

		/**
		 * Split a short code or full short URL into the code and API args
		 *
		 * @param  string $input A short code like 'abc123', or a URL like 'https://example.com/abc123'.
		 * @return array         The short code, and args with the domain when a URL was given.
		 */
		private static function parse_short_url( $input ) {

			$input = trim( $input );

			// just a short code, so use the default domain
			if ( false === strpos( $input, '://' ) ) {
				$shortcode = trim( $input, '/' );
				$request   = array();
			} else {
				// a full URL: the host is the domain, the path is the short code
				$shortcode = rawurldecode( trim( (string) wp_parse_url( $input, PHP_URL_PATH ), '/' ) );
				$request   = array( 'domain' => (string) wp_parse_url( $input, PHP_URL_HOST ) );
			}

			if ( '' === $shortcode ) {
				WP_CLI::error( 'No short code found. Pass a code like abc123 or a URL like https://example.com/abc123.' );
			}

			return array( $shortcode, $request );
		}

		/**
		 * Tag existing short URLs with the host of their long URL.
		 *
		 * A one-off backfill for links created before tagging. Covers every
		 * link on the Shlink instance, so one run tags all sites sharing it.
		 *
		 * ## OPTIONS
		 *
		 * [--host=<host>]
		 * : Only tag links whose long URL is on this host, e.g. example.com.
		 *
		 * [--dry-run=<dry-run>]
		 * : Show what would be tagged without changing anything. Pass
		 * --dry-run=false to apply the tags.
		 * ---
		 * default: true
		 * options:
		 *   - true
		 *   - false
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     # preview, the default
		 *     wp shrinkalink tag-links
		 *
		 *     # tag one site's links
		 *     wp shrinkalink tag-links --host=example.com --dry-run=false
		 *
		 * @subcommand tag-links
		 */
		public function tag_links( $args, $assoc_args ) {

			$only = $assoc_args['host'] ?? '';
			// --dry-run=false arrives as the string "false", so convert it to a real boolean
			$dry_run = filter_var( $assoc_args['dry-run'] ?? true, FILTER_VALIDATE_BOOLEAN );

			$links = ShrinkALink_API::list_shlinks();

			if ( is_wp_error( $links ) ) {
				WP_CLI::error( $links->get_error_message() );
			}

			$tagged  = 0;
			$skipped = 0;

			foreach ( $links as $link ) {
				$host = (string) wp_parse_url( $link['longUrl'] ?? '', PHP_URL_HOST );
				$tags = $link['tags'] ?? array();

				// nothing to tag with, filtered out, or already tagged
				if ( '' === $host || ( '' !== $only && $host !== $only ) || in_array( $host, $tags, true ) ) {
					++$skipped;
					continue;
				}

				WP_CLI::log( "{$link['shortUrl']} → {$host}" );

				if ( ! $dry_run ) {
					// PATCH replaces the tag list, so send the existing tags too
					$updated = ShrinkALink_API::update_shlink(
						$link['shortCode'],
						array( 'tags' => array_merge( $tags, array( $host ) ) ),
						array( 'domain' => (string) wp_parse_url( $link['shortUrl'], PHP_URL_HOST ) )
					);

					if ( is_wp_error( $updated ) ) {
						WP_CLI::warning( "{$link['shortUrl']}: " . $updated->get_error_message() );
						continue;
					}
				}

				++$tagged;
			}

			WP_CLI::success( sprintf( '%s %d links, skipped %d.', $dry_run ? 'Would tag' : 'Tagged', $tagged, $skipped ) );
		}

		/**
		 * Create shortlinks for published posts that don't have one.
		 *
		 * For posts published before Shrinkalink, or saved in ways the old
		 * plugin missed (such as the block editor). Skips posts whose link was
		 * deleted on purpose; update-url creates one for those and unblocks them.

		 *
		 * ## OPTIONS
		 *
		 * [--post_type=<post_type>]
		 * : Only this post type. Defaults to every type Shrinkalink handles.
		 *
		 * [--limit=<number>]
		 * : Stop after this many posts. Default: no limit.
		 *
		 * [--dry-run=<dry-run>]
		 * : List the posts without creating links. Pass --dry-run=false to create them.
		 * ---
		 * default: true
		 * options:
		 *   - true
		 *   - false
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     # see which posts are missing a shortlink
		 *     wp shrinkalink generate-missing
		 *
		 *     # create links for up to 50 posts
		 *     wp shrinkalink generate-missing --limit=50 --dry-run=false
		 *
		 * @subcommand generate-missing
		 */
		public function generate_missing( $args, $assoc_args ) {

			$dry_run = filter_var( $assoc_args['dry-run'] ?? true, FILTER_VALIDATE_BOOLEAN );
			$limit   = absint( $assoc_args['limit'] ?? 0 );
			$types   = isset( $assoc_args['post_type'] ) ? array( $assoc_args['post_type'] ) : array_values( ShrinkALink_Post::get_post_types() );

			// published posts with no shortlink, oldest first
			$post_ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => ShrinkALink_Post::get_statuses( 'save' ),
					'posts_per_page' => $limit ? $limit : 500,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
								array(
									'key'     => ShrinkALink_Post::META_URL,
									'compare' => 'NOT EXISTS',
								),
								array(
							'key'     => ShrinkALink_Post::META_BLOCKED,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);

			if ( empty( $post_ids ) ) {
				WP_CLI::success( 'Every published post already has a shortlink.' );
				return;
			}

			$created = 0;
			$failed  = 0;

			foreach ( $post_ids as $post_id ) {
				$label = "#{$post_id} " . get_the_title( $post_id );

				if ( $dry_run ) {
					WP_CLI::log( $label );
					continue;
				}

				$shorturl = ShrinkALink_Post::create_link( $post_id );

				if ( is_wp_error( $shorturl ) ) {
					WP_CLI::warning( "{$label}: " . $shorturl->get_error_message() );
					++$failed;
					continue;
				}

				WP_CLI::log( "{$label} → {$shorturl}" );
				++$created;
			}

			if ( $dry_run ) {
				WP_CLI::success( sprintf( '%d posts are missing a shortlink. Run with --dry-run=false to create them.', count( $post_ids ) ) );
				return;
			}

			WP_CLI::success( sprintf( 'Created %d shortlinks, %d failed.', $created, $failed ) );
		}

		// end class
	}

	// end exists check
}

// Instantiate our commands
WP_CLI::add_command( 'shrinkalink', ShrinkALink_Commands::class );
