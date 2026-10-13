<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'crowdsec-bridge-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:crowdsec_bridge/Resources/Public/Icons/module-crowdsec.svg',
    ],
];
