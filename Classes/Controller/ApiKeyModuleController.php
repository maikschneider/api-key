<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Controller;

use MaikSchneider\ApiKey\Authentication\ApiKey;
use MaikSchneider\ApiKey\Key\OneTimeSecretStore;
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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Lists API keys. Creating and editing happens in FormEngine; a secret that
 * was not shown there yet (e.g. after "Save and close") is shown here once.
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
        private OneTimeSecretStore $secretStore,
        private Context $context,
        private FlashMessageService $flashMessageService,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('mlang_tabs_tab'));
        $view->makeDocHeaderModuleMenu();
        $this->addNewButton($view);
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/modal.js');

        $rows = $this->repository->findAll();
        $now = $this->now();
        $view->assign('keys', array_map(fn (array $row): array => [
            ...$row,
            'scopeList' => ApiKey::splitList((string)$row['scopes']),
            'status' => $this->status($row, $now),
            'editUrl' => $this->editUrl([(int)$row['uid'] => 'edit']),
        ], $rows));
        $view->assign('createdKeys', $this->takeCreatedKeys($rows));
        $view->assign('revokeUrl', (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER . '.revoke'));

        $response = $view->renderResponse('ApiKeyModule/Index');

        // A secret may be part of this response, it must not end up in any cache.
        return $response->withHeader('Cache-Control', 'no-store');
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
     * @param list<array<string, mixed>> $rows
     * @return list<array{secret: string, identifier: string, title: string}>
     */
    private function takeCreatedKeys(array $rows): array
    {
        $secrets = $this->secretStore->takeAll(array_map(static fn (array $row): int => (int)$row['uid'], $rows));
        if ($secrets === []) {
            return [];
        }
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/copy-to-clipboard.js');

        $createdKeys = [];
        foreach ($rows as $row) {
            if (isset($secrets[(int)$row['uid']])) {
                $createdKeys[] = [
                    'secret' => $secrets[(int)$row['uid']],
                    'identifier' => (string)$row['key_prefix'],
                    'title' => (string)$row['title'],
                ];
            }
        }

        return $createdKeys;
    }

    private function addNewButton(ModuleTemplate $view): void
    {
        $button = $this->componentFactory->createLinkButton()
            ->setHref($this->editUrl([0 => 'new']))
            ->setTitle($this->translate('module.new.title'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL));

        $view->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_LEFT);
    }

    /**
     * @param array<int, string> $records
     */
    private function editUrl(array $records): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [ApiKeyRepository::TABLE => $records],
            'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER),
        ]);
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

    private function now(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }

    private function translate(string $key): string
    {
        return $this->getLanguageService()->sL(self::LLL . ':' . $key);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
