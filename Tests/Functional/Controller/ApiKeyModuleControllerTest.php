<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Controller;

use MaikSchneider\ApiKey\Controller\ApiKeyModuleController;
use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\ApiKey\Key\OneTimeSecretStore;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ApiKeyModuleControllerTest extends FunctionalTestCase
{
    private const NOW = 1_800_000_000;

    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    protected array $testExtensionsToLoad = [
        'maikschneider/api-key',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Skipping before parent::setUp() breaks the testing framework's tearDown().
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The backend module requires TYPO3 v14.');
        }
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . self::NOW)));
    }

    #[Test]
    public function indexShowsASecretThatWasNotShownYetOnce(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy');
        $this->get(OneTimeSecretStore::class)->put($createdKey->uid, $createdKey->secret);

        $response = $this->get(ApiKeyModuleController::class)->indexAction($this->request('_default'));

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString($createdKey->secret, (string)$response->getBody());
        self::assertNull($this->get(OneTimeSecretStore::class)->take($createdKey->uid));

        $body = (string)$this->get(ApiKeyModuleController::class)->indexAction($this->request('_default'))->getBody();
        self::assertStringNotContainsString($createdKey->secret, $body);
    }

    #[Test]
    public function createButtonOpensFormEngine(): void
    {
        $body = (string)$this->get(ApiKeyModuleController::class)->indexAction($this->request('_default'))->getBody();

        self::assertStringContainsString('edit[tx_apikey_key][0]=new', urldecode($body));
    }

    #[Test]
    public function revokeHidesTheKeyAndRedirectsToTheList(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy');

        $response = $this->get(ApiKeyModuleController::class)->revokeAction($this->request('revoke', ['uid' => (string)$createdKey->uid]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, (int)($this->get(ApiKeyRepository::class)->findByUid($createdKey->uid)['hidden'] ?? 0));
    }

    #[Test]
    public function indexListsKeysWithoutTheirHashes(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy', scopes: ['articles:read']);
        $hash = (string)($this->get(ApiKeyRepository::class)->findByUid($createdKey->uid)['key_hash'] ?? '');
        self::assertNotSame('', $hash);

        $body = (string)$this->get(ApiKeyModuleController::class)->indexAction($this->request('_default'))->getBody();

        self::assertStringContainsString('CI deploy', $body);
        self::assertStringContainsString($createdKey->identifier, $body);
        self::assertStringContainsString('articles:read', $body);
        self::assertStringNotContainsString($hash, $body);
        self::assertStringNotContainsString($createdKey->secret, $body);
    }

    /**
     * @param array<string, string>|null $body
     */
    private function request(string $route, ?array $body = null): ServerRequest
    {
        $routeIdentifier = $route === '_default' ? 'api_key' : 'api_key.' . $route;
        $request = (new ServerRequest('https://localhost/typo3/module/integrations/api-key', $body === null ? 'GET' : 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute($routeIdentifier))
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('api_key'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
