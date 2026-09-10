<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\PayKassa\Dto\HistoryPage;
use Al5dy\PayKassaWoo\PayKassa\Dto\HistoryCandidate;
use Al5dy\PayKassaWoo\PayKassa\Exception\InvalidResponseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HistoryPageTest extends TestCase
{
    public function test_unknown_history_schema_is_not_evidence(): void
    {
        $page = HistoryPage::from_response(array('error' => false, 'data' => array('page_count' => 1, 'list' => array(array('status' => 'yes', 'amount' => '100', 'order_id' => '123')))), 0);
        self::assertFalse($page->candidates[0]->can_verify());
        self::assertSame('', $page->candidates[0]->verification_token());
    }

    public function test_candidate_is_only_a_hint_for_sci_verification(): void
    {
        $token = str_repeat('a', 64);
        $page = HistoryPage::from_response(array('error' => false, 'data' => array('page_count' => '2', 'list' => array(array('order_id' => '123', 'private_hash' => $token, 'amount' => 'invented')))), 1);
        self::assertSame(2, $page->page_count);
        self::assertSame(123, $page->candidates[0]->order_id);
        self::assertTrue($page->candidates[0]->can_verify());
        self::assertSame($token, $page->candidates[0]->verification_token());
        self::assertStringNotContainsString($token, $page->fingerprint);
    }

    #[DataProvider('bad_envelopes')]
    public function test_malformed_pages_are_rejected(array $response, int $page): void
    {
        $this->expectException(InvalidResponseException::class);
        HistoryPage::from_response($response, $page);
    }

    public static function bad_envelopes(): array
    {
        return array(
            array(array(), 0),
            array(array('error' => 'false', 'data' => array('page_count' => 0, 'list' => array())), 0),
            array(array('error' => false, 'data' => array('page_count' => -1, 'list' => array())), 0),
            array(array('error' => false, 'data' => array('page_count' => 10001, 'list' => array())), 0),
            array(array('error' => false, 'data' => array('page_count' => 2, 'list' => array())), 0),
            array(array('error' => false, 'data' => array('page_count' => 1, 'list' => array('bad'))), 0),
            array(array('error' => false, 'data' => array('page_count' => 1, 'list' => array(array()))), 1),
            array(array('error' => false, 'data' => array('page_count' => 0, 'list' => array(array()))), 0),
            array(array('error' => false, 'data' => array('page_count' => 1, 'list' => array_fill(0, 1001, array()))), 0),
        );
    }

    #[DataProvider('invalid_candidates')]
    public function test_invalid_discovery_hints_cannot_trigger_verification(array $row): void
    {
        self::assertFalse(HistoryCandidate::from_array($row)->can_verify());
    }

    public static function invalid_candidates(): array
    {
        $token = str_repeat('a', 64);
        return array(
            array(array('order_id' => 'WC-2026-123', 'private_hash' => $token)),
            array(array('order_id' => '0', 'private_hash' => $token)),
            array(array('order_id' => 1.25, 'private_hash' => $token)),
            array(array('order_id' => array('123'), 'private_hash' => $token)),
            array(array('order_id' => '1e3', 'private_hash' => $token)),
            array(array('order_id' => str_repeat('9', 50), 'private_hash' => $token)),
            array(array('order_id' => '123', 'private_hash' => array($token))),
            array(array('order_id' => '123', 'private_hash' => $token . "\n")),
            array(array('order_id' => '123', 'private_hash' => str_repeat('a', 129))),
            array(array('order_id' => '123', 'hash' => $token)),
        );
    }
}
