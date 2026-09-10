<?php

/** Mail isolation for the throwaway integration site, installed as an MU plugin. */

declare(strict_types=1);

if (! defined('PAYKASSA_TEST_DATABASE') || true !== PAYKASSA_TEST_DATABASE) {
    return;
}

// WordPress and WooCommerce may dispatch queued emails at CLI shutdown, before
// or after individual test filters are registered. Never use the host's mailer.
add_filter('pre_wp_mail', '__return_true');

// Emulate a currency extension before WooCommerce caches its currency list.
add_filter('woocommerce_currencies', static fn(array $currencies): array => $currencies + array('ETH' => 'Ethereum'));
