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
 * The sheet of an order marks the lines sold as a preorder, with the restock date
 * frozen on the line when the order was placed.
 */
final class OrderPreorderDetailBackOfficeTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!method_exists(OrderProduct::class, 'setIsPreorder')) {
            self::markTestSkipped('The core of this checkout predates the preorder flag of the order lines.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
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

    public function testAPreorderLineShowsItsMentionAndRestockDate(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $order = $factory->order();
        $preorder = $factory->orderProduct($order, ['title' => 'Late kettle']);
        $preorder->setIsPreorder(1)->setRestockDate('2031-05-20')->save($this->getPropelConnection());
        $undated = $factory->orderProduct($order, ['title' => 'Late teapot']);
        $undated->setIsPreorder(1)->save($this->getPropelConnection());
        $factory->orderProduct($order, ['title' => 'Plain mug']);

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-testid="datatable-order-items-row"]');
        self::assertCount(3, $rows);
        $dated = $rows->eq(0)->filter('[data-testid="order-item-preorder"]');
        self::assertCount(1, $dated);
        self::assertStringContainsString('Preorder', $dated->text());
        self::assertStringContainsString('2031', $dated->text(), 'The restock date is shown.');
        $noDate = $rows->eq(1)->filter('[data-testid="order-item-preorder"]');
        self::assertCount(1, $noDate);
        self::assertStringNotContainsString('2031', $noDate->text());
        self::assertCount(0, $rows->eq(2)->filter('[data-testid="order-item-preorder"]'));
    }
}
