<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\DataHandling;

use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\ApiKey\Key\KeyHasher;
use MaikSchneider\ApiKey\Key\OneTimeSecretStore;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ApiKeyDataHandlerHookTest extends FunctionalTestCase
{
    private const NOW = 1_800_000_000;

    protected array $testExtensionsToLoad = [
        'maikschneider/api-key',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/users.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . self::NOW)));
        $GLOBALS['EXEC_TIME'] = self::NOW;
    }

    #[Test]
    public function newRecordGetsAGeneratedKeyWhoseSecretIsShownOnce(): void
    {
        $dataHandler = $this->process(['NEW1' => [
            'pid' => 0,
            'title' => ' CI deploy ',
            'fe_user' => 'fe_users_1',
            'scopes' => "articles:read, articles:write\n\n",
            'key_hash' => 'injected',
            'key_prefix' => 't3key_injected',
            'created_by' => 2,
        ]]);

        self::assertSame([], $dataHandler->errorLog);
        $uid = (int)$dataHandler->substNEWwithIDs['NEW1'];
        $row = $this->get(ApiKeyRepository::class)->findByUid($uid);
        self::assertNotNull($row);
        self::assertSame('CI deploy', $row['title']);
        self::assertSame(1, (int)$row['fe_user']);
        self::assertSame(1, (int)$row['created_by']);
        self::assertSame("articles:read\narticles:write", $row['scopes']);

        $secret = $this->get(OneTimeSecretStore::class)->take($uid);
        self::assertNotNull($secret);
        self::assertSame((new KeyHasher())->hash($secret), $row['key_hash']);
        self::assertStringStartsWith($row['key_prefix'], $secret);
        self::assertNull($this->get(OneTimeSecretStore::class)->take($uid));
    }

    #[Test]
    public function secretIsStoredEncryptedInTheSession(): void
    {
        $dataHandler = $this->process(['NEW1' => ['pid' => 0, 'title' => 'CI deploy']]);
        $uid = (int)$dataHandler->substNEWwithIDs['NEW1'];

        $sessionData = $GLOBALS['BE_USER']->getSessionData('tx_apikey_created');
        self::assertIsArray($sessionData);
        self::assertArrayHasKey($uid, $sessionData);
        self::assertStringNotContainsString('t3key_', base64_decode($sessionData[$uid], true) ?: '');
    }

    #[Test]
    public function invalidNewRecordIsNotSaved(): void
    {
        $dataHandler = $this->process(['NEW1' => ['pid' => 0, 'title' => 'CI deploy', 'allowed_ips' => 'not-an-ip']]);

        self::assertStringContainsString('"not-an-ip" is neither an IP address nor a CIDR range.', implode("\n", $dataHandler->errorLog));
        self::assertArrayNotHasKey('NEW1', $dataHandler->substNEWwithIDs);
        self::assertSame([], $this->get(ApiKeyRepository::class)->findAll());
        self::assertSame([], $this->get(OneTimeSecretStore::class)->takeAll());
    }

    #[Test]
    public function newRecordCannotBeBoundToTwoUsers(): void
    {
        $dataHandler = $this->process(['NEW1' => ['pid' => 0, 'title' => 'CI deploy', 'fe_user' => 1, 'be_user' => 1]]);

        self::assertStringContainsString('not both', implode("\n", $dataHandler->errorLog));
        self::assertSame([], $this->get(ApiKeyRepository::class)->findAll());
    }

    #[Test]
    public function identityClearsTheOtherUserField(): void
    {
        $dataHandler = $this->process(['NEW1' => [
            'pid' => 0,
            'title' => 'CI deploy',
            'identity' => 'be_user',
            'fe_user' => 'fe_users_1',
            'be_user' => 2,
        ]]);

        self::assertSame([], $dataHandler->errorLog);
        $row = $this->get(ApiKeyRepository::class)->findByUid((int)$dataHandler->substNEWwithIDs['NEW1']);
        self::assertSame(0, (int)($row['fe_user'] ?? -1));
        self::assertSame(2, (int)($row['be_user'] ?? -1));
    }

    #[Test]
    public function serviceIdentityUnbindsAKey(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy', feUser: 1);

        $dataHandler = $this->process([$createdKey->uid => ['identity' => 'service', 'fe_user' => 'fe_users_1']]);

        self::assertSame([], $dataHandler->errorLog);
        self::assertSame(0, (int)($this->get(ApiKeyRepository::class)->findByUid($createdKey->uid)['fe_user'] ?? -1));
    }

    #[Test]
    public function userIdentityNeedsAUser(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy', feUser: 1);

        $dataHandler = $this->process([
            'NEW1' => ['pid' => 0, 'title' => 'New', 'identity' => 'fe_user', 'fe_user' => ''],
            $createdKey->uid => ['title' => 'Renamed', 'identity' => 'be_user', 'be_user' => 0],
        ]);

        $errors = implode("\n", $dataHandler->errorLog);
        self::assertStringContainsString('Select the frontend user to bind the key to.', $errors);
        self::assertStringContainsString('Select the backend user to bind the key to.', $errors);
        $rows = $this->get(ApiKeyRepository::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('CI deploy', $rows[0]['title']);
        self::assertSame(1, (int)$rows[0]['fe_user']);
    }

    #[Test]
    public function unknownIdentityIsRejected(): void
    {
        $dataHandler = $this->process(['NEW1' => ['pid' => 0, 'title' => 'CI deploy', 'identity' => 'admin']]);

        self::assertStringContainsString('"admin" is not a valid identity.', implode("\n", $dataHandler->errorLog));
        self::assertSame([], $this->get(ApiKeyRepository::class)->findAll());
    }

    #[Test]
    public function invalidUpdateLeavesTheRecordUnchanged(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy', allowedIps: ['10.0.0.0/8']);

        $dataHandler = $this->process([$createdKey->uid => ['title' => 'Renamed', 'allowed_ips' => '10.0.0.0/8, 300.0.0.1']]);

        self::assertNotSame([], $dataHandler->errorLog);
        $row = $this->get(ApiKeyRepository::class)->findByUid($createdKey->uid);
        self::assertSame('CI deploy', $row['title'] ?? null);
        self::assertSame('10.0.0.0/8', $row['allowed_ips'] ?? null);
    }

    #[Test]
    public function unchangedValuesAreNotValidatedAgain(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy', expiresAt: self::NOW + 60, feUser: 1);
        $this->getConnectionPool()->getConnectionForTable('tx_apikey_key')
            ->update('tx_apikey_key', ['expires_at' => self::NOW - 60], ['uid' => $createdKey->uid]);
        $this->getConnectionPool()->getConnectionForTable('fe_users')
            ->update('fe_users', ['deleted' => 1], ['uid' => 1]);

        $dataHandler = $this->process([$createdKey->uid => ['title' => 'Renamed', 'expires_at' => self::NOW - 60, 'fe_user' => 1]]);

        self::assertSame([], $dataHandler->errorLog);
        self::assertSame('Renamed', $this->get(ApiKeyRepository::class)->findByUid($createdKey->uid)['title'] ?? null);
    }

    #[Test]
    public function keyColumnsCannotBeChangedByAnUpdate(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy');
        $before = $this->get(ApiKeyRepository::class)->findByUid($createdKey->uid);

        $this->process([$createdKey->uid => ['key_hash' => 'injected', 'key_prefix' => 't3key_x', 'created_by' => 2]]);

        $after = $this->get(ApiKeyRepository::class)->findByUid($createdKey->uid);
        self::assertSame($before['key_hash'] ?? null, $after['key_hash'] ?? null);
        self::assertSame($before['key_prefix'] ?? null, $after['key_prefix'] ?? null);
        self::assertSame(0, (int)($after['created_by'] ?? -1));
    }

    #[Test]
    public function copyGetsItsOwnKey(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy');

        $dataHandler = $this->get(DataHandler::class);
        $dataHandler->start([], ['tx_apikey_key' => [$createdKey->uid => ['copy' => 0]]]);
        $dataHandler->process_cmdmap();

        self::assertSame([], $dataHandler->errorLog);
        $copyUid = (int)($dataHandler->copyMappingArray['tx_apikey_key'][$createdKey->uid] ?? 0);
        self::assertGreaterThan(0, $copyUid);
        $copy = $this->get(ApiKeyRepository::class)->findByUid($copyUid);
        $secret = $this->get(OneTimeSecretStore::class)->take($copyUid);
        self::assertNotNull($secret);
        self::assertNotSame($createdKey->secret, $secret);
        self::assertSame((new KeyHasher())->hash($secret), $copy['key_hash'] ?? null);
    }

    #[Test]
    public function newRecordIsSavedWithoutABackendSession(): void
    {
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = $GLOBALS['BE_USER']->user;
        $backendUser->workspace = 0;
        $GLOBALS['BE_USER'] = $backendUser;

        $dataHandler = $this->process(['NEW1' => ['pid' => 0, 'title' => 'CLI import']]);

        self::assertSame([], $dataHandler->errorLog);
        $uid = (int)$dataHandler->substNEWwithIDs['NEW1'];
        self::assertNotSame('', $this->get(ApiKeyRepository::class)->findByUid($uid)['key_hash'] ?? '');
    }

    #[Test]
    public function otherTablesAreLeftAlone(): void
    {
        $dataHandler = $this->get(DataHandler::class);
        $dataHandler->start(['be_users' => [2 => ['realName' => 'Editor', 'key_hash' => 'kept']]], []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);
        self::assertSame('Editor', $this->getConnectionPool()->getConnectionForTable('be_users')->select(['realName'], 'be_users', ['uid' => 2])->fetchOne());
    }

    /**
     * @param array<int|string, array<string, mixed>> $records
     */
    private function process(array $records): DataHandler
    {
        $dataHandler = $this->get(DataHandler::class);
        $dataHandler->start(['tx_apikey_key' => $records], []);
        $dataHandler->process_datamap();

        return $dataHandler;
    }
}
