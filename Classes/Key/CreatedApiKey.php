<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Key;

final class CreatedApiKey
{
    public function __construct(
        public readonly int $uid,
        #[\SensitiveParameter]
        public readonly string $secret,
        public readonly string $identifier,
    ) {
    }
}
