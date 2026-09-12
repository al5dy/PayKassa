<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Webhook\WebhookRateLimitPolicy;
use PHPUnit\Framework\TestCase;

final class WebhookRateLimitPolicyTest extends TestCase
{
    /** @var array<string, int> */
    private array $counts = array();

    protected function setUp(): void
    {
        $this->counts = array();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_shared_remote_address_cannot_exhaust_other_token_buckets_by_default(): void
    {
        $policy = $this->policy();

        for ($request = 0; $request < 500; ++$request) {
            self::assertTrue($policy->allows(WebhookRateLimitPolicy::INVOICE_CHANNEL, 'random-token-' . $request));
        }
        self::assertTrue($policy->allows(WebhookRateLimitPolicy::INVOICE_CHANNEL, 'legitimate-private-hash'));
    }

    public function test_exact_token_replay_is_throttled_without_affecting_another_token(): void
    {
        $policy = $this->policy();

        for ($request = 0; $request < 120; ++$request) {
            self::assertTrue($policy->allows(WebhookRateLimitPolicy::TRANSACTION_CHANNEL, 'same-private-hash'));
        }
        self::assertFalse($policy->allows(WebhookRateLimitPolicy::TRANSACTION_CHANNEL, 'same-private-hash'));
        self::assertTrue($policy->allows(WebhookRateLimitPolicy::TRANSACTION_CHANNEL, 'different-private-hash'));
    }

    public function test_explicit_trusted_client_key_enables_separate_proxy_aware_bucket(): void
    {
        $policy = $this->policy(static fn (string $channel, string $remote): ?string => $channel . ':' . $remote);

        for ($request = 0; $request < 60; ++$request) {
            self::assertTrue($policy->allows(WebhookRateLimitPolicy::INVOICE_CHANNEL, 'unique-token-' . $request));
        }
        self::assertFalse($policy->allows(WebhookRateLimitPolicy::INVOICE_CHANNEL, 'unique-token-over-limit'));
        self::assertTrue($policy->allows(WebhookRateLimitPolicy::TRANSACTION_CHANNEL, 'other-channel-token'));
    }

    public function test_forwarded_headers_are_not_used_without_an_explicit_trusted_resolver(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
        $observed_remote = null;
        $policy = $this->policy(static function (string $channel, string $remote) use (&$observed_remote): ?string {
            $observed_remote = $remote;
            return null;
        });

        self::assertTrue($policy->allows(WebhookRateLimitPolicy::INVOICE_CHANNEL, 'private-hash'));
        self::assertSame('192.0.2.10', $observed_remote);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function test_unknown_channel_fails_closed(): void
    {
        self::assertFalse($this->policy()->allows('unknown', 'private-hash'));
    }

    /** @param (callable(string,string):?string)|null $trusted_client_key */
    private function policy(?callable $trusted_client_key = null): WebhookRateLimitPolicy
    {
        return new WebhookRateLimitPolicy(
            fn (string $key): int => $this->counts[$key] ?? 0,
            function (string $key, int $count, int $ttl): bool {
                self::assertSame(60, $ttl);
                $this->counts[$key] = $count;
                return true;
            },
            $trusted_client_key
        );
    }
}
