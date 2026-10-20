<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Authentication;

use MaikSchneider\ApiKey\Key\KeyFormat;
use MaikSchneider\ApiKey\Key\KeyHasher;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ApiKeyAuthenticator implements ApiKeyAuthenticatorInterface
{
    public function __construct(
        private readonly CredentialExtractor $credentialExtractor,
        private readonly KeyHasher $keyHasher,
        private readonly ApiKeyRepository $repository,
        private readonly Context $context,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function authenticate(ServerRequestInterface $request): AuthenticationResult
    {
        $credential = $this->credentialExtractor->extract($request);
        if ($credential === null) {
            return AuthenticationResult::notPresent();
        }
        if ($credential instanceof InvalidReason) {
            return $this->reject($request, $credential);
        }

        $hash = $this->keyHasher->hash($credential);
        $row = $this->repository->findByHash($hash);
        $identifier = KeyFormat::identifier($credential);
        if ($row === null || !hash_equals((string)$row['key_hash'], $hash)) {
            return $this->reject($request, InvalidReason::Unknown, $identifier);
        }
        if ((int)$row['deleted'] === 1 || (int)$row['hidden'] === 1) {
            return $this->reject($request, InvalidReason::Revoked, $identifier);
        }
        $expiresAt = (int)$row['expires_at'];
        if ($expiresAt > 0 && $expiresAt <= $this->now()) {
            return $this->reject($request, InvalidReason::Expired, $identifier);
        }

        $allowedIps = ApiKey::splitList((string)($row['allowed_ips'] ?? ''));
        if ($allowedIps !== [] && !$this->isIpAllowed($request, $allowedIps)) {
            return $this->reject($request, InvalidReason::IpNotAllowed, $identifier);
        }
        $sites = ApiKey::splitList((string)($row['sites'] ?? ''));
        if ($sites !== [] && !$this->isSiteAllowed($request, $sites)) {
            return $this->reject($request, InvalidReason::SiteNotAllowed, $identifier);
        }

        $this->repository->touchLastUsed((int)$row['uid']);

        return AuthenticationResult::valid(ApiKey::fromRow($row));
    }

    /**
     * @param list<string> $allowedIps
     */
    private function isIpAllowed(ServerRequestInterface $request, array $allowedIps): bool
    {
        $remoteAddress = $this->getRemoteAddress($request);

        return $remoteAddress !== '' && GeneralUtility::cmpIP($remoteAddress, implode(',', $allowedIps));
    }

    /**
     * @param list<string> $sites
     */
    private function isSiteAllowed(ServerRequestInterface $request, array $sites): bool
    {
        $site = $request->getAttribute('site');

        return $site instanceof Site && in_array($site->getIdentifier(), $sites, true);
    }

    /**
     * Prefers NormalizedParams, which resolves the client address behind
     * trusted reverse proxies (TYPO3_CONF_VARS.SYS.reverseProxyIP).
     */
    private function getRemoteAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams->getRemoteAddress();
        }

        return (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    private function reject(ServerRequestInterface $request, InvalidReason $reason, string $identifier = ''): AuthenticationResult
    {
        $this->logger->warning('API key rejected: {reason}', [
            'reason' => $reason->value,
            'identifier' => $identifier,
            'remoteAddress' => $this->getRemoteAddress($request),
            'uri' => (string)$request->getUri()->withQuery('')->withFragment(''),
        ]);

        return AuthenticationResult::invalid($reason);
    }

    private function now(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }
}
