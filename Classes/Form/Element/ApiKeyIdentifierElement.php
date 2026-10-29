<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Form\Element;

use MaikSchneider\ApiKey\Key\OneTimeSecretStore;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Shows the identifier of a key and, once after the record was created, the
 * secret itself. The secret comes from OneTimeSecretStore, which forgets it
 * when it is read.
 */
#[Autoconfigure(public: true)]
final class ApiKeyIdentifierElement extends AbstractFormElement
{
    private const LLL = 'LLL:EXT:api_key/Resources/Private/Language/locallang_db.xlf';

    public function __construct(
        private readonly OneTimeSecretStore $secretStore,
        private readonly ViewFactoryInterface $viewFactory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $resultArray = $this->initializeResultArray();
        $fieldId = StringUtility::getUniqueId('formengine-apikey-');
        $uid = $this->data['databaseRow']['uid'] ?? null;

        $html = [];
        $html[] = $this->renderLabel($fieldId);
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
        if (!MathUtility::canBeInterpretedAsInteger($uid)) {
            $html[] = '<p id="' . htmlspecialchars($fieldId) . '" class="text-body-secondary">'
                . htmlspecialchars($this->getLanguageService()->sL(self::LLL . ':secret.generatedOnSave'))
                . '</p>';
        } else {
            $identifier = (string)$this->data['parameterArray']['itemFormElValue'];
            $html[] = '<div class="form-control-wrap" style="max-width: ' . $this->formMaxWidth($this->defaultInputWidth) . 'px">';
            $html[] = '<input id="' . htmlspecialchars($fieldId) . '" type="text" class="form-control font-monospace" value="' . htmlspecialchars($identifier) . '" readonly />';
            $html[] = '</div>';

            $secret = $this->secretStore->take((int)$uid);
            if ($secret !== null) {
                $html[] = '<div class="mt-3">' . $this->renderSecret($secret, $identifier) . '</div>';
                $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create('@typo3/backend/copy-to-clipboard.js');
            }
        }
        $html[] = '</div>';

        $resultArray['html'] = implode(LF, $html);

        return $resultArray;
    }

    private function renderSecret(string $secret, string $identifier): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templatePathAndFilename: 'EXT:api_key/Resources/Private/Partials/CreatedKey.fluid.html',
            request: $this->data['request'] ?? null,
        ));
        $view->assignMultiple([
            'secret' => $secret,
            'identifier' => $identifier,
            'title' => (string)($this->data['databaseRow']['title'] ?? ''),
        ]);

        return $view->render();
    }
}
