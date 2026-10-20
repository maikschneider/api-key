<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Key;

use MaikSchneider\ApiKey\Exception\MissingEncryptionKeyException;

/**
 * Keys carry 256 bits of entropy, so a keyed fast hash is enough and keeps the
 * hash usable as an indexed lookup column. The HMAC secret is derived from the
 * TYPO3 encryptionKey, so a leaked database alone does not let an attacker check
 * guessed keys. Rotating the encryptionKey invalidates every stored key.
 */
final class KeyHasher
{
    public function __construct(
        private readonly ?string $encryptionKey = null,
    ) {
    }

    public function hash(string $key): string
    {
        return hash_hmac('sha256', $key, $this->getSecret());
    }

    public function verify(string $key, string $hash): bool
    {
        return hash_equals($this->hash($key), $hash);
    }

    private function getSecret(): string
    {
        $encryptionKey = $this->encryptionKey ?? (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ($encryptionKey === '') {
            throw new MissingEncryptionKeyException(
                'API keys cannot be hashed: $GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'encryptionKey\'] is empty.',
                1792512000
            );
        }

        return hash_hmac('sha256', 'maikschneider/api-key', $encryptionKey);
    }
}
