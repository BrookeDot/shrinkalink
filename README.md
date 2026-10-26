# Shrinkalink

A WordPress plugin that creates [Shlink](https://shlink.io/) short links for posts when they're published, and uses them as the site's shortlinks.

This file is for working on the plugin. For features, setup, filters and the FAQ, see [readme.txt](readme.txt), which is the WordPress.org listing.

## Requirements

- PHP 8.4 or later
- WordPress 7.1 or later (the API key is stored with the Secrets API from 7.2)
- [Composer](https://getcomposer.org/)
- A Shlink server, for trying the plugin by hand. The tests fake Shlink's responses, so they don't need one.

## Setup

Install the development dependencies:

```sh
composer install
```

## Running the tests

The tests use the WordPress test library and need their own database, separate from the site's. These steps assume a [Lando](https://lando.dev/) WordPress site with the plugin at `wp-content/plugins/shrinkalink`, and a `wordpress_test` database the `wordpress` user can access. Run them from the plugin folder.

1. Add the test library installer, answering `n` to every prompt to overwrite an existing file:

   ```sh
   lando wp scaffold plugin-tests shrinkalink
   ```

2. Install the WordPress test library. The arguments are the database name, user, password, host, WordPress version, and `true` to skip creating the database because it already exists. Repeat this after a `lando rebuild`, since the library is installed in the container's `/tmp`.

   ```sh
   lando ssh -c "cd /app/public/wp-content/plugins/shrinkalink && bash bin/install-wp-tests.sh wordpress_test wordpress wordpress database latest true"
   ```

3. Run the tests:

   ```sh
   lando ssh -c "cd /app/public/wp-content/plugins/shrinkalink && vendor/bin/phpunit"
   ```

   To run one file:

   ```sh
   lando ssh -c "cd /app/public/wp-content/plugins/shrinkalink && vendor/bin/phpunit tests/test-api.php"
   ```

The tests are in `tests/`, one file per area:

| File | Covers |
| --- | --- |
| `test-api.php` | Requests to Shlink, error handling, pagination and short code cleanup |
| `test-settings.php` | URL validation and API key storage |
| `test-publish.php` | Creating shortlinks when posts are published |

`class-shrinkalink-testcase.php` saves working settings before each test and fakes Shlink's responses through the `pre_http_request` filter. Queue a response with `$this->queue( $status, $body )`. A request with no response queued returns an error, so a test can never reach a real server.

## Coding standards

The plugin follows the WordPress Coding Standards, configured in `.phpcs.xml.dist`. With `phpcs`, [WPCS](https://github.com/WordPress/WordPress-Coding-Standards) and [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP) installed:

```sh
phpcs
```

## Translations

Regenerate the translation template, `languages/shrinkalink.pot`, after changing any translatable string in PHP or JavaScript:

```sh
composer makepot
```

## Releasing

Files listed in `.distignore` are left out of the release, including the tests, Composer files, the coding standards config and this README.

## License

GPLv3 or later. See [readme.txt](readme.txt).
