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
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
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
