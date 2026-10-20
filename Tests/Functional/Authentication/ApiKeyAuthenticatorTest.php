<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Authentication;

use MaikSchneider\ApiKey\Authentication\ApiKeyAuthenticator;
use MaikSchneider\ApiKey\Authentication\ApiKeyAuthenticatorInterface;
use MaikSchneider\ApiKey\Authentication\AuthenticationResult;
use MaikSchneider\ApiKey\Authentication\CredentialExtractor;
use MaikSchneider\ApiKey\Authentication\InvalidReason;
use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\ApiKey\Key\CreatedApiKey;
use MaikSchneider\ApiKey\Key\KeyHasher;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ApiKeyAuthenticatorTest extends FunctionalTestCase
{
    private const NOW = 1_800_000_000;

    protected array $testExtensionsToLoad = [
        'maikschneider/api-key',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Key/Fixtures/users.csv');
        $this->setNow(self::NOW);
    }

    #[Test]
    public function interfaceResolvesToAuthenticator(): void
    {
        self::assertInstanceOf(ApiKeyAuthenticator::class, $this->get(ApiKeyAuthenticatorInterface::class));
    }

    #[Test]
    public function requestWithoutKeyIsNotPresent(): void
    {
        $result = $this->authenticate(new ServerRequest('https://example.com/api'));

        self::assertFalse($result->isPresent());
    }

    #[Test]
    public function validKeyReturnsKeyData(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create(
            title: 'Importer',
            feUser: 1,
            scopes: ['articles:read'],
            expiresAt: self::NOW + 60,
        );

        $result = $this->authenticate($this->requestWithKey($key->secret));

        self::assertTrue($result->isValid());
        $apiKey = $result->getApiKey();
        self::assertNotNull($apiKey);
        self::assertSame($key->uid, $apiKey->uid);
        self::assertSame('Importer', $apiKey->title);
        self::assertSame($key->identifier, $apiKey->identifier);
        self::assertSame(['articles:read'], $apiKey->scopes);
        self::assertSame(1, $apiKey->frontendUserUid);
        self::assertNull($apiKey->backendUserUid);
        self::assertSame(self::NOW + 60, $apiKey->expiresAt?->getTimestamp());
    }

    #[Test]
    public function bearerTokenIsAccepted(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Bearer');

        $result = $this->authenticate(
            (new ServerRequest('https://example.com/api'))->withHeader('Authorization', 'Bearer ' . $key->secret)
        );

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function validKeyUpdatesLastUsedThrottled(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Throttled');

        $this->authenticate($this->requestWithKey($key->secret));
        self::assertSame(self::NOW, $this->getLastUsed($key));

        $this->setNow(self::NOW + 10);
        $this->authenticate($this->requestWithKey($key->secret));
        self::assertSame(self::NOW, $this->getLastUsed($key));
    }

    #[Test]
    public function unknownKeyIsInvalid(): void
    {
        $this->get(ApiKeyCreator::class)->create('Other');

        $result = $this->authenticate($this->requestWithKey('t3key_KhhNfTRL-5tLoriJVo5I-BQRJWiLD3GqlvEcPDPeHmg'));

        self::assertSame(InvalidReason::Unknown, $result->getInvalidReason());
    }

    #[Test]
    public function hiddenKeyIsRevoked(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Hidden');
        $this->get(ApiKeyRepository::class)->revoke($key->uid);

        self::assertSame(InvalidReason::Revoked, $this->authenticate($this->requestWithKey($key->secret))->getInvalidReason());
    }

    #[Test]
    public function deletedKeyIsRevoked(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Deleted');
        $this->updateKey($key, ['deleted' => 1]);

        self::assertSame(InvalidReason::Revoked, $this->authenticate($this->requestWithKey($key->secret))->getInvalidReason());
    }

    #[Test]
    public function expiredKeyIsInvalid(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Expiring', expiresAt: self::NOW + 60);

        self::assertTrue($this->authenticate($this->requestWithKey($key->secret))->isValid());

        $this->setNow(self::NOW + 60);
        self::assertSame(InvalidReason::Expired, $this->authenticate($this->requestWithKey($key->secret))->getInvalidReason());
    }

    #[Test]
    public function rejectedKeyDoesNotUpdateLastUsed(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Revoked');
        $this->get(ApiKeyRepository::class)->revoke($key->uid);

        $this->authenticate($this->requestWithKey($key->secret));

        self::assertSame(0, $this->getLastUsed($key));
    }

    #[Test]
    public function keyInQueryStringIsInvalidEvenIfValid(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Leaked');

        $result = $this->authenticate(
            $this->requestWithKey($key->secret)->withQueryParams(['api_key' => $key->secret])
        );

        self::assertSame(InvalidReason::KeyInQueryString, $result->getInvalidReason());
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function ipRestrictions(): iterable
    {
        yield 'exact ipv4' => [['192.0.2.1'], '192.0.2.1', true];
        yield 'ipv4 range' => [['10.0.0.0/8'], '10.20.30.40', true];
        yield 'second entry matches' => [['10.0.0.0/8', '192.0.2.0/24'], '192.0.2.99', true];
        yield 'ipv6 range' => [['2001:db8::/32'], '2001:db8::1', true];
        yield 'outside ipv4 range' => [['10.0.0.0/8'], '11.0.0.1', false];
        yield 'outside ipv6 range' => [['2001:db8::/32'], '2001:db9::1', false];
        yield 'ipv4 client for ipv6 range' => [['2001:db8::/32'], '192.0.2.1', false];
        yield 'no client address' => [['10.0.0.0/8'], '', false];
    }

    /**
     * @param list<string> $allowedIps
     */
    #[Test]
    #[DataProvider('ipRestrictions')]
    public function ipRestriction(array $allowedIps, string $remoteAddress, bool $allowed): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('IP', allowedIps: $allowedIps);

        $result = $this->authenticate($this->requestWithKey($key->secret, ['REMOTE_ADDR' => $remoteAddress]));

        self::assertSame($allowed ? null : InvalidReason::IpNotAllowed, $result->getInvalidReason());
        self::assertSame($allowed, $result->isValid());
    }

    #[Test]
    public function ipRestrictionUsesClientAddressBehindTrustedProxy(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Proxy', allowedIps: ['192.0.2.0/24']);
        $request = $this->requestWithKey($key->secret, [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.7',
        ]);
        $systemConfiguration = ['reverseProxyIP' => '10.0.0.1', 'reverseProxyHeaderMultiValue' => 'last'];

        $behindProxy = $request->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($request->getServerParams(), $systemConfiguration));
        self::assertTrue($this->authenticate($behindProxy)->isValid());

        $untrustedProxy = $request->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($request->getServerParams(), []));
        self::assertSame(InvalidReason::IpNotAllowed, $this->authenticate($untrustedProxy)->getInvalidReason());
    }

    #[Test]
    public function siteRestriction(): void
    {
        $this->writeSiteConfiguration('main');
        $this->writeSiteConfiguration('other');
        $key = $this->get(ApiKeyCreator::class)->create('Site', sites: ['main']);
        $request = $this->requestWithKey($key->secret);

        self::assertTrue($this->authenticate($request->withAttribute('site', new Site('main', 1, [])))->isValid());
        self::assertSame(
            InvalidReason::SiteNotAllowed,
            $this->authenticate($request->withAttribute('site', new Site('other', 2, [])))->getInvalidReason()
        );
        self::assertSame(
            InvalidReason::SiteNotAllowed,
            $this->authenticate($request->withAttribute('site', new NullSite()))->getInvalidReason()
        );
        self::assertSame(InvalidReason::SiteNotAllowed, $this->authenticate($request)->getInvalidReason());
    }

    #[Test]
    public function keyWithoutRestrictionsIsValidWithoutSiteAndAddress(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Unrestricted');

        self::assertTrue($this->authenticate($this->requestWithKey($key->secret))->isValid());
    }

    #[Test]
    public function rejectionIsLoggedWithIdentifierButWithoutSecret(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Logged');
        $this->get(ApiKeyRepository::class)->revoke($key->uid);
        $logger = new class() extends AbstractLogger {
            /** @var list<array{mixed, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string)$message, $context];
            }
        };
        $subject = new ApiKeyAuthenticator(
            new CredentialExtractor(),
            new KeyHasher(),
            $this->get(ApiKeyRepository::class),
            $this->get(Context::class),
            $logger,
        );

        $subject->authenticate($this->requestWithKey($key->secret, ['REMOTE_ADDR' => '192.0.2.1']));

        self::assertCount(1, $logger->records);
        [$level, , $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertSame('revoked', $context['reason']);
        self::assertSame($key->identifier, $context['identifier']);
        self::assertSame('192.0.2.1', $context['remoteAddress']);
        self::assertStringNotContainsString($key->secret, (string)json_encode($logger->records));
    }

    private function authenticate(ServerRequest $request): AuthenticationResult
    {
        return $this->get(ApiKeyAuthenticatorInterface::class)->authenticate($request);
    }

    /**
     * @param array<string, string> $serverParams
     */
    private function requestWithKey(string $key, array $serverParams = []): ServerRequest
    {
        return new ServerRequest('https://example.com/api', 'GET', 'php://input', ['X-API-Key' => $key], $serverParams);
    }

    private function writeSiteConfiguration(string $identifier): void
    {
        GeneralUtility::mkdir_deep(Environment::getConfigPath() . '/sites/' . $identifier);
        file_put_contents(
            Environment::getConfigPath() . '/sites/' . $identifier . '/config.yaml',
            "rootPageId: 1\nbase: /" . $identifier . "/\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n"
        );
    }

    private function setNow(int $timestamp): void
    {
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . $timestamp)));
    }

    /**
     * @param array<string, int> $fields
     */
    private function updateKey(CreatedApiKey $key, array $fields): void
    {
        $this->get(ConnectionPool::class)
            ->getConnectionForTable(ApiKeyRepository::TABLE)
            ->update(ApiKeyRepository::TABLE, $fields, ['uid' => $key->uid]);
    }

    private function getLastUsed(CreatedApiKey $key): int
    {
        return (int)($this->get(ApiKeyRepository::class)->findByUid($key->uid)['last_used_at'] ?? -1);
    }
}
