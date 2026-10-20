<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Authentication;

use Psr\Http\Message\ServerRequestInterface;

interface ApiKeyAuthenticatorInterface
{
    /**
     * Authenticates the key a request carries. Has no side effects besides a
     * throttled update of the key's last use: no session, no cookie.
     */
    public function authenticate(ServerRequestInterface $request): AuthenticationResult;
}
