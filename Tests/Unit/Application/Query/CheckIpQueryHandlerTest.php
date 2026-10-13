<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Query;

use Koh\CrowdsecBridge\Application\Query\CheckIpQuery;
use Koh\CrowdsecBridge\Application\Query\CheckIpQueryHandler;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\ReadAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\Exception\InvalidIpAddressException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\AuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use PHPUnit\Framework\TestCase;

final class CheckIpQueryHandlerTest extends TestCase
{
    public function testBannedIpReturnsResultAndWritesAudit(): void
    {
        $audit = new InMemoryAudit();
        $decision = $this->decision();
        $handler = new CheckIpQueryHandler(
            $this->repositoryReturning($decision),
            $audit,
            $this->clock(),
        );

        $result = $handler->handle(new CheckIpQuery('203.0.113.42', 1, 'admin'));

        self::assertTrue($result->wasBanned);
        self::assertNotNull($result->matchedDecisionId);
        self::assertSame(42, $result->matchedDecisionId->toInt());
        self::assertCount(1, $audit->entries);
        self::assertTrue($audit->entries[0]->wasBanned);
        self::assertSame(42, $audit->entries[0]->matchedDecisionId?->toInt());
    }

    public function testUnbannedIpReturnsResultAndWritesAudit(): void
    {
        $audit = new InMemoryAudit();
        $handler = new CheckIpQueryHandler(
            $this->repositoryReturning(null),
            $audit,
            $this->clock(),
        );

        $result = $handler->handle(new CheckIpQuery('198.51.100.5', 1, 'admin'));

        self::assertFalse($result->wasBanned);
        self::assertNull($result->matchedDecisionId);
        self::assertCount(1, $audit->entries);
        self::assertFalse($audit->entries[0]->wasBanned);
    }

    public function testInvalidIpThrowsBeforeRepoCallAndAudit(): void
    {
        $audit = new InMemoryAudit();
        $repo = $this->repositoryThatFailsIfCalled();
        $handler = new CheckIpQueryHandler($repo, $audit, $this->clock());

        $this->expectException(InvalidIpAddressException::class);
        try {
            $handler->handle(new CheckIpQuery('foobar', 1, 'admin'));
        } finally {
            self::assertCount(0, $audit->entries);
        }
    }

    public function testLapiFailureStillWritesAudit(): void
    {
        $audit = new InMemoryAudit();
        $repo = new class implements DecisionRepositoryInterface {
            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                return [];
            }

            public function findByIp(IpAddress $ip): ?Decision
            {
                throw LapiUnavailableException::timeout('connect');
            }

            public function findById(DecisionId $id): ?Decision
            {
                return null;
            }
        };
        $handler = new CheckIpQueryHandler($repo, $audit, $this->clock());

        try {
            $handler->handle(new CheckIpQuery('203.0.113.42', 1, 'admin'));
            self::fail('Expected LapiUnavailableException');
        } catch (LapiUnavailableException) {
            self::assertCount(1, $audit->entries);
            self::assertFalse($audit->entries[0]->wasBanned);
        }
    }

    private function decision(): Decision
    {
        return new Decision(
            id: new DecisionId(42),
            scope: DecisionScope::Ip,
            type: DecisionType::Ban,
            value: '203.0.113.42',
            originalDuration: Duration::fromHours(24),
            expiresAt: new \DateTimeImmutable('2026-05-21T12:00:00+00:00'),
            origin: 'cscli',
            scenario: 'test',
        );
    }

    private function repositoryReturning(?Decision $decision): DecisionRepositoryInterface
    {
        return new class ($decision) implements DecisionRepositoryInterface {
            public function __construct(private ?Decision $decision) {}

            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                return null !== $this->decision ? [$this->decision] : [];
            }

            public function findByIp(IpAddress $ip): ?Decision
            {
                return $this->decision;
            }

            public function findById(DecisionId $id): ?Decision
            {
                return $this->decision;
            }
        };
    }

    private function repositoryThatFailsIfCalled(): DecisionRepositoryInterface
    {
        return new class implements DecisionRepositoryInterface {
            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                throw new \RuntimeException('Should not be called for invalid IP');
            }

            public function findByIp(IpAddress $ip): ?Decision
            {
                throw new \RuntimeException('Should not be called for invalid IP');
            }

            public function findById(DecisionId $id): ?Decision
            {
                throw new \RuntimeException('Should not be called for invalid IP');
            }
        };
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-05-20T12:00:00+00:00');
            }
        };
    }
}

final class InMemoryAudit implements ReadAuditLoggerInterface
{
    /** @var list<AuditContext> */
    public array $entries = [];

    public function logQuickCheck(AuditContext $context): void
    {
        $this->entries[] = $context;
    }
}
