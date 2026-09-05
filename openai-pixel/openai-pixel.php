<?php
/**
 * Plugin Name:       OpenAI Pixel
 * Plugin URI:        https://github.com/unbelievable-digital/openai-pixel
 * Description:       ChatGPT Ads Measurement Pixel for WordPress and WooCommerce: page views, product views, add to cart, checkout and purchases in the browser, plus server-side orders through the Conversions API. Built as a pixel manager so Meta, Google and others can be added later.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Unbelievable Digital
 * Author URI:        https://unbelievable.digital
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       openai-pixel
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OAIP_VERSION', '1.1.0' );
define( 'OAIP_PLUGIN_FILE', __FILE__ );
define( 'OAIP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OAIP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OAIP_OPTION_KEY', 'oaip_settings' );

require_once OAIP_PLUGIN_DIR . 'includes/class-oaip-money.php';
require_once OAIP_PLUGIN_DIR . 'includes/class-oaip-hash.php';
require_once OAIP_PLUGIN_DIR . 'includes/class-oaip-event-bus.php';
require_once OAIP_PLUGIN_DIR . 'includes/class-oaip-provider.php';
require_once OAIP_PLUGIN_DIR . 'includes/providers/class-oaip-openai-capi.php';
require_once OAIP_PLUGIN_DIR . 'includes/providers/class-oaip-provider-openai.php';
require_once OAIP_PLUGIN_DIR . 'includes/integrations/class-oaip-integration-woocommerce.php';
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

	$GLOBALS['oaip_core'] = $core;
}
add_action( 'plugins_loaded', 'oaip_run', 20 );

/**
 * Convenience accessor for other plugins/themes:
 *
 *   oaip()->get_bus()->track( array( 'name' => 'generate_lead' ) );
 *
 * @return OAIP_Core|null
 */
function oaip() {
	return isset( $GLOBALS['oaip_core'] ) ? $GLOBALS['oaip_core'] : null;
}

/**
 * Declare WooCommerce HPOS compatibility (we only use CRUD order APIs).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

function oaip_activate() {
	if ( false === get_option( OAIP_OPTION_KEY ) ) {
		add_option( OAIP_OPTION_KEY, array() );
	}
}
register_activation_hook( __FILE__, 'oaip_activate' );
