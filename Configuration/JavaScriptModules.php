<?php

declare(strict_types=1);

return [
    'dependencies' => ['backend'],
    'imports' => [
        '@koh/crowdsec-bridge/decision-delete-modal.js' => 'EXT:crowdsec_bridge/Resources/Public/JavaScript/decision-delete-modal.js',
        '@koh/crowdsec-bridge/decision-form-counter.js' => 'EXT:crowdsec_bridge/Resources/Public/JavaScript/decision-form-counter.js',
    ],
];
