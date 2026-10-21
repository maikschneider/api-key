<?php

declare(strict_types=1);

use MaikSchneider\ApiKey\Middleware\ApiKeyAuthentication;

return [
    'frontend' => [
        'maikschneider/api-key/authentication' => [
            'target' => ApiKeyAuthentication::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/authentication',
            ],
        ],
    ],
];
