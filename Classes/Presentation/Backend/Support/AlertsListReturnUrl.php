<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Presentation\Backend\Support;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * The alerts-list back link of the alert detail view, built from a whitelisted
 * set of structured query parameters. The Referer header and free-form
 * returnTo URLs are deliberately NOT trusted -- they are attacker-controllable
 * and were a javascript:-URL injection vector (Security audit B2).
 *
 * The alerts list is the sub-route system_crowdsec.alerts and is addressed by
 * that name. Until 2026-10-03 the link went to the default route with an
 * `action=alerts` parameter, which TYPO3 ignores: "back" led to the decision
 * list.
 */
final readonly class AlertsListReturnUrl
{
    public function __construct(private UriBuilder $uriBuilder) {}

    /**
     * @param array<string, mixed> $params
     */
    public function build(array $params): string
    {
        $arguments = [];

        $page = $params['returnPage'] ?? null;
        if (\is_string($page) && ctype_digit($page) && (int) $page >= 1) {
            $arguments['page'] = (int) $page;
        }

        $scope = $params['returnScope'] ?? null;
        if (\is_string($scope) && null !== DecisionScope::tryFrom($scope)) {
            $arguments['scope'] = $scope;
        }

        $scenario = $params['returnScenario'] ?? null;
        if (\is_string($scenario) && '' !== $scenario && mb_strlen($scenario) <= 200) {
            $arguments['scenario'] = $scenario;
        }

        return (string) $this->uriBuilder->buildUriFromRoute('system_crowdsec.alerts', $arguments);
    }
}
