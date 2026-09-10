<?php
/**
 * Plugin Name: PayKassa for WooCommerce
 * Description: Modern crypto payments for WooCommerce through PayKassa.
 * Version: 2.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: al5dy
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: paykassa
 * Domain Path: /languages
 * WC requires at least: 8.5
 * WC tested up to: 11.1
 *
 * @package PayKassa
 */

defined( 'ABSPATH' ) || exit;

define( 'PAYKASSA_VERSION', '2.0.0' );
define( 'PAYKASSA_FILE', __FILE__ );
define( 'PAYKASSA_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYKASSA_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Al5dy\\PayKassaWoo\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = PAYKASSA_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PAYKASSA_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PAYKASSA_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( '\\Al5dy\\PayKassaWoo\\Infrastructure\\Installer', 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\Al5dy\PayKassaWoo\Bootstrap::boot();
	},
	20
);
