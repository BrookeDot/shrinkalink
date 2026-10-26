=== Shrinkalink ===
Contributors: brookedot
Tags: shlink, shortlinks
Requires at least: 7.1
Tested up to: 7.2
Requires PHP: 8.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create Shlink shortlinks for your posts automatically, and use them as your site's shortlinks.

== Description ==

Shrinkalink connects WordPress to a [Shlink](https://shlink.io/) server. 

This plugin builds heavily on the work of Norcross' [YOURLS Link Creator](https://github.com/norcross/yourls-link-creator) porting it to Shlink and with fewer admin options.

* Creates a shortlink when a post, page or public custom post type is published, including scheduled posts when they go live.
* Lets you choose a custom short code, and check that it is unused before setting it.
* Shows each post's shortlink and click count on the post list screens, with Create and Refresh row actions.
* Syncs click counts from Shlink once a day, in a few batched requests.
* Tags every link with your site's host, so several sites can share one Shlink server.
* Stores the API key with the WordPress Secrets API on WordPress 7.2 and later.
* Includes WP-CLI commands for setup, creating links in bulk, and managing short URLs.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → Shrinkalink.
3. Enter your Shlink server's https URL and an API key from that server. Scoped by domain if possible.
4. Publish a post. Its shortlink appears in the editor's Shrinkalink panel and in the Shortlink column on the Posts screen.

You can also set it up with WP-CLI:

    wp shrinkalink set-url https://example.com
    wp shrinkalink set-key
    wp shrinkalink status

To create links for posts published before you installed the plugin:

    wp shrinkalink generate-missing
    wp shrinkalink generate-missing --dry-run=false

== Frequently Asked Questions ==

= Do I need my own Shlink server? =

Yes. Shrinkalink talks to a Shlink server that you host or that is hosted for you. It doesn't include a shortening service of its own.

= Where is the API key stored? =

On WordPress 7.2 and later it's stored with the Secrets API. On earlier versions it's stored in the `shrinkalink_settings` option, and it's moved into Secrets automatically after you upgrade.

= What happens if Shlink can't be reached when I publish? =

The post publishes normally, without a shortlink. You may use the Create Shlink row action, the editor panel, or `wp shrinkalink generate-missing` later to add one. 

= I deleted a post's shortlink. Why isn't a new one created when I save? =

Deleting a link on purpose blocks the post from getting a new one automatically. Enter a short code in the editor panel and create one to unblock it.

= My scheduled post's shortlink points to a ?p=123 URL. =

Scheduled posts don't have their real permalink yet. Use the Refresh Shlink row action after the post is published, and the link is pointed at the real permalink. It still works in the meantime, since WordPress redirects ?p= URLs.

= Can several sites share one Shlink server? =

Yes. Every link is tagged with the site's host, and click syncing only reads links with that tag. To tag links created before you installed Shrinkalink, run `wp shrinkalink tag-links`.

== Filters ==

Shrinkalink's behavior can be changed with these filters. Add them to your theme's `functions.php` or a small plugin.

= Creating links =

**shrinkalink_create_on_save** — Whether to create a link when a post is published. Default `true`.
Arguments: `$create` (bool), `$post_id` (int), `$post` (WP_Post).

    // Only create links by hand, from the editor panel or row actions.
    add_filter( 'shrinkalink_create_on_save', '__return_false' );

**shrinkalink_create_on_schedule** — Whether to create a link when a scheduled post goes live. Default `true`.
Arguments: `$create` (bool), `$post_id` (int), `$post` (WP_Post).

**shrinkalink_find_if_exists** — Whether Shlink should return an existing short URL for the same long URL rather than creating a new one. Default `true`.
Arguments: `$find` (bool).

**shrinkalink_link_tags** — The tags added to every link this site creates. Default: the site's host, e.g. `array( 'example.com' )`. Click syncing lists links by these tags, so returning an empty array stops click counts from syncing.
Arguments: `$tags` (array), `$long_url` (string), `$args` (array of Shlink fields for the request).

    add_filter( 'shrinkalink_link_tags', function ( $tags ) {
        $tags[] = 'blog';
        return $tags;
    } );

= Short codes =

**shrinkalink_keyword_pattern** — A regular expression for characters to remove from custom short codes. Default `''` (keep everything except tags, line breaks and slashes).
Arguments: `$pattern` (string).

    // Only allow letters, numbers and dashes.
    add_filter( 'shrinkalink_keyword_pattern', function () {
        return '/[^a-z0-9-]/i';
    } );

**shrinkalink_multi_segment_slugs** — Whether to keep slashes in custom short codes. Turn this on only if multi-segment slugs are enabled on your Shlink server. Default `false`.
Arguments: `$allowed` (bool).

= Post types and statuses =

**shrinkalink_include_public_post_types** — Whether public custom post types get links. Default `true`.
Arguments: `$include` (bool).

**shrinkalink_post_types** — The custom post types that get links, keyed by name. Default: every public custom post type, or none if `shrinkalink_include_public_post_types` returns `false`.
Arguments: `$post_types` (array).

    // Add a non-public post type.
    add_filter( 'shrinkalink_post_types', function ( $post_types ) {
        $post_types['newsletter'] = 'newsletter';
        return $post_types;
    } );

**shrinkalink_built_in_post_types** — The built-in post types that get links, keyed by name. Default `array( 'post' => 'post', 'page' => 'page' )`.
Arguments: `$post_types` (array).

**shrinkalink_post_status** — The post statuses links can be created for. `$action` is `'save'` for links created automatically on save (default `array( 'publish' )`), or `''` for links created by hand from the editor, row actions or WP-CLI (default `array( 'publish', 'future' )`).
Arguments: `$statuses` (array), `$action` (string).

= Shortlinks on the front end =

**shrinkalink_shortlinks** — Whether to use the Shlink URL as the post's shortlink. Return `false` to keep WordPress's default `?p=123` shortlinks. Default `true`.
Arguments: `$enabled` (bool).

**shrinkalink_admin_bar_shortlink** — Whether to add WordPress's Shortlink item to the admin bar on the front end. Default `false`.
Arguments: `$show` (bool).

= Admin screens =

**shrinkalink_show_url_column** — Whether to show the Shortlink column on post list screens. Default `true`.
Arguments: `$show` (bool), `$post_type` (string).

**shrinkalink_show_clicks_column** — Whether to show the clicks column on post list screens. Default `true`.
Arguments: `$show` (bool), `$post_type` (string).

**shrinkalink_edit_cap** — The capability needed to create, replace, refresh, delete or block a post's short link, and to request a custom short code. It's checked against the post ID. Default `'edit_post'`.
Arguments: `$capability` (string), `$post_id` (int).

    // Only editors and administrators can change short links.
    add_filter( 'shrinkalink_edit_cap', function () {
        return 'edit_others_posts';
    } );

**shrinkalink_settings_cap** — The capability needed to see and save Settings → Shrinkalink. Default `'manage_options'`.
Arguments: `$capability` (string).

= Click syncing =

**shrinkalink_sync_interval** — How often click counts are synced: any WP-Cron schedule name, such as `'hourly'`, `'twicedaily'` or `'daily'`, or `false` to turn the scheduled sync off. Default `'daily'`.
Arguments: `$interval` (string|false).

    add_filter( 'shrinkalink_sync_interval', function () {
        return 'hourly';
    } );

**shrinkalink_sync_batch_size** — How many posts are read from the database at a time while syncing. Default `200`.
Arguments: `$size` (int).

**shrinkalink_list_max_pages** — The most pages of links to read from Shlink in one list request, at 100 links per page. Default `50`.
Arguments: `$pages` (int).

**shrinkalink_refresh_visits** — Whether Refresh Shlink copies the visit count from Shlink. Default `true`.
Arguments: `$refresh` (bool), `$post_id` (int).

**shrinkalink_refresh_long_url** — Whether Refresh Shlink points a link created while the post was scheduled at the post's real permalink. Default `true`.
Arguments: `$refresh` (bool), `$post_id` (int).

= Shlink API =

**shrinkalink_api_version** — The Shlink REST API version to call. Default `3`.
Arguments: `$version` (int).

**shrinkalink_domain** — The short URL domain used when looking up, updating or deleting a link by short code. Default: the host of the Shlink URL in the settings. Return `''` to use the Shlink server's default domain.
Arguments: `$domain` (string).

= Uninstalling =

**shrinkalink_uninstall_delete_meta** — Whether deleting the plugin also removes the shortlink data stored on each post. Default `false`, so reinstalling brings every post's shortlink back. The plugin isn't loaded while it's being deleted, so add this filter in a mu-plugin or your theme, not in code that depends on Shrinkalink.
Arguments: `$delete` (bool).

    add_filter( 'shrinkalink_uninstall_delete_meta', '__return_true' );

= Actions =

**shrinkalink_after_url_save** — Fires after a short link is created and stored on a post.
Arguments: `$post_id` (int), `$shorturl` (string).

    add_action( 'shrinkalink_after_url_save', function ( $post_id, $shorturl ) {
        error_log( "Post {$post_id} got {$shorturl}" );
    }, 10, 2 );

== Changelog ==

= 0.1.0 =
* Initial release.
