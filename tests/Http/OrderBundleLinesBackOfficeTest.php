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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderProduct;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The sheet of an order that holds a bundle: the components come right under the line of the
 * bundle, without a price, marked as included in it. The pack line keeps the price that was sold.
 */
final class OrderBundleLinesBackOfficeTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!method_exists(OrderProduct::class, 'isBundleComponentLine')) {
            self::markTestSkipped('The core does not carry the product bundles.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $factory = new FixtureFactory($this->getPropelConnection());
        $admin = $factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheComponentsAreGroupedUnderTheirBundleWithoutPrice(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $order = $factory->order();
        $bundle = $factory->orderProduct($order, ['title' => 'Bike pack', 'quantity' => 2, 'price' => '600', 'promoPrice' => '540', 'lineType' => OrderProduct::LINE_TYPE_BUNDLE]);
        // A simple line written between the pack and its components must not split them.
        $simple = $factory->orderProduct($order, ['title' => 'Simple line', 'price' => '33']);
        $bike = $factory->orderProduct($order, ['title' => 'Bike', 'quantity' => 2, 'price' => '0', 'promoPrice' => '0', 'lineType' => OrderProduct::LINE_TYPE_BUNDLE_COMPONENT]);
        $bike->setParent((int) $bundle->getId())->save();

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-testid="datatable-order-items-row"]');
        $titles = $rows->each(static fn ($row): string => trim(preg_replace('/\s+/', ' ', $row->filter('td')->first()->text())));
        self::assertStringContainsString('Bike pack', $titles[0]);
        self::assertStringContainsString('Bike', $titles[1], 'The component follows its bundle.');
        self::assertStringContainsString('Included in the bundle', $titles[1]);
        self::assertStringContainsString('Simple line', $titles[2]);
        self::assertCount(1, $crawler->filter('[data-testid="order-item-bundle-badge"]'));
        self::assertCount(1, $crawler->filter('[data-testid="order-item-bundle-component"]'));

        $componentCells = $rows->eq(1)->filter('td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertStringNotContainsString('0.00', implode(' ', \array_slice($componentCells, 1)), 'A component shows no price.');
        self::assertStringContainsString('600.00', $rows->eq(0)->text(), 'The bundle line keeps the price it was sold at.');
        self::assertNotNull($simple);
    }

    public function testAPageBreakNeverSeparatesABundleFromItsComponents(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $order = $factory->order();
        // 24 lines, then the bundle: the 25th line of the page. Its component would be the 26th.
        for ($i = 1; $i <= 24; ++$i) {
            $factory->orderProduct($order, ['title' => 'Plain line '.$i, 'price' => '10']);
        }
        $bundle = $factory->orderProduct($order, ['title' => 'Edge pack', 'price' => '600', 'lineType' => OrderProduct::LINE_TYPE_BUNDLE]);
        $component = $factory->orderProduct($order, ['title' => 'Edge component', 'price' => '0', 'promoPrice' => '0', 'lineType' => OrderProduct::LINE_TYPE_BUNDLE_COMPONENT]);
        $component->setParent((int) $bundle->getId())->save();
        $factory->orderProduct($order, ['title' => 'Last line', 'price' => '10']);

        $first = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        $firstTitles = $first->filter('[data-testid="datatable-order-items-row"]')->each(static fn ($row): string => trim(preg_replace('/\s+/', ' ', $row->filter('td')->first()->text())));
        $second = $this->client->request('GET', '/admin/order/update/'.$order->getId().'?items_page=2');
        $secondTitles = $second->filter('[data-testid="datatable-order-items-row"]')->each(static fn ($row): string => trim(preg_replace('/\s+/', ' ', $row->filter('td')->first()->text())));

        self::assertStringContainsString('Edge pack', $firstTitles[24]);
        self::assertStringContainsString('Edge component', $firstTitles[25], 'The component stays on the page of its bundle.');
        self::assertCount(1, $secondTitles);
        self::assertStringContainsString('Last line', $secondTitles[0]);
    }
}
