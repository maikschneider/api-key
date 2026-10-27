<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'API Key',
    'description' => 'Stateless API key authentication for scripts, cron jobs, CI pipelines and server-to-server integrations.',
    'category' => 'services',
    'author' => 'Maik Schneider',
    'author_email' => 'schneider.maik@me.com',
    'state' => 'alpha',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'frontend' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
