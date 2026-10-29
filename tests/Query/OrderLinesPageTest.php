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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Test\IntegrationTestCase;

/**
 * The lines of an order, paged between lines that stand on their own: a bundle and what it
 * holds go together, and what makes a line a component is its type. The parent column is older
 * than the bundles and some lines carry it without being part of a pack.
 */
final class OrderLinesPageTest extends IntegrationTestCase
{
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = new OrderRepository(new OrderAccess(new AdminSaleUnitScope()));
    }

    public function testALineThatHasAParentWithoutBeingAComponentStandsOnItsOwn(): void
    {
        [$order, $lines] = $this->anOrderWithAPackAndALineWithAHistoricParent();

        self::assertSame(3, $this->orders->countOrderProducts((int) $order->getId()), 'The plain line, the pack and the line with a parent; the components are not counted.');

        $firstPage = $this->orders->findOrderProductsPage((int) $order->getId(), 1, 2);
        $secondPage = $this->orders->findOrderProductsPage((int) $order->getId(), 2, 2);

        self::assertSame(
            [$lines['plain'], $lines['pack'], $lines['first'], $lines['second']],
            array_map(static fn (OrderProduct $line): int => (int) $line->getId(), $firstPage),
            'A page is cut between lines: the pack comes with its components.',
        );
        self::assertSame([$lines['historic']], array_map(static fn (OrderProduct $line): int => (int) $line->getId(), $secondPage));
    }

    /**
     * @return array{0: Order, 1: array{plain: int, pack: int, first: int, second: int, historic: int}}
     */
    private function anOrderWithAPackAndALineWithAHistoricParent(): array
    {
        $factory = $this->createFixtureFactory();
        $order = $factory->order();

        $plain = $factory->orderProduct($order);
        $pack = $factory->orderProduct($order, ['lineType' => OrderProduct::LINE_TYPE_BUNDLE]);
        $first = $factory->orderProduct($order, ['lineType' => OrderProduct::LINE_TYPE_BUNDLE_COMPONENT]);
        $first->setParent($pack->getId())->save();
        $second = $factory->orderProduct($order, ['lineType' => OrderProduct::LINE_TYPE_BUNDLE_COMPONENT]);
        $second->setParent($pack->getId())->save();
        $historic = $factory->orderProduct($order);
        $historic->setParent($plain->getId())->save();

        return [$order, [
            'plain' => (int) $plain->getId(),
            'pack' => (int) $pack->getId(),
            'first' => (int) $first->getId(),
            'second' => (int) $second->getId(),
            'historic' => (int) $historic->getId(),
        ]];
    }
}
