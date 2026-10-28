<?php

declare(strict_types=1);

use MaikSchneider\ApiKey\DataHandling\ApiKeyDataHandlerHook;
use MaikSchneider\ApiKey\Form\Element\ApiKeyIdentifierElement;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['api_key'] = ApiKeyDataHandlerHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1792512400] = [
    'nodeName' => 'apiKeyIdentifier',
    'priority' => 40,
    'class' => ApiKeyIdentifierElement::class,
];
