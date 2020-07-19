<?php
/**
 * Plugin Name: PayKassa
 * Plugin URI: https://paykassa.pro/?lng=en
 * Description: This plugin is the gateway for PayKassa system of WooCommerce
 * Version: 1.0.2
 * Author: al5dy
 * Author URI: https://ziscod.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: paykassa
 * Domain Path: /languages/
 *
 * @package PayKassa
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly.
}

// Define PK_PLUGIN_FILE.
if ( ! defined( 'PK_PLUGIN_FILE' ) ) {
  define( 'PK_PLUGIN_FILE', __FILE__ );
}

// Include the main PayKassa class.
if ( ! class_exists( 'PayKassa' ) ) {
  include_once dirname( __FILE__ ) . '/includes/class-paykassa.php';
}

/**
 * Main instance of PayKassa.
 * Returns the main instance of PayKassa to prevent the need to use globals.
 *
 * @return PayKassa
 * @since  1.0.0
 */
if ( ! function_exists( 'paykassa' ) ) {
  function paykassa() {
    return PayKassa::instance();
  }

  // Global for backwards compatibility.
  $GLOBALS['paykassa'] = paykassa();
}
