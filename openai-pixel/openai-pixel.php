<?php
/**
 * Plugin Name:       OpenAI Pixel
 * Plugin URI:        https://github.com/unbelievable-digital/openai-pixel
 * Description:       Easily add the OpenAI tracking pixel to your WordPress site. Built as a multi-pixel manager so Meta, Google and other providers can be added later.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Unbelievable Digital
 * Author URI:        https://unbelievable.digital
 * License:            GPL v2 or later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       openai-pixel
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OAIP_VERSION', '1.0.0' );
define( 'OAIP_PLUGIN_FILE', __FILE__ );
define( 'OAIP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OAIP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OAIP_OPTION_KEY', 'oaip_settings' );

require_once OAIP_PLUGIN_DIR . 'includes/class-oaip-provider.php';
require_once OAIP_PLUGIN_DIR . 'includes/providers/class-oaip-provider-openai.php';
require_once OAIP_PLUGIN_DIR . 'includes/class-oaip-core.php';
require_once OAIP_PLUGIN_DIR . 'includes/class-oaip-admin.php';

/**
 * Boot the plugin.
 */
function oaip_run() {
	$core = new OAIP_Core();
	$core->init();

	if ( is_admin() ) {
		$admin = new OAIP_Admin( $core );
		$admin->init();
	}
}
add_action( 'plugins_loaded', 'oaip_run' );

/**
 * Seed default options on activation so the settings page always has
 * a predictable structure to work with.
 */
function oaip_activate() {
	$existing = get_option( OAIP_OPTION_KEY );
	if ( false === $existing ) {
		add_option( OAIP_OPTION_KEY, array() );
	}
}
register_activation_hook( __FILE__, 'oaip_activate' );
