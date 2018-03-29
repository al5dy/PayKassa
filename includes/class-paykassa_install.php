<?php
/**
 * Installation related functions and actions.
 *
 * @package  PayKassa
 * @since    1.0.0
 */

defined( 'ABSPATH' ) || exit;


/**
 * PayKassa_Install class.
 */
class PayKassa_Install {


	public static function init() {
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
	}


	/**
	 * Show row meta on the plugin screen.
	 *
	 * @param $links
	 * @param $file
	 *
	 * @return array
	 */
	public static function plugin_row_meta( $links, $file ) {
		if ( PK_PLUGIN_BASENAME === $file ) {
			$row_meta = array(
				'settings' => '<a href="' . esc_url( apply_filters( 'paykassa_settings_url', admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paykassa' ) ) ) . '" aria-label="' . esc_attr__( 'Visit PayKassa settings', 'paykassa' ) . '">' . esc_html__( 'Settings', 'paykassa' ) . '</a>',
				'donate' => '<a href="' . esc_url( apply_filters( 'paykassa_donate_url', 'https://www.paypal.me/al5dy/5usd' ) ) . '" target="_blank" aria-label="' . esc_attr__( 'Send money to me', 'paykassa' ) . '"><strong style="color:red;">' . esc_html__( 'Donate', 'paykassa' ) . '</strong></a>'
			);

			return array_merge( $links, $row_meta );
		}

		return (array) $links;
	}


}

PayKassa_Install::init();
