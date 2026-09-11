<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa;

use Al5dy\PayKassaWoo\PayKassa\Exception\ConfigurationException;
use Al5dy\PayKassaWoo\PayKassa\Exception\ProviderUnavailableException;
use Al5dy\PayKassaWoo\PayKassa\Exception\InvalidResponseException;
use Al5dy\PayKassaWoo\PayKassa\Dto\HistoryPage;

/**
 * Read-only PayKassa API 0.9 adapter for health checks and reconciliation.
 *
 * api_get_shop_txids is deliberately not exposed: sandbox can return TXIDs
 * for arbitrary invoice numbers, so that endpoint is not payment evidence.
 */
final class ApiClient
{
    private const ENDPOINT = 'https://paykassa.app/api/0.9/index.php';

    public function __construct(private readonly string $api_id, private readonly string $api_password, private readonly bool $test_mode)
    {
        if ('' === $api_id || '' === $api_password) {
            throw new ConfigurationException('API credentials are required for this operation.');
        }
    }

    /** @return array<string, mixed> */
    public function merchant_info(string $shop_id): array
    {
        $response = $this->request(array( 'func' => 'api_get_merchant_info', 'shop_id' => $shop_id ));
        if (true === $response['error']) {
            throw new ProviderUnavailableException('PayKassa API rejected the request.');
        }
        return $response;
    }

    public function history(string $shop_id, string $from, string $to, int $page = 0): HistoryPage
    {
        if ('' === $shop_id || $page < 0 || $page >= 10000) {
            throw new ConfigurationException('Invalid history request.');
        }
        $response = $this->request(array( 'func' => 'api_get_history', 'shop_id' => $shop_id, 'type' => 'pay_in', 'status' => 'yes', 'page_num' => (string) $page, 'datetime_start' => $from, 'datetime_end' => $to ));
        try {
            return HistoryPage::from_response($response, $page);
        } catch (InvalidResponseException $exception) {
            if (true === $response['error']) {
                throw new ProviderUnavailableException('PayKassa API rejected the request.', 0, $exception);
            }
            throw $exception;
        }
    }

    /** @param array<string, string> $payload @return array<string, mixed> */
    private function request(array $payload): array
    {
        $payload += array( 'api_id' => $this->api_id, 'api_key' => $this->api_password, 'test' => $this->test_mode ? '1' : '0', 'domain' => '' );
        $response = wp_remote_post(self::ENDPOINT, array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 2097152, 'body' => $payload ));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) > 299) {
            throw new ProviderUnavailableException('PayKassa API is temporarily unavailable.');
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true, 32, JSON_BIGINT_AS_STRING);
        if (! is_array($decoded) || ! array_key_exists('error', $decoded) || ! is_bool($decoded['error'])) {
            throw new InvalidResponseException('PayKassa API returned malformed data.');
        }
        return $decoded;
    }
}
