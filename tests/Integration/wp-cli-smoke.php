<?php

use Al5dy\PayKassaWoo\Gateway\PayKassaGateway;
use Al5dy\PayKassaWoo\Order\OrderMeta;
use Al5dy\PayKassaWoo\Order\PaymentSnapshot;
use Al5dy\PayKassaWoo\Order\PaymentState;
use Al5dy\PayKassaWoo\Order\InvoiceLockStore;
use Al5dy\PayKassaWoo\Order\InvoiceReservation;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentEvidence;
use Al5dy\PayKassaWoo\Webhook\WebhookEventStore;
use Al5dy\PayKassaWoo\Webhook\WebhookProcessor;
use Al5dy\PayKassaWoo\Infrastructure\Logger;

function paykassa_smoke_assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function paykassa_smoke_order(): WC_Order
{
    $order = wc_create_order();
    $order->set_currency('BTC');
    $order->set_total('1.00000000');
    $order->set_payment_method('paykassa');
    $order->set_payment_method_title('PayKassa');
    $order->save();
    return $order;
}

$original_settings = get_option('woocommerce_paykassa_settings', null);
$original_currency = get_option('woocommerce_currency');
$orders = array();
$transactions = array();
$active_order_id = 0;
$create_calls = 0;
$amounts = array();

$settings = array(
    'enabled' => 'yes',
    'shop_id' => 'test-merchant',
    'shop_password' => 'test-secret',
    'testmode' => 'yes',
    'title' => 'PayKassa',
    'description' => 'Test payment',
    'enabled_systems' => 'bitcoin',
);

update_option('woocommerce_paykassa_settings', $settings, false);
update_option('woocommerce_currency', 'BTC', false);

$transport = static function ($preempt, array $args, string $url) use (&$active_order_id, &$create_calls, &$amounts): array {
    $body = is_array($args['body'] ?? null) ? $args['body'] : array();
    $function = $body['func'] ?? '';
    if ('1' !== ( $body['test'] ?? '' )) {
        throw new RuntimeException('PayKassa SCI sandbox requests must send test=1.');
    }
    if ('sci_create_order' === $function) {
        ++$create_calls;
        $hash = hash('sha256', (string) $body['order_id']);
        $payload = array('error' => false, 'message' => 'OK', 'data' => array('url' => 'https://paykassa.app/pay/smoke?hash=' . $hash, 'params' => array('hash' => $hash)));
    } elseif ('sci_confirm_order' === $function) {
        $payload = array('error' => false, 'message' => 'OK', 'data' => array(
            'order_id' => (string) $active_order_id,
            'transaction' => 'transaction-' . $active_order_id,
            'hash' => hash('sha256', (string) $active_order_id),
            'shop_id' => 'test-merchant',
            'currency' => 'BTC',
            'system' => 'BitCoin',
            'amount' => $amounts[$active_order_id] ?? '1.00000000',
            'address' => 'bc1qsmoketestaddress',
            'tag' => '',
            'partial' => 'no',
        ));
    } else {
        throw new RuntimeException('Unexpected PayKassa request: ' . $function);
    }
    return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
};

add_filter('pre_http_request', $transport, 10, 3);
$mail_transport = static function (): bool { return true; };
add_filter('pre_wp_mail', $mail_transport);

try {
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

    $retry = $gateway->process_payment($order->get_id());
    paykassa_smoke_assert('success' === $retry['result'] && 1 === $create_calls, 'Payment retry must reuse the active invoice and not call provider creation again.');

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

    $wrong_merchant = paykassa_smoke_order();
    $orders[] = $wrong_merchant->get_id();
    $_POST['paykassa_system'] = 'bitcoin';
    paykassa_smoke_assert('success' === $gateway->process_payment($wrong_merchant->get_id())['result'], 'Wrong-merchant fixture invoice must be created.');
    $active_order_id = $wrong_merchant->get_id();
    $wrong_merchant_evidence = $client->verify_ipn('valid-private-hash-for-wrong-merchant');
    $transactions[] = $wrong_merchant_evidence->transaction_id;
    $wrong_merchant_evidence = new PaymentEvidence($wrong_merchant_evidence->order_id, $wrong_merchant_evidence->transaction_id, $wrong_merchant_evidence->hash_fingerprint, $wrong_merchant_evidence->amount, $wrong_merchant_evidence->currency, $wrong_merchant_evidence->system, $wrong_merchant_evidence->address, $wrong_merchant_evidence->tag, 'another-merchant', $wrong_merchant_evidence->payment_link_hash);
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
    $wrong_hash_evidence = new PaymentEvidence($wrong_hash_evidence->order_id, $wrong_hash_evidence->transaction_id, $wrong_hash_evidence->hash_fingerprint, $wrong_hash_evidence->amount, $wrong_hash_evidence->currency, $wrong_hash_evidence->system, $wrong_hash_evidence->address, $wrong_hash_evidence->tag, $wrong_hash_evidence->shop_id, str_repeat('a', 64));
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
    paykassa_smoke_assert(InvoiceReservation::ACQUIRED === $first_lock->status && InvoiceReservation::BUSY === $second_lock->status, 'Concurrent invoice creation must have one reservation owner.');

    WP_CLI::success('PayKassa HPOS smoke: invoice idempotency, matching IPN, duplicate IPN, merchant/hash/amount mismatch, and late payment passed.');
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
    update_option('woocommerce_currency', $original_currency, false);
}
