<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Unit\Key;

use MaikSchneider\ApiKey\Exception\MissingEncryptionKeyException;
use MaikSchneider\ApiKey\Key\KeyHasher;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class KeyHasherTest extends UnitTestCase
{
    private const KEY = 't3key_aGVsbG8td29ybGQtdGhpcy1pcy1hLXRlc3Qta2V5';

    #[Test]
    public function hashIsDeterministicHexSha256(): void
    {
        $subject = new KeyHasher('encryption-key');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $subject->hash(self::KEY));
        self::assertSame($subject->hash(self::KEY), $subject->hash(self::KEY));
    }

    #[Test]
    public function hashIsNotAPlainSha256(): void
    {
        self::assertNotSame(hash('sha256', self::KEY), (new KeyHasher('encryption-key'))->hash(self::KEY));
    }

    #[Test]
    public function hashDependsOnEncryptionKey(): void
    {
        self::assertNotSame(
            (new KeyHasher('encryption-key'))->hash(self::KEY),
            (new KeyHasher('other-encryption-key'))->hash(self::KEY)
        );
    }

    #[Test]
    public function verifyAcceptsMatchingHashOnly(): void
    {
        $subject = new KeyHasher('encryption-key');
        $hash = $subject->hash(self::KEY);

        self::assertTrue($subject->verify(self::KEY, $hash));
        self::assertFalse($subject->verify(self::KEY . 'x', $hash));
        self::assertFalse($subject->verify(self::KEY, ''));
    }

    #[Test]
    public function encryptionKeyIsReadFromGlobalConfigurationByDefault(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'encryption-key';

        self::assertSame((new KeyHasher('encryption-key'))->hash(self::KEY), (new KeyHasher())->hash(self::KEY));
    }

    #[Test]
    public function emptyEncryptionKeyThrows(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';

        $this->expectException(MissingEncryptionKeyException::class);
        $this->expectExceptionCode(1792512000);

        (new KeyHasher())->hash(self::KEY);
    }
}
