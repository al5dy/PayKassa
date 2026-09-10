<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Infrastructure;

final class Logger
{
    /** @param array<string, scalar|null> $context */
    public function log(string $level, string $event, array $context = array()): void
    {
        $settings = get_option('woocommerce_paykassa_settings', array());
        if ('yes' !== ( $settings['debug'] ?? 'no' ) && in_array($level, array( 'debug', 'info' ), true)) {
            return;
        }
        $context['source'] = 'paykassa';
        $context['event'] = $event;
        wc_get_logger()->log($level, $event, $context);
    }

    public static function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }
}
