<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Cache;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use Koh\CrowdsecBridge\Infrastructure\Cache\CacheKeyBuilder;
use PHPUnit\Framework\TestCase;

final class CacheKeyBuilderTest extends TestCase
{
    private CacheKeyBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new CacheKeyBuilder();
    }

    public function testVersionKeyIsConstant(): void
    {
        self::assertSame('crowdsec_bridge:decisions:version', $this->builder->versionKey());
        self::assertSame('crowdsec_bridge:decisions:version', $this->builder->versionKey());
    }

    public function testKeyContainsVersionPrefix(): void
    {
        $key = $this->builder->build(17, new FilterSelection(), new Pagination(1));
        self::assertStringStartsWith('crowdsec_bridge:decisions:v17:', $key);
        self::assertStringEndsWith(':p1', $key);
    }

    public function testIdenticalFilterAndPaginationProduceIdenticalKey(): void
    {
        $filter = new FilterSelection(scope: DecisionScope::Ip, type: DecisionType::Ban, origin: 'typo3');
        $pagination = new Pagination(2);

        $a = $this->builder->build(5, $filter, $pagination);
        $b = $this->builder->build(5, $filter, $pagination);

        self::assertSame($a, $b);
    }

    public function testDifferentVersionsProduceDifferentKeys(): void
    {
        $filter = new FilterSelection(scope: DecisionScope::Ip);
        $pagination = new Pagination(1);

        $a = $this->builder->build(5, $filter, $pagination);
        $b = $this->builder->build(6, $filter, $pagination);

        self::assertNotSame($a, $b);
    }

    public function testDifferentFiltersProduceDifferentKeys(): void
    {
        $a = $this->builder->build(1, new FilterSelection(scope: DecisionScope::Ip), new Pagination(1));
        $b = $this->builder->build(1, new FilterSelection(scope: DecisionScope::Range), new Pagination(1));

        self::assertNotSame($a, $b);
    }

    public function testDifferentPagesProduceDifferentKeys(): void
    {
        $filter = new FilterSelection();
        $a = $this->builder->build(1, $filter, new Pagination(1));
        $b = $this->builder->build(1, $filter, new Pagination(2));

        self::assertNotSame($a, $b);
    }

    public function testEmptyFilterAndPopulatedFilterDiffer(): void
    {
        $a = $this->builder->build(1, new FilterSelection(), new Pagination(1));
        $b = $this->builder->build(1, new FilterSelection(origin: 'typo3'), new Pagination(1));

        self::assertNotSame($a, $b);
    }
}
