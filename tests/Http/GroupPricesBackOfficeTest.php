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

use BackOfficeDefaultTwigBundle\Tests\Support\SaleUnitRestrictedAdmin;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\CustomerGroup;
use Thelia\Model\CustomerGroupProductPrice;
use Thelia\Model\CustomerGroupProductPriceQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The prices the customer groups keep of their own, on the combinations tab of a product:
 * the grid (combinations by groups), what an empty cell means, and what the save refuses.
 */
final class GroupPricesBackOfficeTest extends WebIntegrationTestCase
{
    use RecordsSqlQueries;

    private const SAVE_URL = '/admin/product/group-prices/save';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
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
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheGridHasOneCellPerCombinationAndGroup(): void
    {
        $groups = [$this->factory->customerGroup(title: 'Wholesale'), $this->factory->customerGroup(title: 'Friends')];
        [$product, $pse] = $this->productWithACombination();

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertCount(1, $crawler->filter('[data-testid="group-prices-section"]'));
        foreach ($groups as $group) {
            self::assertCount(1, $crawler->filter('[data-testid="group-prices-group-'.$group->getId().'"]'));
            foreach (['price', 'promo_price'] as $field) {
                $input = $crawler->filter('[data-testid="group-prices-'.$field.'-'.$pse->getId().'-'.$group->getId().'"]');
                self::assertCount(1, $input);
                self::assertSame('group_price['.$pse->getId().']['.$group->getId().']['.$field.']', $input->attr('name'));
                self::assertSame('', (string) $input->attr('value'), 'No price of its own: the field is empty, never 0.');
            }
        }
    }

    public function testAPriceIsSavedAndAnEmptiedCellGoesBackToNoPrice(): void
    {
        $group = $this->factory->customerGroup();
        [$product, $pse] = $this->productWithACombination();
        $currency = $this->defaultCurrency();

        $this->save($product, $currency, [$pse->getId() => [$group->getId() => ['price' => '19,5', 'promo_price' => '']]]);

        $row = $this->row($group, $pse, $currency);
        self::assertNotNull($row);
        self::assertSame(19.5, (float) $row->getPrice());
        self::assertNull($row->getPromoPrice(), 'An empty promo price is NULL, never 0.');

        $this->save($product, $currency, [$pse->getId() => [$group->getId() => ['price' => '19.5', 'promo_price' => '15']]]);
        self::assertSame(15.0, (float) $this->row($group, $pse, $currency)?->getPromoPrice());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertSame('19.5', $crawler->filter('[data-testid="group-prices-price-'.$pse->getId().'-'.$group->getId().'"]')->attr('value'));

        $this->save($product, $currency, [$pse->getId() => [$group->getId() => ['price' => '', 'promo_price' => '15']]]);
        $row = $this->row($group, $pse, $currency);
        self::assertNull($row?->getPrice(), 'An emptied price is NULL, the promo price is kept.');
        self::assertSame(15.0, (float) $row->getPromoPrice());

        $this->save($product, $currency, [$pse->getId() => [$group->getId() => ['price' => '', 'promo_price' => '']]]);
        self::assertNull($this->row($group, $pse, $currency), 'A cell with nothing in it has no row.');
    }

    public function testTheCombinationOfAnotherProductIsNotWritten(): void
    {
        $group = $this->factory->customerGroup();
        [$product, $pse] = $this->productWithACombination();
        [, $foreignPse] = $this->productWithACombination();
        $currency = $this->defaultCurrency();

        $this->save($product, $currency, [
            $pse->getId() => [$group->getId() => ['price' => '10', 'promo_price' => '']],
            $foreignPse->getId() => [$group->getId() => ['price' => '99', 'promo_price' => '']],
        ]);

        self::assertNotNull($this->row($group, $pse, $currency));
        self::assertNull($this->row($group, $foreignPse, $currency), 'A combination that is not the product\'s is left alone.');

        $this->client->followRedirect();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('some combinations do not belong to this product', $page);
        self::assertStringNotContainsString('amounts must be numbers', $page, 'The refusal does not blame the amounts.');
    }

