<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Key;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Holds the secret of a key created in the backend until it is shown once.
 *
 * FormEngine redirects after saving, so the secret has to survive one request.
 * It is kept in the backend user session, encrypted with a key derived from the
 * session id. The database only stores a hash of that id, so the session table
 * alone does not reveal the secret.
 */
final class OneTimeSecretStore
{
    private const SESSION_KEY = 'tx_apikey_created';

    public function put(int $uid, #[\SensitiveParameter] string $secret): bool
    {
        $backendUser = $this->getBackendUser();
        $encryptionKey = $this->deriveKey($backendUser);
        if ($backendUser === null || $encryptionKey === null) {
            return false;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $secrets = $this->load($backendUser);
        $secrets[$uid] = base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $encryptionKey));
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, $secrets);

        return true;
    }

    /**
     * Returns the secret and forgets it.
     */
    public function take(int $uid): ?string
    {
        return $this->takeAll([$uid])[$uid] ?? null;
    }

    /**
     * Returns the secrets of the given keys, or of all pending keys, and forgets them.
     *
     * @param list<int>|null $uids
     * @return array<int, string> secrets keyed by key uid
     */
    public function takeAll(?array $uids = null): array
    {
        $backendUser = $this->getBackendUser();
        $encryptionKey = $this->deriveKey($backendUser);
        if ($backendUser === null || $encryptionKey === null) {
            return [];
        }

        $stored = $this->load($backendUser);
        $taken = array_intersect_key($stored, array_flip($uids ?? array_keys($stored)));
        if ($taken === []) {
            return [];
        }
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, array_diff_key($stored, $taken));

        $secrets = [];
        foreach ($taken as $uid => $value) {
            $decoded = (string)base64_decode($value, true);
            $secret = sodium_crypto_secretbox_open(
                substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $encryptionKey
            );
            if (is_string($secret)) {
                $secrets[$uid] = $secret;
            }
        }

        return $secrets;
    }

    /**
     * @return array<int, string>
     */
    private function load(BackendUserAuthentication $backendUser): array
    {
        $stored = $backendUser->getSessionData(self::SESSION_KEY);

        return is_array($stored) ? array_filter($stored, is_string(...)) : [];
    }

    private function deriveKey(?BackendUserAuthentication $backendUser): ?string
    {
        try {
            $sessionId = $backendUser?->getSession()->getIdentifier() ?? '';
        } catch (\TypeError) {
            // getSession() is typed non-nullable but returns null before a session exists, e.g. on the command line
            $sessionId = '';
        }
        $encryptionKey = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ($sessionId === '' || $encryptionKey === '') {
            return null;
        }

        return hash_hmac('sha256', 'maikschneider/api-key:' . $sessionId, $encryptionKey, true);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
