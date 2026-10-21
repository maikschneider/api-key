<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Middleware;

use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Renders a cacheable page with a real page cache, so a keyed response that
 * entered the cache would be served to the next request.
 */
final class PageCacheTest extends AbstractMiddlewareTestCase
{
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'pages' => [
                        'backend' => Typo3DatabaseBackend::class,
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/private-page.csv');
        $this->setUpFrontendRootPage(1, ['EXT:api_key_test/Configuration/TypoScript/page.typoscript']);
    }

    #[Test]
    public function anonymousContentIsCached(): void
    {
        $this->requestPage();

        self::assertGreaterThan(0, $this->countRows('cache_pages'));
        self::assertSame('ANONYMOUS', $this->body($this->requestPage()));
    }

    #[Test]
    public function keyedContentIsNotServedToAnonymousVisitors(): void
    {
        $alice = $this->get(ApiKeyCreator::class)->create('Alice');

        self::assertSame('PRIVATE_FOR_Alice', $this->body($this->requestPage(['X-API-Key' => $alice->secret])));
        self::assertSame('ANONYMOUS', $this->body($this->requestPage()));
    }

    #[Test]
    public function keyedContentIsNotServedToAnotherKey(): void
    {
        $alice = $this->get(ApiKeyCreator::class)->create('Alice');
        $bob = $this->get(ApiKeyCreator::class)->create('Bob');

        self::assertSame('PRIVATE_FOR_Alice', $this->body($this->requestPage(['X-API-Key' => $alice->secret])));
        self::assertSame('PRIVATE_FOR_Bob', $this->body($this->requestPage(['Authorization' => 'Bearer ' . $bob->secret])));
    }

    #[Test]
    public function keyedRequestDoesNotGetCachedAnonymousContent(): void
    {
        $alice = $this->get(ApiKeyCreator::class)->create('Alice');

        self::assertSame('ANONYMOUS', $this->body($this->requestPage()));
        self::assertSame('PRIVATE_FOR_Alice', $this->body($this->requestPage(['X-API-Key' => $alice->secret])));
    }

    #[Test]
    public function keyedResponseIsNotStoredByClients(): void
    {
        $alice = $this->get(ApiKeyCreator::class)->create('Alice');

        $response = $this->requestPage(['X-API-Key' => $alice->secret]);

        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(0, $this->countRows('cache_pages'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function requestPage(array $headers = []): ResponseInterface
    {
        $request = new InternalRequest('http://localhost/private');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->executeFrontendSubRequest($request);
    }

    private function body(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $body = (string)$response->getBody();
        if (preg_match('/PRIVATE_FOR_\w+|ANONYMOUS/', $body, $matches) !== 1) {
            self::fail($body);
        }

        return $matches[0];
    }
}
