<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Unit\Authentication;

use MaikSchneider\ApiKey\Authentication\ApiKey;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ApiKeyTest extends UnitTestCase
{
    #[Test]
    public function fromRowMapsAllFields(): void
    {
        $subject = ApiKey::fromRow([
            'uid' => '7',
            'title' => 'Importer',
            'key_prefix' => 't3key_aaaaaaaa',
            'key_hash' => str_repeat('a', 64),
            'scopes' => "articles:read\r\narticles:write\n\narticles:read",
            'fe_user' => '12',
            'be_user' => '0',
            'expires_at' => '1800000000',
        ]);

        self::assertSame(7, $subject->uid);
        self::assertSame('Importer', $subject->title);
        self::assertSame('t3key_aaaaaaaa', $subject->identifier);
        self::assertSame(['articles:read', 'articles:write'], $subject->scopes);
        self::assertSame(12, $subject->frontendUserUid);
        self::assertNull($subject->backendUserUid);
        self::assertSame(1800000000, $subject->expiresAt?->getTimestamp());
        self::assertFalse($subject->isServiceKey());
    }

    #[Test]
    public function fromRowWithoutRestrictionsIsAServiceKey(): void
    {
        $subject = ApiKey::fromRow(['uid' => 1, 'scopes' => null, 'fe_user' => 0, 'be_user' => 0, 'expires_at' => 0]);

        self::assertSame([], $subject->scopes);
        self::assertNull($subject->expiresAt);
        self::assertTrue($subject->isServiceKey());
    }

    #[Test]
    public function splitListAcceptsNewlinesAndCommas(): void
    {
        self::assertSame(['a', 'b', 'c'], ApiKey::splitList(" a,b \n\nc,a "));
    }

    #[Test]
    public function keyWithoutScopesHasEveryScope(): void
    {
        self::assertTrue((new ApiKey(1, 'Key', 't3key_aaaaaaaa'))->hasScope('articles:write'));
    }

    #[Test]
    public function scopesAreComparedExactly(): void
    {
        $subject = new ApiKey(1, 'Key', 't3key_aaaaaaaa', ['articles:read']);

        self::assertTrue($subject->hasScope('articles:read'));
        self::assertFalse($subject->hasScope('articles:write'));
        self::assertFalse($subject->hasScope('articles'));
        self::assertFalse($subject->hasScope('Articles:read'));
        self::assertFalse($subject->hasScope(''));
    }
}
