<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa;

use Al5dy\PayKassaWoo\Infrastructure\Logger;

final class PayKassaClientFactory
{
    /** @param array<string, string> $settings */
    public function sci(array $settings): SciClient
    {
        return new SciClient((string) ( $settings['shop_id'] ?? '' ), (string) ( $settings['shop_password'] ?? '' ), 'yes' === ( $settings['testmode'] ?? 'no' ), new Logger());
    }
    /** @param array<string, string> $settings */
    public function api(array $settings): ApiClient
    {
        return new ApiClient((string) ( $settings['api_id'] ?? '' ), (string) ( $settings['api_password'] ?? '' ), 'yes' === ( $settings['testmode'] ?? 'no' ));
    }
}
