<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

/** Maps WooCommerce browser destinations onto the configured public storefront origin. */
final class BrowserDestinationUrlMapper
{
    public const DESTINATION_FILTER = 'paykassa_browser_return_destination_url';

    private readonly string $canonical_base_url;
    private readonly string $browser_base_url;
    private readonly bool $has_browser_override;

    public function __construct(MerchantEndpointUrls $endpoint_urls, ?string $canonical_base_url = null)
    {
        $this->canonical_base_url = self::normalize_trusted_base($canonical_base_url ?? home_url('/'));
        $this->browser_base_url = self::normalize_trusted_base($endpoint_urls->browser_return_base_url());
        $this->has_browser_override = 'external_override' === $endpoint_urls->browser_return_source();
    }

    public static function from_current_settings(): self
    {
        $settings = get_option('woocommerce_paykassa_settings', array());
        $settings = is_array($settings) ? $settings : array();
        $canonical_base_url = home_url('/');

        return new self(new MerchantEndpointUrls($settings, $canonical_base_url), $canonical_base_url);
    }

    /**
     * Filters the mapped destination, then fails safely if another plugin changes its public origin.
     *
     * @param string $context Stable PayKassa browser event name.
     */
    public function map(string $native_destination, string $context): string
    {
        $mapped_destination = $this->map_native_destination($native_destination);
        $filtered_destination = apply_filters(
            self::DESTINATION_FILTER,
            $mapped_destination,
            $native_destination,
            $context
        );

        return is_string($filtered_destination) && $this->is_safe_browser_destination($filtered_destination)
            ? $filtered_destination
            : $mapped_destination;
    }

    /** Allow only this redirect's already-validated public browser host in wp_safe_redirect(). */
    public function safe_redirect(string $destination): bool
    {
        if (! $this->is_safe_browser_destination($destination)) {
            return false;
        }
        $host = parse_url($destination, PHP_URL_HOST);
        if (! is_string($host) || '' === $host) {
            return false;
        }

        /** @param list<string> $hosts @return list<string> */
        $allow_browser_host = static function (array $hosts) use ($host): array {
            if (! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
            return $hosts;
        };

        add_filter('allowed_redirect_hosts', $allow_browser_host);
        try {
            return wp_safe_redirect($destination);
        } finally {
            remove_filter('allowed_redirect_hosts', $allow_browser_host);
        }
    }

    public function is_safe_browser_destination(string $destination): bool
    {
        $destination_parts = self::url_parts($destination, true);
        $browser_parts = self::url_parts($this->browser_base_url, false);

        return null !== $destination_parts
            && null !== $browser_parts
            && self::same_origin($destination_parts, $browser_parts)
            && null !== self::relative_path($destination_parts['path'], $browser_parts['path']);
    }

    private function map_native_destination(string $native_destination): string
    {
        $destination_parts = self::url_parts($native_destination, true);
        $canonical_parts = self::url_parts($this->canonical_base_url, false);
        if (null === $destination_parts || null === $canonical_parts || ! self::same_origin($destination_parts, $canonical_parts)) {
            return $this->browser_base_url;
        }
        $relative_path = self::relative_path($destination_parts['path'], $canonical_parts['path']);
        if (null === $relative_path) {
            return $this->browser_base_url;
        }
        if (! $this->has_browser_override) {
            return $native_destination;
        }

        $mapped = $this->browser_base_url . $relative_path;
        if (null !== $destination_parts['query']) {
            $mapped .= '?' . $destination_parts['query'];
        }
        if (null !== $destination_parts['fragment']) {
            $mapped .= '#' . $destination_parts['fragment'];
        }
        return $mapped;
    }

    private static function normalize_trusted_base(string $url): string
    {
        $parts = self::url_parts($url, false);
        if (null === $parts || null !== $parts['query'] || null !== $parts['fragment']) {
            throw new \InvalidArgumentException('PayKassa browser destination base URL is invalid.');
        }
        return rtrim($url, '/') . '/';
    }

    /**
     * @return array{scheme:string,host:string,port:int,path:string,query:?string,fragment:?string}|null
     */
    private static function url_parts(string $url, bool $allow_query): ?array
    {
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
            || (! $allow_query && isset($parts['query']))
            || (! $allow_query && isset($parts['fragment']))
        ) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, array('http', 'https'), true)) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? null;
        $fragment = $parts['fragment'] ?? null;
        if (
            ! is_string($path)
            || ! str_starts_with($path, '/')
            || ! self::is_safe_path($path)
            || (null !== $query && ! is_string($query))
            || (null !== $fragment && ! is_string($fragment))
        ) {
            return null;
        }

        return array(
            'scheme' => $scheme,
            'host' => strtolower($parts['host']),
            'port' => isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : ('https' === $scheme ? 443 : 80),
            'path' => $path,
            'query' => $query,
            'fragment' => $fragment,
        );
    }

    private static function is_safe_path(string $path): bool
    {
        $decoded_path = rawurldecode($path);
        if (str_contains($decoded_path, '\\') || 1 === preg_match('/[\x00-\x1F\x7F]/', $decoded_path)) {
            return false;
        }
        foreach (explode('/', $decoded_path) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{scheme:string,host:string,port:int,path:string,query:?string,fragment:?string} $left
     * @param array{scheme:string,host:string,port:int,path:string,query:?string,fragment:?string} $right
     */
    private static function same_origin(array $left, array $right): bool
    {
        return $left['scheme'] === $right['scheme']
            && $left['host'] === $right['host']
            && $left['port'] === $right['port'];
    }

    private static function relative_path(string $destination_path, string $base_path): ?string
    {
        $base_without_slash = rtrim($base_path, '/');
        $base_prefix = $base_without_slash . '/';
        if ($destination_path === $base_without_slash) {
            return '';
        }
        if (! str_starts_with($destination_path, $base_prefix)) {
            return null;
        }
        return substr($destination_path, strlen($base_prefix));
    }
}