    public function testAnAmountThatIsNotANumberIsRefusedAndTheOthersAreSaved(): void
    {
        $group = $this->factory->customerGroup();
        [$product, $pse] = $this->productWithACombination();
        [, $otherPse] = [null, $this->secondCombination($product)];
        $currency = $this->defaultCurrency();

        $this->save($product, $currency, [
            $pse->getId() => [$group->getId() => ['price' => 'abc', 'promo_price' => '']],
            $otherPse->getId() => [$group->getId() => ['price' => '12', 'promo_price' => '']],
        ]);

        self::assertNull($this->row($group, $pse, $currency));
        self::assertSame(12.0, (float) $this->row($group, $otherPse, $currency)?->getPrice());
    }

    public function testANegativeAmountIsRefused(): void
    {
        $group = $this->factory->customerGroup();
        [$product, $pse] = $this->productWithACombination();
        $currency = $this->defaultCurrency();

        $this->save($product, $currency, [$pse->getId() => [$group->getId() => ['price' => '-3', 'promo_price' => '']]]);

        self::assertNull($this->row($group, $pse, $currency));
    }

    public function testAForgedTokenWritesNothing(): void
    {
        $group = $this->factory->customerGroup();
        [$product, $pse] = $this->productWithACombination();
        $currency = $this->defaultCurrency();

        $this->save($product, $currency, [$pse->getId() => [$group->getId() => ['price' => '10', 'promo_price' => '']]], token: 'forged');

        self::assertNull($this->row($group, $pse, $currency));
    }

