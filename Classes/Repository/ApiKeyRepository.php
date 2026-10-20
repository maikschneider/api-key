<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Repository;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ApiKeyRepository
{
    public const TABLE = 'tx_apikey_key';

    /**
     * Minimum number of seconds between two last_used_at writes of the same key.
     */
    public const LAST_USED_THROTTLE = 300;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
    ) {
    }

    /**
     * Returns the record regardless of its hidden and deleted state, so callers
     * can tell a revoked key apart from an unknown one.
     *
     * @return array<string, mixed>|null
     */
    public function findByHash(string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('key_hash', $queryBuilder->createNamedParameter($hash)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * All keys that are not deleted, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $queryBuilder = $this->createQueryBuilderWithoutHiddenRestriction();

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByIdentifier(string $identifier): array
    {
        $queryBuilder = $this->createQueryBuilderWithoutHiddenRestriction();

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('key_prefix', $queryBuilder->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        $queryBuilder = $this->createQueryBuilderWithoutHiddenRestriction();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Hides the record, so it stays available for auditing.
     */
    public function revoke(int $uid): bool
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['hidden' => 1, 'tstamp' => $this->now()],
            ['uid' => $uid, 'deleted' => 0, 'hidden' => 0]
        ) > 0;
    }

    /**
     * @param array<string, int|string> $fields further columns, e.g. title, fe_user, scopes
     */
    public function add(string $hash, string $prefix, array $fields = []): int
    {
        $now = $this->now();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            ...$fields,
            'pid' => 0,
            'key_hash' => $hash,
            'key_prefix' => $prefix,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * The throttle is part of the WHERE clause, so concurrent requests with the
     * same key write at most once per throttle window.
     */
    public function touchLastUsed(int $uid): bool
    {
        $now = $this->now();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $affectedRows = $queryBuilder
            ->update(self::TABLE)
            ->set('last_used_at', $now, true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->lte(
                    'last_used_at',
                    $queryBuilder->createNamedParameter($now - self::LAST_USED_THROTTLE, Connection::PARAM_INT)
                )
            )
            ->executeStatement();

        return $affectedRows > 0;
    }

    private function createQueryBuilderWithoutHiddenRestriction(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder;
    }

    private function now(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }
}
