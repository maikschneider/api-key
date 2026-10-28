<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Form\Element;

use MaikSchneider\ApiKey\Key\Identity;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Selects whom a key is bound to and shows only the matching user field. The
 * identity is not stored: it follows from fe_user and be_user, and the
 * DataHandler hook clears the user field that does not match the submitted one.
 */
final class ApiKeyIdentityElement extends AbstractFormElement
{
    private const LLL = 'LLL:EXT:api_key/Resources/Private/Language/locallang_db.xlf';

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $resultArray = $this->initializeResultArray();
        $fieldId = StringUtility::getUniqueId('formengine-apikey-identity-');
        $itemName = (string)$this->data['parameterArray']['itemFormElName'];
        $current = $this->currentIdentity();

        $options = [];
        foreach (Identity::cases() as $identity) {
            $options[] = '<option value="' . $identity->value . '"' . ($identity === $current ? ' selected' : '') . '>'
                . htmlspecialchars($this->getLanguageService()->sL(self::LLL . ':tx_apikey_key.identity.' . $identity->value))
                . '</option>';
        }
        // The user fields share the name pattern of this field, e.g. data[tx_apikey_key][12][fe_user]
        $fields = [];
        foreach ([Identity::FrontendUser, Identity::BackendUser] as $identity) {
            $fields[$identity->value] = (string)preg_replace('/\[identity\]$/', '[' . $identity->value . ']', $itemName);
        }

        $html = [];
        $html[] = $this->renderLabel($fieldId);
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
        $html[] = '<div class="form-control-wrap" style="max-width: ' . $this->formMaxWidth($this->defaultInputWidth) . 'px">';
        $html[] = '<select id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($itemName) . '" class="form-select"'
            . ' data-identity-fields="' . htmlspecialchars((string)json_encode($fields)) . '"'
            . ' data-formengine-input-name="' . htmlspecialchars($itemName) . '">';
        $html[] = implode(LF, $options);
        $html[] = '</select>';
        $html[] = '</div>';
        $html[] = '</div>';

        $resultArray['html'] = implode(LF, $html);
        $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create('@maikschneider/api-key/identity-select.js')->instance($fieldId);

        return $resultArray;
    }

    private function currentIdentity(): Identity
    {
        foreach ([Identity::FrontendUser, Identity::BackendUser] as $identity) {
            if ($this->hasRelation($this->data['databaseRow'][$identity->value] ?? null)) {
                return $identity;
            }
        }

        return Identity::Service;
    }

    /**
     * FormEngine prepares relations as lists of items or uids.
     */
    private function hasRelation(mixed $value): bool
    {
        if (!is_array($value)) {
            return (int)$value > 0;
        }
        foreach ($value as $item) {
            if ((int)(is_array($item) ? ($item['uid'] ?? 0) : $item) > 0) {
                return true;
            }
        }

        return false;
    }
}
