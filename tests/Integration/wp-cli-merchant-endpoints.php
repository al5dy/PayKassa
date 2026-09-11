<?php

declare(strict_types=1);

use Al5dy\PayKassaWoo\Gateway\BrowserReturnAccess;
use Al5dy\PayKassaWoo\Gateway\BrowserReturnController;
use Al5dy\PayKassaWoo\Gateway\MerchantEndpointUrls;
use Al5dy\PayKassaWoo\Gateway\PayKassaGateway;
use Al5dy\PayKassaWoo\Admin\DiagnosticsPage;
use Al5dy\PayKassaWoo\Admin\SiteHealth;
use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\Order\OrderMeta;
use Al5dy\PayKassaWoo\Order\PaymentSnapshot;
use Al5dy\PayKassaWoo\Order\PaymentState;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentEvidence;
use Al5dy\PayKassaWoo\PayKassa\Dto\TransactionNotificationEvidence;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\SciCredentialStore;
use Al5dy\PayKassaWoo\Webhook\EvidenceSource;
use Al5dy\PayKassaWoo\Webhook\TransactionNotificationController;
use Al5dy\PayKassaWoo\Webhook\WebhookCredentialResolver;
use Al5dy\PayKassaWoo\Webhook\WebhookEventStore;
use Al5dy\PayKassaWoo\Webhook\WebhookProcessor;
use Automattic\WooCommerce\Utilities\OrderUtil;

function paykassa_endpoint_assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$original_settings = get_option('woocommerce_paykassa_settings', null);
$original_profiles = get_option(SciCredentialStore::OPTION, null);
$original_currency = get_option('woocommerce_currency', 'USD');
$original_user = get_current_user_id();
$orders = array();
$users = array();
$provider_responses = array();
$provider_requests = array();
$payment_completions = array();
$create_calls = 0;

$live_settings = array(
    'enabled' => 'yes',
    'shop_id' => 'merchant-live',
    'shop_password' => 'live-secret-old',
    'testmode' => 'no',
    'title' => 'PayKassa',
    'description' => 'Test payment',
    'accepted_order_currencies' => array('USD'),
    'enabled_payment_directions' => array('ethereum_erc20:USDT', 'tron_trc20:USDT'),
    'external_base_url' => 'https://ocelot-dribble-creature.ngrok-free.dev/',
    'browser_return_base_url' => 'https://ocelot-dribble-creature.ngrok-free.dev/',
    'minimum_payment_directions' => '',
    'debug' => 'no',
);
$test_settings = array_replace($live_settings, array(
    'shop_id' => 'merchant-test',
    'shop_password' => 'test-secret-only',
    'testmode' => 'yes',
    'external_base_url' => '',
    'browser_return_base_url' => '',
));

update_option('woocommerce_paykassa_settings', $live_settings, false);
update_option('woocommerce_currency', 'USD', false);
(new SciCredentialStore())->retain($live_settings);

$transport = static function ($preempt, array $args, string $url) use (&$provider_responses, &$provider_requests, &$create_calls) {
    if ('https://currency.paykassa.pro/pairs.php' === $url) {
        $payload = array('error' => false, 'message' => 'OK', 'data' => array(array('USD_USDT' => '1')));
        return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
    }
    if ('https://paykassa.app/sci/0.4/index.php' !== $url) {
        return $preempt;
    }
    $body = is_array($args['body'] ?? null) ? $args['body'] : array();
    $function = isset($body['func']) && is_string($body['func']) ? $body['func'] : '';
    if ('sci_create_order' === $function) {
        ++$create_calls;
        $hash = hash('sha256', 'endpoint-create-' . (string) ($body['order_id'] ?? ''));
        $payload = array('error' => false, 'message' => 'OK', 'data' => array(
            'url' => 'https://paykassa.app/pay/endpoint-test?hash=' . $hash,
            'params' => array('hash' => $hash),
        ));
        return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
    }
    $hash = isset($body['private_hash']) && is_string($body['private_hash']) ? $body['private_hash'] : '';
    $provider_requests[] = array(
        'func' => $function,
        'hash_fingerprint' => substr(hash('sha256', $hash), 0, 16),
        'shop_id' => $body['sci_id'] ?? '',
        'shop_password' => $body['sci_key'] ?? '',
        'test' => $body['test'] ?? '',
    );
    $fixture = $provider_responses[$function][$hash] ?? null;
    if (! is_array($fixture)) {
        $payload = array('error' => true, 'message' => 'Unknown verification fixture.', 'data' => array());
    } elseif (
        ($body['sci_id'] ?? null) !== $fixture['shop_id']
        || ($body['sci_key'] ?? null) !== $fixture['shop_password']
        || ($body['test'] ?? null) !== $fixture['test']
    ) {
        $payload = array('error' => true, 'message' => 'Wrong retained credential fixture.', 'data' => array());
    } else {
        $payload = array('error' => false, 'message' => 'OK', 'data' => $fixture['data']);
    }
    return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
};
add_filter('pre_http_request', $transport, 10, 3);
add_filter('pre_wp_mail', static fn (): bool => true);

