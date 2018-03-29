<?php
/**
 * PayKassa setup
 *
 * @package  PayKassa
 * @since    1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main PayKassa Class.
 *
 * @class PayKassa
 */
final class PayKassa {

	/**
	 * PayKassa version.
	 *
	 * @var string
	 */
	public $version = '1.0.1';

	/**
	 * The single instance of the class.
	 *
	 * @var PayKassa
	 * @since 1.0.0
	 */
	protected static $_instance = null;


	/**
	 * Main PayKassa Instance.
	 *
	 * Ensures only one instance of PayKassa is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @static
	 * @see   \paykassa()
	 * @return PayKassa - Main instance.
	 */
	public static function instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}


	/**
	 * PayKassa Constructor.
	 */
	public function __construct() {
		// Main Constants
		if ( ! defined( 'PK_ABSPATH' ) ) {
			define( 'PK_ABSPATH', dirname( PK_PLUGIN_FILE ) . '/' );
		}
		if ( ! defined( 'PK_VERSION' ) ) {
			define( 'PK_VERSION', $this->version );
		}
		if ( ! defined( 'PK_PLUGIN_BASENAME' ) ) {
			define( 'PK_PLUGIN_BASENAME', plugin_basename( PK_PLUGIN_FILE ) );
		}

		// Main Install PayKassa Class
		include_once( PK_ABSPATH . 'includes/class-paykassa_install.php' );


		add_action( 'init', array( $this, 'init' ), 0 );

		// Add & init new gateway method
		add_action( 'plugins_loaded', array( $this, 'init_gateway_class' ), 0 );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway_class' ) );


	}

	/**
	 * Added new PayKassa gateway
	 *
	 * @param $methods
	 *
	 * @return array
	 */
	public function add_gateway_class( $methods ) {
		$methods[] = 'PayKassa_Gateway';

		return $methods;
	}


	/**
	 *  Init new PayKassa gateway
	 */
	public function init_gateway_class() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		// Require Main PayKassa Api
		include_once( PK_ABSPATH . 'includes/paykassa_sci.class.php' );

		// Require Main Gateway class
		include_once( PK_ABSPATH . 'includes/class-paykassa_gateway.php' );


	}


	/**
	 * Init PayKassa when WordPress Initialises.
	 */
	public function init() {
		// Set up localisation.
		$this->load_plugin_textdomain();
	}

	/**
	 * Load Localisation files.
	 *
	 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
	 *
	 * Locales found in:
	 *      - WP_LANG_DIR/paykassa/paykassa-LOCALE.mo
	 *      - WP_LANG_DIR/plugins/paykassa-LOCALE.mo
	 */
	public function load_plugin_textdomain() {
		$locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$locale = apply_filters( 'plugin_locale', $locale, 'paykassa' );

		unload_textdomain( 'paykassa' );
		load_textdomain( 'paykassa', WP_LANG_DIR . '/paykassa/paykassa-' . $locale . '.mo' );
		load_plugin_textdomain( 'paykassa', false, plugin_basename( dirname( PK_PLUGIN_FILE ) ) . '/languages' );
	}

}
