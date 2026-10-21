<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Middleware;

use Psr\Http\Message\ResponseInterface;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractMiddlewareTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'maikschneider/api-key',
        __DIR__ . '/Fixtures/Extensions/api_key_test',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/api_key/Tests/Functional/Middleware/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    protected function request(array $headers): ResponseInterface
    {
        $request = new InternalRequest('http://localhost/');
        foreach ($headers as $name => $values) {
            foreach ((array)$values as $value) {
                $request = $request->withAddedHeader($name, $value);
            }
        }

        return $this->executeFrontendSubRequest($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data, (string)$response->getBody());

        return $data;
    }

    protected function countRows(string $table): int
    {
        return $this->getConnectionPool()->getConnectionForTable($table)->count('*', $table, []);
    }
}
