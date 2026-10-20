<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Authentication;

/**
 * Outcome of authenticating a request:
 *
 * - not present: the request carries no key of this extension; try other
 *   authentication mechanisms or treat the request as anonymous
 * - valid: carries the authenticated key
 * - invalid: a key was presented but rejected; answer 401 and never fall back
 *   to anonymous access
 */
final class AuthenticationResult
{
    private function __construct(
        private readonly ?ApiKey $apiKey,
        private readonly ?InvalidReason $invalidReason,
    ) {
    }

    public static function notPresent(): self
    {
        return new self(null, null);
    }

    public static function valid(ApiKey $apiKey): self
    {
        return new self($apiKey, null);
    }

    public static function invalid(InvalidReason $reason): self
    {
        return new self(null, $reason);
    }

    public function isPresent(): bool
    {
        return $this->apiKey !== null || $this->invalidReason !== null;
    }

    public function isValid(): bool
    {
        return $this->apiKey !== null;
    }

    public function isInvalid(): bool
    {
        return $this->invalidReason !== null;
    }

    public function getApiKey(): ?ApiKey
    {
        return $this->apiKey;
    }

    public function getInvalidReason(): ?InvalidReason
    {
        return $this->invalidReason;
    }
}