$paid_hook = static function (int $order_id) use (&$payment_completions): void {
    $payment_completions[$order_id] = ($payment_completions[$order_id] ?? 0) + 1;
};
add_action('woocommerce_payment_complete', $paid_hook);

$make_order = static function (int $user_id = 0, string $method = 'paykassa', string $amount = '2.000000'): WC_Order {
    $order = wc_create_order(array('customer_id' => $user_id));
    if (is_wp_error($order)) {
        throw new RuntimeException('Could not create endpoint fixture order: ' . $order->get_error_message());
    }
    $order->set_currency('USD');
    $order->set_total($amount);
    $order->set_payment_method($method);
    $order->set_payment_method_title('paykassa' === $method ? 'PayKassa' : 'Other');
    $order->save();
    return $order;
};

$attach_snapshot = static function (WC_Order $order, array $settings, string $system = 'Ethereum_ERC20', string $amount = '2.000000', ?string $invoice_hash = null): PaymentSnapshot {
    $context = (new SciCredentialStore())->retain($settings);
    $invoice_hash ??= hash('sha256', 'invoice-' . $order->get_id() . '-' . wp_generate_uuid4());
    $snapshot = new PaymentSnapshot(
        $order->get_id(),
        (string) $order->get_total(),
        (string) $order->get_currency(),
        $system,
        'USDT',
        $invoice_hash,
        gmdate('c'),
        'yes' === ($settings['testmode'] ?? 'no'),
        'hosted',
        $context,
        null,
        (string) $settings['shop_id'],
        $amount
    );
    $order->update_meta_data(OrderMeta::SNAPSHOT, wp_json_encode($snapshot->to_array()));
    $order->update_meta_data(OrderMeta::STATE, PaymentState::AWAITING_PAYMENT);
    $order->update_meta_data(OrderMeta::CREDENTIAL_CONTEXT, $context);
    $order->update_meta_data(OrderMeta::PAYMENT_LINK_HASH, $invoice_hash);
    $order->save();
    return $snapshot;
};

$transaction_data = static function (WC_Order $order, string $transaction, array $overrides = array()): array {
    return array_replace(array(
        'transaction' => $transaction,
        'txid' => hash('sha256', 'txid-' . $transaction),
        'shop_id' => 'merchant-live',
        'order_id' => (string) $order->get_id(),
        'amount' => '2.000000',
        'fee' => '0.100000',
        'currency' => 'USDT',
        'system' => 'Ethereum_ERC20',
        'address_from' => '0xfromaddress',
        'address' => '0xdestinationaddress',
        'tag' => false,
        'confirmations' => 12,
        'required_confirmations' => '12',
        'status' => 'yes',
    ), $overrides);
};

$invoice_data = static function (WC_Order $order, PaymentSnapshot $snapshot, string $transaction, array $overrides = array()): array {
    return array_replace(array(
        'order_id' => (string) $order->get_id(),
        'transaction' => $transaction,
        'shop_id' => 'merchant-live',
        'amount' => $snapshot->payment_amount,
        'currency' => $snapshot->provider_currency,
        'system' => $snapshot->provider_system,
        'address' => '0xdestinationaddress',
        'tag' => false,
        'partial' => 'no',
        'hash' => $snapshot->provider_invoice_id,
    ), $overrides);
};

$add_fixture = static function (string $function, string $hash, array $settings, array $data) use (&$provider_responses): void {
    $provider_responses[$function][$hash] = array(
        'shop_id' => (string) $settings['shop_id'],
        'shop_password' => (string) $settings['shop_password'],
        'test' => 'yes' === ($settings['testmode'] ?? 'no') ? '1' : '0',
        'data' => $data,
    );
};

$event_row = static function (int $order_id, string $transaction): ?object {
    global $wpdb;
    $table = $wpdb->prefix . 'paykassa_events';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d AND provider_transaction_id = %s", $order_id, $transaction));
    return is_object($row) ? $row : null;
};

