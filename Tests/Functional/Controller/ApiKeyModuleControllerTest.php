<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Controller;

use MaikSchneider\ApiKey\Controller\ApiKeyModuleController;
use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\ApiKey\Key\KeyHasher;
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
        if (!class_exists(ComponentFactory::class)) {
            self::markTestSkipped('The backend module requires TYPO3 v14.');
        }
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . self::NOW)));
    }

    #[Test]
    public function createShowsTheSecretOnceAndStoresOnlyItsHash(): void
    {
        $response = $this->get(ApiKeyModuleController::class)->createAction($this->request('create', [
            'title' => 'CI deploy',
            'identity' => 'be_user',
            'be_user' => '2',
            'fe_user' => '99',
            'scopes' => "articles:read\narticles:write",
            'expires_at' => '2027-06-30',
            'allowed_ips' => '10.0.0.0/8',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = (string)$response->getBody();
        preg_match_all('/t3key_[A-Za-z0-9_-]{43}/', $body, $matches);
        $secrets = array_values(array_unique($matches[0]));
        self::assertCount(1, $secrets);

        $row = $this->get(ApiKeyRepository::class)->findByHash((new KeyHasher())->hash($secrets[0]));
        self::assertNotNull($row);
        self::assertSame('CI deploy', $row['title']);
        self::assertSame(2, (int)$row['be_user']);
        self::assertSame(0, (int)$row['fe_user']);
        self::assertSame(1, (int)$row['created_by']);
        self::assertSame("articles:read\narticles:write", $row['scopes']);
        self::assertSame('10.0.0.0/8', $row['allowed_ips']);
        self::assertSame((new \DateTimeImmutable('2027-06-30 00:00:00'))->getTimestamp(), (int)$row['expires_at']);
    }

    #[Test]
    public function invalidDefinitionRendersTheFormAgainWithoutCreatingAKey(): void
    {
        $response = $this->get(ApiKeyModuleController::class)->createAction($this->request('create', [
            'title' => 'CI deploy',
            'allowed_ips' => 'not-an-ip',
        ]));

        $body = (string)$response->getBody();
        self::assertStringContainsString('not-an-ip" is neither an IP address nor a CIDR range.', $body);
        self::assertStringContainsString('value="CI deploy"', $body);
        self::assertStringNotContainsString('t3key_', $body);
        self::assertSame([], $this->get(ApiKeyRepository::class)->findAll());
    }

    #[Test]
    public function userBindingNeedsASelectedUser(): void
    {
        $response = $this->get(ApiKeyModuleController::class)->createAction($this->request('create', [
            'title' => 'CI deploy',
            'identity' => 'fe_user',
        ]));

        self::assertStringContainsString('Select the frontend user to bind the key to.', (string)$response->getBody());
        self::assertSame([], $this->get(ApiKeyRepository::class)->findAll());
    }

    #[Test]
    public function revokeHidesTheKeyAndRedirectsToTheList(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy');

        $response = $this->get(ApiKeyModuleController::class)->revokeAction($this->request('revoke', ['uid' => (string)$createdKey->uid], false));

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
    private function request(string $route, ?array $body = null, bool $nested = true): ServerRequest
    {
        $routeIdentifier = $route === '_default' ? 'api_key' : 'api_key.' . $route;
        $request = (new ServerRequest('https://localhost/typo3/module/integrations/api-key', $body === null ? 'GET' : 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute($routeIdentifier))
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('api_key'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        if ($body !== null) {
            $request = $request->withParsedBody($nested ? ['key' => $body] : $body);
        }
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
