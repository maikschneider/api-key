<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Key;

use MaikSchneider\ApiKey\Exception\InvalidKeyDefinitionException;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;

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
        private readonly KeyDefinitionValidator $validator,
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
        $scopes = $this->validator->normalizeList($scopes, 'Scopes');
        $allowedIps = $this->validator->normalizeList($allowedIps, 'Allowed IPs');
        $sites = $this->validator->normalizeList($sites, 'Sites');

        $this->validator->validateTitle($title);
        $this->validator->validateIdentity($feUser, $beUser);
        $this->validator->validateCreator($createdBy);
        $this->validator->validateExpiresAt($expiresAt);
        $this->validator->validateAllowedIps($allowedIps);
        $this->validator->validateSites($sites);

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
}
