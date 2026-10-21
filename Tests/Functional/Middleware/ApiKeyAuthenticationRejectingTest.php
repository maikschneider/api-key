<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Middleware;

use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Runs with rejectInvalidKeys enabled.
 */
final class ApiKeyAuthenticationRejectingTest extends AbstractMiddlewareTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'api_key' => [
                'rejectInvalidKeys' => '1',
            ],
        ],
    ];

    #[Test]
    public function invalidKeyIsAnsweredWith401(): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Revoked');
        $this->get(ApiKeyRepository::class)->revoke($key->uid);

        $response = $this->request(['X-API-Key' => $key->secret]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ['Bearer realm="api", error="invalid_token"', 'ApiKey realm="api", header="X-API-Key"'],
            $response->getHeader('WWW-Authenticate')
        );
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['error' => 'invalid_api_key'], json_decode((string)$response->getBody(), true));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    /**
     * @return iterable<string, array{string|list<string>}>
     */
    public static function brokenAuthorizationHeaders(): iterable
    {
        yield 'trailing garbage' => ['Bearer {key} garbage'];
        yield 'repeated header' => [['Bearer {key}', 'Bearer t3key_bad']];
        yield 'key after a foreign bearer' => ['Bearer foreign, Bearer {key}'];
    }

    /**
     * @param string|list<string> $authorization
     */
    #[Test]
    #[DataProvider('brokenAuthorizationHeaders')]
    public function brokenAuthorizationHeaderWithKeyIsAnsweredWith401(string|array $authorization): void
    {
        $key = $this->get(ApiKeyCreator::class)->create('Valid');
        $authorization = str_replace('{key}', $key->secret, $authorization);

        $response = $this->request(['Authorization' => $authorization]);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function requestWithoutKeyPassesThrough(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->decode($response)['present']);
    }

    #[Test]
    public function foreignBearerTokenPassesThrough(): void
    {
        $response = $this->request(['Authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln']);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->decode($response)['present']);
    }
}
