<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'tx-apikey-key' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:api_key/Resources/Public/Icons/Extension.svg',
    ],
];
