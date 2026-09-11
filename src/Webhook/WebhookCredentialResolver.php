<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

use Al5dy\PayKassaWoo\Order\InvoiceLifecycleService;
use Al5dy\PayKassaWoo\Order\OrderMeta;
use Al5dy\PayKassaWoo\Order\PaymentSnapshot;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentEvidence;
use Al5dy\PayKassaWoo\PayKassa\Dto\TransactionNotificationEvidence;
use Al5dy\PayKassaWoo\PayKassa\Exception\WebhookVerificationException;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\PayKassa\SciCredentialStore;

/** Selects immutable invoice credentials before SCI verification. */
final class WebhookCredentialResolver
{
    private const MAX_CONTEXTS = 8;

    public function __construct(
        private readonly SciCredentialStore $credentials = new SciCredentialStore(),
        private readonly PayKassaClientFactory $factory = new PayKassaClientFactory()
    ) {
    }

    public function verify(string $private_hash, int $routing_order_id): PaymentEvidence
    {
        $order = wc_get_order($routing_order_id);
        if (! $order instanceof \WC_Order || 'paykassa' !== $order->get_payment_method()) {
            throw new WebhookVerificationException('The callback routing order is unavailable.');
        }
        $contexts = $this->contexts($order);
        if (array() === $contexts || count($contexts) > self::MAX_CONTEXTS) {
            throw new WebhookVerificationException('The callback credential context is unavailable or ambiguous.');
        }

        $last_rejection = null;
        foreach ($contexts as $context) {
            $settings = $this->credentials->settings_for_context($context);
            if (null === $settings) {
                continue;
            }
            try {
                $evidence = $this->factory->sci($settings)->verify_ipn($private_hash);
            } catch (WebhookVerificationException $exception) {
                $last_rejection = $exception;
                continue;
            }
            // The raw order ID is a routing hint only. Provider verification
            // must independently return the same technical Woo order ID.
            if ($evidence->order_id !== $routing_order_id) {
                throw new WebhookVerificationException('The verified order does not match the callback routing hint.');
            }
            return $evidence;
        }
        throw new WebhookVerificationException('No retained SCI credential verified the callback.', 0, $last_rejection);
    }

    public function verify_transaction_notification(string $private_hash, int $routing_order_id): TransactionNotificationEvidence
    {
        $order = wc_get_order($routing_order_id);
        if (! $order instanceof \WC_Order || 'paykassa' !== $order->get_payment_method()) {
            throw new WebhookVerificationException('The transaction callback routing order is unavailable.');
        }
        $contexts = $this->contexts($order);
        if (array() === $contexts || count($contexts) > self::MAX_CONTEXTS) {
            throw new WebhookVerificationException('The transaction callback credential context is unavailable or ambiguous.');
        }

        $last_rejection = null;
        foreach ($contexts as $context) {
            $settings = $this->credentials->settings_for_context($context);
            // PayKassa documents this SCI method as Live-only. Test profiles
            // are never sent to the provider for transaction notifications.
            if (null === $settings || 'yes' === $settings['testmode']) {
                continue;
            }
            try {
                $evidence = $this->factory->sci($settings)->verify_transaction_notification($private_hash);
            } catch (WebhookVerificationException $exception) {
                $last_rejection = $exception;
                continue;
            }
            if ($evidence->order_id !== $routing_order_id) {
                throw new WebhookVerificationException('The verified transaction order does not match the callback routing hint.');
            }
            return $evidence;
        }
        throw new WebhookVerificationException('No retained Live SCI credential verified the transaction callback.', 0, $last_rejection);
    }

    /** @return list<string> */
    private function contexts(\WC_Order $order): array
    {
        $contexts = array();
        $merchant_modes = array();
        $direct = $order->get_meta(OrderMeta::CREDENTIAL_CONTEXT, true);
        if (is_string($direct) && '' !== $direct) {
            $contexts[] = $direct;
        }
        $attempt = $order->get_meta(OrderMeta::INVOICE_ATTEMPT, true);
        if (is_array($attempt) && isset($attempt['merchant_context']) && is_string($attempt['merchant_context'])) {
            $contexts[] = $attempt['merchant_context'];
        }

        $active = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        $snapshots = $active instanceof PaymentSnapshot
            ? array_merge(array($active), InvoiceLifecycleService::retired_snapshots($order))
            : InvoiceLifecycleService::retired_snapshots($order);
        foreach ($snapshots as $snapshot) {
            if ('' !== $snapshot->merchant_context) {
                $contexts[] = $snapshot->merchant_context;
            }
            if ('' !== $snapshot->merchant_shop_id) {
                $merchant_modes[$snapshot->merchant_shop_id . ':' . $snapshot->environment()] = array($snapshot->merchant_shop_id, $snapshot->test_mode);
            }
        }
        // Only legacy snapshots without an explicit context need a lookup by
        // merchant+mode. New snapshots always select one exact secret version.
        if (array() === array_filter($contexts, static fn (string $context): bool => '' !== $context)) {
            foreach ($merchant_modes as $merchant_mode) {
                foreach ($this->credentials->contexts_for_merchant($merchant_mode[0], $merchant_mode[1]) as $context) {
                    $contexts[] = $context;
                }
            }
            // The oldest supported snapshots may contain neither a context nor
            // a merchant ID. Preserve their former current-settings behavior
            // only when the immutable Test/Live side still matches.
            if (array() === $contexts && $active instanceof PaymentSnapshot) {
                $current = get_option('woocommerce_paykassa_settings', array());
                $current_shop = is_array($current) && isset($current['shop_id']) && is_string($current['shop_id']) ? $current['shop_id'] : '';
                $current_secret = is_array($current) && isset($current['shop_password']) && is_string($current['shop_password']) ? $current['shop_password'] : '';
                $current_test = is_array($current) && 'yes' === ($current['testmode'] ?? 'no');
                if ('' !== $current_shop && '' !== $current_secret && $current_test === $active->test_mode) {
                    $contexts[] = SciCredentialStore::context($current_shop, $current_secret, $current_test);
                }
            }
        }
        return array_values(array_unique(array_filter($contexts, static fn (string $context): bool => (bool) preg_match('/^[a-f0-9]{64}$/', $context))));
    }
}
