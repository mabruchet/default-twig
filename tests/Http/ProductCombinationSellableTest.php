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

use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use BackOfficeDefaultTwigBundle\Service\Product\CombinationSellableReader;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Catalog\Stock\OutOfStockPolicy;
use Thelia\Domain\Catalog\Stock\ReservationDetailReader;
use Thelia\Domain\Catalog\Stock\StockSettings;
use Thelia\Model\CartItem;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * Under the stock field of a combination, what it may still sell and, in a tooltip, what the carts hold
 * of it. The figures of the whole tab are read in two queries, and the grid gains neither a line nor a column.
 */
final class ProductCombinationSellableTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!class_exists(ReservationDetailReader::class)) {
            self::markTestSkipped('The core of this checkout predates the detail of the stock reservations.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
        $this->reserve(true);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheSellableQuantityIsTheStockLessWhatTheCartsHold(): void
    {
        [$product, $combination] = $this->productWithACombination(10);
        $near = $this->hold($product, $combination, 3, '+ INTERVAL 5 MINUTE');
        $this->hold($product, $combination, 2, '+ INTERVAL 20 MINUTE');

        $crawler = $this->tab($product);

        $note = $crawler->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]');
        self::assertCount(1, $note);
        self::assertStringContainsString('Sellable: 5', $note->text());
        $tooltip = $crawler->filter('[data-testid="combinations-sellable-hint-'.$combination->getId().'"]')->attr('title');
        self::assertStringContainsString('Held by the carts: 5 in 2 cart(s)', (string) $tooltip);
        self::assertStringContainsString(substr($this->untilOf($near), 0, 16), (string) $tooltip, 'The nearest expiry is the one of the first cart.');
        self::assertStringContainsString('10', $crawler->filter('#combination-'.$combination->getId().'-quantity')->attr('value') ?? '', 'The stock field still edits the physical stock.');
    }

    public function testACombinationThatSellsWithoutLimitSaysSo(): void
    {
        [$product, $combination] = $this->productWithACombination(10);
        $combination->setOutOfStockPolicy(OutOfStockPolicy::Unlimited->value)->save($this->getPropelConnection());
        $this->hold($product, $combination, 4, '+ INTERVAL 5 MINUTE');

        $note = $this->tab($product)->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]');

        self::assertStringContainsString('Sellable: unlimited', $note->text());
        self::assertStringNotContainsString('Sellable: 6', $note->text());
    }

    public function testTheCombinationOfABundleShowsNoSellableFigure(): void
    {
        if (!BundleCore::present()) {
            self::markTestSkipped('The core of this checkout predates the product bundles.');
        }

        [$product, $combination] = $this->productWithACombination(10);
        $product->setIsBundle(1)->save($this->getPropelConnection());

        $crawler = $this->tab($product);

        self::assertCount(0, $crawler->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]'), 'The stock of its components decides: no figure for the bundle.');
        self::assertStringNotContainsString('Sellable', $crawler->text());
    }

    public function testACombinationNoCartHoldsSaysItInTheTooltip(): void
    {
        [$product, $combination] = $this->productWithACombination(7);

        $crawler = $this->tab($product);

        self::assertStringContainsString('Sellable: 7', $crawler->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]')->text());
        self::assertStringContainsString('No cart holds any unit', (string) $crawler->filter('[data-testid="combinations-sellable-hint-'.$combination->getId().'"]')->attr('title'));
    }

    public function testTheNoteIsInAnExistingCellAndTheGridGainsNoLineAndNoColumn(): void
    {
        [$product, $combination] = $this->productWithACombination(10);
        $other = $this->factory->productSaleElement($product, ['quantity' => 4]);
        $this->factory->attributeCombination($other, $this->factory->attributeAv($this->factory->attribute(['title' => 'Color']), ['title' => 'Red']));

        $grid = $this->tab($product)->filter('table[data-testid="combinations-table"]');

        $columns = array_sum($grid->filter('thead > tr > th')->each(static fn (Crawler $cell): int => (int) ($cell->attr('colspan') ?? 1)));
        self::assertSame(8, $columns, 'The 8 columns of the grid.');
        foreach ([$combination, $other] as $pse) {
            $row = \sprintf('tbody[data-row-id="%d"] > tr', $pse->getId());
            self::assertCount(5, $grid->filter($row), 'The five lines of a combination.');
            self::assertCount(1, $grid->filter($row.' > td.bo-combinations-grid__logistics [data-testid="combinations-sellable-'.$pse->getId().'"]'), 'Inside the cell of the stock field.');
        }
    }

    public function testTheFiguresOfAnyNumberOfCombinationsCostTheSameQueries(): void
    {
        [$product, $combination] = $this->productWithACombination(10);
        $reader = $this->getService(CombinationSellableReader::class);
        $few = [$combination];
        $many = [$combination];
        for ($i = 0; $i < 5; ++$i) {
            $many[] = $this->factory->productSaleElement($product, ['quantity' => 3]);
        }

        $fewReads = $this->cartReads(static fn () => $reader->of($few));
        $manyReads = $this->cartReads(static fn () => $reader->of($many));

        self::assertSame($fewReads, $manyReads, 'No query by combination.');
        self::assertLessThanOrEqual(2, $manyReads, 'The sellable answers and the reservation detail.');
        self::assertGreaterThan(0, $manyReads, 'The reservations are read.');
    }

    private function cartReads(callable $work): int
    {
        return \count(array_filter(QueryCounter::statements($work), static fn (string $sql): bool => str_contains($sql, 'cart_item')));
    }

    private function reserve(bool $enabled): void
    {
        ConfigQuery::write(StockSettings::RESERVATION_ENABLED, $enabled ? '1' : '0');
        ConfigQuery::resetCache();
    }

    private function hold(Product $product, ProductSaleElements $combination, int $quantity, string $interval): CartItem
    {
        $cart = $this->factory->cart(null, ['currency' => $this->defaultCurrency()]);
        $line = $this->factory->cartItem($cart, $product, $combination, ['quantity' => (float) $quantity]);
        $this->getPropelConnection()->exec('UPDATE cart_item SET reserved_until = NOW() '.$interval.' WHERE id = '.(int) $line->getId());

        return $line;
    }

    private function untilOf(CartItem $line): string
    {
        return (string) $this->getPropelConnection()->query('SELECT reserved_until FROM cart_item WHERE id = '.(int) $line->getId())->fetchColumn();
    }

    private function tab(Product $product): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithACombination(int $quantity): array
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->defaultCurrency(), ['basePrice' => 25.0]);
        $combination = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($combination);
        $combination->setQuantity($quantity)->save($this->getPropelConnection());

        $this->factory->attributeCombination($combination, $this->factory->attributeAv($this->factory->attribute(['title' => 'Size']), ['title' => 'Large']));

        return [$product, $combination];
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }
}
