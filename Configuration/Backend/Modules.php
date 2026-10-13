<?php

declare(strict_types=1);

use Koh\CrowdsecBridge\Presentation\Backend\Controller\DecisionController;

return [
    'system_crowdsec' => [
        'parent' => 'system',
        'position' => ['after' => 'scheduler'],
        'access' => 'user',
        'workspaces' => 'live',
        'iconIdentifier' => 'crowdsec-bridge-module',
        'labels' => 'LLL:EXT:crowdsec_bridge/Resources/Private/Language/locallang_module.xlf',
        'routes' => [
            '_default' => [
                'target' => DecisionController::class . '::listAction',
            ],
            'check' => [
                'target' => DecisionController::class . '::checkIpAction',
            ],
            'new' => [
                'target' => DecisionController::class . '::newAction',
            ],
            'create' => [
                'target' => DecisionController::class . '::createAction',
            ],
            'delete' => [
                'target' => DecisionController::class . '::deleteAction',
            ],
            'alerts' => [
                'target' => DecisionController::class . '::alertListAction',
            ],
            'alertDetail' => [
                'target' => DecisionController::class . '::alertDetailAction',
            ],
        ],
    ],
];
