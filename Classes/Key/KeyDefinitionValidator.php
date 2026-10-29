<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Key;

use MaikSchneider\ApiKey\Authentication\ApiKey;
use MaikSchneider\ApiKey\Exception\InvalidKeyDefinitionException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The rules a key definition has to follow, shared by ApiKeyCreator and the
 * DataHandler hook, so keys created on the command line and in the backend
 * are checked the same way.
 */
final class KeyDefinitionValidator
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly Context $context,
    ) {
    }

    /**
     * @throws InvalidKeyDefinitionException
     */
    public function validateTitle(string $title): void
    {
        if (trim($title) === '') {
            throw new InvalidKeyDefinitionException('A key needs a title.', 1792512100);
        }
    }

    /**
     * @throws InvalidKeyDefinitionException
     */
    public function validateIdentity(int $feUser, int $beUser): void
    {
        if ($feUser > 0 && $beUser > 0) {
            throw new InvalidKeyDefinitionException('A key can be bound to a frontend user or a backend user, not both.', 1792512101);
        }
        if ($feUser < 0 || $beUser < 0) {
            throw new InvalidKeyDefinitionException('User uids must be positive.', 1792512102);
        }
        if ($feUser > 0 && !$this->recordExists('fe_users', $feUser)) {
            throw new InvalidKeyDefinitionException(sprintf('Frontend user %d does not exist.', $feUser), 1792512103);
        }
        if ($beUser > 0 && !$this->recordExists('be_users', $beUser)) {
            throw new InvalidKeyDefinitionException(sprintf('Backend user %d does not exist.', $beUser), 1792512104);
        }
    }

    /**
     * @throws InvalidKeyDefinitionException
     */
    public function validateCreator(int $createdBy): void
    {
        if ($createdBy < 0) {
            throw new InvalidKeyDefinitionException('User uids must be positive.', 1792512102);
        }
        if ($createdBy > 0 && !$this->recordExists('be_users', $createdBy)) {
            throw new InvalidKeyDefinitionException(sprintf('Backend user %d does not exist.', $createdBy), 1792512105);
        }
    }

    /**
     * @throws InvalidKeyDefinitionException
     */
    public function validateExpiresAt(int $expiresAt): void
    {
        if ($expiresAt < 0 || ($expiresAt > 0 && $expiresAt <= $this->now())) {
            throw new InvalidKeyDefinitionException('The expiry date must be in the future.', 1792512106);
        }
    }

    /**
     * @param list<string> $allowedIps
     * @throws InvalidKeyDefinitionException
     */
    public function validateAllowedIps(array $allowedIps): void
    {
        foreach ($allowedIps as $allowedIp) {
            if (!$this->isValidIpOrRange($allowedIp)) {
                throw new InvalidKeyDefinitionException(sprintf('"%s" is neither an IP address nor a CIDR range.', $allowedIp), 1792512107);
            }
        }
    }

    /**
     * @param list<string> $sites
     * @throws InvalidKeyDefinitionException
     */
    public function validateSites(array $sites): void
    {
        foreach ($sites as $site) {
            try {
                $this->siteFinder->getSiteByIdentifier($site);
            } catch (SiteNotFoundException) {
                throw new InvalidKeyDefinitionException(sprintf('Site "%s" does not exist.', $site), 1792512108);
            }
        }
    }

    /**
     * Parses the values the way authentication reads them back. A restriction
     * that is given but contains no value would otherwise store a key without
     * that restriction.
     *
     * @param list<string> $values
     * @return list<string>
     * @throws InvalidKeyDefinitionException
     */
    public function normalizeList(array $values, string $label): array
    {
        $normalized = ApiKey::splitList(implode("\n", $values));
        if ($values !== [] && $normalized === []) {
            throw new InvalidKeyDefinitionException(sprintf('%s must contain at least one value when given.', $label), 1792512109);
        }

        return $normalized;
    }

    private function isValidIpOrRange(string $value): bool
    {
        $parts = explode('/', $value, 2);
        if (!GeneralUtility::validIP($parts[0])) {
            return false;
        }
        if (!isset($parts[1])) {
            return true;
        }
        $maxBits = GeneralUtility::validIPv4($parts[0]) ? 32 : 128;

        return ctype_digit($parts[1]) && (int)$parts[1] <= $maxBits;
    }

    private function recordExists(string $table, int $uid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (bool)$queryBuilder
            ->count('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchOne();
    }

    private function now(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }
}
