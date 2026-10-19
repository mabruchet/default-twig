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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Module;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPart;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The payment block of the order sheet lists the parts of an order paid with several
 * methods, and stays what it always was for an order with a single one.
 */
final class OrderPaymentPartsSheetTest extends WebIntegrationTestCase
{
    private const LOCALE = 'en_US';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        // TODO remove once thelia/core 3.3 is required.
        if (!class_exists(OrderPaymentPart::class)) {
            self::markTestSkipped('Needs the stored payment methods of Thelia 3.3.');
        }

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin(['locale' => self::LOCALE]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testAnOrderWithTwoPartsListsThemInSettlementOrder(): void
    {
        $order = $this->factory->order();
        $instant = $this->paymentModule('Gift card wallet');
        $provider = $this->paymentModule('Card provider');

        // Written in the opposite order of their position: the sheet sorts them.
        $this->part($order, $provider, '70.000000', OrderPaymentPartState::PENDING, true, 1);
        $this->part($order, $instant, '30.000000', OrderPaymentPartState::SETTLED, false, 0);

        $rows = $this->partRows($order);

        self::assertCount(2, $rows);
        self::assertStringContainsString('Gift card wallet', $rows[0]);
        self::assertStringContainsString('30.00', $rows[0]);
        self::assertStringContainsString('Settled', $rows[0]);
        self::assertStringNotContainsString('Primary', $rows[0]);
        self::assertStringContainsString('Card provider', $rows[1]);
        self::assertStringContainsString('70.00', $rows[1]);
        self::assertStringContainsString('Pending', $rows[1]);
        self::assertStringContainsString('Primary', $rows[1]);
    }

    public function testAPartOfADeletedModuleReadsWithTheTitleFrozenOnThePart(): void
    {
        $order = $this->factory->order();

        $this->part($order, null, '100.000000', OrderPaymentPartState::PENDING, true, 0, 'Vanished provider');

        $rows = $this->partRows($order);

        self::assertCount(1, $rows);
        self::assertStringContainsString('Vanished provider', $rows[0]);
    }

    public function testAnOrderWithoutPartHasNoTraceOfTheList(): void
    {
        $order = $this->factory->order();

        $this->client->request('GET', '/admin/order/update/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('data-testid="order-payment"', $html);
        self::assertStringNotContainsString('order-payment-parts', $html);
        self::assertStringNotContainsString('order-payment-part"', $html);
        self::assertStringNotContainsString('Payment parts', $html);
    }

    /**
     * @return list<string> the text of each row of the parts table
     */
    private function partRows(Order $order): array
    {
        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $rows = $crawler->filter('[data-testid="order-payment"] [data-testid="order-payment-part"]');

        return $rows->each(static fn (Crawler $row): string => (string) preg_replace('/\s+/', ' ', trim($row->text())));
    }

    private function paymentModule(string $title): Module
    {
        $module = (new Module())
            ->setCode('PartsTest'.bin2hex(random_bytes(4)))
            ->setType(BaseModule::PAYMENT_MODULE_TYPE)
            ->setActivate(1)
            ->setPosition(99)
            ->setFullNamespace('Test\\PartsTest');
        $module->setLocale(self::LOCALE);
        $module->setTitle($title);
        $module->save($this->getPropelConnection());

        return $module;
    }

    private function part(
        Order $order,
        ?Module $module,
        string $amount,
        OrderPaymentPartState $state,
        bool $primary,
        int $position,
        ?string $frozenTitle = null,
    ): void {
        (new OrderPaymentPart())
            ->setOrderId((int) $order->getId())
            ->setPaymentModuleId($module?->getId())
            ->setPaymentModuleTitle($frozenTitle)
            ->setAmount($amount)
            ->setState($state->value)
            ->setIsPrimary($primary)
            ->setPosition($position)
            ->save($this->getPropelConnection());
    }
}
