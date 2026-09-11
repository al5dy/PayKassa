<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

use Al5dy\PayKassaWoo\Infrastructure\Logger;

/** Browser-only UX redirects. This controller never verifies or settles money. */
final class BrowserReturnController
{
    public function __construct(
        private readonly BrowserReturnAccess $access = new BrowserReturnAccess(),
        private readonly Logger $logger = new Logger()
    ) {
    }

    public function success(): void
    {
        $this->handle('success');
    }

    public function failure(): void
    {
        $this->handle('failure');
    }

    /** @return array{authorized:bool,url:string,event:string,notice:string,notice_type:string} */
    public function destination(string $kind, int $order_id): array
    {
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (! $order instanceof \WC_Order || ! $this->access->is_authorized($order)) {
            return array(
                'authorized' => false,
                'url' => $this->fallback_url(),
                'event' => 'browser_return_denied',
                'notice' => __('We could not verify access to that order. Sign in or return to checkout to continue.', 'paykassa'),
                'notice_type' => 'error',
            );
        }
        if ('failure' === $kind && ! $order->is_paid()) {
            return array(
                'authorized' => true,
                'url' => $order->get_checkout_payment_url(),
                'event' => 'browser_cancel',
                'notice' => __('The PayKassa payment was not completed. You can try again or choose another payment method.', 'paykassa'),
                'notice_type' => 'notice',
            );
        }
        if ('success' === $kind && ! $order->is_paid()) {
            return array(
                'authorized' => true,
                'url' => $order->get_checkout_order_received_url(),
                'event' => 'browser_return_pending',
                'notice' => __('Your cryptocurrency payment is being confirmed. The order status will update automatically.', 'paykassa'),
                'notice_type' => 'notice',
            );
        }
        return array(
            'authorized' => true,
            'url' => $order->get_checkout_order_received_url(),
            'event' => 'browser_return_success',
            'notice' => '',
            'notice_type' => 'notice',
        );
    }

    private function handle(string $kind): void
    {
        if ('GET' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) {
            status_header(405);
            header('Allow: GET');
            exit;
        }
        $raw = isset($_GET['order_id']) && is_string($_GET['order_id']) ? wp_unslash($_GET['order_id']) : '';
        $order_id = preg_match('/^[1-9][0-9]{0,18}$/', $raw)
            ? filter_var($raw, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)))
            : false;
        $destination = $this->destination('failure' === $kind ? 'failure' : 'success', false === $order_id ? 0 : (int) $order_id);
        if ('' !== $destination['notice']) {
            wc_add_notice($destination['notice'], $destination['notice_type']);
        }
        $this->logger->log(
            $destination['authorized'] ? 'info' : 'warning',
            $destination['event'],
            false === $order_id ? array() : array('order_id' => (int) $order_id)
        );
        nocache_headers();
        wp_safe_redirect($destination['url']);
        exit;
    }

    private function fallback_url(): string
    {
        if (is_user_logged_in()) {
            $account = wc_get_page_permalink('myaccount');
            if (is_string($account) && '' !== $account) {
                return $account;
            }
        }
        $checkout = wc_get_checkout_url();
        return '' !== $checkout ? $checkout : home_url('/');
    }
}
