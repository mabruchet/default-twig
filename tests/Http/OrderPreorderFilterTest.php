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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The list of the orders filters on the preorders the way the badge of a row tells them: an order is
 * a preorder when at least one of its lines was sold as one.
 */
final class OrderPreorderFilterTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

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
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin();
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

    public function testTheWithFilterKeepsTheOrdersHoldingAPreorderLine(): void
    {
        [$preorder, $mixed, $plain, $empty] = $this->orders();

        $crawler = $this->client->request('GET', '/admin/orders?preorder=with');

        self::assertResponseIsSuccessful();
        $refs = $this->refs($crawler);
        self::assertContains($preorder->getRef(), $refs);
        self::assertContains($mixed->getRef(), $refs, 'One preorder line among others is enough.');
        self::assertNotContains($plain->getRef(), $refs);
        self::assertNotContains($empty->getRef(), $refs);
        self::assertSame(1, \count(array_keys($refs, $mixed->getRef(), true)), 'An order with two preorder lines is listed once.');
    }

    public function testTheWithoutFilterKeepsTheOtherOrders(): void
    {
        [$preorder, $mixed, $plain, $empty] = $this->orders();

        $crawler = $this->client->request('GET', '/admin/orders?preorder=without');

        self::assertResponseIsSuccessful();
        $refs = $this->refs($crawler);
        self::assertContains($plain->getRef(), $refs);
        self::assertContains($empty->getRef(), $refs);
        self::assertNotContains($preorder->getRef(), $refs);
        self::assertNotContains($mixed->getRef(), $refs);
    }

    public function testWithoutTheFilterEveryOrderIsListedAndAnUnknownValueIsIgnored(): void
    {
        [$preorder, , $plain] = $this->orders();

        foreach (['/admin/orders', '/admin/orders?preorder=maybe'] as $url) {
            $refs = $this->refs($this->client->request('GET', $url));

            self::assertContains($preorder->getRef(), $refs, $url);
            self::assertContains($plain->getRef(), $refs, $url);
        }
    }

    public function testTheActiveFilterShowsAChipThatRemovesIt(): void
    {
        $this->orders();

        $crawler = $this->client->request('GET', '/admin/orders?preorder=with');

        $chip = $crawler->filter('[data-testid="order-active-chip-preorder"]');
        self::assertCount(1, $chip);
        self::assertStringContainsString('Preorder', $chip->text());
        self::assertStringContainsString('with', $chip->text());
        self::assertStringNotContainsString('preorder=', (string) $chip->attr('href'), 'Removing the chip removes the filter.');
        self::assertCount(1, $crawler->filter('input[name="preorder"][value="with"][checked]'), 'The panel shows the choice made.');
        self::assertCount(0, $this->client->request('GET', '/admin/orders')->filter('[data-testid="order-active-chip-preorder"]'));
    }

    /**
     * @return array{Order, Order, Order, Order} a preorder, one with a preorder line among others, a plain one, one with no line
     */
    private function orders(): array
    {
        $preorder = $this->factory->order();
        $this->line($preorder, true);

        $mixed = $this->factory->order();
        $this->line($mixed, false);
        $this->line($mixed, true);
        $this->line($mixed, true);

        $plain = $this->factory->order();
        $this->line($plain, false);

        return [$preorder, $mixed, $plain, $this->factory->order()];
    }

    private function line(Order $order, bool $isPreorder): void
    {
        $this->factory->orderProduct($order)->setIsPreorder($isPreorder ? 1 : 0)->save($this->getPropelConnection());
    }

    /**
     * @return list<string>
     */
    private function refs(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="bo-order-ref-link"]')->each(static fn (Crawler $link): string => trim($link->text()));
    }
}
