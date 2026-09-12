<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

/** Best-effort replay throttling that never treats a shared proxy address as client identity by default. */
final class WebhookRateLimitPolicy
{
    public const CLIENT_KEY_FILTER = 'paykassa_webhook_rate_limit_client_key';
    public const INVOICE_CHANNEL = 'invoice';
    public const TRANSACTION_CHANNEL = 'transaction';

    private const TOKEN_REQUESTS_PER_MINUTE = 120;
    private const CLIENT_REQUESTS_PER_MINUTE = 60;

    /** @var \Closure(string):int */
    private readonly \Closure $read_count;

    /** @var \Closure(string,int,int):bool */
    private readonly \Closure $write_count;

    /** @var \Closure(string,string):?string */
    private readonly \Closure $trusted_client_key;

    /**
     * @param (callable(string):int)|null          $read_count
     * @param (callable(string,int,int):bool)|null $write_count
     * @param (callable(string,string):?string)|null $trusted_client_key
     */
    public function __construct(
        ?callable $read_count = null,
        ?callable $write_count = null,
        ?callable $trusted_client_key = null
    ) {
        $this->read_count = null === $read_count
            ? static fn (string $key): int => max(0, (int) get_transient($key))
            : \Closure::fromCallable($read_count);
        $this->write_count = null === $write_count
            ? static fn (string $key, int $count, int $ttl): bool => set_transient($key, $count, $ttl)
            : \Closure::fromCallable($write_count);
        $this->trusted_client_key = null === $trusted_client_key
            ? static function (string $channel, string $remote_address): ?string {
                $key = apply_filters(self::CLIENT_KEY_FILTER, null, $channel, $remote_address);
                return is_string($key) ? trim($key) : null;
            }
            : \Closure::fromCallable($trusted_client_key);
    }

    public function allows(string $channel, string $private_hash): bool
    {
        if (! in_array($channel, array(self::INVOICE_CHANNEL, self::TRANSACTION_CHANNEL), true)) {
            return false;
        }
        if (! $this->consume('token:' . $channel . ':' . hash('sha256', $private_hash), self::TOKEN_REQUESTS_PER_MINUTE)) {
            return false;
        }

        $remote_address = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            && false !== filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)
            ? $_SERVER['REMOTE_ADDR']
            : '';
        $client_key = ($this->trusted_client_key)($channel, $remote_address);
        if (null === $client_key || '' === $client_key || strlen($client_key) > 256) {
            return true;
        }

        return $this->consume('client:' . $channel . ':' . $client_key, self::CLIENT_REQUESTS_PER_MINUTE);
    }

    private function consume(string $identity, int $limit): bool
    {
        $key = 'paykassa_webhook_rate_' . hash('sha256', $identity);
        $count = ($this->read_count)($key);
        if ($count >= $limit) {
            return false;
        }

        // Persistence failure is fail-open: admission control is not payment
        // authentication and must not make callbacks unavailable by itself.
        ($this->write_count)($key, $count + 1, 60);
        return true;
    }
}
