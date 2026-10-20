<?php

declare(strict_types=1);

return [
    'frontend' => [
        'maikschneider/api-key-test/echo' => [
            'target' => \MaikSchneider\ApiKeyTest\EchoMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
                'maikschneider/api-key/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
