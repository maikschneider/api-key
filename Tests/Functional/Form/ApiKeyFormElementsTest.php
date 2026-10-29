<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Form;

use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\ApiKey\Key\OneTimeSecretStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ApiKeyFormElementsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    protected array $testExtensionsToLoad = [
        'maikschneider/api-key',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Controller/Fixtures/be_users.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));
    }

    #[Test]
    public function identifierOfANewRecordExplainsThatTheKeyIsGeneratedOnSave(): void
    {
        $html = $this->render('apiKeyIdentifier', 'key_prefix', ['uid' => 'NEW123'], '')['html'];

        self::assertStringContainsString('The key is generated when you save this record and shown once.', $html);
        self::assertStringNotContainsString('<input', $html);
    }

    #[Test]
    public function identifierShowsTheSecretOfANewlyCreatedKeyOnce(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy');
        $this->get(OneTimeSecretStore::class)->put($createdKey->uid, $createdKey->secret);
        $row = ['uid' => $createdKey->uid, 'title' => 'CI deploy'];

        $result = $this->render('apiKeyIdentifier', 'key_prefix', $row, $createdKey->identifier);

        self::assertStringContainsString('value="' . $createdKey->identifier . '" readonly', $result['html']);
        self::assertStringContainsString($createdKey->secret, $result['html']);
        self::assertNotEmpty($result['javaScriptModules']);

        $result = $this->render('apiKeyIdentifier', 'key_prefix', $row, $createdKey->identifier);

        self::assertStringContainsString($createdKey->identifier, $result['html']);
        self::assertStringNotContainsString($createdKey->secret, $result['html']);
        self::assertEmpty($result['javaScriptModules']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function identityCases(): iterable
    {
        yield 'new record' => [[], 'service'];
        yield 'service key' => [['fe_user' => 0, 'be_user' => 0], 'service'];
        yield 'frontend user as uid' => [['fe_user' => 1, 'be_user' => 0], 'fe_user'];
        yield 'frontend user as group items' => [['fe_user' => [['table' => 'fe_users', 'uid' => 1]], 'be_user' => 0], 'fe_user'];
        yield 'empty group items' => [['fe_user' => [], 'be_user' => [0]], 'service'];
        yield 'backend user as uid list' => [['fe_user' => [], 'be_user' => ['2']], 'be_user'];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('identityCases')]
    public function identitySelectsTheIdentityOfTheRecord(array $row, string $expected): void
    {
        $result = $this->render('apiKeyIdentity', 'identity', $row, '');

        self::assertMatchesRegularExpression('/<select [^>]*name="data\[tx_apikey_key\]\[1\]\[identity\]"[^>]*data-api-key-identity/', $result['html']);
        self::assertSame(1, substr_count($result['html'], ' selected'));
        self::assertStringContainsString('<option value="' . $expected . '" selected>', $result['html']);
        self::assertContains('EXT:api_key/Resources/Public/Css/identity-select.css', $result['stylesheetFiles']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function render(string $renderType, string $fieldName, array $row, string $value): array
    {
        $request = (new ServerRequest('https://localhost/typo3/record/edit'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(NodeFactory::class)->create([
            'renderType' => $renderType,
            'request' => $request,
            'tableName' => 'tx_apikey_key',
            'fieldName' => $fieldName,
            'databaseRow' => $row,
            'parameterArray' => [
                'fieldConf' => ['label' => 'Label', 'config' => []],
                'itemFormElName' => 'data[tx_apikey_key][1][' . $fieldName . ']',
                'itemFormElValue' => $value,
            ],
        ])->render();
    }
}
