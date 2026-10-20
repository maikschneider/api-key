<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ApiKeyRepositoryTest extends FunctionalTestCase
{
    private const NOW = 1_800_000_000;

    protected array $testExtensionsToLoad = [
        'maikschneider/api-key',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_apikey_key.csv');
        $this->setNow(self::NOW);
    }

    #[Test]
    public function findByHashReturnsMatchingRecord(): void
    {
        $row = $this->get(ApiKeyRepository::class)->findByHash(str_repeat('a', 64));

        self::assertNotNull($row);
        self::assertSame(1, (int)$row['uid']);
    }

    #[Test]
    public function findByHashReturnsHiddenAndDeletedRecords(): void
    {
        $subject = $this->get(ApiKeyRepository::class);

        self::assertSame(2, (int)($subject->findByHash(str_repeat('b', 64))['uid'] ?? 0));
        self::assertSame(3, (int)($subject->findByHash(str_repeat('c', 64))['uid'] ?? 0));
    }

    #[Test]
    public function findByHashReturnsNullForUnknownHash(): void
    {
        self::assertNull($this->get(ApiKeyRepository::class)->findByHash(str_repeat('f', 64)));
    }

    #[Test]
    public function findByHashNeverMatchesRecordsWithoutHash(): void
    {
        self::assertNull($this->get(ApiKeyRepository::class)->findByHash(''));
    }

    #[Test]
    public function findAllReturnsHiddenButNotDeletedRecordsNewestFirst(): void
    {
        $uids = array_map(
            static fn (array $row): int => (int)$row['uid'],
            $this->get(ApiKeyRepository::class)->findAll()
        );

        self::assertSame([5, 4, 2, 1], $uids);
    }

    #[Test]
    public function findByIdentifierIgnoresDeletedRecords(): void
    {
        $subject = $this->get(ApiKeyRepository::class);

        self::assertSame([2], array_map(static fn (array $row): int => (int)$row['uid'], $subject->findByIdentifier('t3key_bbbbbbbb')));
        self::assertSame([], $subject->findByIdentifier('t3key_cccccccc'));
    }

    #[Test]
    public function findByUidIgnoresDeletedRecords(): void
    {
        $subject = $this->get(ApiKeyRepository::class);

        self::assertSame(2, (int)($subject->findByUid(2)['uid'] ?? 0));
        self::assertNull($subject->findByUid(3));
    }

    #[Test]
    public function revokeHidesActiveRecordOnce(): void
    {
        $subject = $this->get(ApiKeyRepository::class);

        self::assertTrue($subject->revoke(1));
        self::assertSame(1, (int)$this->getRow(1)['hidden']);
        self::assertSame(self::NOW, (int)$this->getRow(1)['tstamp']);
        self::assertFalse($subject->revoke(1));
        self::assertFalse($subject->revoke(3));
        self::assertSame(0, (int)$this->getRow(3)['hidden']);
    }

    #[Test]
    public function addStoresRecordOnRootPage(): void
    {
        $uid = $this->get(ApiKeyRepository::class)->add(str_repeat('d', 64), 't3key_dddddddd', [
            'title' => 'CI deploy',
            'fe_user' => 12,
        ]);

        $row = $this->getRow($uid);
        self::assertSame(0, (int)$row['pid']);
        self::assertSame('CI deploy', $row['title']);
        self::assertSame(str_repeat('d', 64), $row['key_hash']);
        self::assertSame('t3key_dddddddd', $row['key_prefix']);
        self::assertSame(12, (int)$row['fe_user']);
        self::assertSame(self::NOW, (int)$row['crdate']);
        self::assertSame(self::NOW, (int)$row['tstamp']);
    }

    #[Test]
    public function addRejectsDuplicateHash(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $this->get(ApiKeyRepository::class)->add(str_repeat('a', 64), 't3key_aaaaaaaa');
    }

    #[Test]
    public function touchLastUsedWritesAtMostOncePerThrottleWindow(): void
    {
        $subject = $this->get(ApiKeyRepository::class);

        self::assertTrue($subject->touchLastUsed(1));
        self::assertSame(self::NOW, (int)$this->getRow(1)['last_used_at']);

        $this->setNow(self::NOW + ApiKeyRepository::LAST_USED_THROTTLE - 1);
        self::assertFalse($subject->touchLastUsed(1));
        self::assertSame(self::NOW, (int)$this->getRow(1)['last_used_at']);

        $this->setNow(self::NOW + ApiKeyRepository::LAST_USED_THROTTLE);
        self::assertTrue($subject->touchLastUsed(1));
        self::assertSame(self::NOW + ApiKeyRepository::LAST_USED_THROTTLE, (int)$this->getRow(1)['last_used_at']);
    }

    #[Test]
    public function touchLastUsedLeavesOtherRecordsAlone(): void
    {
        $this->get(ApiKeyRepository::class)->touchLastUsed(1);

        self::assertSame(0, (int)$this->getRow(2)['last_used_at']);
    }

    private function setNow(int $timestamp): void
    {
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . $timestamp)));
    }

    /**
     * @return array<string, mixed>
     */
    private function getRow(int $uid): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable(ApiKeyRepository::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from(ApiKeyRepository::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }
}
