<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Key;

final class KeyGenerator
{
    public function generate(): string
    {
        return KeyFormat::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
