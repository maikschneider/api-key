<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Form\Element;

use MaikSchneider\ApiKey\Key\Identity;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Selects whom a key is bound to; identity-select.css shows only the matching
 * user field. The identity is not stored: it follows from fe_user and be_user,
 * and the DataHandler hook clears the user field that does not match.
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

        $html = [];
        $html[] = $this->renderLabel($fieldId);
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
        $html[] = '<div class="form-control-wrap" style="max-width: ' . $this->formMaxWidth($this->defaultInputWidth) . 'px">';
        $html[] = '<select id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($itemName) . '" class="form-select"'
            . ' data-api-key-identity'
            . ' data-formengine-input-name="' . htmlspecialchars($itemName) . '">';
        $html[] = implode(LF, $options);
        $html[] = '</select>';
        $html[] = '</div>';
        $html[] = '</div>';

        $resultArray['html'] = implode(LF, $html);
        $resultArray['stylesheetFiles'][] = 'EXT:api_key/Resources/Public/Css/identity-select.css';

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
