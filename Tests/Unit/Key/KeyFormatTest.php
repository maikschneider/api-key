<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Unit\Key;

use MaikSchneider\ApiKey\Key\KeyFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class KeyFormatTest extends UnitTestCase
{
    private const KEY = 't3key_KhhNfTRL-5tLoriJVo5I-BQRJWiLD3GqlvEcPDPeHmg';

    #[Test]
    public function wellFormedKeyIsAccepted(): void
    {
        self::assertTrue(KeyFormat::isKey(self::KEY));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'prefix only' => ['t3key_'];
        yield 'too short' => [substr(self::KEY, 0, -1)];
        yield 'too long' => [self::KEY . 'a'];
        yield 'wrong prefix' => ['t4key_' . substr(self::KEY, 6)];
        yield 'padding' => [substr(self::KEY, 0, -1) . '='];
        yield 'standard base64 characters' => [substr(self::KEY, 0, -1) . '+'];
        yield 'trailing newline' => [self::KEY . "\n"];
        yield 'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl'];
    }

    #[Test]
    #[DataProvider('malformedKeys')]
    public function malformedKeyIsRejected(string $value): void
    {
        self::assertFalse(KeyFormat::isKey($value));
    }

    #[Test]
    public function hasPrefixOnlyChecksThePrefix(): void
    {
        self::assertTrue(KeyFormat::hasPrefix('t3key_broken'));
        self::assertFalse(KeyFormat::hasPrefix('eyJhbGciOiJIUzI1NiJ9'));
    }

    #[Test]
    public function identifierIsPrefixPlusEightCharacters(): void
    {
        self::assertSame('t3key_KhhNfTRL', KeyFormat::identifier(self::KEY));
    }
}
