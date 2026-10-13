<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

use Koh\CrowdsecBridge\Domain\Exception\InvalidDurationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\Alert;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertLabel;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertSource;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\LapiStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Maps raw decoded LAPI JSON payloads to Domain VOs.
 *
 * Two categories of error:
 *  - **Structural** (entry is not an object, alert misses created_at) — the LAPI response
 *    itself is broken; the whole batch is rejected via LapiUnavailableException.
 *  - **Per-entry value error** (unknown DecisionScope/DecisionType, malformed Duration —
 *    typically a future CrowdSec version with new enum values) — that single entry is
 *    skipped with a warning log and the rest of the batch is returned. This keeps the
 *    backend usable when CrowdSec ships e.g. a new scope before the bridge knows about it.
 *
 * Security audit H2.
 */
final readonly class LapiResponseParser
{
    public function __construct(private LoggerInterface $logger = new NullLogger()) {}

    /**
     * @param array<int|string, mixed>|null $payload
     *
     * @return list<Decision>
     */
    public function parseDecisions(?array $payload): array
    {
        if (null === $payload || [] === $payload) {
            return [];
        }

        $decisions = [];
        foreach ($payload as $entry) {
            if (!\is_array($entry)) {
                throw LapiUnavailableException::invalidResponse('decision entry is not an object');
            }
            $decision = $this->mapDecision($entry);
            if (null !== $decision) {
                $decisions[] = $decision;
            }
        }

        return $decisions;
    }

    /**
     * @param array<int|string, mixed> $entry
     *
     * Returns null if the entry has an unknown scope/type or malformed duration (likely a
     * future CrowdSec version) — the caller skips and the rest of the batch is returned
     */
    private function mapDecision(array $entry): ?Decision
    {
        try {
            $id = new DecisionId((int) ($entry['id'] ?? 0));
            $scope = DecisionScope::fromLapi((string) ($entry['scope'] ?? ''));
            $type = DecisionType::fromLapi((string) ($entry['type'] ?? ''));
            $value = (string) ($entry['value'] ?? '');
            $duration = new Duration((string) ($entry['duration'] ?? ''));
            $origin = (string) ($entry['origin'] ?? '');
            $scenario = (string) ($entry['scenario'] ?? '');
            $until = isset($entry['until']) && \is_string($entry['until']) && '' !== $entry['until']
                ? new \DateTimeImmutable($entry['until'])
                : null;

            return new Decision(
                id: $id,
                scope: $scope,
                type: $type,
                value: $value,
                originalDuration: $duration,
                expiresAt: $until,
                origin: $origin,
                scenario: $scenario,
            );
        } catch (LapiUnavailableException $caught) {
            throw $caught;
        } catch (\ValueError|InvalidDurationException $caught) {
            // Unknown enum value or duration format — likely a future CrowdSec version.
            // Skip this single entry, keep the rest of the batch.
            $this->logger->warning(
                'CrowdSec Bridge skipped decision with unparseable field',
                [
                    'reason' => $caught->getMessage(),
                    'lapi_id' => $entry['id'] ?? null,
                    'lapi_scope' => $entry['scope'] ?? null,
                    'lapi_type' => $entry['type'] ?? null,
                ],
            );

            return null;
        } catch (\Throwable $caught) {
            throw LapiUnavailableException::invalidResponse($caught->getMessage());
        }
    }

    /**
     * @param array<int|string, mixed>|null $body
     * @param array<string, list<string>>   $headers
     */
    public function parseHeartbeat(?array $body, array $headers, \DateTimeImmutable $checkedAt, ?string $bouncerName): LapiStatus
    {
        $headerVersion = $this->extractVersionFromHeaders($headers);
        $bodyVersion = $this->extractVersionFromBody($body);
        $version = $headerVersion ?? $bodyVersion ?? 'unknown';

        return LapiStatus::reachable($version, $bouncerName, $checkedAt);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function extractVersionFromHeaders(array $headers): ?string
    {
        foreach ($headers as $name => $values) {
            if ('x-crowdsec-version' === strtolower($name) && \count($values) > 0) {
                $value = $values[0];

                return '' !== $value ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed>|null $body
     */
    private function extractVersionFromBody(?array $body): ?string
    {
        if (null === $body) {
            return null;
        }
        if (isset($body['version']) && \is_string($body['version']) && '' !== $body['version']) {
            return $body['version'];
        }

        return null;
    }

    /**
     * @param array<int|string, mixed>|null $payload
     *
     * @return list<Alert>
     */
    public function parseAlerts(?array $payload, bool $includeDecisions): array
    {
        if (null === $payload || [] === $payload) {
            return [];
        }

        $alerts = [];
        foreach ($payload as $entry) {
            if (!\is_array($entry)) {
                throw LapiUnavailableException::invalidResponse('alert entry is not an object');
            }
            $alert = $this->mapAlert($entry, $includeDecisions);
            if (null !== $alert) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * @param array<int|string, mixed>|null $payload
     */
    public function parseAlert(?array $payload, bool $includeDecisions): ?Alert
    {
        if (null === $payload || [] === $payload) {
            return null;
        }

        return $this->mapAlert($payload, $includeDecisions);
    }

    /**
     * @param array<int|string, mixed> $entry
     *
     * Returns null on per-entry value errors (unknown enum, malformed duration) — caller
     * skips and keeps the rest of the batch
     */
    private function mapAlert(array $entry, bool $includeDecisions): ?Alert
    {
        try {
            $idValue = isset($entry['id']) && is_numeric($entry['id']) ? (int) $entry['id'] : 0;
            $id = new AlertId($idValue);

            $scenario = isset($entry['scenario']) && \is_string($entry['scenario']) && '' !== $entry['scenario']
                ? $entry['scenario']
                : '';

            $createdAtRaw = (string) ($entry['created_at'] ?? '');
            if ('' === $createdAtRaw) {
                throw LapiUnavailableException::invalidResponse('alert missing created_at');
            }
            $createdAt = new \DateTimeImmutable($createdAtRaw);

            $sourceData = $entry['source'] ?? null;
            if (!\is_array($sourceData)) {
                throw LapiUnavailableException::invalidResponse('alert missing source object');
            }
            $source = $this->mapAlertSource($sourceData);

            $rawDecisions = $entry['decisions'] ?? [];
            $decisionCount = \is_array($rawDecisions) ? \count($rawDecisions) : 0;

            $decisions = [];
            if ($includeDecisions && \is_array($rawDecisions)) {
                foreach ($rawDecisions as $rawDecision) {
                    if (!\is_array($rawDecision)) {
                        continue;
                    }
                    $decision = $this->mapDecision($rawDecision);
                    if (null !== $decision) {
                        $decisions[] = $decision;
                    }
                }
            }

            $labels = [];
            $rawLabels = $entry['labels'] ?? null;
            if (\is_array($rawLabels)) {
                foreach ($rawLabels as $key => $value) {
                    if (\is_array($value) && isset($value['key'], $value['value'])) {
                        $labels[] = new AlertLabel((string) $value['key'], (string) $value['value']);
                    } elseif (\is_string($key) && \is_scalar($value)) {
                        $labels[] = new AlertLabel($key, (string) $value);
                    }
                }
            }

            return new Alert(
                id: $id,
                scenario: $scenario,
                createdAt: $createdAt,
                source: $source,
                decisionCount: $decisionCount,
                decisions: $decisions,
                labels: $labels,
            );
        } catch (LapiUnavailableException $caught) {
            throw $caught;
        } catch (\ValueError|InvalidDurationException $caught) {
            $this->logger->warning(
                'CrowdSec Bridge skipped alert with unparseable field',
                [
                    'reason' => $caught->getMessage(),
                    'lapi_id' => $entry['id'] ?? null,
                ],
            );

            return null;
        } catch (\Throwable $caught) {
            throw LapiUnavailableException::invalidResponse($caught->getMessage());
        }
    }

    /**
     * @param array<int|string, mixed> $entry
     */
    private function mapAlertSource(array $entry): AlertSource
    {
        $scopeRaw = (string) ($entry['scope'] ?? '');
        $value = (string) ($entry['value'] ?? '');
        // tryFromLapi, not tryFrom: an alert whose source is an autonomous
        // system arrives with scope "AS" and would otherwise lose its scope
        // silently while keeping its value.
        $scope = DecisionScope::tryFromLapi($scopeRaw);

        $asNumber = null;
        if (isset($entry['as_number']) && is_numeric($entry['as_number'])) {
            $asNumber = (int) $entry['as_number'];
        }

        $latitude = null;
        if (isset($entry['latitude']) && is_numeric($entry['latitude'])) {
            $latitude = (float) $entry['latitude'];
        }
        $longitude = null;
        if (isset($entry['longitude']) && is_numeric($entry['longitude'])) {
            $longitude = (float) $entry['longitude'];
        }

        return new AlertSource(
            scopeRaw: $scopeRaw,
            value: $value,
            scope: $scope,
            ip: isset($entry['ip']) && \is_string($entry['ip']) && '' !== $entry['ip'] ? $entry['ip'] : null,
            asNumber: $asNumber,
            asName: isset($entry['as_name']) && \is_string($entry['as_name']) && '' !== $entry['as_name'] ? $entry['as_name'] : null,
            country: isset($entry['cn']) && \is_string($entry['cn']) && '' !== $entry['cn'] ? $entry['cn'] : null,
            latitude: $latitude,
            longitude: $longitude,
        );
    }
}
