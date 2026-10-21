<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Middleware;

use MaikSchneider\ApiKey\Authentication\ApiKeyAuthenticatorInterface;
use MaikSchneider\ApiKey\Authentication\AuthenticationResult;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;

/**
 * Authenticates the key of a frontend request once and attaches the
 * AuthenticationResult as request attribute "api_key". Does not log in users:
 * consumers map the bound identity to their own authorization model.
 *
 * Responses to authenticated requests depend on the key, so they must neither
 * enter nor come from the shared page cache.
 */
final class ApiKeyAuthentication implements MiddlewareInterface
{
    public const ATTRIBUTE = 'api_key';

    public function __construct(
        private readonly ApiKeyAuthenticatorInterface $authenticator,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->authenticator->authenticate($request);
        if ($result->isInvalid() && $this->rejectsInvalidKeys()) {
            return $this->createUnauthorizedResponse();
        }

        $request = $request->withAttribute(self::ATTRIBUTE, $result);
        if ($result->isValid()) {
            $request = $this->disablePageCache($request);
        }

        return $handler->handle($request);
    }

    public static function getResult(ServerRequestInterface $request): ?AuthenticationResult
    {
        $result = $request->getAttribute(self::ATTRIBUTE);

        return $result instanceof AuthenticationResult ? $result : null;
    }

    private function disablePageCache(ServerRequestInterface $request): ServerRequestInterface
    {
        $cacheInstruction = $request->getAttribute('frontend.cache.instruction');
        if (!$cacheInstruction instanceof CacheInstruction) {
            $cacheInstruction = new CacheInstruction();
        }
        $cacheInstruction->disableCache('EXT:api_key: Request is authenticated with an API key.');

        return $request->withAttribute('frontend.cache.instruction', $cacheInstruction);
    }

    private function rejectsInvalidKeys(): bool
    {
        try {
            return (bool)$this->extensionConfiguration->get('api_key', 'rejectInvalidKeys');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The reason is not exposed, so clients can't probe which keys exist.
     */
    private function createUnauthorizedResponse(): ResponseInterface
    {
        return $this->responseFactory->createResponse(401)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('WWW-Authenticate', 'Bearer realm="api", error="invalid_token"')
            ->withAddedHeader('WWW-Authenticate', 'ApiKey realm="api", header="X-API-Key"')
            ->withBody($this->streamFactory->createStream((string)json_encode(['error' => 'invalid_api_key'])));
    }
}
