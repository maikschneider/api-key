<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Key;

use MaikSchneider\ApiKey\Exception\InvalidKeyDefinitionException;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Validates a key definition, generates the secret and stores its hash. The
 * secret is only available in the returned object and cannot be recovered.
 */
final class ApiKeyCreator
{
    public function __construct(
        private readonly KeyGenerator $keyGenerator,
        private readonly KeyHasher $keyHasher,
        private readonly ApiKeyRepository $repository,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly Context $context,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $allowedIps IP addresses or CIDR ranges
     * @param list<string> $sites site identifiers
     * @throws InvalidKeyDefinitionException
     */
    public function create(
        string $title,
        int $feUser = 0,
        int $beUser = 0,
        array $scopes = [],
        int $expiresAt = 0,
        array $allowedIps = [],
        array $sites = [],
        int $createdBy = 0,
    ): CreatedApiKey {
        $title = trim($title);
        $scopes = $this->normalizeList($scopes);
        $allowedIps = $this->normalizeList($allowedIps);
        $sites = $this->normalizeList($sites);

        $this->validate($title, $feUser, $beUser, $expiresAt, $allowedIps, $sites, $createdBy);

        $secret = $this->keyGenerator->generate();
        $identifier = KeyFormat::identifier($secret);
        $uid = $this->repository->add($this->keyHasher->hash($secret), $identifier, [
            'title' => $title,
            'fe_user' => $feUser,
            'be_user' => $beUser,
            'created_by' => $createdBy,
            'scopes' => implode("\n", $scopes),
            'expires_at' => $expiresAt,
            'allowed_ips' => implode("\n", $allowedIps),
            'sites' => implode("\n", $sites),
        ]);

        return new CreatedApiKey($uid, $secret, $identifier);
    }

    /**
     * @param list<string> $allowedIps
     * @param list<string> $sites
     */
    private function validate(
        string $title,
        int $feUser,
        int $beUser,
        int $expiresAt,
        array $allowedIps,
        array $sites,
        int $createdBy,
    ): void {
        if ($title === '') {
            throw new InvalidKeyDefinitionException('A key needs a title.', 1792512100);
        }
        if ($feUser > 0 && $beUser > 0) {
            throw new InvalidKeyDefinitionException('A key can be bound to a frontend user or a backend user, not both.', 1792512101);
        }
        if ($feUser < 0 || $beUser < 0 || $createdBy < 0) {
            throw new InvalidKeyDefinitionException('User uids must be positive.', 1792512102);
        }
        if ($feUser > 0 && !$this->recordExists('fe_users', $feUser)) {
            throw new InvalidKeyDefinitionException(sprintf('Frontend user %d does not exist.', $feUser), 1792512103);
        }
        if ($beUser > 0 && !$this->recordExists('be_users', $beUser)) {
            throw new InvalidKeyDefinitionException(sprintf('Backend user %d does not exist.', $beUser), 1792512104);
        }
        if ($createdBy > 0 && !$this->recordExists('be_users', $createdBy)) {
            throw new InvalidKeyDefinitionException(sprintf('Backend user %d does not exist.', $createdBy), 1792512105);
        }
        if ($expiresAt < 0 || ($expiresAt > 0 && $expiresAt <= $this->now())) {
            throw new InvalidKeyDefinitionException('The expiry date must be in the future.', 1792512106);
        }
        foreach ($allowedIps as $allowedIp) {
            if (!$this->isValidIpOrRange($allowedIp)) {
                throw new InvalidKeyDefinitionException(sprintf('"%s" is neither an IP address nor a CIDR range.', $allowedIp), 1792512107);
            }
        }
        foreach ($sites as $site) {
            try {
                $this->siteFinder->getSiteByIdentifier($site);
            } catch (SiteNotFoundException) {
                throw new InvalidKeyDefinitionException(sprintf('Site "%s" does not exist.', $site), 1792512108);
            }
        }
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

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), $values), static fn (string $value): bool => $value !== '')));
    }

    private function now(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }
}
