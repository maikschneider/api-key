<?php

declare(strict_types=1);

use MaikSchneider\ApiKey\Controller\ApiKeyModuleController;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;

// The "Integrations" main module and the ComponentFactory the controller depends on exist since TYPO3 v14.
if (!class_exists(ComponentFactory::class)) {
    return [];
}

return [
    'api_key' => [
        'parent' => 'integrations',
        'position' => ['after' => '*'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/integrations/api-key',
        'iconIdentifier' => 'module-api-key',
        'labels' => 'LLL:EXT:api_key/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ApiKeyModuleController::class . '::indexAction',
            ],
            'new' => [
                'path' => '/new',
                'target' => ApiKeyModuleController::class . '::newAction',
            ],
            'create' => [
                'path' => '/create',
                'target' => ApiKeyModuleController::class . '::createAction',
                'methods' => ['POST'],
            ],
            'revoke' => [
                'path' => '/revoke',
                'target' => ApiKeyModuleController::class . '::revokeAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
