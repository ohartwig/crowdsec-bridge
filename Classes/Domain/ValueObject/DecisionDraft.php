<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\InvalidDecisionDraftException;

/**
 * Pre-LAPI input value object. Holds what the operator typed in the form.
 * Origin is NOT a field — it is always hardcoded to "typo3" in toLapiPayload() (FR-004).
 */
final readonly class DecisionDraft
{
    public const FIXED_ORIGIN = 'typo3';

    public function __construct(
        public DecisionScope $scope,
        public DecisionType $type,
        public string $rawValue,
        public Duration $duration,
        public Reason $reason,
    ) {
        $trimmed = trim($rawValue);
        if ('' === $trimmed) {
            throw InvalidDecisionDraftException::emptyValue();
        }
        $this->validateValueForScope($trimmed);
    }

    private function validateValueForScope(string $value): void
    {
        match ($this->scope) {
            DecisionScope::Ip => $this->assertValidIp($value),
            DecisionScope::Range => $this->assertValidCidr($value),
            DecisionScope::Country => $this->assertValidCountry($value),
            DecisionScope::As => $this->assertValidAsn($value),
        };
    }

    private function assertValidIp(string $value): void
    {
        if (false === filter_var($value, \FILTER_VALIDATE_IP)) {
            throw InvalidDecisionDraftException::invalidValueForScope($value, $this->scope);
        }
    }

    private function assertValidCidr(string $value): void
    {
        if (!str_contains($value, '/')) {
            throw InvalidDecisionDraftException::invalidValueForScope($value, $this->scope);
        }
        [$ip, $prefix] = explode('/', $value, 2);
        if (false === filter_var($ip, \FILTER_VALIDATE_IP) || !ctype_digit($prefix)) {
            throw InvalidDecisionDraftException::invalidValueForScope($value, $this->scope);
        }
        $isV6 = false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6);
        $prefixInt = (int) $prefix;
        $max = $isV6 ? 128 : 32;
        if ($prefixInt < 0 || $prefixInt > $max) {
            throw InvalidDecisionDraftException::invalidValueForScope($value, $this->scope);
        }
    }

    private function assertValidCountry(string $value): void
    {
        if (1 !== preg_match('/^[A-Z]{2}$/', $value)) {
            throw InvalidDecisionDraftException::invalidValueForScope($value, $this->scope);
        }
    }

    private function assertValidAsn(string $value): void
    {
        if (1 !== preg_match('/^AS\d+$/', $value)) {
            throw InvalidDecisionDraftException::invalidValueForScope($value, $this->scope);
        }
    }

    public function normalisedValue(): string
    {
        return trim($this->rawValue);
    }

    /**
     * Builds the payload for `POST /v1/alerts`.
     *
     * There is no POST route for decisions — CrowdSec registers `/v1/decisions`
     * for GET, HEAD and DELETE and answers anything else with 405 before a
     * credential is even looked at. A decision comes into existence as part of
     * an alert that carries it, which is why this method has always built an
     * alert-shaped wrapper; it was simply posted to the wrong address.
     *
     * Every field below except `source.ip` is marked Required in CrowdSec's
     * Alert model, and go-swagger rejects the whole batch if one is absent —
     * which is why nine of them are here that a "just a decision" reading of the
     * endpoint would consider noise. The set and the field names are the ones
     * `cscli decisions add` sends (cmd/crowdsec-cli/clidecision/decisions.go).
     *
     * `source.ip` is added only for the Ip scope. For a country or an autonomous
     * system there is no address to put there, and repeating the value in a
     * field named `ip` would put "DE" where every consumer expects an address.
     *
     * Origin is hardcoded to "typo3" — user input cannot influence this field.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toLapiPayload(\DateTimeImmutable $createdAt): array
    {
        $value = $this->normalisedValue();
        $scope = $this->scope->toLapi();
        $reasonText = $this->reason->toString();
        $timestamp = $createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $source = [
            'scope' => $scope,
            'value' => $value,
        ];
        if (DecisionScope::Ip === $this->scope) {
            $source['ip'] = $value;
        }

        return [
            [
                'capacity' => 0,
                'decisions' => [
                    [
                        'duration' => $this->duration->toString(),
                        'origin' => self::FIXED_ORIGIN,
                        'scenario' => $reasonText,
                        'scope' => $scope,
                        'type' => $this->type->value,
                        'value' => $value,
                    ],
                ],
                // A manual ban has no triggering events. The field is required
                // all the same, and omitting it fails the whole request.
                'events' => [],
                'events_count' => 1,
                'leakspeed' => '0',
                'message' => 'manual add via TYPO3 backend',
                'scenario' => $reasonText,
                'scenario_hash' => '',
                'scenario_version' => '',
                'simulated' => false,
                'source' => $source,
                'start_at' => $timestamp,
                'stop_at' => $timestamp,
            ],
        ];
    }
}
