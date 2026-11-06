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

use BackOfficeDefaultTwigBundle\Repository\PreorderTrackingRepository;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Administrator\AdministratorSaleUnitsEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Catalog\Stock\OutOfStockPolicy;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderStatus;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\SaleUnit;
use Thelia\Model\SaleUnitQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The follow-up of the preorders: the combinations sold below zero stock, what is to order for each,
 * the open orders that wait for it and the restock date, for the administrators allowed to see the products.
 */
final class PreorderTrackingScreenTest extends WebIntegrationTestCase
{
    private const URL = '/admin/preorders';

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
        $this->actAs($this->factory->admin());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    /**
     * Stock 3, then A orders 2 (served from the stock), B 4 and C 2 (preorders): the stock is -5, so 5 are
     * to order. The sum of the preorder lines, 6, would order one unit too many.
     */
    public function testWhatIsToOrderIsTheStockTheCombinationLacksAndNotTheSumOfItsLines(): void
    {
        $pse = $this->combination('B1-EXAMPLE', -5.0, OutOfStockPolicy::Unlimited);
        $this->open($pse, 2, false);
        $this->open($pse, 4, true);
        $this->open($pse, 2, true);

        $row = $this->row($this->page(), $pse);

        self::assertSame('5', $row->filter('[data-testid="datatable-preorders-cell-to_order"]')->text());
        self::assertSame('2', $row->filter('[data-testid="datatable-preorders-cell-open_orders"]')->text(), 'The two orders holding a preorder line, not the one served from the stock.');
        self::assertSame('B1-EXAMPLE', $row->filter('[data-testid="datatable-preorders-cell-ref"]')->text());
        self::assertStringContainsString('Product', $this->page()->filter('[data-testid="datatable-preorders-table"] thead')->text());
    }

    public function testOnlyTheCombinationsBelowZeroThatStillSellAreListed(): void
    {
        $unlimited = $this->combination('B1-UNLIMITED', -1.0, OutOfStockPolicy::Unlimited);
        $limited = $this->combination('B1-LIMITED', -2.0, OutOfStockPolicy::Limited);
        $refusing = $this->combination('B1-REFUSING', -3.0, OutOfStockPolicy::Refuse);
        $empty = $this->combination('B1-EMPTY', 0.0, OutOfStockPolicy::Unlimited);
        $stocked = $this->combination('B1-STOCKED', 8.0, OutOfStockPolicy::Unlimited);

        $crawler = $this->page();

        self::assertCount(1, $this->rowsOf($crawler, $unlimited));
        self::assertCount(1, $this->rowsOf($crawler, $limited));
        foreach ([$refusing, $empty, $stocked] as $notListed) {
            self::assertCount(0, $this->rowsOf($crawler, $notListed), $notListed->getRef());
        }
    }

    public function testOnlyTheOpenOrdersHoldingAPreorderLineAreCounted(): void
    {
        $pse = $this->combination('B1-OPEN', -9.0, OutOfStockPolicy::Unlimited);
        $this->open($pse, 3, true);
        $this->open($pse, 2, true, OrderStatus::CODE_PAID);
        $this->open($pse, 1, true, OrderStatus::CODE_PROCESSING);
        $this->open($pse, 1, true, OrderStatus::CODE_SENT);
        $this->open($pse, 1, true, OrderStatus::CODE_CANCELED);
        $this->open($pse, 1, true, OrderStatus::CODE_REFUNDED);
        $this->open($pse, 1, false);
        $closedByCustomStatus = $this->factory->orderStatus(['code' => 'b1-custom', 'equivalentCode' => OrderStatus::CODE_CANCELED]);
        $custom = $this->open($pse, 1, true);
        $custom->setStatusId($closedByCustomStatus->getId())->save($this->getPropelConnection());
        $this->open($this->combination('B1-OTHER', -1.0, OutOfStockPolicy::Unlimited), 5, true);

        $row = $this->row($this->page(), $pse);

        self::assertSame('3', $row->filter('[data-testid="datatable-preorders-cell-open_orders"]')->text(), 'Not paid, paid and in process: shipped, canceled, refunded, a custom status that stands for canceled and a line that is not a preorder are out.');
    }

