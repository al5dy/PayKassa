<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Gateway\RedirectUrlValidator;
use PHPUnit\Framework\TestCase;

final class RedirectUrlValidatorTest extends TestCase
{
    public function test_only_https_paykassa_hosts_are_allowed(): void
    {
        self::assertTrue(RedirectUrlValidator::is_valid('https://paykassa.app/pay/123'));
        self::assertTrue(RedirectUrlValidator::is_valid('https://crypto.paykassa.pro/sci/index.php?hash=abc'));
        self::assertFalse(RedirectUrlValidator::is_valid('http://paykassa.app/pay/123'));
        self::assertFalse(RedirectUrlValidator::is_valid('https://paykassa.app@evil.example/pay'));
        self::assertFalse(RedirectUrlValidator::is_valid('https://user:password@paykassa.app/pay'));
        self::assertFalse(RedirectUrlValidator::is_valid('https://evilpaykassa.app/pay'));
        self::assertFalse(RedirectUrlValidator::is_valid('https://paykassa.app.evil.example/pay'));
        self::assertFalse(RedirectUrlValidator::is_valid('https://paykassa.app:bad-port/pay'));
    }
}
