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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Catalog\Stock\OutOfStockPolicy;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\ProductPriceTableMap;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The stock settings of a combination (what happens at zero stock, the limit of
 * a preorder, the restock date, the own low stock threshold), saved from the
 * grid of combinations and from the default pricing of a product without any.
 * An empty field clears the setting, a field the form did not send keeps it.
 */
final class ProductCombinationStockSettingsTest extends WebIntegrationTestCase
{
    private const COMBINATIONS_URL = '/admin/product/combinations/update';
    private const DEFAULT_PRICE_URL = '/admin/product/default-price/update';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        if (!enum_exists(OutOfStockPolicy::class)) {
            self::markTestSkipped('The core of this checkout predates the stock settings.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheGridSavesTheFourStockSettings(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $this->postGrid($product, [$pse], [
            'out_of_stock_policy' => ['2'],
            'preorder_limit' => ['10'],
            'restock_date' => ['2031-03-15'],
            'low_stock_threshold' => ['3.5'],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $fresh = $this->freshSaleElement($pse);
        self::assertSame(OutOfStockPolicy::Limited->value, $fresh->getOutOfStockPolicy());
        self::assertSame(10.0, $fresh->getPreorderLimit());
        self::assertSame('2031-03-15', $fresh->getRestockDate('Y-m-d'));
        self::assertSame(3.5, $fresh->getLowStockThreshold());
    }

    public function testAnEmptyFieldClearsTheSetting(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $pse->setOutOfStockPolicy(2)->setPreorderLimit(10.0)->setRestockDate('2031-03-15')->setLowStockThreshold(3.0)
            ->save($this->getPropelConnection());

        $this->postGrid($product, [$pse], [
            'out_of_stock_policy' => ['0'],
            'preorder_limit' => [''],
            'restock_date' => [''],
            'low_stock_threshold' => [''],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $fresh = $this->freshSaleElement($pse);
        self::assertSame(0, $fresh->getOutOfStockPolicy());
        self::assertNull($fresh->getPreorderLimit());
        self::assertNull($fresh->getRestockDate());
        self::assertNull($fresh->getLowStockThreshold(), 'An empty threshold goes back to the one of the shop.');
    }

    public function testAZeroIsKeptAsZeroAndNotCleared(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $this->postGrid($product, [$pse], [
            'out_of_stock_policy' => ['2'],
            'preorder_limit' => ['0'],
            'restock_date' => [''],
            'low_stock_threshold' => ['0'],
        ]);

        $fresh = $this->freshSaleElement($pse);
        self::assertSame(0.0, $fresh->getPreorderLimit());
        self::assertSame(0.0, $fresh->getLowStockThreshold(), 'A threshold of 0 is a value: the alert only fires at zero.');
    }

    public function testAFormThatSendsNoStockSettingLeavesThemAlone(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $pse->setOutOfStockPolicy(1)->setPreorderLimit(4.0)->setRestockDate('2031-03-15')->setLowStockThreshold(3.0)
            ->save($this->getPropelConnection());

        $this->postGrid($product, [$pse], []);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $fresh = $this->freshSaleElement($pse);
        self::assertSame(1, $fresh->getOutOfStockPolicy());
        self::assertSame(4.0, $fresh->getPreorderLimit());
        self::assertSame('2031-03-15', $fresh->getRestockDate('Y-m-d'));
        self::assertSame(3.0, $fresh->getLowStockThreshold());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidRows(): iterable
    {
        yield 'negative limit' => [['out_of_stock_policy' => '2', 'preorder_limit' => '-1']];
        yield 'negative threshold' => [['low_stock_threshold' => '-2']];
        yield 'text threshold' => [['low_stock_threshold' => 'many']];
        yield 'impossible date' => [['restock_date' => '2031-02-31']];
        yield 'text date' => [['restock_date' => 'soon']];
        yield 'unknown mode' => [['out_of_stock_policy' => '7']];
    }

    /**
     * @param array<string, string> $invalid the settings sent for the first row
     */
    #[DataProvider('invalidRows')]
    public function testAnInvalidRowIsNotSavedAndNamedWhileTheOthersAre(array $invalid): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $other = $this->unpricedCombinationOf($product);

        // The first row is the invalid one, the second one is valid.
        $valid = ['out_of_stock_policy' => '2', 'preorder_limit' => '6', 'restock_date' => '', 'low_stock_threshold' => ''];
        $blank = ['out_of_stock_policy' => '0', 'preorder_limit' => '', 'restock_date' => '', 'low_stock_threshold' => ''];
        $payload = [];
        foreach ($valid as $name => $value) {
            $payload[$name] = [$invalid[$name] ?? $blank[$name], $value];
        }

        $this->postGrid($product, [$pse, $other], $payload, ['25', '12.5']);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $stored = $this->freshSaleElement($pse);
        self::assertSame(0, $stored->getOutOfStockPolicy(), 'The invalid row keeps its stored settings.');
        self::assertNull($stored->getPreorderLimit());
        self::assertNull($stored->getLowStockThreshold());
        self::assertNull($stored->getRestockDate());
        self::assertSame(2, $this->freshSaleElement($other)->getOutOfStockPolicy(), 'The valid row is saved.');
        self::assertSame(6.0, $this->freshSaleElement($other)->getPreorderLimit());

        $this->client->followRedirect();
        self::assertStringContainsString(
            $pse->getRef(),
            $this->client->getCrawler()->filter('[data-testid="bo-flash-danger"]')->text(''),
            'The merchant is told which combination is wrong.',
        );
    }

    public function testTheDefaultPricingFormSavesAndClearsTheStockSettings(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();

        $this->postDefault($product, $pse, [
            'out_of_stock_policy' => '2',
            'preorder_limit' => '8',
            'restock_date' => '2031-04-01',
            'low_stock_threshold' => '2',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $fresh = $this->freshSaleElement($pse);
        self::assertSame(2, $fresh->getOutOfStockPolicy());
        self::assertSame(8.0, $fresh->getPreorderLimit());
        self::assertSame('2031-04-01', $fresh->getRestockDate('Y-m-d'));
        self::assertSame(2.0, $fresh->getLowStockThreshold());

        $this->postDefault($product, $pse, [
            'out_of_stock_policy' => '0',
            'preorder_limit' => '',
            'restock_date' => '',
            'low_stock_threshold' => '',
        ]);

        $fresh = $this->freshSaleElement($pse);
        self::assertSame(0, $fresh->getOutOfStockPolicy());
        self::assertNull($fresh->getPreorderLimit());
        self::assertNull($fresh->getRestockDate());
        self::assertNull($fresh->getLowStockThreshold());
    }

    public function testTheDefaultPricingFormRefusesAnInvalidSettingAndSavesNothing(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();

        $this->postDefault($product, $pse, [
            'out_of_stock_policy' => '2',
            'preorder_limit' => '-4',
            'restock_date' => '',
            'low_stock_threshold' => '',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $fresh = $this->freshSaleElement($pse);
        self::assertSame(0, $fresh->getOutOfStockPolicy());
        self::assertNull($fresh->getPreorderLimit());
        $this->client->followRedirect();
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="bo-flash-danger"]'));
    }

    public function testTheGridOffersTheThreeModesAndTheThresholdShowsTheGlobalOneAsPlaceholder(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        ConfigQuery::write('low_stock_threshold', '20');
        $pse->setOutOfStockPolicy(2)->setPreorderLimit(10.0)->setRestockDate('2031-03-15')
            ->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        $row = \sprintf('tbody[data-testid="combinations-row-%d"]', $pse->getId());
        $options = $crawler->filter($row.' select[name="out_of_stock_policy[]"] option');
        self::assertSame(['0', '1', '2'], $options->each(static fn ($option): string => (string) $option->attr('value')));
        self::assertSame('2', $crawler->filter($row.' select[name="out_of_stock_policy[]"] option[selected]')->attr('value'));
        self::assertSame('10', $crawler->filter($row.' input[name="preorder_limit[]"]')->attr('value'));
        self::assertSame('2031-03-15', $crawler->filter($row.' input[name="restock_date[]"]')->attr('value'));
        $threshold = $crawler->filter($row.' input[name="low_stock_threshold[]"]');
        self::assertSame('', $threshold->attr('value'), 'An inherited threshold is shown empty.');
        self::assertSame('20', $threshold->attr('placeholder'), 'The placeholder is the threshold of the shop.');
    }

    public function testTheValueOfTheLimitedModeComesFromTheCoreAndDrivesTheFieldOfTheLimit(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        [$withoutCombination, $defaultPse] = $this->productWithoutCombination();
        $limited = (string) OutOfStockPolicy::Limited->value;

        foreach ([[0, true], [$limited, false]] as [$policy, $hidden]) {
            $pse->setOutOfStockPolicy((int) $policy)->save($this->getPropelConnection());
            $defaultPse->setOutOfStockPolicy((int) $policy)->save($this->getPropelConnection());

            $grid = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
            $cell = $grid->filter(\sprintf('tbody[data-testid="combinations-row-%d"] td.bo-combinations-grid__identity [data-testid="combinations-stock-fields-%d"] div[data-controller="bo-stock-policy"]', $pse->getId(), $pse->getId()));
            self::assertSame($limited, $cell->attr('data-bo-stock-policy-limited-value'), 'the grid hands the controller the value of the limited mode');
            self::assertSame($hidden, str_contains((string) $cell->filter('input[name="preorder_limit[]"]')->attr('class'), 'd-none'));

            $form = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$withoutCombination->getId());
            $block = $form->filter('form[data-testid="default-pse-form"] [data-controller="bo-stock-policy"]');
            self::assertSame($limited, $block->attr('data-bo-stock-policy-limited-value'), 'the default form hands the controller the value of the limited mode');
            self::assertSame($hidden, str_contains((string) $block->filter('[data-bo-stock-policy-target="limit"]')->attr('class'), 'd-none'));
        }
    }

    public function testTheStockSettingsAreVisibleInTheGridAndNotFoldedAway(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        $row = \sprintf('tbody[data-testid="combinations-row-%d"]', $pse->getId());
        foreach (['out_of_stock_policy', 'preorder_limit', 'restock_date', 'low_stock_threshold'] as $field) {
            $in = static fn (string $scope): string => \sprintf('%1$s %2$s input[name="%3$s[]"], %1$s %2$s select[name="%3$s[]"]', $row, $scope, $field);
            self::assertCount(1, $crawler->filter($in('tr')), $field.' is rendered once in the grid');
            self::assertCount(0, $crawler->filter($in('tr[hidden]')), $field.' is not in the folded fields');
            self::assertCount(1, $crawler->filter($in('tr:not([hidden]) td.bo-combinations-grid__identity')), $field.' is in the line of the name of the combination');
        }
    }

    public function testTheRenderedFormKeepsTheSettingsOfEachCombinationWhateverTheOrderOfTheLines(): void
    {
        $this->loginFullAdmin();
        [$product, $first] = $this->productWithACombination();
        $second = $this->factory->productSaleElement($product, ['quantity' => 4]);
        $this->factory->attributeCombination($second, $this->factory->attributeAv($this->factory->attribute(['title' => 'Color']), ['title' => 'Red']));
        $this->factory->productPrice($second, $this->defaultCurrency(), ['price' => '30.000000', 'promoPrice' => '30.000000']);
        $first->setPosition(2)->save($this->getPropelConnection());
        $second->setPosition(1)->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertSame(
            [(string) $second->getId(), (string) $first->getId()],
            $crawler->filter('form[data-testid="combinations-form"] input[name="product_sale_element_id[]"]')->each(static fn ($input): string => (string) $input->attr('value')),
            'The lines come in the reverse order of their ids.',
        );

        $form = $crawler->filter('form[data-testid="combinations-form"]')->form();
        $form->setValues([
            'out_of_stock_policy' => ['1', '2'],
            'preorder_limit' => ['', '15'],
            'restock_date' => ['2031-01-10', '2032-02-20'],
            'low_stock_threshold' => ['4', '9.5'],
        ]);
        $this->client->submit($form);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $freshSecond = $this->freshSaleElement($second);
        self::assertSame(1, (int) $freshSecond->getOutOfStockPolicy());
        self::assertNull($freshSecond->getPreorderLimit());
        self::assertSame('2031-01-10', $freshSecond->getRestockDate('Y-m-d'));
        self::assertSame(4.0, $freshSecond->getLowStockThreshold());
        $freshFirst = $this->freshSaleElement($first);
        self::assertSame(2, (int) $freshFirst->getOutOfStockPolicy());
        self::assertSame(15.0, $freshFirst->getPreorderLimit());
        self::assertSame('2032-02-20', $freshFirst->getRestockDate('Y-m-d'));
        self::assertSame(9.5, $freshFirst->getLowStockThreshold());
    }

    public function testTheDefaultPricingFormShowsTheStoredSettings(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();
        ConfigQuery::write('low_stock_threshold', '0');
        $pse->setOutOfStockPolicy(1)->setLowStockThreshold(4.0)->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertSame('1', $crawler->filter('form[data-testid="default-pse-form"] select[name="out_of_stock_policy"] option[selected]')->attr('value'));
        $threshold = $crawler->filter('form[data-testid="default-pse-form"] input[name="low_stock_threshold"]');
        self::assertSame('4', $threshold->attr('value'));
        self::assertSame('0', $threshold->attr('placeholder'), 'A global threshold of 0 is shown as 0.');
    }

    /**
     * @param list<ProductSaleElements>   $pses
     * @param array<string, list<string>> $settings
     * @param list<string>|null           $prices
     */
    private function postGrid(Product $product, array $pses, array $settings, ?array $prices = null): void
    {
        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'default_pse' => $pses[0]->getId(),
            'product_sale_element_id' => array_map(static fn (ProductSaleElements $pse): int => (int) $pse->getId(), $pses),
            'reference' => array_map(static fn (ProductSaleElements $pse): string => (string) $pse->getRef(), $pses),
            'quantity' => array_map(static fn (ProductSaleElements $pse): float => (float) $pse->getQuantity(), $pses),
            'price' => $prices ?? array_map(static fn (): string => '25', $pses),
        ] + $settings);
    }

    /**
     * @param array<string, string> $settings
     */
    private function postDefault(Product $product, ProductSaleElements $pse, array $settings): void
    {
        $this->client->request('POST', self::DEFAULT_PRICE_URL, [
            '_token' => $this->tokenOf($product, 'default-pse-form'),
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'reference' => $pse->getRef(),
            'tax_rule' => $product->getTaxRuleId(),
            'quantity' => 7,
        ] + $settings);
    }

    private function loginFullAdmin(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithoutCombination(): array
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->defaultCurrency(),
            ['basePrice' => 25.0, 'baseQuantity' => 7],
        );
        $pse = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($pse);

        $price = ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($this->defaultCurrency()->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($price);
        $price->setPromoPrice('20.000000')->save($this->getPropelConnection());

        return [$product, $pse];
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithACombination(): array
    {
        [$product, $pse] = $this->productWithoutCombination();

        $attribute = $this->factory->attribute(['title' => 'Size']);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($attribute, ['title' => 'Large']));

        return [$product, $pse];
    }

    /**
     * A combination written without any price row, the way the API or an import
     * can create one.
     */
    private function unpricedCombinationOf(Product $product): ProductSaleElements
    {
        $pse = $this->factory->productSaleElement($product, ['quantity' => 3]);
        $attribute = $this->factory->attribute(['title' => 'Color']);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($attribute, ['title' => 'Red']));
        self::assertNull($this->priceOf($pse));

        return $pse;
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }

    /**
     * The token the given form of the combinations tab renders in its body, so
     * the POST is submitted the way the browser submits it.
     */
    private function tokenOf(Product $product, string $formTestId): string
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        $token = $crawler->filter(\sprintf('form[data-testid="%s"] input[name="_token"]', $formTestId))->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('The "%s" form renders no token field.', $formTestId));

        return (string) $token->attr('value');
    }

    private function freshSaleElement(ProductSaleElements $pse): ProductSaleElements
    {
        ProductSaleElementsTableMap::clearInstancePool();
        $fresh = ProductSaleElementsQuery::create()->findPk($pse->getId(), $this->getPropelConnection());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function priceOf(ProductSaleElements $pse): ?ProductPrice
    {
        ProductPriceTableMap::clearInstancePool();

        return ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($this->defaultCurrency()->getId())
            ->findOne($this->getPropelConnection());
    }
}