    public function testAnAdministratorOfSomeSaleUnitsCountsTheOrdersOfThoseUnitsOnly(): void
    {
        SaleUnitQuery::create()->find($this->getPropelConnection())->delete($this->getPropelConnection());
        $north = $this->saleUnit('north');
        $south = $this->saleUnit('south');
        $pse = $this->combination('B1-UNITS', -4.0, OutOfStockPolicy::Unlimited);
        $this->open($pse, 2, true)->setSaleUnitId($north->getId())->save($this->getPropelConnection());
        $this->open($pse, 2, true)->setSaleUnitId($south->getId())->save($this->getPropelConnection());

        self::assertSame('2', $this->row($this->page(), $pse)->filter('[data-testid="datatable-preorders-cell-open_orders"]')->text());

        $admin = $this->factory->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]]);
        $this->getService(EventDispatcherInterface::class)->dispatch(
            new AdministratorSaleUnitsEvent((int) $admin->getId(), [(int) $north->getId()]),
            TheliaEvents::ADMINISTRATOR_UPDATE_SALE_UNITS,
        );
        $this->actAs($admin);

        self::assertSame('1', $this->row($this->page(), $pse)->filter('[data-testid="datatable-preorders-cell-open_orders"]')->text());
    }

    public function testTheSoonestRestockComesFirstThenTheLargestShortageAndTheUndatedLast(): void
    {
        $this->combination('B1-S-UNDATED-BIG', -90.0, OutOfStockPolicy::Unlimited);
        $this->combination('B1-S-LATE', -50.0, OutOfStockPolicy::Unlimited, '2031-09-01');
        $this->combination('B1-S-SOON-SMALL', -1.0, OutOfStockPolicy::Unlimited, '2031-03-01');
        $this->combination('B1-S-SOON-BIG', -7.0, OutOfStockPolicy::Unlimited, '2031-03-01');
        $this->combination('B1-S-UNDATED-SMALL', -2.0, OutOfStockPolicy::Unlimited);

        $refs = array_column($this->repository()->findPaginated('en_US', 1, 1000)['rows'], 'ref');
        $mine = array_values(array_filter($refs, static fn (string $ref): bool => str_starts_with($ref, 'B1-S-')));

        self::assertSame(
            ['B1-S-SOON-BIG', 'B1-S-SOON-SMALL', 'B1-S-LATE', 'B1-S-UNDATED-BIG', 'B1-S-UNDATED-SMALL'],
            $mine,
            'Restock date ascending, the larger shortage first on the same day, the combinations without a date after the others.',
        );
    }

    public function testThePagesHoldTwentyFiveRows(): void
    {
        for ($i = 1; $i <= 27; ++$i) {
            $this->combination(\sprintf('B1-P-%02d', $i), -1.0 * $i, OutOfStockPolicy::Unlimited, '2031-01-'.\sprintf('%02d', $i));
        }
        $total = $this->repository()->count();
        self::assertGreaterThanOrEqual(27, $total);

        $first = $this->client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertCount(25, $first->filter('[data-testid="datatable-preorders-row"]'));
        self::assertCount(1, $first->filter('[data-testid="preorder-pagination"]'), 'More than one page: the pagination is there.');

        $last = $this->client->request('GET', self::URL.'?page='.(int) ceil($total / 25));
        self::assertCount($total - 25 * ((int) ceil($total / 25) - 1), $last->filter('[data-testid="datatable-preorders-row"]'));
    }

    public function testTheScreenAndItsMenuEntryAreForTheAdministratorsWhoSeeTheProducts(): void
    {
        $this->actAs($this->factory->restrictedAdmin([AdminResources::CATEGORY => [AccessManager::VIEW]]));

        $this->client->request('GET', self::URL);
        self::assertResponseStatusCodeSame(403, 'The categories alone do not open the follow-up.');
        $categories = $this->client->request('GET', '/admin/categories');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $categories->filter('[data-testid="bo-nav-catalog"]'), 'The Catalog menu is shown to him.');
        self::assertCount(0, $categories->filter('[data-testid="bo-nav-catalog-preorders"]'), 'No entry for an administrator who cannot open it.');

        $this->actAs($this->factory->restrictedAdmin([AdminResources::CATEGORY => [AccessManager::VIEW], AdminResources::PRODUCT => [AccessManager::VIEW]]));

        $this->client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $categories = $this->client->request('GET', '/admin/categories');
        self::assertResponseIsSuccessful();
        $entry = $categories->filter('[data-testid="bo-nav-catalog-preorders"]');
        self::assertCount(1, $entry);
        self::assertSame(self::URL, $entry->attr('href'));
    }

    public function testAnAdministratorWhoSeesTheProductsWithoutTheCategoriesStillFindsTheEntry(): void
    {
        $this->actAs($this->factory->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]]));

        $page = $this->client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-testid="bo-nav-catalog"]'), 'The Catalog menu opens on the products right alone.');
        $entry = $page->filter('[data-testid="bo-nav-catalog-preorders"]');
        self::assertCount(1, $entry);
        self::assertSame(self::URL, $entry->attr('href'));
    }

    private function repository(): PreorderTrackingRepository
    {
        return $this->getService(PreorderTrackingRepository::class);
    }

    private function actAs($admin): void
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    private function page(): Crawler
    {
        $crawler = $this->client->request('GET', self::URL.'?page=1');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function rowsOf(Crawler $crawler, ProductSaleElements $pse): Crawler
    {
        return $crawler->filter(\sprintf('[data-testid="datatable-preorders-row"][data-row-id="%d"]', $pse->getId()));
    }

    private function row(Crawler $crawler, ProductSaleElements $pse): Crawler
    {
        $row = $this->rowsOf($crawler, $pse);
        self::assertCount(1, $row, 'The combination is listed (it may sit on a later page when the shop holds many).');

        return $row;
    }

    private function combination(string $ref, float $quantity, OutOfStockPolicy $policy, ?string $restockDate = null): ProductSaleElements
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->defaultCurrency(), ['title' => 'Kettle '.$ref]);
        $pse = $product->getDefaultSaleElements();
        $pse->setRef($ref)->setQuantity($quantity)->setOutOfStockPolicy($policy->value)->setRestockDate($restockDate)
            ->save($this->getPropelConnection());

        return $pse;
    }

    private function open(ProductSaleElements $pse, int $quantity, bool $isPreorder, string $statusCode = OrderStatus::CODE_NOT_PAID): Order
    {
        $order = $this->factory->order(null, ['statusCode' => $statusCode]);
        $this->factory->orderProduct($order, ['productSaleElementsId' => (int) $pse->getId(), 'quantity' => $quantity])
            ->setIsPreorder($isPreorder ? 1 : 0)
            ->save($this->getPropelConnection());

        return $order;
    }

    private function saleUnit(string $code): SaleUnit
    {
        $unit = (new SaleUnit())->setCode($code)->setActive(true)->setPosition(1)->setLocale('en_US')->setTitle($code.' title');
        $unit->save($this->getPropelConnection());

        return $unit;
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }
}
