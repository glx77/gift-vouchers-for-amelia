<?php
/**
 * Plugin Name:       Gift Vouchers for Amelia
 * Plugin URI:        https://github.com/glx77/gift-vouchers-for-amelia
 * Description:        Sell gift vouchers for your Amelia services through WooCommerce. Each paid order automatically generates a single-use 100% Amelia coupon restricted to the purchased service.
 * Version:           1.0.0
 * Author:            Glow with Lilou
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       gift-vouchers-for-amelia
 *
 * @package GiftVouchersForAmelia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GVFA_VERSION', '1.0.0' );
define( 'GVFA_FILE', __FILE__ );
define( 'GVFA_DIR', plugin_dir_path( __FILE__ ) );
define( 'GVFA_URL', plugin_dir_url( __FILE__ ) );

require_once GVFA_DIR . 'includes/class-gvfa-plugin.php';
require_once GVFA_DIR . 'includes/class-gvfa-sync.php';
require_once GVFA_DIR . 'includes/class-gvfa-vouchers.php';
require_once GVFA_DIR . 'includes/class-gvfa-admin.php';

// Declare HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', GVFA_FILE, true );
		}
	}
);

// Load translations.
add_action( 'init', array( 'GVFA_Plugin', 'load_textdomain' ) );

// Boot once all plugins are loaded (so WooCommerce is available).
add_action( 'plugins_loaded', array( 'GVFA_Plugin', 'instance' ) );

// Activation: create the product category and the gift vouchers page.
register_activation_hook( __FILE__, array( 'GVFA_Plugin', 'activate' ) );
