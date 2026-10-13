<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

/**
 * Source object embedded in a CrowdSec alert. Tolerates unknown LAPI scope values:
 * the typed `scope` becomes null, but `scopeRaw` retains the original string for display.
 */
final readonly class AlertSource
{
    public function __construct(
        public string $scopeRaw,
        public string $value,
        public ?DecisionScope $scope = null,
        public ?string $ip = null,
        public ?int $asNumber = null,
        public ?string $asName = null,
        public ?string $country = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {}

    public function hasGeo(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }
}
