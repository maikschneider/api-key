<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Command;

use MaikSchneider\ApiKey\Exception\InvalidKeyDefinitionException;
use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Context\Context;

#[AsCommand(name: 'apikey:create', description: 'Create an API key and print its secret once')]
final class CreateCommand extends Command
{
    public function __construct(
        private readonly ApiKeyCreator $apiKeyCreator,
        private readonly Context $context,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Human readable label, e.g. "CI deploy script"')
            ->addOption('fe-user', null, InputOption::VALUE_REQUIRED, 'Bind the key to this fe_users uid')
            ->addOption('be-user', null, InputOption::VALUE_REQUIRED, 'Bind the key to this be_users uid')
            ->addOption('created-by', null, InputOption::VALUE_REQUIRED, 'Record this be_users uid as creator of the key')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict the key to a scope (repeatable)')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Expiry as date or relative time, e.g. "2027-01-01" or "+90 days"')
            ->addOption('allowed-ip', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict the key to an IP address or CIDR range (repeatable)')
            ->addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict the key to a site identifier (repeatable)')
            ->setHelp(
                'The secret is printed once and cannot be recovered; only its hash is stored.' . PHP_EOL
                . 'A key without --fe-user and --be-user is a service key.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $createdKey = $this->apiKeyCreator->create(
                title: (string)$input->getOption('title'),
                feUser: $this->getUidOption($input, 'fe-user'),
                beUser: $this->getUidOption($input, 'be-user'),
                scopes: $this->getListOption($input, 'scope'),
                expiresAt: $this->getExpiresAt($input),
                allowedIps: $this->getListOption($input, 'allowed-ip'),
                sites: $this->getListOption($input, 'site'),
                createdBy: $this->getUidOption($input, 'created-by'),
            );
        } catch (InvalidKeyDefinitionException $exception) {
            $io->error($exception->getMessage());
            return Command::INVALID;
        }

        $io->success(sprintf('Created API key %s (uid %d).', $createdKey->identifier, $createdKey->uid));
        $io->writeln('Store this key now, it will not be shown again:');
        $io->newLine();
        $output->writeln($createdKey->secret);
        $io->newLine();

        return Command::SUCCESS;
    }

    private function getUidOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return 0;
        }
        if (!is_string($value) || !ctype_digit($value) || (int)$value === 0) {
            throw new InvalidKeyDefinitionException(sprintf('--%s must be a positive uid.', $name), 1792512200);
        }

        return (int)$value;
    }

    /**
     * @return list<string>
     */
    private function getListOption(InputInterface $input, string $name): array
    {
        $values = $input->getOption($name);

        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }

    private function getExpiresAt(InputInterface $input): int
    {
        $value = $input->getOption('expires');
        if ($value === null) {
            return 0;
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $timestamp = is_string($value) ? strtotime($value, $now) : false;
        if ($timestamp === false) {
            throw new InvalidKeyDefinitionException(sprintf('--expires "%s" is not a valid date.', (string)$value), 1792512201);
        }
        // 0 means "never expires" to ApiKeyCreator, so an explicit past date must not reach it
        if ($timestamp <= $now) {
            throw new InvalidKeyDefinitionException('The expiry date must be in the future.', 1792512202);
        }

        return $timestamp;
    }
}
