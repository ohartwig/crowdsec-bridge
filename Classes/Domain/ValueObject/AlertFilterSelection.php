<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class AlertFilterSelection
{
    public function __construct(
        public ?DecisionScope $scope = null,
        public ?string $scenarioContains = null,
    ) {}

    public function isEmpty(): bool
    {
        return null === $this->scope && (null === $this->scenarioContains || '' === $this->scenarioContains);
    }

    /**
     * Only the scope is delegated to LAPI; scenario filtering happens client-side
     * (Clarify Q1: substring, case-insensitive).
     *
     * @return array<string, string>
     */
    public function toLapiQueryParams(): array
    {
        $params = [];
        if (null !== $this->scope) {
            $params['scope'] = $this->scope->toLapi();
        }

        return $params;
    }

    public function matchesScenario(string $scenario): bool
    {
        if (null === $this->scenarioContains || '' === $this->scenarioContains) {
            return true;
        }

        return false !== mb_stripos($scenario, $this->scenarioContains);
    }
}
