<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Repository;

use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\LapiClientInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;

final readonly class LapiDecisionRepository implements DecisionRepositoryInterface
{
    public function __construct(
        private LapiClientInterface $lapi,
        private LapiResponseParser $parser,
    ) {}

    /**
     * @return list<Decision>
     */
    public function findActive(FilterSelection $filter, Pagination $pagination): array
    {
        $query = $filter->toLapiQueryParams();
        $query['limit'] = (string) $pagination->pageSize();
        $query['offset'] = (string) $pagination->offset();

        $body = $this->lapi->get('/v1/decisions', $query);

        return $this->parser->parseDecisions([] === $body ? null : $body);
    }

    public function findByIp(IpAddress $ip): ?Decision
    {
        $body = $this->lapi->get('/v1/decisions', ['ip' => $ip->value]);
        $decisions = $this->parser->parseDecisions([] === $body ? null : $body);

        return $decisions[0] ?? null;
    }

    public function findById(DecisionId $id): ?Decision
    {
        $body = $this->lapi->get('/v1/decisions', ['id' => (string) $id->toInt()]);
        $decisions = $this->parser->parseDecisions([] === $body ? null : $body);
        foreach ($decisions as $decision) {
            if ($decision->id->toInt() === $id->toInt()) {
                return $decision;
            }
        }

        return null;
    }
}
