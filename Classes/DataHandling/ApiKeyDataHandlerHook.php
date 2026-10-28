<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\DataHandling;

use MaikSchneider\ApiKey\Exception\InvalidKeyDefinitionException;
use MaikSchneider\ApiKey\Key\Identity;
use MaikSchneider\ApiKey\Key\KeyDefinitionValidator;
use MaikSchneider\ApiKey\Key\KeyFormat;
use MaikSchneider\ApiKey\Key\KeyGenerator;
use MaikSchneider\ApiKey\Key\KeyHasher;
use MaikSchneider\ApiKey\Key\OneTimeSecretStore;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Lets FormEngine create and edit keys: generates the secret of new records,
 * stores only its hash and applies the same rules as ApiKeyCreator. A record
 * that breaks a rule is not saved at all, so a key never ends up with fewer
 * restrictions than the editor entered.
 */
#[Autoconfigure(public: true)]
final class ApiKeyDataHandlerHook
{
    /**
     * Columns only this hook writes.
     */
    private const PROTECTED_FIELDS = ['key_hash', 'key_prefix', 'created_by'];

    private const VALIDATED_FIELDS = ['title', 'fe_user', 'be_user', 'expires_at', 'scopes', 'allowed_ips', 'sites'];

    /**
     * @var array<string, string> secrets of inserted records, keyed by their NEW id
     */
    private array $pendingSecrets = [];

