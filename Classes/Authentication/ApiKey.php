<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Authentication;

/**
 * A successfully authenticated key. Never carries the secret or its hash.
 */
final class ApiKey
{
    /**
     * @param string $identifier key prefix, safe to log
     * @param list<string> $scopes opaque to this extension; empty means unrestricted
     */
    public function __construct(
        public readonly int $uid,
        public readonly string $title,
        public readonly string $identifier,
        public readonly array $scopes = [],
        public readonly ?int $frontendUserUid = null,
        public readonly ?int $backendUserUid = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $expiresAt = (int)($row['expires_at'] ?? 0);

        return new self(
            uid: (int)$row['uid'],
            title: (string)($row['title'] ?? ''),
            identifier: (string)($row['key_prefix'] ?? ''),
            scopes: self::splitList((string)($row['scopes'] ?? '')),
            frontendUserUid: (int)($row['fe_user'] ?? 0) ?: null,
            backendUserUid: (int)($row['be_user'] ?? 0) ?: null,
            expiresAt: $expiresAt > 0 ? new \DateTimeImmutable('@' . $expiresAt) : null,
        );
    }

    /**
     * A key without scopes is unrestricted. Scopes are compared exactly.
     */
    public function hasScope(string $scope): bool
    {
        return $this->scopes === [] || in_array($scope, $this->scopes, true);
    }

    public function isServiceKey(): bool
    {
        return $this->frontendUserUid === null && $this->backendUserUid === null;
    }

    /**
     * @return list<string>
     */
    public static function splitList(string $value): array
    {
        return array_values(array_unique(array_filter(
            array_map(trim(...), preg_split('/[\r\n,]+/', $value) ?: []),
            static fn (string $item): bool => $item !== ''
        )));
    }
}
