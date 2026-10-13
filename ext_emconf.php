<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'CrowdSec Bridge',
    'description' => 'TYPO3 backend module bridging the CrowdSec Local API (LAPI): list, add and delete decisions, run IP quick-checks, view alerts, monitor LAPI heartbeat. Server-side ACL with full sys_log audit for every write attempt; optional decision cache via moselwal/keyvalue-store.',
    'category' => 'be',
    'author' => 'Kai Ole Hartwig',
    'author_email' => 'o.hartwig@ko-web.net',
    'author_company' => '',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'version' => '0.10.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'php' => '8.5.0-8.5.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'keyvalue_store' => '',
        ],
    ],
];
