<?php
/**
 * Shrinkalink Link Creator - Admin Module
 *
 * Handles the settings admin page
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

if ( ! class_exists( ShrinkALink_AdminSettings::class ) ) {
	/**
	 * The Settings → Shrinkalink admin page.
	 */
	class ShrinkALink_AdminSettings {

		/**
		 * Register the settings page and its script.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'shrinkalink_settings_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		}

		/**
		 * Add the Shrinkalink page under the Settings menu.
		 *
		 * @link   https://developer.wordpress.org/reference/functions/add_options_page/
		 * @return void
		 */
		public function shrinkalink_settings_page() {
			add_options_page(
				__( 'Shrinkalink Settings', 'shrinkalink' ),
				__( 'Shrinkalink', 'shrinkalink' ),
				apply_filters( 'shrinkalink_settings_cap', 'manage_options' ),
				'shrinkalink',
				array( $this, 'shrinkalink_settings_display' )
			);
		}

		/**
		 * Output the settings page.
		 *
		 * Before WordPress 7.2 the saved API key is filled in and masked, with a
		 * show/hide toggle. From 7.2 the key is in the Secrets API, so the field is
		 * left empty and blank means "keep the saved key".
		 *
		 * @return void
		 */
		public function shrinkalink_settings_display() {
			$shrinkalink_settings = get_option( 'shrinkalink_settings' );
			$has_key              = ShrinkALink_Settings::has_api_key();
			$use_secrets          = function_exists( 'wp_get_secret' );
			$visible              = $use_secrets ? '' : ( $shrinkalink_settings['api_key'] ?? '' );
			$in_secrets           = ShrinkALink_Settings::api_key_in_secrets();
			?>
			<div class="wrap">
			<h1><?php echo esc_html__( 'Shrinkalink Settings', 'shrinkalink' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'shrinkalink_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="shrinkalink_instance_url"><?php esc_html_e( 'Shlink Install URL', 'shrinkalink' ); ?></label></th>
							<td>
								<input name="shrinkalink_settings[instance_url]" type="url" pattern="https://.*" id="shrinkalink_instance_url" value="<?php echo esc_url( $shrinkalink_settings['instance_url'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'https://example.com', 'shrinkalink' ); ?>" aria-describedby="shrinkalink-instance-url-description" required>
								<p class="description" id="shrinkalink-instance-url-description">
									<?php esc_html_e( 'The base URL for your Shlink install. Must use https.', 'shrinkalink' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pwd"><?php esc_html_e( 'Shlink API Key', 'shrinkalink' ); ?></label></th>
							<td>
								<div class="wp-pwd">
									<input name="shrinkalink_settings[api_key]" 
										id="pwd" 
										type="<?php echo $use_secrets ? 'text' : 'password'; ?>" 
										value="<?php echo esc_attr( $visible ); ?>" 
										class="regular-text" 
										autocomplete="off" 
										spellcheck="false" 
										aria-describedby="shrinkalink-api-key-description" 
										placeholder="<?php echo esc_attr( $in_secrets ? __( 'Key saved to secrets', 'shrinkalink' ) : __( 'Shlink API Key', 'shrinkalink' ) ); ?>"
									<?php echo $has_key ? '' : 'required'; ?>>
									<?php if ( ! $use_secrets ) : ?>
										<button type="button" class="button wp-hide-pw pwd-toggle hide-if-no-js" data-toggle="0" aria-label="<?php esc_attr_e( 'Show password' ); ?>">
											<span class="dashicons dashicons-visibility"></span>
											<span class="text"><?php esc_html_e( 'Show' ); ?></span>
										</button>
									<?php endif; ?>
								</div>
								<p class="description" id="shrinkalink-api-key-description">
								<?php
								if ( $in_secrets ) {
									esc_html_e( 'API key saved to WordPress Secrets. Leave blank to keep it, or enter a new key to replace it.', 'shrinkalink' );
								} else {
									esc_html_e( 'The API key for your Shlink install.', 'shrinkalink' );
								}
								?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
			</div>
			<?php
		} /* end of admin page settings */

		/**
		 * Load core's password-toggle script on the settings page.
		 *
		 * Only needed before WordPress 7.2, when the key field has a show/hide toggle.
		 *
		 * @param  string $hook The current admin page's hook suffix.
		 * @return void
		 */
		public function enqueue_scripts( $hook ) {
			// the show/hide toggle is only output before 7.2
			if ( 'settings_page_shrinkalink' === $hook && ! function_exists( 'wp_get_secret' ) ) {
				wp_enqueue_script( 'password-toggle' );
			}
		}
	}
}
new ShrinkALink_AdminSettings();
