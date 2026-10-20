<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKeyTest;

use MaikSchneider\ApiKey\Middleware\ApiKeyAuthentication;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

final class EchoMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Context $context,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = ApiKeyAuthentication::getResult($request);

        return new JsonResponse([
            'attribute' => $result !== null,
            'present' => $result?->isPresent(),
            'valid' => $result?->isValid(),
            'reason' => $result?->getInvalidReason()?->value,
            'uid' => $result?->getApiKey()?->uid,
            'frontendUserUid' => $result?->getApiKey()?->frontendUserUid,
            'frontendUserLoggedIn' => (bool)$this->context->getPropertyFromAspect('frontend.user', 'isLoggedIn'),
        ]);
    }
}