    public function testBothRightsAreNeededToSaveAndToSeeTheSaveButton(): void
    {
        $group = $this->factory->customerGroup();
        [$product, $pse] = $this->productWithACombination();
        $currency = $this->defaultCurrency();
        $token = $this->sessionToken();

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]]));
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(0, $crawler->filter('[data-testid="group-prices-section"]'), 'The grid needs the right to see the customer groups.');

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE], AdminResources::CUSTOMER_GROUP => [AccessManager::VIEW]]));
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="group-prices-section"]'));
        self::assertCount(0, $crawler->filter('[data-testid="group-prices-submit"]'));

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE], AdminResources::CUSTOMER_GROUP => [AccessManager::VIEW]]));
        $this->save($product, $currency, [$pse->getId() => [$group->getId() => ['price' => '10', 'promo_price' => '']]], token: $token, expectedStatus: 403);
        self::assertNull($this->row($group, $pse, $currency), 'Without the right to update the groups nothing is written.');

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW], AdminResources::CUSTOMER_GROUP => [AccessManager::VIEW, AccessManager::UPDATE]]));
        $this->save($product, $currency, [$pse->getId() => [$group->getId() => ['price' => '10', 'promo_price' => '']]], token: $token, expectedStatus: 403);
        self::assertNull($this->row($group, $pse, $currency), 'Without the right to update the product nothing is written.');

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE], AdminResources::CUSTOMER_GROUP => [AccessManager::VIEW, AccessManager::UPDATE]]));
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="group-prices-submit"]'));
    }

    public function testAnAdministratorRestrictedToASaleUnitCannotPriceTheGroups(): void
    {
        $group = $this->factory->customerGroup();
        [$product, $pse] = $this->productWithACombination();
        $currency = $this->defaultCurrency();
        $token = $this->sessionToken();

        $this->injector->setAdmin(SaleUnitRestrictedAdmin::create($this->factory, $this->getService(EventDispatcherInterface::class), $this->getPropelConnection(), [
            AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE],
            AdminResources::CUSTOMER_GROUP => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="group-prices-section"]'), 'It may read the grid.');
        self::assertCount(0, $crawler->filter('[data-testid="group-prices-submit"]'), 'The button is not offered to it.');

        $this->save($product, $currency, [$pse->getId() => [$group->getId() => ['price' => '10', 'promo_price' => '']]], token: $token, expectedStatus: 403);
        self::assertNull($this->row($group, $pse, $currency), 'What holds for every sale unit is not written by a restricted administrator.');
    }

    public function testAnotherCurrencyShowsTheDefaultCurrencyPriceConverted(): void
    {
        $group = $this->factory->customerGroup();
        [$product, $pse] = $this->productWithACombination();
        $default = $this->defaultCurrency();
        $other = $this->factory->currency(['code' => 'XTS', 'symbol' => 'X', 'rate' => (float) $default->getRate() * 2]);

        $this->save($product, $default, [$pse->getId() => [$group->getId() => ['price' => '10', 'promo_price' => '8']]]);

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId().'&currency_id='.$other->getId());
        $price = $crawler->filter('[data-testid="group-prices-price-'.$pse->getId().'-'.$group->getId().'"]');
        self::assertSame('', (string) $price->attr('value'));
        self::assertSame('20', $price->attr('placeholder'));
        self::assertSame('16', $crawler->filter('[data-testid="group-prices-promo_price-'.$pse->getId().'-'.$group->getId().'"]')->attr('placeholder'));
        self::assertStringContainsString('Converted from '.$default->getCode(), $crawler->filter('[data-testid="group-prices-price-source-'.$pse->getId().'-'.$group->getId().'"]')->text());
    }

    public function testAPriceTypedInASecondaryCurrencyIsKeptApartFromTheDefaultOne(): void
    {
        $group = $this->factory->customerGroup();
        [$product, $pse] = $this->productWithACombination();
        $default = $this->defaultCurrency();
        $other = $this->factory->currency(['code' => 'XTS', 'symbol' => 'X', 'rate' => (float) $default->getRate() * 2]);

        $this->save($product, $default, [$pse->getId() => [$group->getId() => ['price' => '10', 'promo_price' => '']]]);
        $this->save($product, $other, [$pse->getId() => [$group->getId() => ['price' => '25', 'promo_price' => '']]]);

        self::assertSame(10.0, (float) $this->row($group, $pse, $default)?->getPrice());
        $typed = $this->row($group, $pse, $other);
        self::assertSame(25.0, (float) $typed?->getPrice());
        self::assertFalse((bool) $typed->getFromDefaultCurrency(), 'A price typed in the currency is not a converted one.');

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId().'&currency_id='.$other->getId());
        self::assertSame('25', $crawler->filter('[data-testid="group-prices-price-'.$pse->getId().'-'.$group->getId().'"]')->attr('value'));
    }

    public function testTheNumberOfQueriesOfTheGridDoesNotGrowWithItsCells(): void
    {
        $this->factory->customerGroup();
        [$product] = $this->productWithACombination();
        $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        $few = $this->priceReads($product);

        for ($i = 0; $i < 4; ++$i) {
            $this->factory->customerGroup();
            $this->secondCombination($product);
        }

        self::assertSame($few, $this->priceReads($product), 'The group prices are read in one statement, whatever the number of cells.');
        self::assertSame(1, $this->priceReads($product));
    }

    private function priceReads(Product $product): int
    {
        $statements = $this->recordSqlQueries(function () use ($product): void {
            $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        });

        return \count(array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/FROM\s+`?customer_group_product_price`?/i', $sql)));
    }

    /**
     * @param array<int, array<int, array{price: string, promo_price: string}>> $cells
     */
    private function save(Product $product, Currency $currency, array $cells, ?string $token = null, int $expectedStatus = 302): void
    {
        $this->client->request('POST', self::SAVE_URL, [
            '_token' => $token ?? $this->sessionToken(),
            'product_id' => $product->getId(),
            'currency_id' => $currency->getId(),
            'group_price' => $cells,
        ]);

        self::assertSame($expectedStatus, $this->client->getResponse()->getStatusCode());
    }

    private function row(CustomerGroup $group, ProductSaleElements $pse, Currency $currency): ?CustomerGroupProductPrice
    {
        return CustomerGroupProductPriceQuery::create()
            ->filterByCustomerGroupId($group->getId())
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($currency->getId())
            ->findOne($this->getPropelConnection());
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function restrictedAdmin(array $grants): \Thelia\Model\Admin
    {
        $admin = $this->factory->restrictedAdmin($grants);
        $admin->eraseCredentials();

        return $admin;
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithACombination(): array
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->defaultCurrency(),
            ['basePrice' => 25.0, 'baseQuantity' => 7],
        );
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($pse);

        $attribute = $this->factory->attribute(['title' => 'Size']);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($attribute, ['title' => 'Large']));

        return [$product, $pse];
    }

    private function secondCombination(Product $product): ProductSaleElements
    {
        $pse = new ProductSaleElements();
        $pse->setProductId($product->getId());
        $pse->setRef('second-'.uniqid());
        $pse->setQuantity(1);
        $pse->save($this->getPropelConnection());

        return $pse;
    }

    private function defaultCurrency(): Currency
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        self::assertNotNull($currency);

        return $currency;
    }

    private function sessionToken(): string
    {
        $html = (string) $this->client->request('GET', '/admin/configuration/sale-units')->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }
}
