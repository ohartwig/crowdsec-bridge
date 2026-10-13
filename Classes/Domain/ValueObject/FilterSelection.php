<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class FilterSelection
{
    public function __construct(
        public ?DecisionScope $scope = null,
        public ?DecisionType $type = null,
        public ?string $origin = null,
    ) {}

    public function isEmpty(): bool
    {
        return null === $this->scope && null === $this->type && (null === $this->origin || '' === $this->origin);
    }

    /**
     * The decision filter's vocabulary spells the origin `origins`, plural, and
     * takes a comma-separated list.
     *
     * `origin` is not a synonym — it is not part of the vocabulary at all, and
     * CrowdSec's filter loop has no default branch, so an unrecognised parameter
     * is neither an error nor a filter: it is dropped in silence and the
     * operator gets an unfiltered list that looks filtered. Pinned by
     * Tests/Contract/LapiDecisionReadContractTest::
     * testTheDecisionsEndpointFiltersOnOriginsNotOnOrigin().
     *
     * @return array<string, string>
     */
    public function toLapiQueryParams(): array
    {
        $params = [];
        if (null !== $this->scope) {
            $params['scope'] = $this->scope->toLapi();
        }
        if (null !== $this->type) {
            $params['type'] = $this->type->value;
        }
        if (null !== $this->origin && '' !== $this->origin) {
            $params['origins'] = $this->origin;
        }

        return $params;
    }
}
