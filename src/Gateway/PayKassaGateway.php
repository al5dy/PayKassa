<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

use Al5dy\PayKassaWoo\Order\OrderPaymentService;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;

final class PayKassaGateway extends \WC_Payment_Gateway
{
    /** @var array<string, string> */
    private array $settings_data;

    public function __construct()
    {
        $this->id = 'paykassa';
        $this->method_title = __('PayKassa', 'paykassa');
        $this->method_description = __('Provider-verified cryptocurrency payments through PayKassa.', 'paykassa');
        $this->has_fields = true;
        $this->supports = array( 'products' );
        $this->init_form_fields();
        $this->init_settings();
        $this->settings_data = $this->settings;
        $this->title = $this->get_option('title', __('Cryptocurrency (PayKassa)', 'paykassa'));
        $this->description = $this->get_option('description', __('Pay securely with cryptocurrency through PayKassa.', 'paykassa'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
    }

    public function init_form_fields(): void
    {
        $this->form_fields = array(
            'enabled' => array( 'title' => __('Enable/Disable', 'paykassa'), 'type' => 'checkbox', 'label' => __('Enable PayKassa', 'paykassa'), 'default' => 'no' ),
            'title' => array( 'title' => __('Title', 'paykassa'), 'type' => 'text', 'default' => __('Cryptocurrency (PayKassa)', 'paykassa') ),
            'description' => array( 'title' => __('Description', 'paykassa'), 'type' => 'textarea', 'default' => __('Pay securely with cryptocurrency through PayKassa.', 'paykassa') ),
            'shop_id' => array( 'title' => __('Merchant / Shop ID', 'paykassa'), 'type' => 'text', 'description' => __('Your PayKassa SCI merchant identifier.', 'paykassa') ),
            'shop_password' => array( 'title' => __('Merchant secret', 'paykassa'), 'type' => 'paykassa_secret', 'description' => __('Leave blank when saving to keep the current secret.', 'paykassa') ),
            'testmode' => array( 'title' => __('Test mode', 'paykassa'), 'type' => 'checkbox', 'label' => __('Use PayKassa test mode', 'paykassa'), 'default' => 'no' ),
            'enabled_systems' => array( 'title' => __('Enabled payment networks', 'paykassa'), 'type' => 'text', 'description' => __('Comma-separated current PayKassa system keys (for example bitcoin,tron_trc20,ton). Leave empty to enable documented crypto directions compatible with the order currency.', 'paykassa') ),
            'api_id' => array( 'title' => __('API ID (optional)', 'paykassa'), 'type' => 'text', 'description' => __('Only required for connection tests and payment recovery.', 'paykassa') ),
            'api_password' => array( 'title' => __('API password (optional)', 'paykassa'), 'type' => 'paykassa_secret', 'description' => __('Leave blank when saving to keep the current secret.', 'paykassa') ),
            'reconciliation_enabled' => array( 'title' => __('Payment recovery', 'paykassa'), 'type' => 'checkbox', 'label' => __('Enable bounded background reconciliation when API credentials are configured', 'paykassa'), 'default' => 'no' ),
            'debug' => array( 'title' => __('Debug logging', 'paykassa'), 'type' => 'checkbox', 'label' => __('Write redacted diagnostic logs', 'paykassa'), 'default' => 'no' ),
            'delete_data_on_uninstall' => array( 'title' => __('Uninstall cleanup', 'paykassa'), 'type' => 'checkbox', 'label' => __('Delete plugin settings and webhook audit data on uninstall', 'paykassa'), 'default' => 'no' ),
        );
    }

    /** Preserve a configured secret when the masked password field is left empty. */
    public function process_admin_options(): bool
    {
        $stored = get_option($this->get_option_key(), array());
        foreach (array( 'shop_password', 'api_password' ) as $key) {
            $field = $this->plugin_id . $this->id . '_' . $key;
            if (isset($_POST[ $field ]) && '' === (string) wp_unslash($_POST[ $field ]) && is_array($stored) && isset($stored[ $key ])) {
                $_POST[ $field ] = $stored[ $key ];
            }
        }
        $saved = parent::process_admin_options();
        $this->settings_data = $this->settings;
        return $saved;
    }

    /** Render an intentionally empty secret field; WC's stock password control reprints its stored value. */
    public function generate_paykassa_secret_html($key, $data): string
    {
        $field_key = $this->get_field_key($key);
        $title = (string) ( $data['title'] ?? '' );
        $description = (string) ( $data['description'] ?? '' );
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($title); ?></label></th>
            <td class="forminp"><input type="password" autocomplete="new-password" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" value="" /><p class="description"><?php echo esc_html($description); ?></p></td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    public function is_available(): bool
    {
        if (! parent::is_available()) {
            return false;
        }
        $currency = get_woocommerce_currency();
        return ( new GatewayAvailability() )->for_currency($currency, $this->settings_data);
    }

    public function payment_fields(): void
    {
        $currency = get_woocommerce_currency();
        $systems = ( new PaymentSystemRegistry() )->all();
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize((string) apply_filters('paykassa_checkout_description', $this->description, $this))));
        }
        echo '<p><label for="paykassa_system">' . esc_html__('Cryptocurrency network', 'paykassa') . '</label><select id="paykassa_system" name="paykassa_system">';
        foreach ($systems as $key => $system) {
            if (( new GatewayAvailability() )->enabled((string) $key, $this->settings_data) && in_array(strtoupper($currency), $system['currencies'], true)) {
                echo '<option value="' . esc_attr((string) $key) . '">' . esc_html((string) $system['label'] . ' — ' . $currency) . '</option>';
            }
        }
        echo '</select></p>';
    }

    public function validate_fields(): bool
    {
        $key = isset($_POST['paykassa_system']) ? sanitize_key((string) wp_unslash($_POST['paykassa_system'])) : '';
        if (! ( new GatewayAvailability() )->enabled($key, $this->settings_data) || ! ( new PaymentSystemRegistry() )->get($key)) {
            wc_add_notice(__('Choose an available PayKassa cryptocurrency network.', 'paykassa'), 'error');
            return false;
        }
        return true;
    }

    /** @return array{result:string,redirect?:string} */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order) {
            wc_add_notice(__('We could not find this order.', 'paykassa'), 'error');
            return array( 'result' => 'failure' );
        }
        $key = isset($_POST['paykassa_system']) ? sanitize_key((string) wp_unslash($_POST['paykassa_system'])) : '';
        try {
            $url = ( new OrderPaymentService() )->create_or_reuse($order, $this->settings_data, $key);
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }
            return array( 'result' => 'success', 'redirect' => $url );
        } catch (PayKassaException $exception) {
            $order->add_order_note(__('PayKassa payment creation failed. See redacted PayKassa logs for correlation details.', 'paykassa'));
            wc_add_notice(__('We could not start the PayKassa payment. Please try another method or contact the store.', 'paykassa'), 'error');
            return array( 'result' => 'failure' );
        }
    }
}
