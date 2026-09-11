<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

final class BrowserReturnAccess
{
    private const SESSION_KEY = 'paykassa_return_orders';
    private const MAX_GUEST_ORDERS = 20;

    public function grant(\WC_Order $order): void
    {
        if (0 !== $order->get_user_id()) {
            return;
        }
        $session = $this->session();
        $fingerprint = $this->fingerprint($order);
        if (! $session instanceof \WC_Session || '' === $fingerprint) {
            return;
        }
        $grants = $session->get(self::SESSION_KEY, array());
        $grants = is_array($grants) ? $grants : array();
        $grants[(string) $order->get_id()] = $fingerprint;
        if (count($grants) > self::MAX_GUEST_ORDERS) {
            $grants = array_slice($grants, -self::MAX_GUEST_ORDERS, null, true);
        }
        $session->set(self::SESSION_KEY, $grants);
    }

    public function is_authorized(\WC_Order $order): bool
    {
        if ('paykassa' !== $order->get_payment_method()) {
            return false;
        }
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        $user_id = $order->get_user_id();
        if ($user_id > 0) {
            return is_user_logged_in() && $user_id === get_current_user_id();
        }
        $session = $this->session();
        if (! $session instanceof \WC_Session) {
            return false;
        }
        $grants = $session->get(self::SESSION_KEY, array());
        $stored = is_array($grants) ? ($grants[(string) $order->get_id()] ?? null) : null;
        $expected = $this->fingerprint($order);
        return is_string($stored) && '' !== $expected && hash_equals($expected, $stored);
    }

    private function fingerprint(\WC_Order $order): string
    {
        $key = $order->get_order_key();
        return '' === $key ? '' : hash_hmac('sha256', $key, wp_salt('auth'));
    }

    private function session(): ?\WC_Session
    {
        if (! function_exists('WC')) {
            return null;
        }
        $woocommerce = WC();
        return $woocommerce->session instanceof \WC_Session ? $woocommerce->session : null;
    }
}
