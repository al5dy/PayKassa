<?php
/**
 * Installation related functions and actions.
 *
 * @package  PayKassa
 * @since    1.0.0
 */

defined( 'ABSPATH' ) || exit;


/**
 * PayKassa Payment Gateway.
 *
 * Provide services of receiving payments on the website
 *
 * @class       PayKassa_Gateway
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 * @package     PayKassa/Includes
 * @author      al5dy
 */
if ( ! class_exists( 'PayKassa_Gateway' ) ) {
	class PayKassa_Gateway extends WC_Payment_Gateway {

		public $shop_id;

		public $shop_password;

		public $testmode;

		public $debug;

		/** @var bool Whether or not logging is enabled */
		public static $log_enabled = false;

		/** @var WC_Logger Logger instance */
		public static $log = false;


		public function __construct() {
			$this->id                 = 'paykassa';
			$this->has_fields         = false;
			$this->icon               = apply_filters( 'paykassa_icon', '' );
			$this->method_title       = __( 'PayKassa', 'paykassa' );
			$this->method_description = __( 'Provide services of receiving payments on the website', 'paykassa' );


			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();


			// Define user set variables
			$this->title         = $this->get_option( 'title' );
			$this->description   = $this->get_option( 'description' );
			$this->testmode      = 'yes' === $this->get_option( 'testmode', 'no' );
			$this->shop_id       = $this->get_option( 'shop_id' );
			$this->shop_password = $this->get_option( 'shop_password' );
			$this->debug         = 'yes' === $this->get_option( 'debug', 'no' );

			self::$log_enabled = $this->debug;


			if ( $this->testmode ) {
				$this->description .= ' ' . sprintf( __( '%sSandbox enabled.%s', 'paykassa' ), '<strong>', '</strong>' );
				$this->description = trim( $this->description );
			}

			// SSL check
			add_action( 'admin_notices', array( $this, 'do_ssl_check' ) );


			// Payment listener/API hook
			// badly !!!!! set in paykassa account
			// http://site.com/?wc-api=wc_gateway_paykassa
			// add_query_arg('wc-api', 'wc_gateway_paykassa', home_url('/'));
			add_action( 'woocommerce_api_wc_gateway_paykassa', array( $this, 'check_ipn_response' ) );


			if ( is_admin() ) {
				// Save our administration options. Since we are not going to be doing anything special
				// we have not defined 'process_admin_options' in this class so the method in the parent
				// class will be used instead
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			if ( ! $this->is_valid_for_use() ) {
				$this->enabled = false;
			}
		}


		/**
		 * Check for PayKassa Response.
		 */
		public function check_ipn_response() {

			if ( ! empty( $_POST ) ) {
				$paykassa = new PayKassaSCI(
					$this->shop_id,       // shop id
					$this->shop_password,  // shop password
					$this->testmode // sandbox
				);

				$res = $paykassa->sci_confirm_order();
				if ( $res['error'] ) {
					//wc_add_notice( $res['message'], 'error' );
				} else {
					$order_id    = (int) $res["data"]["order_id"];
					$transaction = $res["data"]["transaction"];
					$hash        = $res["data"]["hash"];
					$currency    = $res["data"]["currency"];
					$amount      = $res["data"]["amount"];
					$system      = $res["data"]["system"];

					$order = new WC_Order( $order_id );

					if ( $order->has_status( wc_get_is_paid_statuses() ) ) {
						exit;
					}

					if ( $order->has_status( 'cancelled' ) ) {
						$this->payment_status_paid_cancelled_order( $order, $res );
					}


					$this->payment_complete( $order, $transaction, __( 'Payment completed', 'paykassa' ) );

					echo $order_id . '|success'; // So very Funny moment :)

					exit;
				}

			}

			wp_die( __( 'PayKassa Request Failure', 'paykassa' ), __( 'PayKassa IPN', 'paykassa' ), array( 'response' => 500 ) );
		}


		/**
		 * When a user cancelled order is marked paid.
		 *
		 * @param WC_Order $order Order object.
		 * @param array $posted Posted data.
		 */
		protected function payment_status_paid_cancelled_order( $order, $posted ) {
			$this->send_ipn_email_notification(
			/* translators: %s: order link. */
				sprintf( __( 'Payment for cancelled order %s received', 'paykassa' ), '<a class="link" href="' . esc_url( $order->get_edit_order_url() ) . '">' . $order->get_order_number() . '</a>' ),
				/* translators: %s: order ID. */
				sprintf( __( 'Order #%s has been marked paid by PayKassa, but was previously cancelled. Admin handling required.', 'paykassa' ), $order->get_order_number() )
			);
		}


		/**
		 * Send a notification to the user handling orders.
		 *
		 * @param string $subject Email subject.
		 * @param string $message Email message.
		 */
		protected function send_ipn_email_notification( $subject, $message ) {
			$new_order_settings = get_option( 'woocommerce_new_order_settings', array() );
			$mailer             = WC()->mailer();
			$message            = $mailer->wrap_message( $subject, $message );
			$mailer->send( ! empty( $new_order_settings['recipient'] ) ? $new_order_settings['recipient'] : get_option( 'admin_email' ), strip_tags( $subject ), $message );
		}


		/**
		 * Complete order, add transaction ID and note.
		 *
		 * @param  WC_Order $order
		 * @param  string $txn_id
		 * @param  string $note
		 */
		protected function payment_complete( $order, $txn_id = '', $note = '' ) {
			$order->add_order_note( $note );
			$order->payment_complete( $txn_id );
		}

		/**
		 * Hold order and add note.
		 *
		 * @param  WC_Order $order
		 * @param  string $reason
		 */
		protected function payment_on_hold( $order, $reason = '' ) {
			$order->update_status( 'on-hold', $reason );
			wc_reduce_stock_levels( $order->get_id() );
			WC()->cart->empty_cart();
		}


		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'       => array(
					'title'   => __( 'Enable/Disable', 'paykassa' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable PayKassa transfer', 'paykassa' ),
					'default' => 'no'
				),
				'title'         => array(
					'title'       => __( 'Title', 'paykassa' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'paykassa' ),
					'default'     => __( 'PayKassa', 'paykassa' ),
					'desc_tip'    => true
				),
				'description'   => array(
					'title'       => __( 'Description', 'paykassa' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'paykassa' ),
					'default'     => __( 'It allowing you to instantly make payments on your website and make bulk payments through many payment systems.', 'paykassa' ),
					'desc_tip'    => true
				),
				'shop_id'       => array(
					'title'       => __( 'Shop ID', 'paykassa' ),
					'type'        => 'text',
					'description' => __( 'ID from Shop settings page. Required parameter.', 'paykassa' ),
					'desc_tip'    => true
				),
				'shop_password' => array(
					'title'       => __( 'Password shop', 'paykassa' ),
					'type'        => 'text',
					'description' => __( 'The secret key from Shop settings page. Required parameter.', 'paykassa' ),
					'desc_tip'    => true
				),
				'testmode'      => array(
					'title'       => __( 'PayKassa sandbox', 'paykassa' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable PayKassa sandbox', 'paykassa' ),
					'default'     => 'no',
					'description' => __( 'PayKassa sandbox can be used to test payments.', 'paykassa' )
				)
			);

		}


		/**
		 * Admin settings
		 */
		public function admin_options() { ?>

            <h3><?php _e( 'PayKassa gateway', 'paykassa' ); ?></h3>

            <p><?php _e( 'Before using, please take the following steps:', 'paykassa' ); ?></p>

            <ol>
                <li><?php printf( __( '%sRegister%s and add new shop.', 'paykassa' ), '<a href="https://paykassa.pro/signup/" target="_blank">', '</a>' ) ?></li>
                <li><?php _e( 'Set the following endpoints:', 'paykassa' ) ?><br><br>
                    <ul>
                        <li><?php printf( __( 'URL notification: https://YOUR_STORE.COM/%s?wc-api=wc_gateway_paykassa%s', 'paykassa' ), '<strong>', '</strong>' ); ?></li>
                    </ul>
                </li>
            </ol>

			<?php if ( $this->is_valid_for_use() ) { ?>
                <table class="form-table"><?php $this->generate_settings_html(); ?></table>
			<?php } else { ?>
                <div class="inline error"><p><strong><?php _e( 'Gateway disabled', 'paykassa' ); ?></strong>: <?php _e( 'PayKassa does not support your store currency. Supported currencies - RUB, USD, BTC, ETH, LTC, DASH, BCH, ZEC', 'paykassa' ); ?></p></div>
			<?php } ?>

		<?php }


		/**
		 * Check if this gateway is enabled and available in the user's currency.
		 *
		 * @return bool
		 */
		function is_valid_for_use() {
			if ( ! in_array( get_option( 'woocommerce_currency' ), array( 'RUB', 'USD', 'BTC', 'ETH', 'LTC', 'DASH', 'BCH', 'ZEC' ) ) ) {
				return false;
			}

			return true;
		}


		/**
		 * Process Payment.
		 *
		 * @param int $order_id
		 *
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			$request = $this->make_request( $order );

			$process_status = 'success';
			$process_url    = $this->get_return_url( $order );


			if ( is_array( $request ) ) {
				if ( ! empty( $request['error'] ) ) {
					wc_add_notice( $request['message'], 'error' );
					$order->add_order_note( sprintf( __( 'Error: %s' ), $request['message'] ) );
				} elseif ( ! empty( $request['data']['url'] ) ) {

					$environment_url = $request['data']['url'];

					// Just in case :)
					try {
						$response = wp_remote_post( $environment_url, array(
							'method'    => 'POST',
							'body'      => http_build_query( $this->get_paykassa_args( $order ) ),
							'timeout'   => 90,
							'sslverify' => false
						) );
						if ( is_wp_error( $response ) ) {
							throw new Exception( __( 'There is issue for connectin payment gateway. Sorry for the inconvenience.', 'paykassa' ) );
						}
						if ( empty( $response['body'] ) ) {
							throw new Exception( __( 'Response was not get any data.', 'paykassa' ) );
						}


						// get body response while get not error
						$response_body = wp_remote_retrieve_body( $response );


						if ( empty( $response_body ) ) {
							throw new Exception( __( 'Response body is empty.', 'paykassa' ) );
						} else {
							$this->payment_on_hold( $order, __( 'Payment pending', 'paykassa' ) );

							$process_status = 'success';
							$process_url    = $environment_url;
						}
					} catch ( Exception $e ) {
						wc_add_notice( $e->getMessage(), 'error' );
						$order->add_order_note( sprintf( __( 'Error: %s', 'paykassa' ), $e->getMessage() ) );
					}

				}
			}


			return array(
				'result'   => $process_status,
				'redirect' => $process_url,
			);

		}

		/**
		 * Get the PayKassa request URL for an order.
		 *
		 * @param $order
		 *
		 * @return mixed
		 */
		public function make_request( $order ) {
			$paykassa = new PayKassaSCI(
				$this->shop_id,       // shop id
				$this->shop_password,  // shop password
				$this->testmode // sandbox
			);

			$args = $this->get_paykassa_args( $order );
			$res  = call_user_func_array( array( $paykassa, 'sci_create_order' ), $args );

			self::log( 'PayKassa Request Args for order ' . $order->get_order_number() . ': ' . wc_print_r( $args, true ) );

			return $res;
		}


		/**
		 * Logging method.
		 *
		 * @param string $message Log message.
		 * @param string $level Optional. Default 'info'.
		 *     emergency|alert|critical|error|warning|notice|info|debug
		 */
		public static function log( $message, $level = 'info' ) {
			if ( self::$log_enabled ) {
				if ( empty( self::$log ) ) {
					self::$log = wc_get_logger();
				}
				self::$log->log( $level, $message, array( 'source' => 'paykassa' ) );
			}
		}

		/**
		 * Get PayKassa Args for passing to PP.
		 *
		 * @param  $order
		 *
		 * @return array
		 */
		protected function get_paykassa_args( $order ) {

			$system_id = [
				'payeer'       => 1,  // RUB USD
				'perfectmoney' => 2,  // USD
				'advcash'      => 4,  // RUB USD
				'bitcoin'      => 11, // BTC
				'ethereum'     => 12, // ETH
				'litecoin'     => 14, // LTC
				'dash'         => 16, // DASH
				'bitcoincash'  => 18, // BCH
				'zcash'        => 19, // ZEC
			];

			$currency = get_woocommerce_currency();

			switch ( $currency ) {
				case 'ZEC' :
					$system = 'zcash';
					break;
				case 'BCH' :
					$system = 'bitcoincash';
					break;
				case 'DASH' :
					$system = 'dash';
					break;
				case 'LTC' :
					$system = 'litecoin';
					break;
				case 'ETH' :
					$system = 'ethereum';
					break;
				case 'BTC' :
					$system = 'bitcoin';
					break;
				case 'RUB' :
				case 'USD' :
					$system = 'payeer';
					break;
				default :
					$system = 'advcash';
			}

			$comment = ! empty( $order->get_customer_note() ) ? $order->get_customer_note() : sprintf( __( 'Order - #%s', 'paykassa' ), $order->get_order_number() );

			return apply_filters( 'paykassa_args', array_merge(
				array(
					'amount'   => $order->order_total,
					'currency' => $currency,
					'order_id' => $order->get_order_number(),
					'comment'  => $comment,
					'system'   => $system_id[ $system ]
				),
				$this->get_phone_number_args( $order )
			), $order );
		}


		/**
		 * Get phone number args for PayKassa request.
		 *
		 * @param $order
		 *
		 * @return array
		 */
		protected function get_phone_number_args( $order ) {
			if ( in_array( $order->get_billing_country(), array( 'US', 'CA' ) ) ) {
				$phone_number = str_replace( array( '(', '-', ' ', ')', '.' ), '', $order->get_billing_phone() );
				$phone_number = ltrim( $phone_number, '+1' );
				$phone_args   = array(
					'phone' => substr( $phone_number, 0, 3 )
				);
			} else {
				$phone_args = array(
					'phone' => $order->get_billing_phone(),
				);
			}

			return $phone_args;
		}


		/**
		 * Further check of SSL if you want
		 */
		public function do_ssl_check() {
			if ( $this->enabled === 'yes' && get_option( 'woocommerce_force_ssl_checkout' ) === 'no' && ! isset( $_POST['save'] ) ) {
				echo '<div id="ssl-check" class="notice notice-error is-dismissible"><p>' . sprintf( __( '<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href="%s">forcing the checkout pages to be secured</a>.', 'paykassa' ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '</p></div>';
			}
		}

	}
}
