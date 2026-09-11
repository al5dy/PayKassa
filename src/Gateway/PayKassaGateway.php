<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

use Al5dy\PayKassaWoo\Order\OrderPaymentService;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;

final class PayKassaGateway extends \WC_Payment_Gateway
{
    /** @var array<string, mixed> */
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
        $directions = array();
        foreach ((new PaymentSystemRegistry())->directions() as $key => $direction) {
            $directions[$key] = $direction['label'];
        }
        $this->form_fields = array(
            'enabled' => array( 'title' => __('Enable/Disable', 'paykassa'), 'type' => 'checkbox', 'label' => __('Enable PayKassa', 'paykassa'), 'default' => 'no' ),
            'title' => array( 'title' => __('Title', 'paykassa'), 'type' => 'text', 'default' => __('Cryptocurrency (PayKassa)', 'paykassa') ),
            'description' => array( 'title' => __('Description', 'paykassa'), 'type' => 'textarea', 'default' => __('Pay securely with cryptocurrency through PayKassa.', 'paykassa') ),
            'shop_id' => array( 'title' => __('Merchant / Shop ID', 'paykassa'), 'type' => 'text', 'description' => __('Your PayKassa SCI merchant identifier.', 'paykassa') ),
            'shop_password' => array( 'title' => __('Merchant secret', 'paykassa'), 'type' => 'paykassa_secret', 'description' => __('Leave blank when saving to keep the current secret.', 'paykassa') ),
            'testmode' => array( 'title' => __('Test mode', 'paykassa'), 'type' => 'checkbox', 'label' => __('Use PayKassa test mode', 'paykassa'), 'default' => 'no' ),
            'accepted_order_currencies' => array( 'title' => __('Accepted WooCommerce currencies', 'paykassa'), 'type' => 'multiselect', 'class' => 'wc-enhanced-select', 'css' => 'min-width: 320px;', 'options' => (new \Al5dy\PayKassaWoo\PayKassa\CurrencyRegistry())->order_currency_options(), 'default' => \Al5dy\PayKassaWoo\Infrastructure\Installer::default_order_currencies(), 'description' => __('Show PayKassa only for these WooCommerce order currencies. Fiat orders receive a fresh PayKassa quote only when an invoice is created.', 'paykassa') ),
            'enabled_payment_directions' => array( 'title' => __('Enabled crypto payment methods', 'paykassa'), 'type' => 'multiselect', 'class' => 'wc-enhanced-select', 'css' => 'min-width: 320px;', 'options' => $directions, 'default' => array(), 'description' => __('Choose exact cryptocurrency and network combinations. Existing enabled network settings are preserved during upgrade until this setting is saved.', 'paykassa') ),
            'api_id' => array( 'title' => __('API ID (optional)', 'paykassa'), 'type' => 'text', 'description' => __('Only required for connection tests and payment recovery.', 'paykassa') ),
            'api_password' => array( 'title' => __('API password (optional)', 'paykassa'), 'type' => 'paykassa_secret', 'description' => __('Leave blank when saving to keep the current secret.', 'paykassa') ),
            'reconciliation_enabled' => array( 'title' => __('Payment recovery', 'paykassa'), 'type' => 'checkbox', 'label' => __('Enable bounded background reconciliation when API credentials are configured', 'paykassa'), 'default' => 'no', 'description' => __('Experimental: PayKassa Test Mode history recovery is unsupported. Keep unattended recovery disabled until a controlled live lost-webhook test succeeds.', 'paykassa') ),
            'reconciliation_backfill_days' => array('title' => __('Initial recovery lookback (days)', 'paykassa'), 'type' => 'select', 'options' => array('7' => '7', '30' => '30', '90' => '90', '365' => '365'), 'default' => '30', 'description' => __('Later runs resume saved progress, including after a long outage. History records without an SCI verification token require review; they are never credited from history alone.', 'paykassa')),
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
        $accepted_field = $this->plugin_id . $this->id . '_accepted_order_currencies';
        if (isset($_POST[$accepted_field]) && is_array($_POST[$accepted_field])) {
            $submitted = array_map('strval', wp_unslash($_POST[$accepted_field]));
            $_POST[$accepted_field] = array_values(array_intersect(array_keys((new \Al5dy\PayKassaWoo\PayKassa\CurrencyRegistry())->order_currency_options()), array_map('strtoupper', $submitted)));
        }
        $directions_field = $this->plugin_id . $this->id . '_enabled_payment_directions';
        if (isset($_POST[$directions_field]) && is_array($_POST[$directions_field])) {
            $submitted = array_map('strval', wp_unslash($_POST[$directions_field]));
            $_POST[$directions_field] = array_values(array_intersect(array_keys((new PaymentSystemRegistry())->directions()), $submitted));
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
        $directions = (new GatewayAvailability())->directions_for_order_currency($currency, $this->settings_data);
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize((string) apply_filters('paykassa_checkout_description', $this->description, $this))));
        }
        echo '<p><label for="paykassa_direction">' . esc_html__('Pay with cryptocurrency', 'paykassa') . '</label><select id="paykassa_direction" name="paykassa_direction">';
        foreach ($directions as $key => $direction) {
            echo '<option value="' . esc_attr((string) $key) . '">' . esc_html($direction['label']) . '</option>';
        }
        echo '</select></p>';
    }

    public function validate_fields(): bool
    {
        $direction = $this->selected_direction();
        if (! (new GatewayAvailability())->enabled_direction($direction, $this->settings_data) || ! isset((new GatewayAvailability())->directions_for_order_currency(get_woocommerce_currency(), $this->settings_data)[$direction])) {
            wc_add_notice(__('Choose an available PayKassa cryptocurrency and network.', 'paykassa'), 'error');
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
        $direction = $this->selected_direction();
        try {
            $url = ( new OrderPaymentService() )->create_or_reuse($order, $this->settings_data, $direction);
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

    private function selected_direction(): string
    {
        if (isset($_POST['paykassa_direction'])) {
            return (string) wp_unslash($_POST['paykassa_direction']);
        }
        // Old custom classic templates submitted only a system key. Retain
        // that path only when its provider network has one crypto asset.
        $legacy = isset($_POST['paykassa_system']) ? sanitize_key((string) wp_unslash($_POST['paykassa_system'])) : '';
        $matches = array_filter((new PaymentSystemRegistry())->directions(), static fn (array $direction): bool => $direction['system_key'] === $legacy);
        return 1 === count($matches) ? (string) array_key_first($matches) : '';
    }
}
