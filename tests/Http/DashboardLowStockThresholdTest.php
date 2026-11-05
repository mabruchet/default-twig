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

use BackOfficeDefaultTwigBundle\Repository\ProductRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\ProductSaleElements;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The low stock panel of the dashboard follows the threshold of the shop, and a
 * combination that sets its own threshold is judged against that one.
 */
final class DashboardLowStockThresholdTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!method_exists(ProductSaleElements::class, 'setLowStockThreshold')) {
            self::markTestSkipped('The core of this checkout predates the low stock threshold of a combination.');
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

        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheShopThresholdDecidesWhichCombinationsAreListed(): void
    {
        ConfigQuery::write('low_stock_threshold', '20');
        $atTheLimit = $this->combination('At the limit', 20);
        $above = $this->combination('Above', 21);
        $below = $this->combination('Below', 12);

        $listed = $this->listedIds(new ProductRepository());

        self::assertContains((int) $atTheLimit->getId(), $listed);
        self::assertContains((int) $below->getId(), $listed);
        self::assertNotContains((int) $above->getId(), $listed);
    }

    public function testACombinationWithItsOwnThresholdIsJudgedAgainstIt(): void
    {
        ConfigQuery::write('low_stock_threshold', '20');
        $quietOverride = $this->combination('Quiet', 10)->setLowStockThreshold(2.0);
        $quietOverride->save($this->getPropelConnection());
        $loudOverride = $this->combination('Loud', 50)->setLowStockThreshold(60.0);
        $loudOverride->save($this->getPropelConnection());
        $zeroOverride = $this->combination('Zero', 1)->setLowStockThreshold(0.0);
        $zeroOverride->save($this->getPropelConnection());
        $inherits = $this->combination('Inherits', 10);

        $listed = $this->listedIds(new ProductRepository());

        self::assertNotContains((int) $quietOverride->getId(), $listed, 'Stock 10 over its own threshold of 2: not low, though under the 20 of the shop.');
        self::assertContains((int) $loudOverride->getId(), $listed, 'Stock 50 under its own threshold of 60: low, though over the 20 of the shop.');
        self::assertNotContains((int) $zeroOverride->getId(), $listed, 'A threshold of 0 is a value, not an absence: stock 1 is not low.');
        self::assertContains((int) $inherits->getId(), $listed, 'Without a threshold of its own, the one of the shop applies.');
    }

    public function testThePanelReadsTheThresholdOfTheShopAndNamesItAsTheDefault(): void
    {
        ConfigQuery::write('low_stock_threshold', '20');
        // Far below any other stock of the shop, so they head the five rows of the panel.
        $plain = $this->combination('Plain', -5000);
        $quiet = $this->combination('Quiet', -4000)->setLowStockThreshold(-4500.0);
        $quiet->save($this->getPropelConnection());
        $loud = $this->combination('Loud', -3000)->setLowStockThreshold(100.0);
        $loud->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $rows = implode(' ', $crawler->filter('[data-testid="dashboard-low-stock"]')->each(static fn ($row): string => $row->text()));
        self::assertStringContainsString($plain->getRef(), $rows);
        self::assertStringContainsString($loud->getRef(), $rows);
        self::assertStringNotContainsString($quiet->getRef(), $rows, 'Its own threshold of -4500 is not reached.');

        $title = $crawler->filter('[data-testid="dashboard-low-stock-title"]')->text();
        self::assertStringContainsString('20', $title);
        self::assertStringContainsString('Default threshold', $title, 'A combination may set its own: the figure shown is the default one.');
    }

    public function testAShopThresholdOfZeroIsKept(): void
    {
        ConfigQuery::write('low_stock_threshold', '0');
        $empty = $this->combination('Empty', 0);
        $last = $this->combination('Last one', 1);

        $listed = $this->listedIds(new ProductRepository(), 0.0);

        self::assertContains((int) $empty->getId(), $listed);
        self::assertNotContains((int) $last->getId(), $listed);

        $crawler = $this->client->request('GET', '/admin');
        self::assertStringContainsString('0', $crawler->filter('[data-testid="dashboard-low-stock-title"]')->text());
        self::assertStringNotContainsString('5', $crawler->filter('[data-testid="dashboard-low-stock-title"]')->text(), 'A stored 0 is not replaced by the default of 5.');
    }

    /**
     * @return list<int>
     */
    private function listedIds(ProductRepository $products, float $threshold = 20.0): array
    {
        return array_map(
            static fn (ProductSaleElements $pse): int => (int) $pse->getId(),
            $products->findLowStock($threshold, 1000, 'en_US'),
        );
    }

    private function combination(string $title, int $quantity): ProductSaleElements
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            Currency::getDefaultCurrency(),
            ['title' => $title, 'baseQuantity' => $quantity],
        );
        $pse = $product->getDefaultSaleElements();
        self::assertNotNull($pse);

        return $pse;
    }
}
