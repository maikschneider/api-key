<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Unit\Authentication;

use MaikSchneider\ApiKey\Authentication\ApiKey;
use MaikSchneider\ApiKey\Authentication\AuthenticationResult;
use MaikSchneider\ApiKey\Authentication\InvalidReason;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AuthenticationResultTest extends UnitTestCase
{
    #[Test]
    public function notPresent(): void
    {
        $subject = AuthenticationResult::notPresent();

        self::assertFalse($subject->isPresent());
        self::assertFalse($subject->isValid());
        self::assertFalse($subject->isInvalid());
        self::assertNull($subject->getApiKey());
        self::assertNull($subject->getInvalidReason());
    }

    #[Test]
    public function valid(): void
    {
        $apiKey = new ApiKey(1, 'CI', 't3key_aaaaaaaa');
        $subject = AuthenticationResult::valid($apiKey);

        self::assertTrue($subject->isPresent());
        self::assertTrue($subject->isValid());
        self::assertFalse($subject->isInvalid());
        self::assertSame($apiKey, $subject->getApiKey());
        self::assertNull($subject->getInvalidReason());
    }

    #[Test]
    public function invalid(): void
    {
        $subject = AuthenticationResult::invalid(InvalidReason::Expired);

        self::assertTrue($subject->isPresent());
        self::assertFalse($subject->isValid());
        self::assertTrue($subject->isInvalid());
        self::assertNull($subject->getApiKey());
        self::assertSame(InvalidReason::Expired, $subject->getInvalidReason());
    }
}
