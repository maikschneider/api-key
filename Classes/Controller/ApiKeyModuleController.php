<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Controller;

use MaikSchneider\ApiKey\Authentication\ApiKey;
use MaikSchneider\ApiKey\Exception\InvalidKeyDefinitionException;
use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\ApiKey\Repository\ApiKeyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Lists API keys and creates new ones. FormEngine cannot show a secret once,
 * so keys are created here through ApiKeyCreator and the secret is part of
 * the create response only.
 */
#[AsController]
#[Autoconfigure(public: true)]
final readonly class ApiKeyModuleController
{
    private const LLL = 'LLL:EXT:api_key/Resources/Private/Language/locallang_mod.xlf';
    private const MODULE_IDENTIFIER = 'api_key';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentFactory $componentFactory,
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
        private PageRenderer $pageRenderer,
        private ApiKeyRepository $repository,
        private ApiKeyCreator $apiKeyCreator,
        private ConnectionPool $connectionPool,
        private SiteFinder $siteFinder,
        private Context $context,
        private FlashMessageService $flashMessageService,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createView($request);
        $this->addNewButton($view);
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/modal.js');

        $now = $this->now();
        $view->assign('keys', array_map(fn (array $row): array => [
            ...$row,
            'scopeList' => ApiKey::splitList((string)$row['scopes']),
            'status' => $this->status($row, $now),
            'editUrl' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [ApiKeyRepository::TABLE => [(int)$row['uid'] => 'edit']],
                'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER),
            ]),
        ], $this->repository->findAll()));
        $view->assign('revokeUrl', (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER . '.revoke'));

        return $view->renderResponse('ApiKeyModule/Index');
    }

    public function newAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderForm($this->createView($request), []);
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createView($request);
        $body = $request->getParsedBody();
        $values = is_array($body) && is_array($body['key'] ?? null) ? $body['key'] : [];
        $identity = (string)($values['identity'] ?? 'service');

        try {
            $createdKey = $this->apiKeyCreator->create(
                title: (string)($values['title'] ?? ''),
                feUser: $identity === 'fe_user' ? $this->uid($values['fe_user'] ?? '', 'frontend user') : 0,
                beUser: $identity === 'be_user' ? $this->uid($values['be_user'] ?? '', 'backend user') : 0,
                scopes: $this->lines($values['scopes'] ?? ''),
                expiresAt: $this->expiresAt((string)($values['expires_at'] ?? '')),
                allowedIps: $this->lines($values['allowed_ips'] ?? ''),
                sites: $this->selectedSites($values),
                createdBy: (int)$this->getBackendUser()->getUserId(),
            );
        } catch (InvalidKeyDefinitionException $exception) {
            $view->addFlashMessage(
                $exception->getMessage(),
                $this->translate('module.create.error'),
                ContextualFeedbackSeverity::ERROR,
                false
            );

            return $this->renderForm($view, $values);
        }

        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/copy-to-clipboard.js');
        $view->setTitle($this->translate('mlang_tabs_tab'), $this->translate('module.created.title'));
        $this->addBackButton($view);
        $view->assign('createdKey', $createdKey);
        $view->assign('title', trim((string)($values['title'] ?? '')));

        // The secret is part of this response only, it must not end up in any cache.
        return $view->renderResponse('ApiKeyModule/Created')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function revokeAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uid = (int)(is_array($body) ? ($body['uid'] ?? 0) : 0);
        $row = $uid > 0 ? $this->repository->findByUid($uid) : null;
        if ($row !== null && $this->repository->revoke($uid)) {
            $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(new FlashMessage(
                sprintf($this->translate('module.revoke.success'), $row['title'], $row['key_prefix']),
                '',
                ContextualFeedbackSeverity::OK,
                true
            ));
        }

        return new RedirectResponse($this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER));
    }

    /**
     * @param array<mixed> $values
     */
    private function renderForm(ModuleTemplate $view, array $values): ResponseInterface
    {
        $view->setTitle($this->translate('mlang_tabs_tab'), $this->translate('module.new.title'));
        $this->addBackButton($view);
        $view->assign('values', ['identity' => 'service', ...$values]);
        $view->assign('backendUsers', $this->backendUsers());
        $selectedSites = $this->selectedSites($values);
        $view->assign('sites', array_map(
            static fn (string $identifier): array => ['identifier' => $identifier, 'checked' => in_array($identifier, $selectedSites, true)],
            array_keys($this->siteFinder->getAllSites())
        ));
        $view->assign('createUrl', (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER . '.create'));

        return $view->renderResponse('ApiKeyModule/New');
    }

    private function createView(ServerRequestInterface $request): ModuleTemplate
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('mlang_tabs_tab'));
        $view->makeDocHeaderModuleMenu();

        return $view;
    }

    private function addNewButton(ModuleTemplate $view): void
    {
        $button = $this->componentFactory->createLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER . '.new'))
            ->setTitle($this->translate('module.new.title'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL));

        $view->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_LEFT);
    }

    private function addBackButton(ModuleTemplate $view): void
    {
        $button = $this->componentFactory->createLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER))
            ->setTitle($this->translate('module.back'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));

        $view->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_LEFT);
    }

    /**
     * @return array<int, string> usernames keyed by uid
     */
    private function backendUsers(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $rows = $queryBuilder
            ->select('uid', 'username', 'realName')
            ->from('be_users')
            ->orderBy('username')
            ->executeQuery()
            ->fetchAllAssociative();

        $users = [];
        foreach ($rows as $row) {
            $realName = trim((string)$row['realName']);
            $users[(int)$row['uid']] = $realName !== '' ? sprintf('%s (%s)', $row['username'], $realName) : (string)$row['username'];
        }

        return $users;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function status(array $row, int $now): string
    {
        if ((int)$row['hidden'] === 1) {
            return 'revoked';
        }
        if ((int)$row['expires_at'] > 0 && (int)$row['expires_at'] <= $now) {
            return 'expired';
        }

        return 'active';
    }

    private function uid(mixed $value, string $userType): int
    {
        $value = trim(is_scalar($value) ? (string)$value : '');
        if (!ctype_digit($value) || (int)$value === 0) {
            throw new InvalidKeyDefinitionException(sprintf('Select the %s to bind the key to.', $userType), 1792512300);
        }

        return (int)$value;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function selectedSites(array $values): array
    {
        return array_values(array_filter((array)($values['sites'] ?? []), is_string(...)));
    }

    /**
     * @return list<string>
     */
    private function lines(mixed $value): array
    {
        return ApiKey::splitList(is_string($value) ? $value : '');
    }

    private function expiresAt(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
            throw new InvalidKeyDefinitionException(sprintf('"%s" is not a valid date.', $value), 1792512301);
        }

        return $date->getTimestamp();
    }

    private function now(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }

    private function translate(string $key): string
    {
        return $this->getLanguageService()->sL(self::LLL . ':' . $key);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
