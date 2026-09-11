<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\PayKassa\ApiClient;
use Al5dy\PayKassaWoo\PayKassa\Dto\HistoryCandidate;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentEvidence;
use Al5dy\PayKassaWoo\PayKassa\SciClient;
use PHPUnit\Framework\TestCase;

final class PaymentEvidenceTrustBoundaryTest extends TestCase
{
    public function test_shop_txids_endpoint_is_not_exposed_as_runtime_evidence(): void
    {
        $api = new \ReflectionClass(ApiClient::class);

        self::assertFalse($api->hasMethod('getTxIdsByInvoiceIds'));
        self::assertFalse($api->hasMethod('get_shop_txids'));
        foreach ($api->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== ApiClient::class || '__construct' === $method->getName()) {
                continue;
            }
            self::assertStringNotContainsString('txid', strtolower($method->getName()));
            $type = $method->getReturnType();
            self::assertFalse($type instanceof \ReflectionNamedType && PaymentEvidence::class === $type->getName());
        }
    }

    public function test_synthetic_shop_txids_cannot_become_a_verifiable_candidate(): void
    {
        $response = array(
            '987654321' => array(str_repeat('a', 64)),
            '555555555' => array(str_repeat('b', 64)),
            '314159265' => array(str_repeat('c', 64)),
        );

        foreach ($response as $invoice_id => $txids) {
            $candidate = HistoryCandidate::from_array(array(
                'order_id' => $invoice_id,
                'invoice_id' => $invoice_id,
                'transaction' => $txids[0],
                'txid' => $txids[0],
                'txids' => $txids,
            ));
            self::assertFalse($candidate->can_verify());
        }
    }

    public function test_payment_evidence_remains_a_result_of_sci_verification(): void
    {
        $method = new \ReflectionMethod(SciClient::class, 'verify_ipn');
        $type = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(PaymentEvidence::class, $type->getName());
    }
}
