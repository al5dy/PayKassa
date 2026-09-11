<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

final class MerchantEndpointUrls
{
    public const INVOICE_NOTIFICATION = 'wc_gateway_paykassa';
    public const SUCCESS_RETURN = 'wc_gateway_paykassa_return';
    public const FAILURE_RETURN = 'wc_gateway_paykassa_cancel';
    public const TRANSACTION_NOTIFICATION = 'wc_gateway_paykassa_transaction';

    private readonly string $base_url;
    private readonly string $source;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = array(), ?string $home_base_url = null)
    {
        $home_base_url ??= home_url('/');
        $external = isset($settings['external_base_url']) && is_string($settings['external_base_url'])
            ? trim($settings['external_base_url'])
            : '';
        if ('' !== $external) {
            $this->base_url = self::normalize_external_base_url($external, 'yes' !== ($settings['testmode'] ?? 'no'));
            $this->source = 'external_override';
            return;
        }
        $this->base_url = self::with_trailing_slash($home_base_url);
        $this->source = 'wordpress_home';
    }

    public function invoice_notification_url(): string
    {
        return $this->endpoint(self::INVOICE_NOTIFICATION);
    }

    public function success_return_url(): string
    {
        return $this->endpoint(self::SUCCESS_RETURN);
    }

    public function failure_return_url(): string
    {
        return $this->endpoint(self::FAILURE_RETURN);
    }

    public function transaction_notification_url(): string
    {
        return $this->endpoint(self::TRANSACTION_NOTIFICATION);
    }

    public function base_url(): string
    {
        return $this->base_url;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function is_https(): bool
    {
        return 'https' === strtolower((string) (parse_url($this->base_url, PHP_URL_SCHEME) ?? ''));
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

    private function endpoint(string $name): string
    {
        return $this->base_url . '?wc-api=' . rawurlencode($name);
    }

    private static function with_trailing_slash(string $url): string
    {
        return rtrim($url, '/') . '/';
    }
}
