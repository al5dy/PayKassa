<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use PayKassaWoo\Tools\HistoryContractProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic redaction/observation tests, not real provider-response fixtures. */
final class HistoryContractProbeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/HistoryContractProbe.php';
    }

    public function test_each_item_is_described_without_inventing_missing_fields(): void
    {
        $report = HistoryContractProbe::describe('{"error":false,"data":{"page_count":"1","list":[{"transaction":"123","amount":"1.00"},{"order_id":123,"private_hash":"secret"},{"amount":1.00}]}}');
        self::assertFalse($report['release_ready']);
        self::assertSame(3, $report['item_count']);
        self::assertSame(array('transaction' => 'string', 'amount' => 'string'), $report['items'][0]['object']);
        self::assertSame(array('order_id' => 'int', 'private_hash' => 'string'), $report['items'][1]['object']);
        self::assertSame('float', $report['items'][2]['object']['amount']);
    }

    public function test_secrets_and_arbitrary_map_keys_never_leave_the_report(): void
    {
        $secret = str_repeat('b', 64);
        $response = array('error' => false, 'message' => $secret, 'data' => array('page_count' => 1, 'list' => array(array(
            'private_hash' => $secret, 'shop_password' => $secret, 'api_key' => $secret,
            'comment' => $secret, $secret => array('private_hash' => $secret),
            'address' => array($secret, array('tag' => $secret)),
        ))));
        $encoded = json_encode(HistoryContractProbe::describe(json_encode($response, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $encoded);
        self::assertStringNotContainsString('shop_password', $encoded);
        self::assertStringContainsString('unknown_field_', $encoded);
    }

    public function test_empty_history_is_not_release_evidence(): void
    {
        $report = HistoryContractProbe::describe('{"error":false,"data":{"page_count":0,"list":[]}}');
        self::assertSame(0, $report['item_count']);
        self::assertFalse($report['release_ready']);
    }

    #[DataProvider('invalid_responses')]
    public function test_invalid_responses_fail_without_provider_messages(string $response, string $reason): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($reason);
        HistoryContractProbe::describe($response);
    }

    /** @return list<array{string, string}> */
    public static function invalid_responses(): array
    {
        return array(
            array('{', 'invalid_json'),
            array('{"error":"false"}', 'invalid_envelope'),
            array('{"error":true,"message":"secret"}', 'provider_rejected'),
            array('{"error":false,"data":{"page_count":1,"list":{}}}', 'invalid_history_list'),
            array('{"error":false,"data":{"page_count":1.5,"list":[]}}', 'invalid_page_count'),
        );
    }

    public function test_no_request_is_possible_without_explicit_sandbox_credentials(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing_sandbox_credentials');
        HistoryContractProbe::run(array(), '2026-09-01T00:00:00Z', '2026-09-02T00:00:00Z', 0);
    }

    public function test_unbounded_range_cannot_make_a_request(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_range_or_page');
        HistoryContractProbe::run(array('api_id' => 'synthetic', 'api_password' => 'synthetic', 'shop_id' => 'synthetic'), '2020-01-01', '2026-01-01', 0);
    }
}
