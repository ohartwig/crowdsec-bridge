<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Dto;

use Koh\CrowdsecBridge\Domain\ValueObject\Alert;

final readonly class AlertView
{
    public const TRUNCATE_AT = 60;

    public function __construct(
        public int $id,
        public string $scenario,
        public string $scenarioTruncated,
        public string $sourceScope,
        public string $sourceValue,
        public string $sourceValueTruncated,
        public \DateTimeImmutable $createdAt,
        public int $decisionCount,
    ) {}

    public static function fromAlert(Alert $alert): self
    {
        $sourceScope = $alert->source->scope->value ?? $alert->source->scopeRaw;
        $sourceValue = $alert->source->value;

        return new self(
            id: $alert->id->toInt(),
            scenario: $alert->scenario,
            scenarioTruncated: self::truncate($alert->scenario),
            sourceScope: '' !== $sourceScope ? $sourceScope : '—',
            sourceValue: $sourceValue,
            sourceValueTruncated: self::truncate($sourceValue),
            createdAt: $alert->createdAt,
            decisionCount: $alert->decisionCount,
        );
    }

    private static function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::TRUNCATE_AT) {
            return $value;
        }

        return mb_substr($value, 0, self::TRUNCATE_AT - 1) . '…';
    }
}