    public function __construct(
        private readonly KeyGenerator $keyGenerator,
        private readonly KeyHasher $keyHasher,
        private readonly KeyDefinitionValidator $validator,
        private readonly ApiKeyRepository $repository,
        private readonly OneTimeSecretStore $secretStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>|null $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(?array &$incomingFieldArray, string $table, int|string $id, DataHandler $dataHandler): void
    {
        if ($table !== ApiKeyRepository::TABLE || !is_array($incomingFieldArray)) {
            return;
        }
        foreach (self::PROTECTED_FIELDS as $field) {
            unset($incomingFieldArray[$field]);
        }
        $isNew = !MathUtility::canBeInterpretedAsInteger($id);
        if (array_key_exists('identity', $incomingFieldArray)) {
            $identity = $incomingFieldArray['identity'];
            unset($incomingFieldArray['identity']);
            try {
                $incomingFieldArray = $this->applyIdentity(
                    is_string($identity) ? $identity : '',
                    $incomingFieldArray,
                    $isNew ? [] : ($this->repository->findByUid((int)$id) ?? [])
                );
            } catch (InvalidKeyDefinitionException $exception) {
                $this->logRejection($dataHandler, $isNew ? SystemLogDatabaseAction::INSERT : SystemLogDatabaseAction::UPDATE, $isNew ? 0 : (int)$id, $exception);
                $incomingFieldArray = null;
                return;
            }
        }
        if (!$isNew) {
            return;
        }

        // A new record can only be skipped here: emptying it later still runs the
        // after-save hooks of other extensions, which expect the inserted values.
        try {
            $this->validate($this->convert($incomingFieldArray, (string)$id, $dataHandler), null);
        } catch (InvalidKeyDefinitionException $exception) {
            $this->logRejection($dataHandler, SystemLogDatabaseAction::INSERT, 0, $exception);
            $incomingFieldArray = null;
        }
    }

    /**
     * Unchanged fields are already removed from $fieldArray on update, so only
     * changed values are checked and editing an expired key still works.
     *
     * @param array<string, mixed>|null $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, int|string $id, ?array &$fieldArray, DataHandler $dataHandler): void
    {
        if ($table !== ApiKeyRepository::TABLE || !is_array($fieldArray)) {
            return;
        }
        $isNew = $status === 'new';

        try {
            $fieldArray = $this->validate($fieldArray, $isNew ? null : $this->repository->findByUid((int)$id));
        } catch (InvalidKeyDefinitionException $exception) {
            if ($isNew) {
                // processDatamap_preProcessFieldArray() validated the same values already
                throw $exception;
            }
            $this->logRejection($dataHandler, SystemLogDatabaseAction::UPDATE, (int)$id, $exception);
            $fieldArray = [];
            return;
        }

        if ($isNew) {
            $secret = $this->keyGenerator->generate();
            $fieldArray['key_hash'] = $this->keyHasher->hash($secret);
            $fieldArray['key_prefix'] = KeyFormat::identifier($secret);
            $fieldArray['created_by'] = (int)$dataHandler->BE_USER->getUserId();
            $this->pendingSecrets[(string)$id] = $secret;
        }
    }

    /**
     * @param array<string, mixed>|null $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, ?array $fieldArray, DataHandler $dataHandler): void
    {
        if ($table !== ApiKeyRepository::TABLE || $status !== 'new' || !isset($this->pendingSecrets[(string)$id])) {
            return;
        }
        $secret = $this->pendingSecrets[(string)$id];
        unset($this->pendingSecrets[(string)$id]);

        $uid = (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($uid > 0 && !$this->secretStore->put($uid, $secret)) {
            $this->logger->warning('API key {uid} was created without a backend session, its secret cannot be shown.', ['uid' => $uid]);
        }
    }

    /**
     * Clears the user field that does not belong to the selected identity, so
     * switching a key from one user type to another needs no second edit.
     *
     * @param array<string, mixed> $incomingFieldArray
     * @param array<string, mixed> $currentRecord empty for a new record
     * @return array<string, mixed>
     * @throws InvalidKeyDefinitionException
     */
    private function applyIdentity(string $identity, array $incomingFieldArray, array $currentRecord): array
    {
        if (Identity::tryFrom($identity) === null) {
            throw new InvalidKeyDefinitionException(sprintf('"%s" is not a valid identity.', $identity), 1792512110);
        }
        foreach (['fe_user' => 'frontend user', 'be_user' => 'backend user'] as $field => $label) {
            if ($field !== $identity) {
                $incomingFieldArray[$field] = 0;
            } elseif ($this->toUid($incomingFieldArray[$field] ?? $currentRecord[$field] ?? 0) === 0) {
                throw new InvalidKeyDefinitionException(sprintf('Select the %s to bind the key to.', $label), 1792512111);
            }
        }

        return $incomingFieldArray;
    }

    private function logRejection(DataHandler $dataHandler, int $action, int $uid, InvalidKeyDefinitionException $exception): void
    {
        $dataHandler->log(
            ApiKeyRepository::TABLE,
            $uid,
            $action,
            null,
            SystemLogErrorClassification::USER_ERROR,
            'API key not saved: {reason}',
            null,
            ['reason' => $exception->getMessage()]
        );
    }

    /**
     * Converts submitted values the way DataHandler stores them, e.g. a date to a timestamp.
     *
     * @param array<string, mixed> $incomingFieldArray
     * @return array<string, mixed>
     */
    private function convert(array $incomingFieldArray, string $id, DataHandler $dataHandler): array
    {
        $converted = [];
        foreach (array_intersect_key($incomingFieldArray, array_flip(self::VALIDATED_FIELDS)) as $field => $value) {
            $result = $dataHandler->checkValue(ApiKeyRepository::TABLE, (string)$field, $value, $id, 'new', 0, 0, $incomingFieldArray);
            if (array_key_exists('value', $result)) {
                $converted[$field] = $result['value'];
            }
        }

        return $converted;
    }

    /**
     * @param array<string, mixed> $fieldArray
     * @param array<string, mixed>|null $currentRecord null for a new record
     * @return array<string, mixed>
     * @throws InvalidKeyDefinitionException
     */
    private function validate(array $fieldArray, ?array $currentRecord): array
    {
        $isNew = $currentRecord === null;

        if ($isNew || array_key_exists('title', $fieldArray)) {
            $fieldArray['title'] = trim((string)($fieldArray['title'] ?? ''));
            $this->validator->validateTitle($fieldArray['title']);
        }
        if ($isNew || array_key_exists('fe_user', $fieldArray) || array_key_exists('be_user', $fieldArray)) {
            $this->validator->validateIdentity(
                $this->toUid($fieldArray['fe_user'] ?? $currentRecord['fe_user'] ?? 0),
                $this->toUid($fieldArray['be_user'] ?? $currentRecord['be_user'] ?? 0)
            );
        }
        if (array_key_exists('expires_at', $fieldArray)) {
            $this->validator->validateExpiresAt((int)$fieldArray['expires_at']);
        }
        foreach (['scopes' => 'Scopes', 'allowed_ips' => 'Allowed IPs', 'sites' => 'Sites'] as $field => $label) {
            if (!array_key_exists($field, $fieldArray)) {
                continue;
            }
            $value = trim((string)$fieldArray[$field]);
            $values = $this->validator->normalizeList($value === '' ? [] : [$value], $label);
            match ($field) {
                'allowed_ips' => $this->validator->validateAllowedIps($values),
                'sites' => $this->validator->validateSites($values),
                default => null,
            };
            $fieldArray[$field] = implode("\n", $values);
        }

        return $fieldArray;
    }

    /**
     * Group fields submit a single relation as "table_uid".
     */
    private function toUid(mixed $value): int
    {
        $value = is_scalar($value) ? (string)$value : '';

        return preg_match('/(\d+)$/', $value, $matches) === 1 ? (int)$matches[1] : 0;
    }
}
