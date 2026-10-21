<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Authentication;

use MaikSchneider\ApiKey\Key\KeyFormat;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a key from the X-API-Key header or an Authorization bearer token.
 *
 * Returns the key, null when the request carries none, or the reason why the
 * presented credential is rejected. Bearer tokens without the key prefix belong
 * to other authenticators (OAuth2, JWT) and count as no key.
 */
final class CredentialExtractor
{
    public const HEADER = 'X-API-Key';

    public function extract(ServerRequestInterface $request): string|InvalidReason|null
    {
        if ($this->queryContainsKey($request->getQueryParams())) {
            return InvalidReason::KeyInQueryString;
        }

        $candidates = [];
        foreach ([$this->fromApiKeyHeader($request), $this->fromBearerToken($request)] as $candidate) {
            if ($candidate instanceof InvalidReason) {
                return $candidate;
            }
            if ($candidate !== null) {
                $candidates[$candidate] = $candidate;
            }
        }
        if ($candidates === []) {
            return null;
        }
        if (count($candidates) > 1) {
            return InvalidReason::Ambiguous;
        }

        return $this->validate(reset($candidates));
    }

    private function fromApiKeyHeader(ServerRequestInterface $request): string|InvalidReason|null
    {
        $values = $request->getHeader(self::HEADER);
        if ($values === []) {
            return null;
        }
        if (count($values) > 1) {
            return InvalidReason::Ambiguous;
        }

        return trim($values[0]);
    }

    /**
     * Ownership is decided before the syntax is checked, so a broken header
     * carrying a key is rejected instead of being passed on as "no key".
     */
    private function fromBearerToken(ServerRequestInterface $request): string|InvalidReason|null
    {
        $values = $request->getHeader('Authorization');
        if ($values === []) {
            // Apache with PHP-FPM may only expose the header after a rewrite
            $serverParams = $request->getServerParams();
            $redirected = (string)($serverParams['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            $values = $redirected !== '' ? [$redirected] : [];
        }
        $ownValues = array_values(array_filter($values, $this->isOwnBearerToken(...)));
        if ($ownValues === []) {
            return null;
        }
        if (count($values) > 1) {
            return InvalidReason::Ambiguous;
        }
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/iD', $ownValues[0], $matches) !== 1) {
            return InvalidReason::Malformed;
        }

        return $matches[1];
    }

    private function isOwnBearerToken(string $value): bool
    {
        return preg_match('/^\s*Bearer\s+' . preg_quote(KeyFormat::PREFIX, '/') . '/i', $value) === 1;
    }

    private function validate(string $candidate): string|InvalidReason
    {
        return KeyFormat::isKey($candidate) ? $candidate : InvalidReason::Malformed;
    }

    /**
     * @param array<mixed> $params
     */
    private function queryContainsKey(array $params): bool
    {
        foreach ($params as $value) {
            if (is_array($value) ? $this->queryContainsKey($value) : (is_string($value) && KeyFormat::containsKey($value))) {
                return true;
            }
        }

        return false;
    }
}
