<?php

declare(strict_types=1);

namespace PayKassaWoo\Tools;

/**
 * Development-only observation tool: no WordPress bootstrap, no order
 * creation, no settlement. Every PayKassa request this probe makes hardcodes
 * the provider's own sandbox `test=1` flag, independent of the store's live
 * "Test mode" setting, so it can never move real funds.
 *
 * This talks to PayKassa directly and does not exercise the plugin's
 * runtime ApiClient/SciClient code paths.
 */
final class MerchantConnectivityProbe
{
    private const API_ENDPOINT = 'https://paykassa.app/api/0.9/index.php';
    private const SCI_ENDPOINT = 'https://paykassa.app/sci/0.4/index.php';
    private const WEBHOOK_ROUTE = 'wc_gateway_paykassa';
    private const MAX_BYTES = 262144;

    /**
     * @param array<string, string> $config
     * @return array<string, mixed>
     */
    public static function run(array $config): array
    {
        $report = array(
            'status' => 'observed',
            'release_ready' => false,
            'generated_at' => gmdate('c'),
            'public_site' => array('checked' => false, 'reason' => 'PAYKASSA_PUBLIC_SITE_URL not set'),
            'webhook_endpoint' => array('checked' => false, 'reason' => 'PAYKASSA_PUBLIC_SITE_URL not set'),
            'api_connectivity' => array('checked' => false, 'reason' => 'missing_api_credentials'),
            'sci_test_invoice' => array('checked' => false, 'reason' => 'missing_sci_credentials'),
        );

        $public_url = trim($config['public_site_url'] ?? '');
        if ('' !== $public_url && false !== filter_var($public_url, FILTER_VALIDATE_URL) && str_starts_with($public_url, 'https://')) {
            $report['public_site'] = self::check_public_site($public_url);
            $report['webhook_endpoint'] = self::check_webhook_endpoint($public_url);
        } elseif ('' !== $public_url) {
            $report['public_site'] = array('checked' => false, 'reason' => 'PAYKASSA_PUBLIC_SITE_URL must be an https:// URL');
            $report['webhook_endpoint'] = $report['public_site'];
        }

        $api_id = trim($config['api_id'] ?? '');
        $api_password = trim($config['api_password'] ?? '');
        $shop_id = trim($config['shop_id'] ?? '');
        if ('' !== $api_id && '' !== $api_password && '' !== $shop_id) {
            $api_test_mode = trim($config['api_test_mode'] ?? '') !== '' ? '0' !== $config['api_test_mode'] : true;
            $report['api_connectivity'] = self::check_api_connectivity($api_id, $api_password, $shop_id, $api_test_mode);
        }

        $shop_password = trim($config['shop_password'] ?? '');
        if ('' !== $shop_id && '' !== $shop_password) {
            $report['sci_test_invoice'] = self::check_sci_test_invoice(
                $shop_id,
                $shop_password,
                trim($config['system_id'] ?? '') !== '' ? $config['system_id'] : '30',
                trim($config['currency'] ?? '') !== '' ? $config['currency'] : 'USDT',
                trim($config['amount'] ?? '') !== '' ? $config['amount'] : '1'
            );
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private static function check_public_site(string $url): array
    {
        $result = self::http_request('GET', $url);
        $status = $result['http_status'];
        return array(
            'checked' => true,
            'url_host' => (string) parse_url($url, PHP_URL_HOST),
            'reachable' => null !== $status && $status >= 200 && $status < 400,
            'http_status' => $status,
            'ngrok_offline' => null !== $result['ngrok_error_code'],
            'transport_error' => $result['error'],
        );
    }

    /** @return array<string, mixed> */
    private static function check_webhook_endpoint(string $base_url): array
    {
        $url = rtrim($base_url, '/') . '/?wc-api=' . rawurlencode(self::WEBHOOK_ROUTE);
        $result = self::http_request('GET', $url);
        $status = $result['http_status'];
        return array(
            'checked' => true,
            // The callback route only accepts POST; a registered, reachable
            // route must answer an unrelated GET with 405, never 404/200.
            'expected_http_status' => 405,
            'http_status' => $status,
            'route_reachable' => 405 === $status,
            'ngrok_offline' => null !== $result['ngrok_error_code'],
            'transport_error' => $result['error'],
        );
    }

    /** @return array<string, mixed> */
    private static function check_api_connectivity(string $api_id, string $api_password, string $shop_id, bool $test_mode): array
    {
        $result = self::http_request('POST', self::API_ENDPOINT, array(
            'func' => 'api_get_merchant_info', 'shop_id' => $shop_id,
            'api_id' => $api_id, 'api_key' => $api_password, 'test' => $test_mode ? '1' : '0', 'domain' => '',
        ));
        $base = array('requested_test_mode' => $test_mode);
        if (null !== $result['error']) {
            return $base + array('checked' => true, 'connected' => false, 'reason' => 'transport_failed');
        }
        $decoded = json_decode($result['body'], true, 8);
        if (! is_array($decoded) || ! array_key_exists('error', $decoded) || ! is_bool($decoded['error'])) {
            return $base + array('checked' => true, 'connected' => false, 'reason' => 'invalid_envelope');
        }
        if (true === $decoded['error']) {
            // Provider messages may echo request credentials; never export them.
            // A rejection here can also mean these API keys are provisioned
            // for the other mode — retry with PAYKASSA_PROBE_API_TEST_MODE=0.
            return $base + array('checked' => true, 'connected' => false, 'reason' => 'provider_rejected');
        }
        return $base + array('checked' => true, 'connected' => true, 'reason' => null);
    }

    /** @return array<string, mixed> */
    private static function check_sci_test_invoice(string $shop_id, string $shop_password, string $system_id, string $currency, string $amount): array
    {
        $order_id = 900000000 + random_int(0, 99999999);
        $result = self::http_request('POST', self::SCI_ENDPOINT, array(
            'func' => 'sci_create_order', 'sci_id' => $shop_id, 'sci_key' => $shop_password,
            'test' => '1', 'domain' => '', 'amount' => $amount, 'system' => $system_id,
            'currency' => $currency, 'order_id' => (string) $order_id, 'comment' => 'paykassa-connectivity-probe',
        ));
        $base = array('checked' => true, 'system_id' => $system_id, 'currency' => $currency, 'amount' => $amount, 'probe_order_id' => $order_id);
        if (null !== $result['error']) {
            return $base + array('created' => false, 'reason' => 'transport_failed');
        }
        $decoded = json_decode($result['body'], true, 8);
        if (! is_array($decoded) || ! array_key_exists('error', $decoded) || ! is_bool($decoded['error'])) {
            return $base + array('created' => false, 'reason' => 'invalid_envelope');
        }
        if (true === $decoded['error']) {
            return $base + array('created' => false, 'reason' => 'provider_rejected');
        }
        $data = $decoded['data'] ?? null;
        $url = is_array($data) && isset($data['url']) && is_string($data['url']) ? $data['url'] : '';
        if ('' === $url) {
            return $base + array('created' => false, 'reason' => 'no_payment_url');
        }
        return $base + array(
            'created' => true,
            'reason' => null,
            'payment_url_host' => (string) parse_url($url, PHP_URL_HOST),
            'payment_link_fingerprint' => substr(hash('sha256', $url), 0, 12),
        );
    }

    /**
     * @param array<string, string>|null $post_fields
     * @return array{http_status: ?int, body: string, error: ?string, ngrok_error_code: ?string}
     */
    private static function http_request(string $method, string $url, ?array $post_fields = null): array
    {
        if (! extension_loaded('curl')) {
            return array('http_status' => null, 'body' => '', 'error' => 'curl_required', 'ngrok_error_code' => null);
        }
        $handle = curl_init($url);
        if (false === $handle) {
            return array('http_status' => null, 'body' => '', 'error' => 'transport_unavailable', 'ngrok_error_code' => null);
        }
        $body = '';
        $ngrok_error_code = null;
        try {
            $options = array(
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => 'GET' === $method,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$ngrok_error_code): int {
                    if (1 === preg_match('/^ngrok-error-code:\s*(\S+)/i', $line, $matches)) {
                        $ngrok_error_code = $matches[1];
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            );
            if (null !== $post_fields) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = http_build_query($post_fields);
                $options[CURLOPT_HTTPHEADER] = array('Content-Type: application/x-www-form-urlencoded');
            }
            curl_setopt_array($handle, $options);
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (false === $ok) {
                return array('http_status' => null, 'body' => '', 'error' => 'transport_failed', 'ngrok_error_code' => $ngrok_error_code);
            }
            return array('http_status' => $status, 'body' => $body, 'error' => null, 'ngrok_error_code' => $ngrok_error_code);
        } finally {
            curl_close($handle);
        }
    }
}
