<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

final class MerchantEndpointUrls
{
    public const INVOICE_NOTIFICATION = 'wc_gateway_paykassa';
    public const SUCCESS_RETURN = 'wc_gateway_paykassa_return';
    public const FAILURE_RETURN = 'wc_gateway_paykassa_cancel';
    public const TRANSACTION_NOTIFICATION = 'wc_gateway_paykassa_transaction';

    private readonly string $server_callback_base_url;
    private readonly string $browser_return_base_url;
    private readonly string $server_callback_source;
    private readonly string $browser_return_source;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = array(), ?string $home_base_url = null)
    {
        $home_base_url ??= home_url('/');
        $home_base_url = self::with_trailing_slash($home_base_url);
        $live_mode = 'yes' !== ($settings['testmode'] ?? 'no');
        $callback_external = self::setting_value($settings, 'external_base_url');
        $browser_external = self::setting_value($settings, 'browser_return_base_url');

        if ('' !== $callback_external) {
            $this->server_callback_base_url = self::normalize_external_base_url($callback_external, $live_mode);
            $this->server_callback_source = 'external_override';
        } else {
            $this->server_callback_base_url = $home_base_url;
            $this->server_callback_source = 'wordpress_home';
        }

        if ('' !== $browser_external) {
            $this->browser_return_base_url = self::normalize_external_base_url($browser_external, $live_mode);
            $this->browser_return_source = 'external_override';
        } else {
            $this->browser_return_base_url = $home_base_url;
            $this->browser_return_source = 'wordpress_home';
        }
    }

    public function invoice_notification_url(): string
    {
        return self::endpoint($this->server_callback_base_url, self::INVOICE_NOTIFICATION);
    }

    public function success_return_url(): string
    {
        return self::endpoint($this->browser_return_base_url, self::SUCCESS_RETURN);
    }

    public function failure_return_url(): string
    {
        return self::endpoint($this->browser_return_base_url, self::FAILURE_RETURN);
    }

    public function transaction_notification_url(): string
    {
        return self::endpoint($this->server_callback_base_url, self::TRANSACTION_NOTIFICATION);
    }

    public function server_callback_base_url(): string
    {
        return $this->server_callback_base_url;
    }

    public function browser_return_base_url(): string
    {
        return $this->browser_return_base_url;
    }

    public function server_callback_source(): string
    {
        return $this->server_callback_source;
    }

    public function browser_return_source(): string
    {
        return $this->browser_return_source;
    }

    public function server_callbacks_use_https(): bool
    {
        return self::uses_https($this->server_callback_base_url);
    }

    public function browser_returns_use_https(): bool
    {
        return self::uses_https($this->browser_return_base_url);
    }

    public static function normalize_external_base_url(string $url, bool $live_mode): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (
            '' === $url
            || false === filter_var($url, FILTER_VALIDATE_URL)
            || ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! is_string($parts['scheme'])
            || ! is_string($parts['host'])
            || '' === $parts['host']
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || str_contains($url, '?')
            || str_contains($url, '#')
        ) {
            throw new \InvalidArgumentException('External PayKassa base URL must be an absolute URL without credentials, query string, or fragment.');
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, array('http', 'https'), true) || ($live_mode && 'https' !== $scheme)) {
            throw new \InvalidArgumentException('External PayKassa base URL must use HTTPS in Live mode.');
        }
        return self::with_trailing_slash($url);
    }

    private static function endpoint(string $base_url, string $name): string
    {
        return $base_url . '?wc-api=' . rawurlencode($name);
    }

    private static function uses_https(string $url): bool
    {
        return 'https' === strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
    }

    private static function with_trailing_slash(string $url): string
    {
        return rtrim($url, '/') . '/';
    }

    /** @param array<string, mixed> $settings */
    private static function setting_value(array $settings, string $key): string
    {
        if (! isset($settings[$key])) {
            return '';
        }
        if (! is_string($settings[$key])) {
            throw new \InvalidArgumentException('External PayKassa base URL must be text.');
        }
        return trim($settings[$key]);
    }
}
