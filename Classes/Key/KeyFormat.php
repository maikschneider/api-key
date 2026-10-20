<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Key;

/**
 * A key is the prefix followed by 32 random bytes in unpadded base64url. The
 * fixed prefix makes keys recognisable for secret scanners and lets bearer
 * token parsers tell these keys apart from tokens of other authenticators.
 */
final class KeyFormat
{
    public const PREFIX = 't3key_';

    /**
     * Prefix plus the first 8 characters of the random part, safe to show and log.
     */
    public const IDENTIFIER_LENGTH = 14;

    private const PATTERN = '/^t3key_[A-Za-z0-9_-]{43}$/D';

    private const EMBEDDED_PATTERN = '/t3key_[A-Za-z0-9_-]{43}/';

    public static function isKey(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function containsKey(string $value): bool
    {
        return preg_match(self::EMBEDDED_PATTERN, $value) === 1;
    }

    public static function hasPrefix(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public static function identifier(string $key): string
    {
        return substr($key, 0, self::IDENTIFIER_LENGTH);
    }
}
