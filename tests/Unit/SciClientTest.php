<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa {
    final class SciClientTestTransport
    {
        /** @var array<string, mixed> */
        public static array $provider_response = array();

        /** @var array<string, mixed> */
        public static array $request_args = array();
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    function wp_remote_post(string $url, array $args): array
    {
        SciClientTestTransport::$request_args = $args;

        return array(
            'response' => array('code' => 200),
            'body' => json_encode(SciClientTestTransport::$provider_response, JSON_THROW_ON_ERROR),
        );
    }

    function is_wp_error(mixed $response): bool
    {
        return false;
    }

    /** @param array<string, mixed> $response */
    function wp_remote_retrieve_response_code(array $response): int
    {
        return (int) ($response['response']['code'] ?? 0);
    }

    /** @param array<string, mixed> $response */
    function wp_remote_retrieve_body(array $response): string
    {
        return is_string($response['body'] ?? null) ? $response['body'] : '';
    }
}

namespace PayKassaWoo\Tests\Unit {
    use Al5dy\PayKassaWoo\Infrastructure\Logger;
    use Al5dy\PayKassaWoo\PayKassa\Exception\WebhookVerificationException;
    use Al5dy\PayKassaWoo\PayKassa\SciClient;
    use Al5dy\PayKassaWoo\PayKassa\SciClientTestTransport;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class SciClientTest extends TestCase
    {
        protected function setUp(): void
        {
            SciClientTestTransport::$provider_response = $this->live_response_fixture();
            SciClientTestTransport::$request_args = array();
        }

        public function test_live_confirm_order_response_with_false_tag_produces_verified_evidence(): void
        {
            $evidence = $this->client()->verify_ipn(str_repeat('a', 64));

            self::assertSame(143, $evidence->order_id);
            self::assertSame('292207', $evidence->transaction_id);
            self::assertSame('30298', $evidence->shop_id);
            self::assertSame('2.000000', $evidence->amount);
            self::assertSame('USDT', $evidence->currency);
            self::assertSame('Ethereum_ERC20', $evidence->system);
            self::assertSame('0x1234567890abcdef1234567890abcdef12345678', $evidence->address);
            self::assertSame('', $evidence->tag);
            self::assertSame('live', $evidence->environment);
            self::assertSame('sci_confirm_order', SciClientTestTransport::$request_args['body']['func'] ?? null);
            self::assertSame('0', SciClientTestTransport::$request_args['body']['test'] ?? null);
        }

        #[DataProvider('accepted_optional_metadata')]
        public function test_tag_accepts_only_documented_optional_representations(bool $present, mixed $value, string $expected): void
        {
            $this->set_optional_metadata('tag', $present, $value);

            self::assertSame($expected, $this->client()->verify_ipn(str_repeat('b', 64))->tag);
        }

        #[DataProvider('accepted_empty_metadata')]
        public function test_address_false_null_or_missing_is_normalized_to_empty_string(bool $present, mixed $value): void
        {
            $this->set_optional_metadata('address', $present, $value);

            self::assertSame('', $this->client()->verify_ipn(str_repeat('c', 64))->address);
        }

        #[DataProvider('rejected_optional_metadata')]
        public function test_invalid_tag_types_and_length_are_rejected(mixed $value): void
        {
            $this->set_optional_metadata('tag', true, $value);
            $this->expectException(WebhookVerificationException::class);

            $this->client()->verify_ipn(str_repeat('d', 64));
        }

        #[DataProvider('rejected_optional_metadata')]
        public function test_invalid_address_types_and_length_are_rejected(mixed $value): void
        {
            $this->set_optional_metadata('address', true, $value);
            $this->expectException(WebhookVerificationException::class);

            $this->client()->verify_ipn(str_repeat('e', 64));
        }

        /** @return array<string, array{bool, mixed, string}> */
        public static function accepted_optional_metadata(): array
        {
            return array(
                'false' => array(true, false, ''),
                'null' => array(true, null, ''),
                'missing' => array(false, null, ''),
                'string' => array(true, '12345', '12345'),
            );
        }

        /** @return array<string, array{bool, mixed}> */
        public static function accepted_empty_metadata(): array
        {
            return array(
                'false' => array(true, false),
                'null' => array(true, null),
                'missing' => array(false, null),
            );
        }

        /** @return array<string, array{mixed}> */
        public static function rejected_optional_metadata(): array
        {
            return array(
                'true' => array(true),
                'array' => array(array('12345')),
                'integer' => array(12345),
                'float' => array(123.45),
                'object' => array(new \stdClass()),
                'overlong string' => array(str_repeat('x', 257)),
            );
        }

        private function client(): SciClient
        {
            return new SciClient('30298', 'merchant-secret', false, new Logger());
        }

        private function set_optional_metadata(string $key, bool $present, mixed $value): void
        {
            if (! isset(SciClientTestTransport::$provider_response['data']) || ! is_array(SciClientTestTransport::$provider_response['data'])) {
                self::fail('SCI fixture data is missing.');
            }
            if ($present) {
                SciClientTestTransport::$provider_response['data'][$key] = $value;
                return;
            }
            unset(SciClientTestTransport::$provider_response['data'][$key]);
        }

        /** @return array<string, mixed> */
        private function live_response_fixture(): array
        {
            $contents = file_get_contents(dirname(__DIR__) . '/fixtures/sci-confirm-order-live-tag-false.json');
            self::assertIsString($contents);

            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            return $decoded;
        }
    }
}
