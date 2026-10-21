<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Functional\Command;

use MaikSchneider\ApiKey\Command\CreateCommand;
use MaikSchneider\ApiKey\Command\ListCommand;
use MaikSchneider\ApiKey\Command\RevokeCommand;
use MaikSchneider\ApiKey\Key\KeyHasher;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CommandsTest extends FunctionalTestCase
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
    }

    #[Test]
    public function createPrintsSecretOnceAndStoresItsHash(): void
    {
        $tester = new CommandTester($this->get(CreateCommand::class));
        $tester->execute([
            '--title' => 'CI deploy',
            '--fe-user' => '1',
            '--scope' => ['articles:read', 'articles:write'],
            '--expires' => '+90 days',
            '--allowed-ip' => ['10.0.0.0/8'],
            '--created-by' => '1',
        ]);

        $tester->assertCommandIsSuccessful();
        $secret = $this->extractSecret($tester);
        self::assertSame(1, substr_count($tester->getDisplay(), $secret));

        $row = $this->get(ApiKeyRepository::class)->findByHash((new KeyHasher())->hash($secret));
        self::assertNotNull($row);
        self::assertSame('CI deploy', $row['title']);
        self::assertSame(1, (int)$row['fe_user']);
        self::assertSame("articles:read\narticles:write", $row['scopes']);
        self::assertSame(strtotime('+90 days', self::NOW), (int)$row['expires_at']);
        self::assertSame('10.0.0.0/8', $row['allowed_ips']);
        self::assertSame(1, (int)$row['created_by']);
    }

    #[Test]
    public function createRejectsInvalidInput(): void
    {
        $cases = [
            [['--title' => 'x', '--fe-user' => '1', '--be-user' => '1'], 'not both'],
            [['--title' => 'x', '--fe-user' => 'abc'], '--fe-user must be a positive uid'],
            [['--title' => 'x', '--created-by' => '99'], 'Backend user 99 does not exist'],
            [['--title' => 'x', '--expires' => 'someday'], 'is not a valid date'],
            [['--title' => 'x', '--expires' => '-1 day'], 'must be in the future'],
            [['--title' => 'x', '--scope' => [',,,']], 'Scopes must contain at least one value'],
            [['--title' => 'x', '--expires' => '@0'], 'must be in the future'],
            [['--title' => 'x', '--expires' => '1970-01-01 00:00:00 UTC'], 'must be in the future'],
            [['--title' => 'x', '--expires' => '@' . self::NOW], 'must be in the future'],
            [[], 'needs a title'],
        ];
        foreach ($cases as [$input, $message]) {
            $tester = new CommandTester($this->get(CreateCommand::class));
            self::assertSame(Command::INVALID, $tester->execute($input));
            self::assertStringContainsString($message, $tester->getDisplay());
        }

        self::assertSame([], $this->get(ApiKeyRepository::class)->findAll());
    }

    #[Test]
    public function createWithoutExpiryStoresAKeyThatNeverExpires(): void
    {
        $tester = new CommandTester($this->get(CreateCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['--title' => 'No expiry']));

        $row = $this->get(ApiKeyRepository::class)->findAll()[0];
        self::assertSame(0, (int)$row['expires_at']);
    }

    #[Test]
    public function listShowsKeysWithoutSecrets(): void
    {
        $create = new CommandTester($this->get(CreateCommand::class));
        $create->execute(['--title' => 'Service key']);
        $secret = $this->extractSecret($create);

        $tester = new CommandTester($this->get(ListCommand::class));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Service key', $display);
        self::assertStringContainsString(substr($secret, 0, 14), $display);
        self::assertStringNotContainsString($secret, $display);
        self::assertStringContainsString('service', $display);
        self::assertStringContainsString('active', $display);
    }

    #[Test]
    public function revokeByIdentifierHidesTheKey(): void
    {
        $create = new CommandTester($this->get(CreateCommand::class));
        $create->execute(['--title' => 'To revoke']);
        $secret = $this->extractSecret($create);
        $identifier = substr($secret, 0, 14);

        $tester = new CommandTester($this->get(RevokeCommand::class));
        $tester->execute(['key' => $identifier]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Revoked API key ' . $identifier, $tester->getDisplay());

        $tester->execute(['key' => $identifier]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already revoked', $tester->getDisplay());

        $list = new CommandTester($this->get(ListCommand::class));
        $list->execute([]);
        self::assertStringContainsString('revoked', $list->getDisplay());
    }

    #[Test]
    public function revokeRefusesTheSecretAndUnknownKeys(): void
    {
        $create = new CommandTester($this->get(CreateCommand::class));
        $create->execute(['--title' => 'Key']);
        $secret = $this->extractSecret($create);

        $tester = new CommandTester($this->get(RevokeCommand::class));
        self::assertSame(Command::INVALID, $tester->execute(['key' => $secret]));
        self::assertSame(Command::FAILURE, $tester->execute(['key' => '999']));
        self::assertSame(Command::FAILURE, $tester->execute(['key' => 't3key_unknown1']));
    }

    private function extractSecret(CommandTester $tester): string
    {
        if (preg_match('/^(t3key_[A-Za-z0-9_-]{43})$/m', $tester->getDisplay(), $matches) !== 1) {
            self::fail('The command output contains no key');
        }

        return $matches[1];
    }
}
