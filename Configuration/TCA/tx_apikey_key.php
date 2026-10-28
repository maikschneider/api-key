<?php

declare(strict_types=1);

$ll = 'LLL:EXT:api_key/Resources/Private/Language/locallang_db.xlf:tx_apikey_key';

return [
    'ctrl' => [
        'title' => $ll,
        'label' => 'title',
        'label_alt' => 'key_prefix',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'adminOnly' => true,
        'rootLevel' => 1,
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'default_sortby' => 'crdate DESC',
        'typeicon_classes' => [
            'default' => 'tx-apikey-key',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => $ll . '.title',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'required' => true,
            ],
        ],
        'key_prefix' => [
            'label' => $ll . '.key_prefix',
            'description' => $ll . '.key_prefix.description',
            'config' => [
                'type' => 'user',
                'renderType' => 'apiKeyIdentifier',
            ],
        ],
        'key_hash' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'fe_user' => [
            'label' => $ll . '.fe_user',
            'config' => [
                'type' => 'group',
                'allowed' => 'fe_users',
                'size' => 1,
                'maxitems' => 1,
                'default' => 0,
            ],
        ],
        'be_user' => [
            'label' => $ll . '.be_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'default' => 0,
            ],
        ],
        'created_by' => [
            'label' => $ll . '.created_by',
            'displayCond' => 'REC:NEW:false',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'scopes' => [
            'label' => $ll . '.scopes',
            'description' => $ll . '.scopes.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        'expires_at' => [
            'label' => $ll . '.expires_at',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
            ],
        ],
        'last_used_at' => [
            'label' => $ll . '.last_used_at',
            'displayCond' => 'REC:NEW:false',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'allowed_ips' => [
            'label' => $ll . '.allowed_ips',
            'description' => $ll . '.allowed_ips.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        'sites' => [
            'label' => $ll . '.sites',
            'description' => $ll . '.sites.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    title, key_prefix, --palette--;;identity,
                --div--;' . $ll . '.tabs.restrictions,
                    scopes, expires_at, allowed_ips, sites,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden, last_used_at,
            ',
        ],
    ],
    'palettes' => [
        'identity' => [
            'label' => $ll . '.palettes.identity',
            'showitem' => 'fe_user, be_user, --linebreak--, created_by',
        ],
    ],
];
