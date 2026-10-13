<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Repository;

use Koh\CrowdsecBridge\Domain\Contract\LapiClientInterface;
use Koh\CrowdsecBridge\Domain\Contract\LapiStatusRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\LapiStatus;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;

/**
 * Infrastructure adapter for the LAPI heartbeat endpoint. Converts transport-level
 * failures into LapiStatus::unreachable() so the application layer never has to
 * handle infrastructure exceptions for the status check.
 */
final readonly class LapiStatusRepository implements LapiStatusRepositoryInterface
{
    public function __construct(
        private LapiClientInterface $lapi,
        private LapiResponseParser $parser,
        private ?string $bouncerName = 'typo3-bridge',
    ) {}

    public function findStatus(\DateTimeImmutable $checkedAt): LapiStatus
    {
        try {
            $body = $this->lapi->get('/v1/heartbeat');
        } catch (LapiAuthenticationException $caught) {
            // The heartbeat is a watcher route, so "no machine account" is by
            // far the likeliest reason to land here — and it is the one the
            // operator can act on. Reporting it as a rejected key would send
            // them to rotate a bouncer key that is working fine.
            return LapiStatus::unreachable($caught->reasonCode, $checkedAt);
        } catch (LapiUnavailableException $caught) {
            $code = str_contains($caught->getMessage(), 'timed out') ? 'timeout' : 'invalid_response';

            return LapiStatus::unreachable($code, $checkedAt);
        }

        return $this->parser->parseHeartbeat($body, [], $checkedAt, $this->bouncerName);
    }
}