try {
    $expected_hpos = getenv('PAYKASSA_EXPECT_HPOS');
    paykassa_endpoint_assert(in_array($expected_hpos, array('yes', 'no'), true) && ('yes' === $expected_hpos) === OrderUtil::custom_orders_table_usage_is_enabled(), 'Requested HPOS store must be active for endpoint tests.');

    foreach (
        array(
        MerchantEndpointUrls::INVOICE_NOTIFICATION,
        MerchantEndpointUrls::SUCCESS_RETURN,
        MerchantEndpointUrls::FAILURE_RETURN,
        MerchantEndpointUrls::TRANSACTION_NOTIFICATION,
        ) as $endpoint
    ) {
        paykassa_endpoint_assert(false !== has_action('woocommerce_api_' . $endpoint), 'Every PayKassa merchant endpoint must be registered: ' . $endpoint);
    }
    $urls = new MerchantEndpointUrls($live_settings);
    paykassa_endpoint_assert('https://ocelot-dribble-creature.ngrok-free.dev/?wc-api=wc_gateway_paykassa' === $urls->invoice_notification_url(), 'Invoice Merchant URL must use the external base override.');
    paykassa_endpoint_assert('https://ocelot-dribble-creature.ngrok-free.dev/?wc-api=wc_gateway_paykassa_return' === $urls->success_return_url(), 'Success Merchant URL must use its independently configured public browser base.');
    paykassa_endpoint_assert('https://ocelot-dribble-creature.ngrok-free.dev/?wc-api=wc_gateway_paykassa_cancel' === $urls->failure_return_url(), 'Failure Merchant URL must use its independently configured public browser base.');
    paykassa_endpoint_assert('https://ocelot-dribble-creature.ngrok-free.dev/?wc-api=wc_gateway_paykassa_transaction' === $urls->transaction_notification_url(), 'Transaction Merchant URL must use the external base override.');
    $split_urls = new MerchantEndpointUrls(array_replace($live_settings, array('browser_return_base_url' => '')));
    paykassa_endpoint_assert(home_url('/?wc-api=wc_gateway_paykassa_return') === $split_urls->success_return_url(), 'Empty browser return override must independently fall back to the canonical WordPress origin.');
    paykassa_endpoint_assert(home_url('/?wc-api=wc_gateway_paykassa_cancel') === $split_urls->failure_return_url(), 'Split-origin failure return must preserve the canonical WordPress origin.');
    paykassa_endpoint_assert($urls->server_callback_base_url() === $urls->browser_return_base_url(), 'Same-public-origin configuration must be supported explicitly.');
    paykassa_endpoint_assert($split_urls->server_callback_base_url() !== $split_urls->browser_return_base_url(), 'Split callback/browser origin configuration must be supported explicitly.');
    wp_set_current_user(1);
    ob_start();
    (new DiagnosticsPage())->render();
    $diagnostics = (string) ob_get_clean();
    paykassa_endpoint_assert(4 === substr_count($diagnostics, 'data-copy-target='), 'Diagnostics must render one Copy button for each of the four Merchant URLs.');
    foreach (array('URL of Invoice Payment Notifications', 'URL of successful payment', 'URL malfunction when paying', 'URL of Cryptocurrency Transaction Processor') as $merchant_label) {
        paykassa_endpoint_assert(str_contains($diagnostics, $merchant_label), 'Diagnostics must show the exact PayKassa Merchant field label: ' . $merchant_label);
    }
    paykassa_endpoint_assert(! str_contains($diagnostics, 'Legacy callback URL') && str_contains($diagnostics, $urls->transaction_notification_url()), 'Diagnostics must remove the legacy label and show the generated transaction URL.');
    paykassa_endpoint_assert(2 === substr_count($diagnostics, 'External override'), 'Diagnostics must report independent external sources for callback and browser return bases.');
    $original_https = $_SERVER['HTTPS'] ?? null;
    $_SERVER['HTTPS'] = 'on';
    $site_health = (new SiteHealth())->test_configuration();
    if (null === $original_https) {
        unset($_SERVER['HTTPS']);
    } else {
        $_SERVER['HTTPS'] = $original_https;
    }
    paykassa_endpoint_assert('good' === ($site_health['status'] ?? ''), 'HTTPS callback and browser return overrides must pass Site Health in Live mode.');

    if (! WC()->session instanceof WC_Session) {
        WC()->session = new WC_Session_Handler();
        WC()->session->init();
    }
    $owner_id = wp_create_user('paykassa-owner-' . wp_generate_password(8, false), wp_generate_password(24), 'owner@example.invalid');
    $other_id = wp_create_user('paykassa-other-' . wp_generate_password(8, false), wp_generate_password(24), 'other@example.invalid');
    paykassa_endpoint_assert(is_int($owner_id) && is_int($other_id), 'Return ownership fixtures require registered users.');
    $users[] = $owner_id;
    $users[] = $other_id;
    (new WP_User($owner_id))->set_role('customer');
    (new WP_User($other_id))->set_role('customer');
    $return_access = new BrowserReturnAccess();
    $return_controller = new BrowserReturnController($return_access, new Logger());

    $registered_paid = $make_order($owner_id);
    $orders[] = $registered_paid->get_id();
    $registered_paid->payment_complete('browser-fixture-paid');
    $paid_before_return = $payment_completions[$registered_paid->get_id()] ?? 0;
    wp_set_current_user($owner_id);
    $destination = $return_controller->destination('success', $registered_paid->get_id());
    paykassa_endpoint_assert($destination['authorized'] && $registered_paid->get_checkout_order_received_url() === $destination['url'], 'Registered owner of a paid order must reach the native order-received URL.');
    paykassa_endpoint_assert($paid_before_return === ($payment_completions[$registered_paid->get_id()] ?? 0), 'Successful browser return must not call payment_complete again.');
    wp_set_current_user($other_id);
    $denied = $return_controller->destination('success', $registered_paid->get_id());
    paykassa_endpoint_assert(! $denied['authorized'] && ! str_contains($denied['url'], $registered_paid->get_order_key()) && ! str_contains($denied['url'], (string) $registered_paid->get_id()), 'Registered non-owner fallback must not disclose an order key or order-specific URL.');

    $registered_pending = $make_order($owner_id);
    $orders[] = $registered_pending->get_id();
    $registered_pending->update_meta_data(OrderMeta::STATE, PaymentState::AWAITING_PAYMENT);
    $registered_pending->save();
    wp_set_current_user($owner_id);
    $pending_status = $registered_pending->get_status();
    $pending = $return_controller->destination('success', $registered_pending->get_id());
    $pending_repeat = $return_controller->destination('success', $registered_pending->get_id());
    $registered_pending = wc_get_order($registered_pending->get_id());
    paykassa_endpoint_assert(
        $registered_pending instanceof WC_Order
        && $pending['authorized']
        && 'browser_return_pending' === $pending['event']
        && $pending['url'] === $pending_repeat['url']
        && $pending_status === $registered_pending->get_status()
        && ! $registered_pending->is_paid()
        && 0 === ($payment_completions[$registered_pending->get_id()] ?? 0),
        'Early and repeated success returns must be idempotent UX-only redirects without settlement.'
    );

    wp_set_current_user(0);
    WC()->session->set('paykassa_return_orders', array());
    $guest = $make_order();
    $orders[] = $guest->get_id();
    $return_access->grant($guest);
    $grants = WC()->session->get('paykassa_return_orders', array());
    paykassa_endpoint_assert(is_array($grants) && isset($grants[(string) $guest->get_id()]) && ! str_contains(wp_json_encode($grants), $guest->get_order_key()), 'Guest return session must store only an HMAC marker, never the raw order key.');
    $guest_success = $return_controller->destination('success', $guest->get_id());
    paykassa_endpoint_assert($guest_success['authorized'] && $guest->get_checkout_order_received_url() === $guest_success['url'], 'Guest with a valid session marker must reach the native order-received URL.');
    WC()->session->set('paykassa_return_orders', array());
    $guest_denied = $return_controller->destination('success', $guest->get_id());
    paykassa_endpoint_assert(! $guest_denied['authorized'] && ! str_contains($guest_denied['url'], $guest->get_order_key()), 'Guest with a lost session must receive a generic fallback without order data.');

    $tampered = $make_order();
    $orders[] = $tampered->get_id();
    $tampered_result = $return_controller->destination('success', $tampered->get_id());
    paykassa_endpoint_assert(! $tampered_result['authorized'] && ! str_contains($tampered_result['url'], $tampered->get_order_key()), 'A tampered ungranted order ID must not expose its order URL.');
    $other_gateway = $make_order(0, 'cod');
    $orders[] = $other_gateway->get_id();
    $return_access->grant($other_gateway);
    paykassa_endpoint_assert(! $return_controller->destination('success', $other_gateway->get_id())['authorized'], 'A non-PayKassa order must never be exposed through PayKassa return endpoints.');

    $failure_guest = $make_order();
    $orders[] = $failure_guest->get_id();
    $return_access->grant($failure_guest);
    $failure_before_status = $failure_guest->get_status();
    $failure = $return_controller->destination('failure', $failure_guest->get_id());
    $failure_guest = wc_get_order($failure_guest->get_id());
    paykassa_endpoint_assert(
        $failure_guest instanceof WC_Order
        && $failure['authorized']
        && 'browser_cancel' === $failure['event']
        && $failure_guest->get_checkout_payment_url() === $failure['url']
        && $failure_before_status === $failure_guest->get_status()
        && ! $failure_guest->is_paid()
        && 0 === ($payment_completions[$failure_guest->get_id()] ?? 0),
        'Authorized failure return must lead to native payment retry without cancellation or settlement.'
    );
    $return_access->grant($registered_paid);
    wp_set_current_user($owner_id);
    $paid_failure = $return_controller->destination('failure', $registered_paid->get_id());
    paykassa_endpoint_assert($paid_failure['authorized'] && $registered_paid->get_checkout_order_received_url() === $paid_failure['url'] && $paid_before_return === ($payment_completions[$registered_paid->get_id()] ?? 0), 'Failure return for an already-paid order must use order-received and never initiate another payment.');

    update_option('woocommerce_paykassa_settings', array_replace($live_settings, array('minimum_payment_directions' => 'Ethereum_ERC20:USDT=5')), false);
    $minimum_order = $make_order(0, 'paykassa', '2.000000');
    $orders[] = $minimum_order->get_id();
    $_POST['paykassa_direction'] = 'ethereum_erc20:USDT';
    $before_minimum_create = $create_calls;
    $minimum_result = (new PayKassaGateway())->process_payment($minimum_order->get_id());
    paykassa_endpoint_assert('failure' === $minimum_result['result'] && $before_minimum_create === $create_calls, 'Merchant direction minimum must reject before sci_create_order without inventing a provider minimum.');
    unset($_POST['paykassa_direction']);
    update_option('woocommerce_paykassa_settings', $live_settings, false);

    $processor = new WebhookProcessor(new WebhookEventStore(), new Logger());
    $transaction_controller = new TransactionNotificationController($processor, new Logger());
    $resolver = new WebhookCredentialResolver();

    $pending_transaction_order = $make_order();
    $orders[] = $pending_transaction_order->get_id();
    $attach_snapshot($pending_transaction_order, $live_settings);
    $pending_hash = hash('sha256', 'pending-transaction-' . $pending_transaction_order->get_id());
    $pending_transaction_id = 'txn-pending-' . $pending_transaction_order->get_id();
    $add_fixture('sci_confirm_transaction_notification', $pending_hash, $live_settings, $transaction_data($pending_transaction_order, $pending_transaction_id, array('status' => 'no', 'confirmations' => 0)));
    $pending_evidence = $resolver->verify_transaction_notification($pending_hash, $pending_transaction_order->get_id());
    $pending_result = $transaction_controller->process_verified($pending_evidence);
    paykassa_endpoint_assert($pending_result['accepted'] && $pending_transaction_order->get_id() . '|success' === $pending_result['ack'] && 'pending' === $pending_result['outcome'], 'Verified status=no transaction must receive an exact success acknowledgement.');
    paykassa_endpoint_assert(! wc_get_order($pending_transaction_order->get_id())->is_paid() && null === $event_row($pending_transaction_order->get_id(), $pending_transaction_id), 'Verified status=no transaction must not settle or create a settlement event.');

    $transaction_first = $make_order();
    $orders[] = $transaction_first->get_id();
    $transaction_first_snapshot = $attach_snapshot($transaction_first, $live_settings);
    $transaction_first_id = 'txn-first-' . $transaction_first->get_id();
    $transaction_first_hash = hash('sha256', 'transaction-first-' . $transaction_first->get_id());
    $invoice_second_hash = hash('sha256', 'invoice-second-' . $transaction_first->get_id());
    $add_fixture('sci_confirm_transaction_notification', $transaction_first_hash, $live_settings, $transaction_data($transaction_first, $transaction_first_id));
    $add_fixture('sci_confirm_order', $invoice_second_hash, $live_settings, $invoice_data($transaction_first, $transaction_first_snapshot, $transaction_first_id));
    $transaction_first_result = $transaction_controller->process_verified($resolver->verify_transaction_notification($transaction_first_hash, $transaction_first->get_id()));
    $transaction_first_count = $payment_completions[$transaction_first->get_id()] ?? 0;
    $invoice_second_result = $processor->process($resolver->verify($invoice_second_hash, $transaction_first->get_id()), EvidenceSource::WEBHOOK_INVOICE);
    $transaction_first_event = $event_row($transaction_first->get_id(), $transaction_first_id);
    paykassa_endpoint_assert($transaction_first_result['accepted'] && $invoice_second_result['accepted'] && 1 === $transaction_first_count && 1 === ($payment_completions[$transaction_first->get_id()] ?? 0), 'Transaction IPN followed by invoice IPN must call payment_complete exactly once.');
    paykassa_endpoint_assert(is_object($transaction_first_event) && EvidenceSource::WEBHOOK_TRANSACTION === $transaction_first_event->source, 'First transaction channel must be recorded without becoming part of event identity.');
    $transaction_duplicate = $transaction_controller->process_verified($resolver->verify_transaction_notification($transaction_first_hash, $transaction_first->get_id()));
    paykassa_endpoint_assert($transaction_duplicate['accepted'] && 1 === ($payment_completions[$transaction_first->get_id()] ?? 0), 'Duplicate status=yes transaction notification must be acknowledged without settlement side effects.');

    $invoice_first = $make_order();
    $orders[] = $invoice_first->get_id();
    $invoice_first_snapshot = $attach_snapshot($invoice_first, $live_settings);
    $invoice_first_id = 'invoice-first-' . $invoice_first->get_id();
    $invoice_first_hash = hash('sha256', 'invoice-first-' . $invoice_first->get_id());
    $transaction_second_hash = hash('sha256', 'transaction-second-' . $invoice_first->get_id());
    $add_fixture('sci_confirm_order', $invoice_first_hash, $live_settings, $invoice_data($invoice_first, $invoice_first_snapshot, $invoice_first_id));
    $add_fixture('sci_confirm_transaction_notification', $transaction_second_hash, $live_settings, $transaction_data($invoice_first, $invoice_first_id));
    $invoice_first_result = $processor->process($resolver->verify($invoice_first_hash, $invoice_first->get_id()), EvidenceSource::WEBHOOK_INVOICE);
    $invoice_first_count = $payment_completions[$invoice_first->get_id()] ?? 0;
    $transaction_second_result = $transaction_controller->process_verified($resolver->verify_transaction_notification($transaction_second_hash, $invoice_first->get_id()));
    $invoice_first_event = $event_row($invoice_first->get_id(), $invoice_first_id);
    paykassa_endpoint_assert($invoice_first_result['accepted'] && $transaction_second_result['accepted'] && 1 === $invoice_first_count && 1 === ($payment_completions[$invoice_first->get_id()] ?? 0), 'Invoice IPN followed by transaction IPN must call payment_complete exactly once.');
    paykassa_endpoint_assert(is_object($invoice_first_event) && EvidenceSource::WEBHOOK_INVOICE === $invoice_first_event->source, 'First invoice channel must be recorded without source entering event identity.');

    foreach (
        array(
        'wrong_currency' => array('currency' => 'USDC'),
        'wrong_system' => array('system' => 'TRON_TRC20'),
        ) as $case => $overrides
    ) {
        $invoice_mismatch = $make_order();
        $orders[] = $invoice_mismatch->get_id();
        $invoice_mismatch_snapshot = $attach_snapshot($invoice_mismatch, $live_settings);
        $invoice_mismatch_id = 'invoice-' . $case . '-' . $invoice_mismatch->get_id();
        $invoice_mismatch_hash = hash('sha256', $invoice_mismatch_id);
        $add_fixture('sci_confirm_order', $invoice_mismatch_hash, $live_settings, $invoice_data($invoice_mismatch, $invoice_mismatch_snapshot, $invoice_mismatch_id, $overrides));
        $invoice_mismatch_result = $processor->process($resolver->verify($invoice_mismatch_hash, $invoice_mismatch->get_id()), EvidenceSource::WEBHOOK_INVOICE);
        $invoice_mismatch = wc_get_order($invoice_mismatch->get_id());
        paykassa_endpoint_assert(
            ! $invoice_mismatch_result['accepted']
            && $invoice_mismatch instanceof WC_Order
            && ! $invoice_mismatch->is_paid()
            && PaymentState::MANUAL_REVIEW === $invoice_mismatch->get_meta(OrderMeta::STATE, true),
            'Invoice IPN ' . $case . ' mismatch must preserve the existing fail-closed settlement behavior.'
        );
    }

    foreach (
        array(
        'wrong_shop' => array('shop_id' => 'merchant-other'),
        'wrong_amount' => array('amount' => '1.999999'),
        'wrong_currency' => array('currency' => 'USDC'),
        'wrong_system' => array('system' => 'TRON_TRC20'),
        ) as $case => $overrides
    ) {
        $mismatch_order = $make_order();
        $orders[] = $mismatch_order->get_id();
        $attach_snapshot($mismatch_order, $live_settings);
        $transaction_id = 'txn-' . $case . '-' . $mismatch_order->get_id();
        $hash = hash('sha256', $case . '-' . $mismatch_order->get_id());
        $add_fixture('sci_confirm_transaction_notification', $hash, $live_settings, $transaction_data($mismatch_order, $transaction_id, $overrides));
        $result = $transaction_controller->process_verified($resolver->verify_transaction_notification($hash, $mismatch_order->get_id()));
        $mismatch_order = wc_get_order($mismatch_order->get_id());
        $row = $event_row($mismatch_order->get_id(), $transaction_id);
        paykassa_endpoint_assert($result['accepted'] && 'manual_review' === $result['outcome'] && $mismatch_order instanceof WC_Order && ! $mismatch_order->is_paid() && PaymentState::MANUAL_REVIEW === $mismatch_order->get_meta(OrderMeta::STATE, true), 'Verified transaction ' . $case . ' mismatch must be durably acknowledged for manual review without fulfilment.');
        paykassa_endpoint_assert(is_object($row) && 'manual_review' === $row->status && EvidenceSource::WEBHOOK_TRANSACTION === $row->source, 'Transaction mismatch must persist one redacted manual-review event: ' . $case);
    }

    $ambiguous = $make_order();
    $orders[] = $ambiguous->get_id();
    $ambiguous_active = $attach_snapshot($ambiguous, $live_settings);
    $ambiguous_retired = new PaymentSnapshot(
        $ambiguous->get_id(),
        $ambiguous_active->expected_amount,
        $ambiguous_active->order_currency,
        $ambiguous_active->provider_system,
        $ambiguous_active->provider_currency,
        hash('sha256', 'retired-ambiguous-' . $ambiguous->get_id()),
        gmdate('c'),
        false,
        'hosted',
        $ambiguous_active->merchant_context,
        null,
        $ambiguous_active->merchant_shop_id,
        $ambiguous_active->payment_amount
    );
    $ambiguous->update_meta_data(OrderMeta::RETIRED_SNAPSHOTS, array($ambiguous_retired->fingerprint() => array('snapshot' => $ambiguous_retired->to_array(), 'retired_at' => gmdate('c'), 'reason' => 'test')));
    $ambiguous->save();
    $ambiguous_id = 'txn-ambiguous-' . $ambiguous->get_id();
    $ambiguous_hash = hash('sha256', 'ambiguous-' . $ambiguous->get_id());
    $add_fixture('sci_confirm_transaction_notification', $ambiguous_hash, $live_settings, $transaction_data($ambiguous, $ambiguous_id));
    $ambiguous_result = $transaction_controller->process_verified($resolver->verify_transaction_notification($ambiguous_hash, $ambiguous->get_id()));
    $ambiguous = wc_get_order($ambiguous->get_id());
    paykassa_endpoint_assert($ambiguous_result['accepted'] && 'manual_review' === $ambiguous_result['outcome'] && $ambiguous instanceof WC_Order && ! $ambiguous->is_paid() && 'transaction_invoice_ambiguous' === $ambiguous->get_meta('_paykassa_manual_review_reason', true), 'Transaction matching active and retired snapshots must never auto-settle without a provider invoice hash.');

    $retired_only = $make_order();
    $orders[] = $retired_only->get_id();
    $retired_snapshot = $attach_snapshot($retired_only, $live_settings);
    $retired_only->update_meta_data(OrderMeta::RETIRED_SNAPSHOTS, array($retired_snapshot->fingerprint() => array('snapshot' => $retired_snapshot->to_array(), 'retired_at' => gmdate('c'), 'reason' => 'test')));
    $retired_only->delete_meta_data(OrderMeta::SNAPSHOT);
    $retired_only->update_meta_data(OrderMeta::STATE, PaymentState::EXPIRED);
    $retired_only->save();
    $retired_id = 'txn-retired-' . $retired_only->get_id();
    $retired_hash = hash('sha256', 'retired-' . $retired_only->get_id());
    $add_fixture('sci_confirm_transaction_notification', $retired_hash, $live_settings, $transaction_data($retired_only, $retired_id));
    $retired_result = $transaction_controller->process_verified($resolver->verify_transaction_notification($retired_hash, $retired_only->get_id()));
    $retired_only = wc_get_order($retired_only->get_id());
    paykassa_endpoint_assert($retired_result['accepted'] && 'manual_review' === $retired_result['outcome'] && $retired_only instanceof WC_Order && ! $retired_only->is_paid(), 'Unique retired-invoice transaction notification must be acknowledged for manual review without fulfilment.');

    $rotation = $make_order();
    $orders[] = $rotation->get_id();
    $attach_snapshot($rotation, $live_settings);
    $rotated_settings = array_replace($live_settings, array('shop_password' => 'live-secret-new'));
    update_option('woocommerce_paykassa_settings', $rotated_settings, false);
    (new SciCredentialStore())->retain($rotated_settings);
    $rotation_id = 'txn-rotation-' . $rotation->get_id();
    $rotation_hash = hash('sha256', 'rotation-' . $rotation->get_id());
    $add_fixture('sci_confirm_transaction_notification', $rotation_hash, $live_settings, $transaction_data($rotation, $rotation_id));
    $requests_before_rotation = count($provider_requests);
    $rotation_evidence = $resolver->verify_transaction_notification($rotation_hash, $rotation->get_id());
    $rotation_request = $provider_requests[array_key_last($provider_requests)] ?? array();
    paykassa_endpoint_assert($requests_before_rotation + 1 === count($provider_requests) && 'live-secret-old' === ($rotation_request['shop_password'] ?? '') && '0' === ($rotation_request['test'] ?? ''), 'Transaction notification after credential rotation must use the retained old Live SCI profile.');
    paykassa_endpoint_assert($transaction_controller->process_verified($rotation_evidence)['accepted'] && wc_get_order($rotation->get_id())->is_paid(), 'Credential-rotated transaction evidence must settle through the common processor.');
    update_option('woocommerce_paykassa_settings', $live_settings, false);

    $test_profile_order = $make_order();
    $orders[] = $test_profile_order->get_id();
    $attach_snapshot($test_profile_order, $test_settings);
    $requests_before_test_profile = count($provider_requests);
    $test_rejected = false;
    try {
        $resolver->verify_transaction_notification(hash('sha256', 'test-profile-' . $test_profile_order->get_id()), $test_profile_order->get_id());
    } catch (PayKassaException $exception) {
        $test_rejected = true;
    }
    paykassa_endpoint_assert($test_rejected && $requests_before_test_profile === count($provider_requests), 'Transaction processor must reject Test credentials without contacting sci_confirm_transaction_notification.');

    $provider_error_order = $make_order();
    $orders[] = $provider_error_order->get_id();
    $attach_snapshot($provider_error_order, $live_settings);
    $requests_before_provider_error = count($provider_requests);
    $provider_error_rejected = false;
    try {
        $resolver->verify_transaction_notification(hash('sha256', 'unregistered-provider-error-' . $provider_error_order->get_id()), $provider_error_order->get_id());
    } catch (PayKassaException $exception) {
        $provider_error_rejected = true;
    }
    paykassa_endpoint_assert($provider_error_rejected && count($provider_requests) > $requests_before_provider_error && ! wc_get_order($provider_error_order->get_id())->is_paid(), 'Provider verification error must fail closed after a real sci_confirm_transaction_notification attempt.');

    $wrong_routing = $make_order();
    $orders[] = $wrong_routing->get_id();
    $attach_snapshot($wrong_routing, $live_settings);
    $routing_hash = hash('sha256', 'wrong-routing-' . $wrong_routing->get_id());
    $add_fixture('sci_confirm_transaction_notification', $routing_hash, $live_settings, $transaction_data($wrong_routing, 'txn-routing-' . $wrong_routing->get_id(), array('order_id' => (string) ($wrong_routing->get_id() + 1))));
    $routing_rejected = false;
    try {
        $resolver->verify_transaction_notification($routing_hash, $wrong_routing->get_id());
    } catch (PayKassaException $exception) {
        $routing_rejected = true;
    }
    paykassa_endpoint_assert($routing_rejected, 'Raw transaction order_id must remain only a routing hint and match independently verified order_id.');

    $requests_before_unknown = count($provider_requests);
    $unknown_rejected = false;
    try {
        $resolver->verify_transaction_notification(hash('sha256', 'unknown-order'), 999999999);
    } catch (PayKassaException $exception) {
        $unknown_rejected = true;
    }
    paykassa_endpoint_assert($unknown_rejected && $requests_before_unknown === count($provider_requests), 'Unknown transaction routing order must fail before provider verification.');

    $synthetic_unknown = new TransactionNotificationEvidence(999999999, 'txn-unknown', 'txid-unknown', 'merchant-live', '2', '0', 'USDT', 'Ethereum_ERC20', '', '', '', '1', '1', 'yes', 'fingerprint');
    paykassa_endpoint_assert(! $processor->process($synthetic_unknown, EvidenceSource::WEBHOOK_TRANSACTION)['accepted'], 'Verified DTO for an unavailable Woo order must not settle or acknowledge.');

    WP_CLI::success('PayKassa merchant endpoints: four URL registrations, ownership-safe browser returns, direction minimum, Live transaction verification, status=no, status=yes, cross-channel idempotency, mismatch/manual-review, retired ambiguity and credential rotation passed; HPOS=' . (OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off') . '.');
} finally {
    remove_filter('pre_http_request', $transport, 10);
    remove_action('woocommerce_payment_complete', $paid_hook);
    $_POST = array();
    wp_set_current_user($original_user);
    foreach ($orders as $order_id) {
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    global $wpdb;
    if ($orders) {
        $placeholders = implode(',', array_fill(0, count($orders), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}paykassa_events WHERE order_id IN ({$placeholders})", ...$orders));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}paykassa_invoice_locks WHERE order_id IN ({$placeholders})", ...$orders));
    }
    foreach ($users as $user_id) {
        wp_delete_user($user_id);
    }
    if (null === $original_settings) {
        delete_option('woocommerce_paykassa_settings');
    } else {
        update_option('woocommerce_paykassa_settings', $original_settings, false);
    }
    if (null === $original_profiles) {
        delete_option(SciCredentialStore::OPTION);
    } else {
        update_option(SciCredentialStore::OPTION, $original_profiles, false);
    }
    update_option('woocommerce_currency', $original_currency, false);
}
