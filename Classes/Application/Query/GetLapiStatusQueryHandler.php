<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Query;

use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Contract\LapiStatusRepositoryInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\LapiStatus;

final readonly class GetLapiStatusQueryHandler
{
    public function __construct(
        private LapiStatusRepositoryInterface $statusRepository,
        private ClockInterface $clock,
    ) {}

    public function handle(GetLapiStatusQuery $query): LapiStatus
    {
        return $this->statusRepository->findStatus($this->clock->now());
    }
}
