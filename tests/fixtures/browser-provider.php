<?php

/** Test-only PayKassa transport used by the disposable browser smoke site. */

declare(strict_types=1);

if (! defined('PAYKASSA_TEST_DATABASE') || true !== PAYKASSA_TEST_DATABASE || ! defined('PAYKASSA_BROWSER_TEST') || true !== PAYKASSA_BROWSER_TEST) {
    return;
}

add_action('init', static function (): void {
    if ('GET' === strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) && '1' === ($_GET['paykassa_browser_merchant_urls'] ?? null)) {
        $settings = get_option('woocommerce_paykassa_settings', array());
        $settings = is_array($settings) ? $settings : array();
        $urls = new \Al5dy\PayKassaWoo\Gateway\MerchantEndpointUrls($settings);
        wp_send_json(array(
            'invoice_notification_url' => $urls->invoice_notification_url(),
            'success_return_url' => $urls->success_return_url(),
            'failure_return_url' => $urls->failure_return_url(),
            'transaction_notification_url' => $urls->transaction_notification_url(),
        ));
    }
    if (
        'POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))
        || 'blocks' !== ($_GET['paykassa_browser_checkout'] ?? null)
        || 'paykassa-browser-fixture' !== ($_POST['token'] ?? null)
    ) {
        return;
    }
    $page_id = (int) get_option('paykassa_browser_blocks_checkout_id', 0);
    if ($page_id < 1) {
        status_header(500);
        exit;
    }
    update_option('woocommerce_checkout_page_id', $page_id, false);
    status_header(204);
    exit;
});

add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    $body = is_array($args['body'] ?? null) ? $args['body'] : array();
    if ('https://currency.paykassa.pro/pairs.php' === $url) {
        $payload = array('error' => false, 'message' => 'OK', 'data' => array(array('USD_USDT' => '1')));
        return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
    }
    if ('https://paykassa.app/sci/0.4/index.php' !== $url) {
        return $preempt;
    }
    if ('browser-shop' !== ($body['sci_id'] ?? '') || 'browser-secret' !== ($body['sci_key'] ?? '') || '0' !== ($body['test'] ?? '')) {
        $payload = array('error' => true, 'message' => 'Unknown browser-test credential.', 'data' => array());
        return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
    }
    $function = $body['func'] ?? '';
    if ('sci_create_order' === $function) {
        $order_id = (int) ($body['order_id'] ?? 0);
        $hash = hash('sha256', 'browser-invoice-' . $order_id);
        update_option('paykassa_browser_invoice_' . $order_id, array(
            'hash' => $hash,
            'amount' => (string) ($body['amount'] ?? ''),
            'currency' => (string) ($body['currency'] ?? ''),
            'system' => 32 === (int) ($body['system'] ?? 0) ? 'Ethereum_ERC20' : '',
        ), false);
        $payload = array('error' => false, 'message' => 'OK', 'data' => array(
            'url' => 'https://paykassa.app/browser-smoke?order_id=' . $order_id . '&hash=' . $hash,
            'params' => array('hash' => $hash),
        ));
    } elseif ('sci_confirm_order' === $function) {
        $private_hash = isset($body['private_hash']) && is_string($body['private_hash']) ? $body['private_hash'] : '';
        preg_match('/^browser-invoice-([1-9][0-9]*)-[a-f0-9]{16}$/', $private_hash, $matches);
        $order_id = isset($matches[1]) ? (int) $matches[1] : 0;
        $invoice = get_option('paykassa_browser_invoice_' . $order_id, array());
        if ($order_id < 1 || ! is_array($invoice) || '' === ($invoice['hash'] ?? '')) {
            $payload = array('error' => true, 'message' => 'Unknown browser-test invoice.', 'data' => array());
        } else {
            $payload = array('error' => false, 'message' => 'Payment successfully confirmed', 'data' => array(
                'order_id' => (string) $order_id,
                'transaction' => 'browser-transaction-' . $order_id,
                'shop_id' => 'browser-shop',
                'amount' => (string) $invoice['amount'],
                'currency' => (string) $invoice['currency'],
                'system' => (string) $invoice['system'],
                'address' => '0xbrowserdestination',
                'tag' => false,
                'partial' => 'no',
                'hash' => (string) $invoice['hash'],
            ));
        }
    } elseif ('sci_confirm_transaction_notification' === $function) {
        $private_hash = isset($body['private_hash']) && is_string($body['private_hash']) ? $body['private_hash'] : '';
        preg_match('/^browser-transaction-(pending|confirmed)-([1-9][0-9]*)-[a-f0-9]{16}$/', $private_hash, $matches);
        $status = 'confirmed' === ($matches[1] ?? '') ? 'yes' : 'no';
        $order_id = isset($matches[2]) ? (int) $matches[2] : 0;
        $invoice = get_option('paykassa_browser_invoice_' . $order_id, array());
        if ($order_id < 1 || ! is_array($invoice) || '' === ($invoice['hash'] ?? '')) {
            $payload = array('error' => true, 'message' => 'Unknown browser-test transaction.', 'data' => array());
        } else {
            $payload = array('error' => false, 'message' => 'Ok', 'data' => array(
                'order_id' => (string) $order_id,
                'transaction' => 'browser-transaction-' . $order_id,
                'txid' => hash('sha256', 'browser-chain-' . $order_id),
                'shop_id' => 'browser-shop',
                'amount' => (string) $invoice['amount'],
                'fee' => '0.000000',
                'currency' => (string) $invoice['currency'],
                'system' => (string) $invoice['system'],
                'address_from' => '0xbrowsersource',
                'address' => '0xbrowserdestination',
                'tag' => false,
                'confirmations' => 'yes' === $status ? 12 : 0,
                'required_confirmations' => 12,
                'status' => $status,
            ));
        }
    } else {
        $payload = array('error' => true, 'message' => 'Unknown browser-test method.', 'data' => array());
    }
    return array('headers' => array(), 'body' => wp_json_encode($payload), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
}, 10, 3);
