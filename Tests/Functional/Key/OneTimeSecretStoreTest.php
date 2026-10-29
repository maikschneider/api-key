<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Key;

use MaikSchneider\ApiKey\Key\OneTimeSecretStore;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class OneTimeSecretStoreTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'maikschneider/api-key',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../DataHandling/Fixtures/users.csv');
    }

    #[Test]
    public function secretsAreOnlyReturnedToTheSessionTheyWereStoredIn(): void
    {
        $store = $this->get(OneTimeSecretStore::class);
        $this->setUpBackendUser(1);
        self::assertTrue($store->put(1, 't3key_first'));
        self::assertTrue($store->put(2, 't3key_second'));

        self::assertSame([2 => 't3key_second'], $store->takeAll([2, 3]));
        self::assertSame([], $store->takeAll([2]));

        $this->setUpBackendUser(1);
        self::assertSame([], $store->takeAll());
    }

    #[Test]
    public function nothingIsStoredWithoutABackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
        $store = $this->get(OneTimeSecretStore::class);

        self::assertFalse($store->put(1, 't3key_secret'));
        self::assertSame([], $store->takeAll());
        self::assertNull($store->take(1));
    }

    #[Test]
    public function nothingIsStoredWithoutABackendSession(): void
    {
        $GLOBALS['BE_USER'] = new BackendUserAuthentication();
        $store = $this->get(OneTimeSecretStore::class);

        self::assertFalse($store->put(1, 't3key_secret'));
        self::assertSame([], $store->takeAll());
    }
}
