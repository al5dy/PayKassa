<?php

declare(strict_types=1);

namespace PayKassaWoo\Tools;

/** Development-only observation tool: no WordPress bootstrap or settlement. */
final class HistoryContractProbe
{
    private const ENDPOINT = 'https://paykassa.app/api/0.9/index.php';
    private const MAX_BYTES = 2097152;

    /**
     * Describe every item without exporting field values, tokens or messages.
     * This is an observation, not a provider guarantee or a settlement fixture.
     *
     * @return array<string, mixed>
     */
    public static function describe(string $body): array
    {
        try {
            $response = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('invalid_json');
        }
        if (! $response instanceof \stdClass || ! isset($response->error) || ! is_bool($response->error)) {
            throw new \RuntimeException('invalid_envelope');
        }
        if ($response->error) {
            // Provider messages may echo request credentials. Never export them.
            throw new \RuntimeException('provider_rejected');
        }
        $data = $response->data ?? null;
        if (! $data instanceof \stdClass || ! isset($data->list) || ! is_array($data->list) || count($data->list) > 1000) {
            throw new \RuntimeException('invalid_history_list');
        }
        $count = $data->page_count ?? null;
        if (is_string($count) && preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $count)) {
            $count = (int) $count;
        }
        if (! is_int($count) || $count < 0 || $count > 10000) {
            throw new \RuntimeException('invalid_page_count');
        }
        $items = array();
        foreach ($data->list as $row) {
            $items[] = self::shape($row);
        }
        return array(
            'status' => 'observed', 'release_ready' => false,
            'page_count' => $count, 'item_count' => count($items),
            'items' => $items,
        );
    }

    /** @return string|array<string, mixed> */
    private static function shape(mixed $value, int $depth = 0): string|array
    {
        if ($depth >= 8) {
            return 'depth_limit';
        }
        if ($value instanceof \stdClass) {
            $fields = array();
            foreach (get_object_vars($value) as $key => $child) {
                // Arbitrary map keys may themselves contain addresses/tokens.
                // Only known protocol field names are exported verbatim.
                $known = array('order_id', 'private_hash', 'transaction', 'invoice', 'invoice_id', 'hash', 'txid', 'shop_id', 'amount', 'amount_pay', 'currency', 'system', 'status', 'test', 'type', 'partial', 'address', 'address_from', 'tag', 'fee', 'confirmations', 'required_confirmations', 'static', 'date', 'datetime', 'date_update', 'comment', 'payment_id', 'explorer_address_link', 'explorer_transaction_link');
                $label = in_array($key, $known, true) ? $key : 'unknown_field_' . count($fields);
                $fields[$label] = self::shape($child, $depth + 1);
            }
            return array('object' => $fields);
        }
        if (is_array($value)) {
            $types = array();
            foreach ($value as $child) {
                $type = self::shape($child, $depth + 1);
                if (! in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
            return array('array_item_shapes' => $types);
        }
        return get_debug_type($value);
    }

    /** @param array<string, string> $config @return array<string, mixed> */
    public static function run(array $config, string $from, string $to, int $page): array
    {
        foreach (array('api_id', 'api_password', 'shop_id') as $key) {
            if (! isset($config[$key]) || '' === trim($config[$key])) {
                throw new \RuntimeException('missing_sandbox_credentials');
            }
        }
        $start = strtotime($from);
        $end = strtotime($to);
        if (false === $start || false === $end || $start < 0 || $start >= $end || $end > time() + 60 || $end - $start > 31 * 86400 || $page < 0 || $page >= 10000) {
            throw new \RuntimeException('invalid_range_or_page');
        }
        if (! extension_loaded('curl')) {
            throw new \RuntimeException('curl_required');
        }
        $handle = curl_init(self::ENDPOINT);
        if (false === $handle) {
            throw new \RuntimeException('transport_unavailable');
        }
        $body = '';
        try {
            curl_setopt_array($handle, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(array(
                    'func' => 'api_get_history', 'api_id' => $config['api_id'], 'api_key' => $config['api_password'],
                    'shop_id' => $config['shop_id'], 'test' => '1', 'domain' => '',
                    'type' => 'pay_in', 'status' => 'yes', 'page_num' => (string) $page,
                    'datetime_start' => gmdate('c', $start), 'datetime_end' => gmdate('c', $end),
                )),
                CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ));
            $ok = curl_exec($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (false === $ok || $code < 200 || $code > 299) {
                throw new \RuntimeException('transport_failed');
            }
        } finally {
            curl_close($handle);
        }
        return array('endpoint' => self::ENDPOINT, 'requested_test_mode' => true, 'at' => gmdate('c'), 'page' => $page) + self::describe($body);
    }
}
