<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Tests\Service\Order;

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Order\OrderListRowPresenter;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * An order holding a preorder line is flagged in the list of orders, and the
 * flag of a whole page is read in a single query.
 */
final class OrderPreorderListTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (!method_exists(OrderProduct::class, 'setIsPreorder')) {
            self::markTestSkipped('The core of this checkout predates the preorder flag of the order lines.');
        }

        $this->factory = $this->createFixtureFactory();
    }

    public function testOnlyTheOrdersHoldingAPreorderLineAreReturned(): void
    {
        $preorder = $this->orderWithLines(false, true);
        $plain = $this->orderWithLines(false, false);
        $empty = $this->orderWithLines();
        $repository = static::getContainer()->get(OrderRepository::class);

        $found = $repository->findPreorderOrderIds([(int) $preorder->getId(), (int) $plain->getId(), (int) $empty->getId()]);

        self::assertSame([(int) $preorder->getId()], $found);
        self::assertSame([], $repository->findPreorderOrderIds([]));
    }

    public function testTheFlagOfAnyNumberOfOrdersCostsOneQuery(): void
    {
        $repository = static::getContainer()->get(OrderRepository::class);
        $few = [(int) $this->orderWithLines(true)->getId()];
        $many = [];
        for ($i = 0; $i < 6; ++$i) {
            $many[] = (int) $this->orderWithLines(true, false)->getId();
        }

        self::assertSame(1, QueryCounter::count(static fn () => $repository->findPreorderOrderIds($few)));
        self::assertSame(1, QueryCounter::count(static fn () => $repository->findPreorderOrderIds($many)));
    }

    public function testThePageOfOrdersShowsABadgeOnThePreorderOnly(): void
    {
        $preorder = $this->orderWithLines(true);
        $plain = $this->orderWithLines(false);
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        $rows = $presenter->presentAll([$preorder, $plain], 'en_US');

        self::assertStringContainsString('data-testid="bo-order-preorder-badge"', $rows[0]['items_html']);
        self::assertStringContainsString('Preorder', $rows[0]['items_html']);
        self::assertStringNotContainsString('bo-order-preorder-badge', $rows[1]['items_html']);
    }

    public function testThePageReadsThePreorderFlagInOneQueryWhateverItsSize(): void
    {
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);
        $page = [];
        for ($i = 0; $i < 5; ++$i) {
            $page[] = $this->orderWithLines(0 === $i % 2);
        }

        $statements = QueryCounter::statements(static fn () => $presenter->presentAll($page, 'en_US'));

        $flagReads = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'is_preorder'));
        self::assertCount(1, $flagReads, 'The flag of the five orders is read once, not once per row.');
    }

    private function orderWithLines(bool ...$preorderLines): Order
    {
        $order = $this->factory->order();
        foreach ($preorderLines as $isPreorder) {
            $line = $this->factory->orderProduct($order);
            $line->setIsPreorder($isPreorder ? 1 : 0)->save($this->getPropelConnection());
        }

        return $order;
    }
}
