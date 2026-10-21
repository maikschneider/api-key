<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKeyTest;

use MaikSchneider\ApiKey\Middleware\ApiKeyAuthentication;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;

/**
 * Cacheable USER content object whose output depends on the presented key.
 */
final class KeyedContent
{
    /**
     * @param array<string, mixed> $conf
     */
    #[AsAllowedCallable]
    public function render(string $content, array $conf, ServerRequestInterface $request): string
    {
        $apiKey = ApiKeyAuthentication::getResult($request)?->getApiKey();

        return $apiKey !== null ? 'PRIVATE_FOR_' . $apiKey->title : 'ANONYMOUS';
    }
}
