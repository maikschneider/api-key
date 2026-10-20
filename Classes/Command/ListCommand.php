<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Command;

use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Context\Context;

#[AsCommand(name: 'apikey:list', description: 'List API keys without their secrets')]
final class ListCommand extends Command
{
    public function __construct(
        private readonly ApiKeyRepository $repository,
        private readonly Context $context,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = $this->repository->findAll();
        if ($rows === []) {
            $io->writeln('No API keys found.');
            return Command::SUCCESS;
        }

        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $io->table(
            ['UID', 'Identifier', 'Title', 'Identity', 'Scopes', 'Expires', 'Last used', 'Status'],
            array_map(fn (array $row): array => [
                (int)$row['uid'],
                (string)$row['key_prefix'],
                (string)$row['title'],
                $this->formatIdentity($row),
                str_replace("\n", ', ', trim((string)($row['scopes'] ?? ''))) ?: 'all',
                $this->formatDate((int)$row['expires_at'], 'never'),
                $this->formatDate((int)$row['last_used_at'], '-'),
                $this->formatStatus($row, $now),
            ], $rows)
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatIdentity(array $row): string
    {
        if ((int)$row['fe_user'] > 0) {
            return 'fe_users:' . (int)$row['fe_user'];
        }
        if ((int)$row['be_user'] > 0) {
            return 'be_users:' . (int)$row['be_user'];
        }

        return 'service';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatStatus(array $row, int $now): string
    {
        if ((int)$row['hidden'] === 1) {
            return 'revoked';
        }
        if ((int)$row['expires_at'] > 0 && (int)$row['expires_at'] <= $now) {
            return 'expired';
        }

        return 'active';
    }

    private function formatDate(int $timestamp, string $empty): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : $empty;
    }
}
