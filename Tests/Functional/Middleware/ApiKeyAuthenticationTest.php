<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Middleware;

use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

final class ApiKeyAuthenticationTest extends AbstractMiddlewareTestCase
{
    #[Test]
    public function requestWithoutKeyGetsNotPresentResult(): void
    {
        $data = $this->decode($this->executeFrontendSubRequest(new InternalRequest('http://localhost/')));

        self::assertTrue($data['attribute']);
        self::assertFalse($data['present']);
    }

    #[Test]
    public function validKeyIsAttachedToTheRequest(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Frontend user key', feUser: 1);

        $data = $this->decode($this->request(['X-API-Key' => $key->secret]));

        self::assertTrue($data['valid']);
        self::assertSame($key->uid, $data['uid']);
        self::assertSame(1, $data['frontendUserUid']);
    }

    #[Test]
    public function siteRestrictedKeyWorksBecauseTheSiteIsResolvedFirst(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Site key', sites: ['main']);

        self::assertTrue($this->decode($this->request(['Authorization' => 'Bearer ' . $key->secret]))['valid']);
    }

    #[Test]
    public function keyedRequestHasNoSessionOrLoginSideEffects(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Frontend user key', feUser: 1);

        $response = $this->request(['X-API-Key' => $key->secret]);
        $data = $this->decode($response);

        self::assertTrue($data['valid']);
        self::assertFalse($data['frontendUserLoggedIn']);
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame(0, $this->countRows('fe_sessions'));
        self::assertSame(0, (int)$this->getConnectionPool()->getConnectionForTable('fe_users')
            ->select(['lastlogin'], 'fe_users', ['uid' => 1])->fetchOne());
    }

    #[Test]
    public function invalidKeyIsAttachedWhenRejectionIsOff(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Revoked');
        $this->get(ApiKeyRepository::class)->revoke($key->uid);

        $response = $this->request(['X-API-Key' => $key->secret]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('revoked', $this->decode($response)['reason']);
    }
}
