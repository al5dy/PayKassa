<?php

use Al5dy\PayKassaWoo\Blocks\PayKassaPaymentMethod;
use Al5dy\PayKassaWoo\Gateway\PayKassaGateway;
use Al5dy\PayKassaWoo\Gateway\GatewayAvailability;
use Al5dy\PayKassaWoo\Order\OrderMeta;
use Al5dy\PayKassaWoo\Order\PaymentSnapshot;
use Al5dy\PayKassaWoo\Order\PaymentState;
use Al5dy\PayKassaWoo\Order\InvoiceLifecycleService;
use Al5dy\PayKassaWoo\Order\InvoiceLockStatus;
use Al5dy\PayKassaWoo\Order\InvoiceLockStore;
use Al5dy\PayKassaWoo\Order\InvoiceReservation;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentEvidence;
use Al5dy\PayKassaWoo\Webhook\WebhookEventStore;
use Al5dy\PayKassaWoo\Webhook\WebhookCredentialResolver;
use Al5dy\PayKassaWoo\Webhook\WebhookProcessor;
use Al5dy\PayKassaWoo\Infrastructure\DatabaseMutex;
use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\SciCredentialStore;
use Automattic\WooCommerce\Utilities\OrderUtil;

function paykassa_smoke_assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function paykassa_smoke_order(string $currency = 'BTC', string $total = '1.00000000'): WC_Order
{
    $order = wc_create_order();
    $order->set_currency($currency);
    $order->set_total($total);
    $order->set_payment_method('paykassa');
    $order->set_payment_method_title('PayKassa');
    $order->save();
    return $order;
}

$original_settings = get_option('woocommerce_paykassa_settings', null);
$original_credential_profiles = get_option(SciCredentialStore::OPTION, null);
$original_currency = get_option('woocommerce_currency');
$orders = array();
$transactions = array();
$active_order_id = 0;
$create_calls = 0;
$amounts = array();
$currencies = array();
$systems = array();
$hashes = array();
$create_behaviors = array();
$verification_profiles = array();

$settings = array(
    'enabled' => 'yes',
    'shop_id' => 'test-merchant',
    'shop_password' => 'test-secret',
    'testmode' => 'yes',
    'title' => 'PayKassa',
    'description' => 'Test payment',
    'enabled_systems' => 'bitcoin',
    'accepted_order_currencies' => array('BTC'),
    'enabled_payment_directions' => array('bitcoin:BTC'),
);

update_option('woocommerce_paykassa_settings', $settings, false);
update_option('woocommerce_currency', 'BTC', false);

$transport = static function ($preempt, array $args, string $url) use (&$active_order_id, &$create_calls, &$amounts, &$currencies, &$systems, &$hashes, &$create_behaviors, &$verification_profiles) {
    $body = is_array($args['body'] ?? null) ? $args['body'] : array();
    if ('https://currency.paykassa.pro/pairs.php' === $url) {
        $pairs = isset($body['pairs']) && is_array($body['pairs']) ? $body['pairs'] : array();
        $pair = isset($pairs[0]) && is_string($pairs[0]) ? $pairs[0] : '';
        if ('USD_USDT' !== $pair && 'USD_BTC' !== $pair && 'USD_ETH' !== $pair && 'EUR_USDT' !== $pair) {
            throw new RuntimeException('Unexpected PayKassa currency pair: ' . $pair);
        }
        $rates = array('USD_USDT' => '0.99843217', 'USD_BTC' => '0.00001295', 'USD_ETH' => '0.00042', 'EUR_USDT' => '1.17000000');
        $payload = array('error' => false, 'message' => 'OK', 'data' => array(array($pair => $rates[$pair])));
        return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
    }
    $function = $body['func'] ?? '';
    if ('sci_create_order' === $function) {
        if ('1' !== ($body['test'] ?? '') || 'test-secret' !== ($body['sci_key'] ?? '')) {
            throw new RuntimeException('PayKassa SCI sandbox create requests must use the configured test credential profile.');
        }
        ++$create_calls;
        $request_order_id = (int) $body['order_id'];
        $amounts[$request_order_id] = (string) $body['amount'];
        $currencies[$request_order_id] = (string) $body['currency'];
        $systems[$request_order_id] = array(11 => 'BitCoin', 12 => 'Ethereum', 30 => 'TRON_TRC20')[(int) $body['system']] ?? '';
        $behavior = $create_behaviors[$request_order_id] ?? 'success';
        if ('timeout' === $behavior) {
            return new WP_Error('http_request_failed', 'Simulated ambiguous timeout after provider create may have started.');
        }
        if ('reject' === $behavior) {
            $payload = array('error' => true, 'message' => 'Rejected by sandbox fixture.', 'data' => array());
            return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
        }
        $hash = hash('sha256', (string) $body['order_id'] . ':' . $create_calls);
        $hashes[$request_order_id] = $hash;
        $payload = array('error' => false, 'message' => 'OK', 'data' => array('url' => 'https://paykassa.app/pay/smoke?hash=' . $hash, 'params' => array('hash' => $hash)));
    } elseif ('sci_confirm_order' === $function) {
        $verification_profiles[] = array('shop_id' => $body['sci_id'] ?? '', 'shop_password' => $body['sci_key'] ?? '', 'test' => $body['test'] ?? '');
        if ('test-merchant' !== ($body['sci_id'] ?? '') || 'test-secret' !== ($body['sci_key'] ?? '') || '1' !== ($body['test'] ?? '')) {
            $payload = array('error' => true, 'message' => 'Wrong SCI credential profile.', 'data' => array());
            return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
        }
        $payload = array('error' => false, 'message' => 'OK', 'data' => array(
            'order_id' => (string) $active_order_id,
            'transaction' => 'transaction-' . $active_order_id,
            'hash' => $hashes[$active_order_id] ?? hash('sha256', (string) $active_order_id),
            'shop_id' => 'test-merchant',
            'currency' => $currencies[$active_order_id] ?? 'BTC',
            'system' => $systems[$active_order_id] ?? 'BitCoin',
            'amount' => $amounts[$active_order_id] ?? '1.00000000',
            'address' => 'bc1qsmoketestaddress',
            // Confirmed LIVE behavior for payment systems without a memo/tag.
            'tag' => false,
            'partial' => 'no',
        ));
    } else {
        throw new RuntimeException('Unexpected PayKassa request: ' . $function);
    }
    return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
};

