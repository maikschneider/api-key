<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Key;

use MaikSchneider\ApiKey\Exception\InvalidKeyDefinitionException;
use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\ApiKey\Key\KeyFormat;
use MaikSchneider\ApiKey\Key\KeyHasher;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ApiKeyCreatorTest extends FunctionalTestCase
{
    private const NOW = 1_800_000_000;

    protected array $testExtensionsToLoad = [
        'maikschneider/api-key',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/users.csv');
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . self::NOW)));
        GeneralUtility::mkdir_deep(Environment::getConfigPath() . '/sites/main');
        file_put_contents(
            Environment::getConfigPath() . '/sites/main/config.yaml',
            "rootPageId: 1\nbase: /\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n"
        );
    }

    #[Test]
    public function createdSecretMatchesStoredHash(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('CI deploy');

        self::assertTrue(KeyFormat::isKey($createdKey->secret));
        self::assertSame(KeyFormat::identifier($createdKey->secret), $createdKey->identifier);

        $row = $this->get(ApiKeyRepository::class)->findByHash((new KeyHasher())->hash($createdKey->secret));
        self::assertNotNull($row);
        self::assertSame($createdKey->uid, (int)$row['uid']);
        self::assertSame($createdKey->identifier, $row['key_prefix']);
        self::assertSame('CI deploy', $row['title']);
        self::assertStringNotContainsString($createdKey->secret, (string)json_encode($row));
    }

    #[Test]
    public function restrictionsAreStoredOnePerLine(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create(
            title: ' Importer ',
            feUser: 1,
            scopes: ['articles:read', ' articles:write ', '', 'articles:read'],
            expiresAt: self::NOW + 3600,
            allowedIps: ['10.0.0.0/8', '2001:db8::/32', '192.168.1.10'],
            sites: ['main'],
            createdBy: 1,
        );

        $row = $this->get(ApiKeyRepository::class)->findByUid($createdKey->uid);
        self::assertNotNull($row);
        self::assertSame('Importer', $row['title']);
        self::assertSame(1, (int)$row['fe_user']);
        self::assertSame(0, (int)$row['be_user']);
        self::assertSame(1, (int)$row['created_by']);
        self::assertSame("articles:read\narticles:write", $row['scopes']);
        self::assertSame(self::NOW + 3600, (int)$row['expires_at']);
        self::assertSame("10.0.0.0/8\n2001:db8::/32\n192.168.1.10", $row['allowed_ips']);
        self::assertSame('main', $row['sites']);
    }

    #[Test]
    public function backendUserBindingIsStored(): void
    {
        $createdKey = $this->get(ApiKeyCreator::class)->create('Backend', beUser: 1);

        self::assertSame(1, (int)($this->get(ApiKeyRepository::class)->findByUid($createdKey->uid)['be_user'] ?? 0));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'empty title' => [['title' => '  '], 1792512100];
        yield 'fe and be user' => [['feUser' => 1, 'beUser' => 1], 1792512101];
        yield 'negative uid' => [['feUser' => -1], 1792512102];
        yield 'unknown fe user' => [['feUser' => 99], 1792512103];
        yield 'deleted fe user' => [['feUser' => 2], 1792512103];
        yield 'unknown be user' => [['beUser' => 99], 1792512104];
        yield 'deleted be user' => [['beUser' => 2], 1792512104];
        yield 'unknown creator' => [['createdBy' => 99], 1792512105];
        yield 'expiry now' => [['expiresAt' => self::NOW], 1792512106];
        yield 'expiry in the past' => [['expiresAt' => self::NOW - 1], 1792512106];
        yield 'invalid ip' => [['allowedIps' => ['10.0.0']], 1792512107];
        yield 'wildcard ip' => [['allowedIps' => ['10.0.*.*']], 1792512107];
        yield 'ipv4 prefix too long' => [['allowedIps' => ['10.0.0.0/33']], 1792512107];
        yield 'ipv6 prefix too long' => [['allowedIps' => ['2001:db8::/129']], 1792512107];
        yield 'non-numeric prefix' => [['allowedIps' => ['10.0.0.0/x']], 1792512107];
        yield 'unknown site' => [['sites' => ['unknown']], 1792512108];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('invalidDefinitions')]
    public function invalidDefinitionIsRejectedWithoutStoringAKey(array $arguments, int $code): void
    {
        try {
            $this->get(ApiKeyCreator::class)->create(...['title' => 'Key', ...$arguments]);
            self::fail('Expected an InvalidKeyDefinitionException');
        } catch (InvalidKeyDefinitionException $exception) {
            self::assertSame($code, $exception->getCode());
        }

        self::assertSame([], $this->get(ApiKeyRepository::class)->findAll());
    }
}
