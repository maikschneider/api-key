<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Command;

use MaikSchneider\ApiKey\Key\KeyFormat;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'apikey:revoke', description: 'Revoke an API key by uid or identifier')]
final class RevokeCommand extends Command
{
    public function __construct(
        private readonly ApiKeyRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'The uid or the identifier (e.g. t3key_AbCd1234) of the key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = trim((string)$input->getArgument('key'));

        if (KeyFormat::isKey($argument)) {
            $io->error('Pass the uid or the identifier of the key, not the secret itself.');
            return Command::INVALID;
        }

        if (ctype_digit($argument)) {
            $row = $this->repository->findByUid((int)$argument);
        } else {
            $rows = $this->repository->findByIdentifier($argument);
            if (count($rows) > 1) {
                $io->error(sprintf('The identifier %s matches %d keys. Revoke by uid instead.', $argument, count($rows)));
                return Command::FAILURE;
            }
            $row = $rows[0] ?? null;
        }

        if ($row === null) {
            $io->error(sprintf('No API key found for "%s".', $argument));
            return Command::FAILURE;
        }

        if (!$this->repository->revoke((int)$row['uid'])) {
            $io->note(sprintf('API key %s (uid %d) is already revoked.', $row['key_prefix'], $row['uid']));
            return Command::SUCCESS;
        }

        $io->success(sprintf('Revoked API key %s (uid %d).', $row['key_prefix'], $row['uid']));

        return Command::SUCCESS;
    }
}
