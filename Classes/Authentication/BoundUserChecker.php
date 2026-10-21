<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Authentication;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Checks whether a user a key is bound to may still be used: not deleted, not
 * disabled and inside its start and end time.
 */
final class BoundUserChecker
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function isAvailable(string $table, int $uid, int $now): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('deleted', 'disable', 'starttime', 'endtime')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return false;
        }

        $starttime = (int)$row['starttime'];
        $endtime = (int)$row['endtime'];

        return (int)$row['deleted'] === 0
            && (int)$row['disable'] === 0
            && ($starttime === 0 || $starttime <= $now)
            && ($endtime === 0 || $endtime > $now);
    }
}
