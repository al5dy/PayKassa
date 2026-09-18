<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

use Al5dy\PayKassaWoo\Order\OrderPaymentService;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;
use Al5dy\PayKassaWoo\PayKassa\SciCredentialStore;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;

final class PayKassaGateway extends \WC_Payment_Gateway
{
    private const RESET_SECRET_ACTION_PREFIX = 'paykassa_reset_';

    /** @var array<string, mixed> */
    private array $settings_data;

    public function __construct()
    {
        $this->id = 'paykassa';
        $this->method_title = __('PayKassa', 'paykassa');
        $this->method_description = __('Provider-verified cryptocurrency payments through PayKassa.', 'paykassa');
        $this->has_fields = true;
        $this->supports = array( 'products' );
        $this->icon = PAYKASSA_URL . 'assets/images/logo.svg';
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
            'external_base_url' => array( 'title' => __('External PayKassa callback base URL (optional)', 'paykassa'), 'type' => 'text', 'placeholder' => 'https://public-callback.example/', 'description' => __('Used only for the Invoice Payment Notification and Cryptocurrency Transaction Processor URLs. Empty uses the WordPress home URL. HTTPS is required in Live mode.', 'paykassa') ),
            'browser_return_base_url' => array( 'title' => __('External PayKassa browser return base URL (optional)', 'paykassa'), 'type' => 'text', 'placeholder' => 'https://public-store.example/', 'description' => __('Used only for successful payment and malfunction browser returns. Set it to the public origin where customers perform checkout so login and WooCommerce session cookies remain available. Empty uses the WordPress home URL. HTTPS is required in Live mode.', 'paykassa') ),
            'accepted_order_currencies' => array( 'title' => __('Accepted WooCommerce currencies', 'paykassa'), 'type' => 'multiselect', 'class' => 'wc-enhanced-select', 'css' => 'min-width: 320px;', 'options' => (new \Al5dy\PayKassaWoo\PayKassa\CurrencyRegistry())->order_currency_options(), 'default' => \Al5dy\PayKassaWoo\Infrastructure\Installer::default_order_currencies(), 'description' => __('Show PayKassa only for these WooCommerce order currencies. Fiat orders receive a fresh PayKassa quote only when an invoice is created.', 'paykassa') ),
            'enabled_payment_directions' => array( 'title' => __('Enabled crypto payment methods', 'paykassa'), 'type' => 'multiselect', 'class' => 'wc-enhanced-select', 'css' => 'min-width: 320px;', 'options' => $directions, 'default' => array(), 'description' => __('Choose exact cryptocurrency and network combinations. Existing enabled network settings are preserved during upgrade until this setting is saved.', 'paykassa') ),
            'api_id' => array( 'title' => __('API ID (optional)', 'paykassa'), 'type' => 'text', 'description' => __('Only required for connection tests and payment recovery.', 'paykassa') ),
            'api_password' => array( 'title' => __('API password (optional)', 'paykassa'), 'type' => 'paykassa_secret', 'description' => __('Leave blank when saving to keep the current secret.', 'paykassa') ),
            'minimum_payment_directions' => array( 'title' => __('Minimum payment amounts by PayKassa direction (optional)', 'paykassa'), 'type' => 'textarea', 'css' => 'min-width: 420px; min-height: 100px;', 'placeholder' => "Ethereum_ERC20:USDT=5\nTRON_TRC20:USDT=2", 'description' => __('Optional merchant policy, one exact provider network and currency per line: Provider_System:CURRENCY=amount. Empty means disabled. These values are not claimed to be official PayKassa minimums.', 'paykassa') ),
            'reconciliation_enabled' => array( 'title' => __('Payment recovery', 'paykassa'), 'type' => 'checkbox', 'label' => __('Enable bounded background reconciliation when API credentials are configured', 'paykassa'), 'default' => 'no', 'description' => __('Experimental: PayKassa Test Mode history recovery is unsupported. Keep unattended recovery disabled until a controlled live lost-webhook test succeeds.', 'paykassa') ),
            'reconciliation_backfill_days' => array('title' => __('Initial recovery lookback (days)', 'paykassa'), 'type' => 'select', 'options' => array('7' => '7', '30' => '30', '90' => '90', '365' => '365'), 'default' => '30', 'description' => __('Later runs resume saved progress, including after a long outage. History records without an SCI verification token require review; they are never credited from history alone.', 'paykassa')),
            'debug' => array( 'title' => __('Debug logging', 'paykassa'), 'type' => 'checkbox', 'label' => __('Write redacted diagnostic logs', 'paykassa'), 'default' => 'no' ),
            'delete_data_on_uninstall' => array( 'title' => __('Uninstall cleanup', 'paykassa'), 'type' => 'checkbox', 'label' => __('Delete plugin settings and webhook audit data on uninstall', 'paykassa'), 'default' => 'no' ),
            'customer_return_pages' => array(
                'title' => __('Customer return pages', 'paykassa'),
                'type' => 'title',
                'description' => __('These settings affect browser UX only. They never verify, settle, cancel, or otherwise change a payment.', 'paykassa'),
            ),
            BrowserReturnDestinationResolver::SUCCESS_PAGE_SETTING => array(
                'title' => __('After successful payment', 'paykassa'),
                'type' => 'paykassa_page_select',
                'default' => '0',
                'native_label' => __('Default — Native WooCommerce thank-you page', 'paykassa'),
                'description' => __('Selecting a custom page bypasses the native WooCommerce order-received page and its thank-you hooks. Leave this at Default unless you intentionally use a custom post-payment page.', 'paykassa'),
            ),
            BrowserReturnDestinationResolver::PENDING_PAGE_SETTING => array(
                'title' => __('While payment is being confirmed', 'paykassa'),
                'type' => 'paykassa_page_select',
                'default' => '0',
                'native_label' => __('Default — Native WooCommerce order page', 'paykassa'),
                'description' => __('Used only when the customer returns before the verified server notification has completed the order.', 'paykassa'),
            ),
            BrowserReturnDestinationResolver::FAILURE_PAGE_SETTING => array(
                'title' => __('After failed / cancelled payment', 'paykassa'),
                'type' => 'paykassa_page_select',
                'default' => '0',
                'native_label' => __('Default — Native WooCommerce retry-payment page', 'paykassa'),
                'description' => __('Used only for an unpaid order. If the order is already paid, PayKassa always uses the successful-payment destination.', 'paykassa'),
            ),
        );
    }

    public static function settings_url(): string
    {
        return add_query_arg(
            array(
                'page' => 'wc-settings',
                'tab' => 'checkout',
                'section' => 'paykassa',
                'from' => 'WCADMIN_PAYMENT_SETTINGS',
            ),
            admin_url('admin.php')
        );
    }

    public static function enqueue_admin_assets(string $hook_suffix): void
    {
        if ('woocommerce_page_wc-settings' !== $hook_suffix) {
            return;
        }
        $section = isset($_GET['section']) && is_string($_GET['section'])
            ? sanitize_key(wp_unslash($_GET['section']))
            : '';
        if ('paykassa' !== $section) {
            return;
        }
        wp_enqueue_style(
            'paykassa-admin-settings',
            PAYKASSA_URL . 'assets/admin-settings.css',
            array(),
            PAYKASSA_VERSION
        );
    }

    /** Preserve a configured secret when the masked password field is left empty. */
    public function process_admin_options(): bool
    {
        $stored = get_option($this->get_option_key(), array());
        $testmode_field = $this->plugin_id . $this->id . '_testmode';
        $minimums_field = $this->plugin_id . $this->id . '_minimum_payment_directions';
        try {
            foreach (array('external_base_url', 'browser_return_base_url') as $external_key) {
                $external_field = $this->plugin_id . $this->id . '_' . $external_key;
                if (! isset($_POST[$external_field])) {
                    continue;
                }
                $external = wp_unslash($_POST[$external_field]);
                if (! is_string($external)) {
                    throw new \InvalidArgumentException('External PayKassa base URL must be text.');
                }
                $_POST[$external_field] = '' === trim($external)
                    ? ''
                    : MerchantEndpointUrls::normalize_external_base_url($external, ! isset($_POST[$testmode_field]));
            }
            if (isset($_POST[$minimums_field])) {
                $minimums = wp_unslash($_POST[$minimums_field]);
                if (! is_string($minimums)) {
                    throw new \InvalidArgumentException('PayKassa minimum-payment rules must be text.');
                }
                $_POST[$minimums_field] = (new MinimumPaymentPolicy())->normalize_rules($minimums);
            }
            foreach (
                array(
                    BrowserReturnDestinationResolver::SUCCESS_PAGE_SETTING,
                    BrowserReturnDestinationResolver::PENDING_PAGE_SETTING,
                    BrowserReturnDestinationResolver::FAILURE_PAGE_SETTING,
                ) as $page_setting
            ) {
                $page_field = $this->plugin_id . $this->id . '_' . $page_setting;
                if (isset($_POST[$page_field])) {
                    $_POST[$page_field] = BrowserReturnDestinationResolver::normalize_configured_page_id(
                        wp_unslash($_POST[$page_field])
                    );
                }
            }
        } catch (\InvalidArgumentException $exception) {
            \WC_Admin_Settings::add_error($exception->getMessage());
            return false;
        }
        if (is_array($stored) && '' !== (string) ($stored['shop_id'] ?? '') && '' !== (string) ($stored['shop_password'] ?? '')) {
            try {
                // Retain the old profile before WooCommerce overwrites it.
                ( new SciCredentialStore() )->retain($stored, true);
            } catch (PayKassaException $exception) {
                \WC_Admin_Settings::add_error(__('PayKassa settings were not changed because the existing SCI credentials could not be retained for unfinished orders.', 'paykassa'));
                return false;
            }
        }
        foreach (array( 'shop_password', 'api_password' ) as $key) {
            $field = $this->plugin_id . $this->id . '_' . $key;
            if ($this->secret_reset_requested($key)) {
                $_POST[ $field ] = '';
            } elseif (isset($_POST[ $field ]) && '' === (string) wp_unslash($_POST[ $field ]) && is_array($stored) && isset($stored[ $key ])) {
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
        try {
            $saved = parent::process_admin_options();
        } catch (PayKassaException $exception) {
            \WC_Admin_Settings::add_error(__('PayKassa settings were not changed because the previous SCI credential profile could not be retained.', 'paykassa'));
            return false;
        }
        $this->settings_data = $this->settings;
        if ($saved) {
            $current = get_option($this->get_option_key(), array());
            if (is_array($current) && '' !== (string) ($current['shop_id'] ?? '') && '' !== (string) ($current['shop_password'] ?? '')) {
                try {
                    ( new SciCredentialStore() )->retain($current);
                } catch (PayKassaException $exception) {
                    \WC_Admin_Settings::add_error(__('The new PayKassa SCI credentials were saved, but cannot be used to create invoices until their verification profile can be retained.', 'paykassa'));
                    return false;
                }
            }
        }
        return $saved;
    }

    /** Render published WordPress pages while keeping the native behavior as the safe default. */
    public function generate_paykassa_page_select_html($key, $data): string
    {
        $native_label = is_array($data) && is_string($data['native_label'] ?? null)
            ? $data['native_label']
            : __('Default — Native WooCommerce behavior', 'paykassa');
        $data = is_array($data) ? $data : array();
        $data['type'] = 'select';
        $data['class'] = trim((string) ($data['class'] ?? '') . ' wc-enhanced-select');
        $data['css'] = (string) ($data['css'] ?? 'min-width: 420px;');
        $data['options'] = array('0' => $native_label) + BrowserReturnDestinationResolver::published_page_options();
        return $this->generate_select_html($key, $data);
    }

    /**
     * Keep the Merchant URL table out of stored settings while rendering it last.
     *
     * @param array<string, array<string, mixed>> $form_fields
     */
    public function generate_settings_html($form_fields = array(), $echo = true): string
    {
        $generated = parent::generate_settings_html($form_fields, false);
        $html = (is_string($generated) ? $generated : '') . $this->merchant_urls_html();
        if ($echo) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each value is escaped by the two renderers.
        }
        return $html;
    }

    /** Render an empty secret field and a masked summary when a value is configured. */
    public function generate_paykassa_secret_html($key, $data): string
    {
        $field_key = $this->get_field_key($key);
        $title = (string) ( $data['title'] ?? '' );
        $description = (string) ( $data['description'] ?? '' );
        $stored_value = $this->get_option($key, '');
        $masked_value = is_string($stored_value) ? self::mask_secret($stored_value) : '';
        $reset_action = self::RESET_SECRET_ACTION_PREFIX . $key;
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($title); ?></label></th>
            <td class="forminp">
                <input type="password" autocomplete="new-password" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" value="" />
                <p class="description"><?php echo esc_html($description); ?></p>
                <?php if ('' !== $masked_value) : ?>
                    <p class="description paykassa-secret-status">
                        <span><?php echo esc_html__('Stored value:', 'paykassa'); ?> <code><?php echo esc_html($masked_value); ?></code></span>
                        <button type="submit" class="button button-secondary" name="save" value="<?php echo esc_attr($reset_action); ?>"><?php echo esc_html__('Reset value', 'paykassa'); ?></button>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    private function merchant_urls_html(): string
    {
        $settings = get_option($this->get_option_key(), array());
        $settings = is_array($settings) ? $settings : array();
        $live_mode = 'yes' !== ($settings['testmode'] ?? 'no');
        foreach (array('external_base_url', 'browser_return_base_url') as $base_key) {
            $value = $settings[$base_key] ?? '';
            if (! is_string($value) || '' === trim($value)) {
                $settings[$base_key] = '';
                continue;
            }
            try {
                $settings[$base_key] = MerchantEndpointUrls::normalize_external_base_url($value, $live_mode);
            } catch (\InvalidArgumentException) {
                $settings[$base_key] = '';
            }
        }
        $urls = new MerchantEndpointUrls($settings);
        $rows = array(
            __('URL of Invoice Payment Notifications', 'paykassa') => array(
                $urls->invoice_notification_url(),
                __('Required. Server-to-server payment verification using sci_confirm_order.', 'paykassa'),
            ),
            __('URL of successful payment', 'paykassa') => array(
                $urls->success_return_url(),
                __('Browser redirect only. Never used as payment evidence.', 'paykassa'),
            ),
            __('URL malfunction when paying', 'paykassa') => array(
                $urls->failure_return_url(),
                __('Browser redirect only. Does not cancel or settle an order.', 'paykassa'),
            ),
            __('URL of Cryptocurrency Transaction Processor', 'paykassa') => array(
                $urls->transaction_notification_url(),
                __('Optional secondary verified transaction notification channel using sci_confirm_transaction_notification. Live only.', 'paykassa'),
            ),
        );

        ob_start();
        ?>
        <tr valign="top" class="paykassa-merchant-urls-setting">
            <th scope="row" class="titledesc">
                
                <label><?php echo esc_html__('PayKassa Merchant URLs', 'paykassa'); ?></label>
            
            </th>
            <td colspan="2" class="paykassa-merchant-urls-content">
                <p class="description paykassa-merchant-urls-intro"><?php echo esc_html__('Copy each URL into the matching field in PayKassa Merchant settings. Callback and browser-return bases are independent; the plugin never accesses your PayKassa account.', 'paykassa'); ?></p>
                <table class="widefat striped paykassa-merchant-urls"><tbody>
                    <?php $index = 0; ?>
                    <?php foreach ($rows as $label => $row) : ?>
                        <?php
                        ++$index;
                        $id = 'paykassa-merchant-url-' . $index;
                        ?>
                        <tr>
                            <th class="paykassa-merchant-url-details"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label><p class="description"><?php echo esc_html($row[1]); ?></p></th>
                            <td class="paykassa-merchant-url-value"><div class="paykassa-merchant-url-controls"><input type="text" readonly class="large-text code" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($row[0]); ?>"><button type="button" class="button button-secondary paykassa-copy-url" data-copy-target="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Copy', 'paykassa'); ?></button></div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <p id="paykassa-copy-status" class="screen-reader-text" aria-live="polite"></p>
                <script>(function(){var status=document.getElementById("paykassa-copy-status");function copied(){status.textContent="<?php echo esc_js(__('URL copied.', 'paykassa')); ?>";}function fallback(input){input.focus();input.select();try{if(document.execCommand("copy")){copied();}}catch(error){status.textContent="<?php echo esc_js(__('Select and copy the URL manually.', 'paykassa')); ?>";}}document.querySelectorAll(".paykassa-copy-url").forEach(function(button){button.addEventListener("click",function(){var input=document.getElementById(button.getAttribute("data-copy-target"));if(!input){return;}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(input.value).then(copied,function(){fallback(input);});}else{fallback(input);}});});})();</script>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    private function secret_reset_requested(string $key): bool
    {
        if (! isset($_POST['save']) || ! is_string($_POST['save'])) {
            return false;
        }
        return self::RESET_SECRET_ACTION_PREFIX . $key === wp_unslash($_POST['save']);
    }

    private static function mask_secret(string $value): string
    {
        $length = strlen($value);
        if (0 === $length) {
            return '';
        }
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $visible_length = $length - 4;
        $prefix_length = min(8, max(1, intdiv($visible_length, 2)));
        $suffix_length = $visible_length - $prefix_length;
        $suffix = $suffix_length > 0 ? substr($value, -$suffix_length) : '';
        return substr($value, 0, $prefix_length) . '****' . $suffix;
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
            (new BrowserReturnAccess())->grant($order);
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
