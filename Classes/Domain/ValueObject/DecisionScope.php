<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

enum DecisionScope: string
{
    case Ip = 'Ip';
    case Range = 'Range';
    case Country = 'Country';
    case As = 'As';

    /**
     * How CrowdSec spells the autonomous-system scope.
     *
     * types.NormalizeScope() maps every casing of "as" onto the constant `AS`,
     * so a decision this module creates with `As` is stored and returned as
     * `AS`. Matching on the case alone therefore made the AS scope write-only:
     * created successfully, then dropped on the way back in by
     * LapiResponseParser, which skips an entry it cannot map and logs a warning
     * nobody reads. The decision blocks traffic and is invisible in the module.
     *
     * Pinned by Tests/Contract/LapiDecisionReadContractTest::
     * testTheAutonomousSystemScopeComesBackUppercased().
     */
    private const LAPI_AS = 'AS';

    public static function fromLapi(string $value): self
    {
        return self::tryFromLapi($value) ?? throw new \ValueError(\sprintf('Unknown LAPI scope "%s"', $value));
    }

    public static function tryFromLapi(string $value): ?self
    {
        if (self::LAPI_AS === $value) {
            return self::As;
        }

        return self::tryFrom($value);
    }

    /**
     * The spelling to put on the wire. Sending `As` happens to work because
     * CrowdSec normalises it, but sending what it stores keeps the request and
     * the response talking about the same string.
     */
    public function toLapi(): string
    {
        return self::As === $this ? self::LAPI_AS : $this->value;
    }
}