add_filter('pre_http_request', $transport, 10, 3);
$mail_transport = static function (): bool {
    return true;
};
add_filter('pre_wp_mail', $mail_transport);

try {
    $expected_hpos = getenv('PAYKASSA_EXPECT_HPOS');
    paykassa_smoke_assert(in_array($expected_hpos, array('yes', 'no'), true) && ('yes' === $expected_hpos) === OrderUtil::custom_orders_table_usage_is_enabled(), 'Requested HPOS store must actually be active for the invoice lifecycle smoke.');

    $blocks_handles = (new PayKassaPaymentMethod())->get_payment_method_script_handles();
    $blocks_script = wp_scripts()->registered['paykassa-blocks'] ?? null;
    paykassa_smoke_assert(
        array('paykassa-blocks') === $blocks_handles
        && $blocks_script instanceof _WP_Dependency
        && isset($blocks_script->textdomain, $blocks_script->translations_path)
        && 'paykassa' === $blocks_script->textdomain
        && wp_normalize_path(PAYKASSA_DIR . 'languages') === wp_normalize_path($blocks_script->translations_path),
        'Checkout Blocks script must register the paykassa translation domain and packaged languages path.'
    );

    $gateway = new PayKassaGateway();
    paykassa_smoke_assert($gateway->is_available(), 'Gateway must be available for configured BTC checkout.');

    $order = paykassa_smoke_order();
    $orders[] = $order->get_id();
    $_POST['paykassa_system'] = 'bitcoin';
    $first = $gateway->process_payment($order->get_id());
    paykassa_smoke_assert('success' === $first['result'], 'Hosted payment creation must succeed with a verified transport response.');
    paykassa_smoke_assert(1 === $create_calls, 'First payment attempt must create exactly one invoice.');
    $order = wc_get_order($order->get_id());
    $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
    paykassa_smoke_assert($snapshot instanceof PaymentSnapshot && $snapshot->order_id === $order->get_id(), 'Snapshot must use the internal WooCommerce order ID.');
    paykassa_smoke_assert($snapshot instanceof PaymentSnapshot && ! $snapshot->expiration_is_known() && null === $snapshot->expires_at, 'SCI createOrder has no documented expiry field; the snapshot must record an explicit unknown expiration, never an invented quote TTL.');
    paykassa_smoke_assert(InvoiceLockStatus::CREATED === (new InvoiceLockStore())->status($order->get_id()) && PaymentState::AWAITING_PAYMENT === $order->get_meta(OrderMeta::STATE, true), 'A successful create must finish the explicit creating -> created -> awaiting lifecycle.');

    $retry = $gateway->process_payment($order->get_id());
    paykassa_smoke_assert('success' === $retry['result'] && 1 === $create_calls, 'Payment retry must reuse the active invoice and not call provider creation again.');

    // Simulate another request holding the global credential-store mutex. A
    // previously retained identical profile is read-only and must not make a
    // new checkout compete for that mutex or fail temporarily.
    $contended_order = paykassa_smoke_order();
    $orders[] = $contended_order->get_id();
    $before_contended_create = $create_calls;
    $credential_mutex = new DatabaseMutex();
    paykassa_smoke_assert($credential_mutex->acquire('sci-credential-store'), 'Credential contention fixture must acquire its connection-bound mutex.');
    try {
        $contended_result = $gateway->process_payment($contended_order->get_id());
    } finally {
        $credential_mutex->release();
    }
    paykassa_smoke_assert('success' === $contended_result['result'] && $before_contended_create + 1 === $create_calls, 'Checkout must use the matching credential fast-path while another worker owns the credential-store mutex.');

    $legacy_order = paykassa_smoke_order();
    $orders[] = $legacy_order->get_id();
    $legacy_hash = hash('sha256', 'legacy-active-link-' . $legacy_order->get_id());
    $hashes[$legacy_order->get_id()] = $legacy_hash;
    $legacy_context = hash('sha256', "test-merchant\0test");
    $legacy_snapshot = new PaymentSnapshot($legacy_order->get_id(), '1.00000000', 'BTC', 'BitCoin', 'BTC', $legacy_hash, '2026-09-10T00:00:00+00:00', true, 'hosted', $legacy_context, '', 'test-merchant');
    $legacy_json = (string) wp_json_encode($legacy_snapshot->to_array());
    $legacy_url = 'https://paykassa.app/pay/legacy?hash=' . $legacy_hash;
    $legacy_order->update_meta_data(OrderMeta::SNAPSHOT, $legacy_json);
    $legacy_order->update_meta_data(OrderMeta::STATE, PaymentState::AWAITING_PAYMENT);
    $legacy_order->update_meta_data('_paykassa_redirect_url', $legacy_url);
    $legacy_order->save();
    global $wpdb;
    paykassa_smoke_assert(1 === $wpdb->insert($wpdb->prefix . 'paykassa_invoice_locks', array(
        'order_id' => $legacy_order->get_id(),
        'status' => InvoiceLockStatus::CREATED,
        'snapshot_hash' => hash('sha256', $legacy_json),
        'lease_expires_at' => null,
        'attempts' => 1,
        'created_at' => '2026-09-10 00:00:00',
        'updated_at' => '2026-09-10 00:00:00',
    )), 'Legacy active-invoice fixture must be inserted.');
    $before_legacy_reuse = $create_calls;
    $legacy_retry = $gateway->process_payment($legacy_order->get_id());
    paykassa_smoke_assert('success' === $legacy_retry['result'] && $legacy_url === $legacy_retry['redirect'] && $before_legacy_reuse === $create_calls, 'Upgrade must preserve and reuse a schema-v3 snapshot hashed with expires_at="" without creating a duplicate invoice.');

    $active_order_id = $order->get_id();
    $client = (new PayKassaClientFactory())->sci($settings);
    $evidence = $client->verify_ipn('valid-private-hash-for-smoke-test');
    $transactions[] = $evidence->transaction_id;
    $processor = new WebhookProcessor(new WebhookEventStore(), new Logger());
    $accepted = $processor->process($evidence);
    paykassa_smoke_assert($accepted['accepted'] && $order->get_id() . '|success' === $accepted['ack'], 'Verified payment must receive documented PayKassa acknowledgement.');
    $order = wc_get_order($order->get_id());
    paykassa_smoke_assert($order instanceof WC_Order && $order->has_status(wc_get_is_paid_statuses()), 'Verified matching evidence must settle the WooCommerce order.');
    $notes_before_duplicate = count(wc_get_order_notes(array('order_id' => $order->get_id())));
    $duplicate = $processor->process($evidence);
    $notes_after_duplicate = count(wc_get_order_notes(array('order_id' => $order->get_id())));
    paykassa_smoke_assert($duplicate['accepted'] && $notes_before_duplicate === $notes_after_duplicate, 'Duplicate webhook must be acknowledged without a second order note or settlement.');

    $rotated_order = paykassa_smoke_order();
    $orders[] = $rotated_order->get_id();
    $_POST['paykassa_system'] = 'bitcoin';
    paykassa_smoke_assert('success' === $gateway->process_payment($rotated_order->get_id())['result'], 'Credential-rotation fixture invoice must be created with the original Test Mode profile.');
    $rotated_order = wc_get_order($rotated_order->get_id());
    $rotated_snapshot = PaymentSnapshot::from_json((string) $rotated_order->get_meta(OrderMeta::SNAPSHOT, true));
    paykassa_smoke_assert(
        $rotated_snapshot instanceof PaymentSnapshot
        && SciCredentialStore::context('test-merchant', 'test-secret', true) === $rotated_snapshot->merchant_context,
        'A new snapshot must reference the exact secret-specific SCI credential profile.'
    );
    paykassa_smoke_assert(! str_contains((string) $rotated_order->get_meta(OrderMeta::SNAPSHOT, true), 'test-secret'), 'The immutable order snapshot must never contain the retained SCI secret.');
    update_option('woocommerce_paykassa_settings', array_replace($settings, array('shop_password' => 'rotated-live-secret', 'testmode' => 'no')), false);
    $active_order_id = $rotated_order->get_id();
    $profiles_before_rotation_callback = count($verification_profiles);
    $rotated_evidence = (new WebhookCredentialResolver())->verify('valid-private-hash-after-credential-rotation', $rotated_order->get_id());
    $transactions[] = $rotated_evidence->transaction_id;
    paykassa_smoke_assert(
        $profiles_before_rotation_callback + 1 === count($verification_profiles)
        && 'test-secret' === $verification_profiles[array_key_last($verification_profiles)]['shop_password']
        && '1' === $verification_profiles[array_key_last($verification_profiles)]['test']
        && 'test' === $rotated_evidence->environment,
        'A callback for an unfinished Test Mode invoice must use its retained old secret/mode after current settings switch to a new Live profile.'
    );
    paykassa_smoke_assert($processor->process($rotated_evidence)['accepted'], 'Provider evidence verified with the retained invoice credential profile must settle normally.');
    $rotated_order = wc_get_order($rotated_order->get_id());
    paykassa_smoke_assert($rotated_order instanceof WC_Order && $rotated_order->is_paid(), 'Credential rotation must not strand the unfinished old invoice.');

    $routing_mismatch_rejected = false;
    try {
        (new WebhookCredentialResolver())->verify('valid-private-hash-with-forged-routing-order', $legacy_order->get_id());
    } catch (PayKassaException $exception) {
        $routing_mismatch_rejected = true;
    }
    paykassa_smoke_assert($routing_mismatch_rejected, 'A raw callback order ID must be rejected when provider verification returns a different technical order ID.');

    $active_order_id = $legacy_order->get_id();
    $legacy_rotated_evidence = (new WebhookCredentialResolver())->verify('valid-private-hash-for-legacy-snapshot-after-rotation', $legacy_order->get_id());
    $transactions[] = $legacy_rotated_evidence->transaction_id;
    paykassa_smoke_assert('test' === $legacy_rotated_evidence->environment && $processor->process($legacy_rotated_evidence)['accepted'], 'A pre-versioning snapshot must resolve through the once-seeded legacy credential alias after settings rotation.');
    $legacy_order = wc_get_order($legacy_order->get_id());
    paykassa_smoke_assert($legacy_order instanceof WC_Order && $legacy_order->is_paid(), 'Credential rotation must not strand a legacy active invoice.');
    update_option('woocommerce_paykassa_settings', $settings, false);

    $fiat_settings = array_replace($settings, array(
        'accepted_order_currencies' => array('USD', 'EUR', 'USDT'),
        'enabled_payment_directions' => array('tron_trc20:USDT', 'bitcoin:BTC', 'ethereum:ETH'),
    ));
    update_option('woocommerce_paykassa_settings', $fiat_settings, false);
    update_option('woocommerce_currency', 'USD', false);
    $fiat_availability = new GatewayAvailability();
    paykassa_smoke_assert($fiat_availability->for_currency('USD', $fiat_settings), 'USD must have an enabled PayKassa conversion direction in the settings model: ' . implode(',', array_keys($fiat_availability->directions_for_order_currency('USD', $fiat_settings))));
    $fiat_gateway = new PayKassaGateway();
    paykassa_smoke_assert($fiat_gateway->is_available(), 'Gateway must be available for an explicitly enabled USD order currency.');

    $usd_usdt = paykassa_smoke_order('USD', '100.00');
    $orders[] = $usd_usdt->get_id();
    $_POST['paykassa_direction'] = 'tron_trc20:USDT';
    paykassa_smoke_assert('success' === $fiat_gateway->process_payment($usd_usdt->get_id())['result'], 'USD to USDT payment creation must use the PayKassa quote.');
    $usd_usdt = wc_get_order($usd_usdt->get_id());
    $usd_usdt_snapshot = PaymentSnapshot::from_json((string) $usd_usdt->get_meta(OrderMeta::SNAPSHOT, true));
    paykassa_smoke_assert($usd_usdt_snapshot instanceof PaymentSnapshot && '100.00' === $usd_usdt_snapshot->expected_amount && 'USD' === $usd_usdt_snapshot->order_currency && '99.843217' === $usd_usdt_snapshot->payment_amount && 'USDT' === $usd_usdt_snapshot->provider_currency && 'TRON_TRC20' === $usd_usdt_snapshot->provider_system && 'USD_USDT' === $usd_usdt_snapshot->conversion_pair, 'Fiat snapshot must retain independent order and payment money with the PayKassa quote.');
    paykassa_smoke_assert('USD' === $usd_usdt->get_currency() && '100.00' === $usd_usdt->get_total(), 'Creating a crypto invoice must not alter WooCommerce order money.');
    $active_order_id = $usd_usdt->get_id();
    $usd_usdt_evidence = $client->verify_ipn('valid-private-hash-for-usd-usdt');
    $transactions[] = $usd_usdt_evidence->transaction_id;
    paykassa_smoke_assert($processor->process($usd_usdt_evidence)['accepted'], 'Verified USD to USDT evidence must settle through the common idempotent pipeline.');
    $usd_usdt = wc_get_order($usd_usdt->get_id());
    paykassa_smoke_assert($usd_usdt instanceof WC_Order && $usd_usdt->has_status(wc_get_is_paid_statuses()) && 'USD' === $usd_usdt->get_currency(), 'Fiat order must remain USD after crypto settlement.');
    unset($_POST['paykassa_direction']);

    $lifecycle = new InvoiceLifecycleService();
    $expired_order = paykassa_smoke_order();
    $orders[] = $expired_order->get_id();
    $_POST['paykassa_system'] = 'bitcoin';
    $before_expired_create = $create_calls;
    paykassa_smoke_assert('success' === $gateway->process_payment($expired_order->get_id())['result'], 'Expiry lifecycle fixture invoice must be created.');
    $expired_order = wc_get_order($expired_order->get_id());
    $expired_snapshot = PaymentSnapshot::from_json((string) $expired_order->get_meta(OrderMeta::SNAPSHOT, true));
    paykassa_smoke_assert($expired_snapshot instanceof PaymentSnapshot, 'Created invoice must have a snapshot before retirement.');
    $retired_hash = $expired_snapshot->provider_invoice_id;
    $lifecycle->expire_current($expired_order, 'integration_confirmed_invoice_unusable', 1);
    $expired_order = wc_get_order($expired_order->get_id());
    paykassa_smoke_assert(
        PaymentState::EXPIRED === $expired_order->get_meta(OrderMeta::STATE, true)
        && InvoiceLockStatus::EXPIRED === (new InvoiceLockStore())->status($expired_order->get_id())
        && '' === $expired_order->get_meta(OrderMeta::SNAPSHOT, true)
        && '' === $expired_order->get_meta('_paykassa_redirect_url', true)
        && 1 === count(InvoiceLifecycleService::retired_snapshots($expired_order)),
        'Retiring a created invoice must archive its immutable snapshot, clear the active redirect, and make exactly one replacement eligible.'
    );
    paykassa_smoke_assert('success' === $gateway->process_payment($expired_order->get_id())['result'] && $before_expired_create + 2 === $create_calls, 'An explicitly retired invoice must allow exactly one replacement create.');
    $expired_order = wc_get_order($expired_order->get_id());
    $replacement_snapshot = PaymentSnapshot::from_json((string) $expired_order->get_meta(OrderMeta::SNAPSHOT, true));
    paykassa_smoke_assert($replacement_snapshot instanceof PaymentSnapshot && ! hash_equals($retired_hash, $replacement_snapshot->provider_invoice_id), 'Replacement invoice identity must be distinct from the archived invoice identity.');

    $retired_transaction = 'retired-' . wp_generate_uuid4();
    $transactions[] = $retired_transaction;
    $retired_evidence = new PaymentEvidence(
        $expired_order->get_id(),
        $retired_transaction,
        hash('sha256', 'retired-private-hash'),
        $expired_snapshot->payment_amount,
        $expired_snapshot->provider_currency,
        $expired_snapshot->provider_system,
        'retired-payment-address',
        '',
        'test-merchant',
        $retired_hash,
        'test'
    );
    $retired_result = $processor->process($retired_evidence);
    $expired_order = wc_get_order($expired_order->get_id());
    paykassa_smoke_assert($retired_result['accepted'] && 'manual_review' === $retired_result['outcome'] && ! $expired_order->is_paid() && PaymentState::MANUAL_REVIEW === $expired_order->get_meta(OrderMeta::STATE, true), 'A provider-verified payment for a retired invoice must be acknowledged and held for manual review, never auto-fulfilled against its replacement.');

    $uncertain_order = paykassa_smoke_order();
    $orders[] = $uncertain_order->get_id();
    $create_behaviors[$uncertain_order->get_id()] = 'timeout';
    $before_timeout = $create_calls;
    paykassa_smoke_assert('failure' === $gateway->process_payment($uncertain_order->get_id())['result'], 'An ambiguous SCI timeout must fail checkout safely.');
    $uncertain_order = wc_get_order($uncertain_order->get_id());
    paykassa_smoke_assert(
        $before_timeout + 1 === $create_calls
        && InvoiceLockStatus::UNCERTAIN === (new InvoiceLockStore())->status($uncertain_order->get_id())
        && PaymentState::INVOICE_UNCERTAIN === $uncertain_order->get_meta(OrderMeta::STATE, true),
        'An ambiguous timeout must persist an uncertain lifecycle state in both the durable lock and WooCommerce diagnostics.'
    );
    $create_behaviors[$uncertain_order->get_id()] = 'success';
    paykassa_smoke_assert('failure' === $gateway->process_payment($uncertain_order->get_id())['result'] && $before_timeout + 1 === $create_calls, 'Uncertain must never reclaim automatically or call SCI a second time.');
    $lifecycle->resolve_uncertain_as_failed($uncertain_order, 1);
    $uncertain_order = wc_get_order($uncertain_order->get_id());
    paykassa_smoke_assert(InvoiceLockStatus::FAILED === (new InvoiceLockStore())->status($uncertain_order->get_id()) && PaymentState::INVOICE_FAILED === $uncertain_order->get_meta(OrderMeta::STATE, true), 'Explicit merchant resolution must atomically make one uncertain attempt retryable.');
    paykassa_smoke_assert('success' === $gateway->process_payment($uncertain_order->get_id())['result'] && $before_timeout + 2 === $create_calls, 'A manually resolved uncertain attempt must permit one new provider create.');

    $rejected_order = paykassa_smoke_order();
    $orders[] = $rejected_order->get_id();
    $create_behaviors[$rejected_order->get_id()] = 'reject';
    $before_reject = $create_calls;
    paykassa_smoke_assert('failure' === $gateway->process_payment($rejected_order->get_id())['result'], 'A documented provider rejection must fail checkout.');
    $rejected_order = wc_get_order($rejected_order->get_id());
    paykassa_smoke_assert(InvoiceLockStatus::FAILED === (new InvoiceLockStore())->status($rejected_order->get_id()) && PaymentState::INVOICE_FAILED === $rejected_order->get_meta(OrderMeta::STATE, true), 'A definitive error=true create rejection must be failed, not uncertain.');
    $create_behaviors[$rejected_order->get_id()] = 'success';
    paykassa_smoke_assert('success' === $gateway->process_payment($rejected_order->get_id())['result'] && $before_reject + 2 === $create_calls, 'A definitive rejected create must be retryable without manual uncertain resolution.');

    $wrong_merchant = paykassa_smoke_order();
    $orders[] = $wrong_merchant->get_id();
    $_POST['paykassa_system'] = 'bitcoin';
    paykassa_smoke_assert('success' === $gateway->process_payment($wrong_merchant->get_id())['result'], 'Wrong-merchant fixture invoice must be created.');
    $active_order_id = $wrong_merchant->get_id();
    $wrong_merchant_evidence = $client->verify_ipn('valid-private-hash-for-wrong-merchant');
    $transactions[] = $wrong_merchant_evidence->transaction_id;
    $wrong_merchant_evidence = new PaymentEvidence($wrong_merchant_evidence->order_id, $wrong_merchant_evidence->transaction_id, $wrong_merchant_evidence->hash_fingerprint, $wrong_merchant_evidence->amount, $wrong_merchant_evidence->currency, $wrong_merchant_evidence->system, $wrong_merchant_evidence->address, $wrong_merchant_evidence->tag, 'another-merchant', $wrong_merchant_evidence->payment_link_hash, $wrong_merchant_evidence->environment);
    $wrong_merchant_result = $processor->process($wrong_merchant_evidence);
    $wrong_merchant = wc_get_order($wrong_merchant->get_id());
    paykassa_smoke_assert(! $wrong_merchant_result['accepted'] && $wrong_merchant instanceof WC_Order && PaymentState::MANUAL_REVIEW === $wrong_merchant->get_meta(OrderMeta::STATE, true) && ! $wrong_merchant->has_status(wc_get_is_paid_statuses()), 'Wrong merchant must not settle the order.');

    $wrong_hash = paykassa_smoke_order();
    $orders[] = $wrong_hash->get_id();
    $_POST['paykassa_system'] = 'bitcoin';
    paykassa_smoke_assert('success' === $gateway->process_payment($wrong_hash->get_id())['result'], 'Wrong-hash fixture invoice must be created.');
    $active_order_id = $wrong_hash->get_id();
    $wrong_hash_evidence = $client->verify_ipn('valid-private-hash-for-wrong-hash');
    $transactions[] = $wrong_hash_evidence->transaction_id;
    $wrong_hash_evidence = new PaymentEvidence($wrong_hash_evidence->order_id, $wrong_hash_evidence->transaction_id, $wrong_hash_evidence->hash_fingerprint, $wrong_hash_evidence->amount, $wrong_hash_evidence->currency, $wrong_hash_evidence->system, $wrong_hash_evidence->address, $wrong_hash_evidence->tag, $wrong_hash_evidence->shop_id, str_repeat('a', 64), $wrong_hash_evidence->environment);
    $wrong_hash_result = $processor->process($wrong_hash_evidence);
    $wrong_hash = wc_get_order($wrong_hash->get_id());
    paykassa_smoke_assert(! $wrong_hash_result['accepted'] && $wrong_hash instanceof WC_Order && PaymentState::MANUAL_REVIEW === $wrong_hash->get_meta(OrderMeta::STATE, true) && ! $wrong_hash->has_status(wc_get_is_paid_statuses()), 'Wrong payment-link hash must not settle the order.');

    $mismatch = paykassa_smoke_order();
    $orders[] = $mismatch->get_id();
    $_POST['paykassa_system'] = 'bitcoin';
    paykassa_smoke_assert('success' === $gateway->process_payment($mismatch->get_id())['result'], 'Mismatch fixture invoice must be created.');
    $active_order_id = $mismatch->get_id();
    $amounts[$active_order_id] = '0.99999999';
    $mismatch_evidence = $client->verify_ipn('valid-private-hash-for-mismatch');
    $transactions[] = $mismatch_evidence->transaction_id;
    $mismatch_result = $processor->process($mismatch_evidence);
    $mismatch = wc_get_order($mismatch->get_id());
    paykassa_smoke_assert(! $mismatch_result['accepted'] && $mismatch instanceof WC_Order && PaymentState::MANUAL_REVIEW === $mismatch->get_meta(OrderMeta::STATE, true) && ! $mismatch->has_status(wc_get_is_paid_statuses()), 'Wrong amount must not settle the order and must enter manual review.');

    $recovery = paykassa_smoke_order();
    $orders[] = $recovery->get_id();
    $_POST['paykassa_system'] = 'bitcoin';
    paykassa_smoke_assert('success' === $gateway->process_payment($recovery->get_id())['result'], 'Recovery fixture invoice must be created.');
    $active_order_id = $recovery->get_id();
    $recovery_evidence = $client->verify_ipn('valid-private-hash-for-recovery');
    $transactions[] = $recovery_evidence->transaction_id;
    // Simulate a fatal exactly after durable PayKassa metadata save and before
    // WC_Order::payment_complete(). Redelivery must resume, not reject PAID→PAID.
    $recovery->update_meta_data(OrderMeta::STATE, PaymentState::PAID);
    $recovery->update_meta_data(OrderMeta::TRANSACTION, $recovery_evidence->transaction_id);
    $recovery->save();
    $recovery_result = $processor->process($recovery_evidence);
    $recovery = wc_get_order($recovery->get_id());
    paykassa_smoke_assert($recovery_result['accepted'] && $recovery instanceof WC_Order && $recovery->has_status(wc_get_is_paid_statuses()), 'A webhook redelivery must recover settlement after save() before payment_complete().');

    $late = paykassa_smoke_order();
    $orders[] = $late->get_id();
    $_POST['paykassa_system'] = 'bitcoin';
    paykassa_smoke_assert('success' === $gateway->process_payment($late->get_id())['result'], 'Late-payment fixture invoice must be created.');
    $late->update_status('cancelled', 'Smoke-test cancellation.');
    $active_order_id = $late->get_id();
    $late_evidence = $client->verify_ipn('valid-private-hash-for-late-payment');
    $transactions[] = $late_evidence->transaction_id;
    $late_result = $processor->process($late_evidence);
    $late = wc_get_order($late->get_id());
    paykassa_smoke_assert($late_result['accepted'] && $late instanceof WC_Order && $late->has_status('on-hold') && PaymentState::MANUAL_REVIEW === $late->get_meta(OrderMeta::STATE, true), 'Cancelled-order late payment must not be lost or auto-fulfilled.');

    $store = new WebhookEventStore();
    $lease_transaction = 'lease-' . wp_generate_uuid4();
    $transactions[] = $lease_transaction;
    $lease_context = hash('sha256', 'test-merchant' . "\0test");
    $first_reservation = $store->acquire($lease_transaction, 'fingerprint', $order->get_id(), $lease_context, 'test');
    $second_reservation = $store->acquire($lease_transaction, 'fingerprint', $order->get_id(), $lease_context, 'test');
    paykassa_smoke_assert($first_reservation->acquired() && 'duplicate' === $second_reservation->status, 'Concurrent webhook reservations must have one owner.');
    global $wpdb;
    $events_table = $wpdb->prefix . 'paykassa_events';
    $wpdb->query($wpdb->prepare("UPDATE {$events_table} SET lease_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE event_key = %s", $first_reservation->event_key));
    $reclaimed = $store->acquire($lease_transaction, 'fingerprint', $order->get_id(), $lease_context, 'test');
    paykassa_smoke_assert($reclaimed->acquired() && ! $store->begin_settlement($first_reservation->event_key, $first_reservation->owner_token) && $store->begin_settlement($reclaimed->event_key, $reclaimed->owner_token) && ! $store->finish($reclaimed->event_key, $first_reservation->owner_token, 'processed') && $store->finish($reclaimed->event_key, $reclaimed->owner_token, 'processed'), 'Expired webhook lease must fence a stale worker and allow only the new owner to finalize.');

    $concurrent_order = paykassa_smoke_order();
    $orders[] = $concurrent_order->get_id();
    $lock_store = new InvoiceLockStore();
    $first_lock = $lock_store->acquire($concurrent_order->get_id());
    $second_lock = $lock_store->acquire($concurrent_order->get_id());
    paykassa_smoke_assert(InvoiceReservation::ACQUIRED === $first_lock->status && InvoiceReservation::BUSY === $second_lock->status && InvoiceLockStatus::PREPARING === $lock_store->status($concurrent_order->get_id()), 'Concurrent invoice preparation must have one reservation owner.');
    $live_worker_mutex = new DatabaseMutex();
    paykassa_smoke_assert($live_worker_mutex->acquire(InvoiceLockStore::creation_mutex_resource($concurrent_order->get_id())), 'The simulated live SCI worker must own the connection-bound creation mutex.');
    paykassa_smoke_assert($lock_store->begin_creation($concurrent_order->get_id(), $first_lock->owner_token), 'The owner must explicitly cross the remote provider-create boundary.');
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paykassa_invoice_locks SET lease_expires_at = '2000-01-01 00:00:00' WHERE order_id = %d", $concurrent_order->get_id()));
    $abandoned_remote = $lock_store->acquire($concurrent_order->get_id());
    paykassa_smoke_assert(InvoiceReservation::BUSY === $abandoned_remote->status && InvoiceLockStatus::UNCERTAIN === $lock_store->status($concurrent_order->get_id()), 'An expired lease after the provider boundary must freeze as uncertain and must never auto-reclaim.');
    $manual_release_blocked = false;
    try {
        $lifecycle->resolve_uncertain_as_failed($concurrent_order, 1);
    } catch (PayKassaException $exception) {
        $manual_release_blocked = true;
    } finally {
        $live_worker_mutex->release();
    }
    paykassa_smoke_assert($manual_release_blocked && InvoiceLockStatus::UNCERTAIN === $lock_store->status($concurrent_order->get_id()), 'Manual resolution must not release an uncertain lease while the original SCI worker still owns the connection-bound mutex.');
    $lifecycle->resolve_uncertain_as_failed($concurrent_order, 1);
    $resolved_remote = $lock_store->acquire($concurrent_order->get_id());
    paykassa_smoke_assert($resolved_remote->acquired(), 'Only explicit merchant resolution may release an abandoned remote create for retry.');
    paykassa_smoke_assert($lock_store->fail($concurrent_order->get_id(), $resolved_remote->owner_token), 'Resolved remote-create test reservation must be releasable.');

    $preparing_order = paykassa_smoke_order();
    $orders[] = $preparing_order->get_id();
    $preparing_first = $lock_store->acquire($preparing_order->get_id());
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paykassa_invoice_locks SET lease_expires_at = '2000-01-01 00:00:00' WHERE order_id = %d", $preparing_order->get_id()));
    $preparing_reclaimed = $lock_store->acquire($preparing_order->get_id());
    paykassa_smoke_assert(
        $preparing_first->acquired()
        && $preparing_reclaimed->acquired()
        && ! $lock_store->begin_creation($preparing_order->get_id(), $preparing_first->owner_token)
        && $lock_store->begin_creation($preparing_order->get_id(), $preparing_reclaimed->owner_token)
        && ! $lock_store->fail($preparing_order->get_id(), $preparing_first->owner_token)
        && $lock_store->fail($preparing_order->get_id(), $preparing_reclaimed->owner_token),
        'After a preparing lease is reclaimed, the stale owner must fail the final pre-SCI CAS and only the new owner may cross the remote-create boundary.'
    );

    WP_CLI::success('PayKassa smoke: invoice lifecycle/retry fencing, idempotency, matching IPN, duplicate IPN, merchant/hash/amount mismatch, and late payment passed; HPOS=' . (OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off') . '.');
} finally {
    remove_filter('pre_http_request', $transport, 10);
    // Keep the test-only mail transport installed through WordPress shutdown: queued
    // WooCommerce actions may dispatch after this finally block, and must not invoke
    // the local CLI's unavailable sendmail binary.
    $_POST = array();
    foreach ($orders as $order_id) {
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    global $wpdb;
    if ($transactions) {
        $placeholders = implode(',', array_fill(0, count($transactions), '%s'));
        $table = $wpdb->prefix . 'paykassa_events';
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE provider_transaction_id IN ({$placeholders})", ...$transactions));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}paykassa_invoice_locks WHERE order_id IN (" . implode(',', array_fill(0, count($orders), '%d')) . ')', ...$orders));
    if (null === $original_settings) {
        delete_option('woocommerce_paykassa_settings');
    } else {
        update_option('woocommerce_paykassa_settings', $original_settings, false);
    }
    if (null === $original_credential_profiles) {
        delete_option(SciCredentialStore::OPTION);
    } else {
        update_option(SciCredentialStore::OPTION, $original_credential_profiles, false);
    }
    update_option('woocommerce_currency', $original_currency, false);
}
