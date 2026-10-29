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

use BackOfficeDefaultTwigBundle\BackOfficeDefaultTwigBundle;
use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Model\ProductBundleItem;
use Thelia\Model\ProductBundleItemQuery;
use Thelia\Model\ProductQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Integration\Domain\Catalog\Bundle\BuildsBundles;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The product sheet of a bundle: the pricing fields of the general tab, the Composition tab
 * (components, summary, pickers) and every change of the composition, which goes through the
 * events of the core.
 */
final class ProductBundleBackOfficeTest extends WebIntegrationTestCase
{
    use BuildsBundles;

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private BundleCompositionService $composition;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!BundleCore::present()) {
            self::markTestSkipped('The core does not carry the product bundles: ProductBundleWithoutCoreTest covers it.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->composition = $this->getService(BundleCompositionService::class);

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

    public function testTheCompositionTabIsHiddenOnASimpleProduct(): void
    {
        $product = $this->simpleProduct();

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId().'&current_tab=bundle');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="product-tab-bundle"]'));
        self::assertCount(0, $crawler->filter('#tab-bundle'));
        self::assertCount(1, $crawler->filter('#tab-general.show.active'), 'A tab that does not exist falls back on the general one.');
        self::assertCount(1, $crawler->filter('[data-testid="product-edit-is-bundle"]'), 'The box that makes the product a bundle is offered.');
    }

    public function testTheCompositionTabListsTheComponentsAndSumsUpTheBundle(): void
    {
        $example = $this->exampleBundleWithTheLockInTheDefaultComposition();

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$example['bundle']->getId().'&current_tab=bundle');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="product-tab-bundle"]'));
        self::assertCount(1, $crawler->filter('#tab-bundle.show.active'));
        self::assertCount(3, $crawler->filter('[data-testid="datatable-product-bundle-items-row"]'), 'Bike, helmet and lock.');

        // The plan's figures: 500 + 2 x 30 + 2 x 20 = 600, less 10 %, 540; stock 7, 5 and 10 allow 2.
        self::assertStringContainsString('600.00', $crawler->filter('[data-testid="product-bundle-full-price"] del')->text());
        self::assertStringContainsString('540.00', $crawler->filter('[data-testid="product-bundle-price"]')->text());
        self::assertStringContainsString('10 %', $crawler->filter('[data-testid="product-bundle-discount"]')->text());
        self::assertSame('2', trim(explode(' ', trim($crawler->filter('[data-testid="product-bundle-availability"]')->text()))[0]));
        self::assertCount(1, $crawler->filter('[data-testid="product-bundle-state-sellable"]'));
        self::assertCount(0, $crawler->filter('[data-testid="product-bundle-deleted"]'));
    }

    public function testTheCombinationsTabWarnsThatTheStockOfABundleIsIgnored(): void
    {
        $example = $this->exampleBundleWithTheLockInTheDefaultComposition();

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$example['bundle']->getId());
        self::assertCount(1, $crawler->filter('[data-testid="product-bundle-stock-ignored"]'));

        $simple = $this->client->request('GET', '/admin/products/update?product_id='.$this->simpleProduct()->getId());
        self::assertCount(0, $simple->filter('[data-testid="product-bundle-stock-ignored"]'));
    }

    public function testAnOrphanedComponentIsShownAsDeletedAndTheBundleCannotBeSold(): void
    {
        $example = $this->exampleBundleWithTheLockInTheDefaultComposition();
        $example['helmetProduct']->getDefaultSaleElements()->delete();

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$example['bundle']->getId().'&current_tab=bundle');

        self::assertResponseIsSuccessful();
        self::assertSame('Deleted component', trim($crawler->filter('[data-testid="product-bundle-deleted"]')->text()));
        self::assertCount(1, $crawler->filter('[data-testid="product-bundle-state-component-unavailable"]'));
        self::assertCount(0, $crawler->filter('[data-testid="product-bundle-state-sellable"]'));
    }

    public function testAComponentIsAdded(): void
    {
        $bundle = $this->emptyBundle();
        $component = $this->component($this->factory, '30', 5);
        $saleElementsId = (int) $component->getDefaultSaleElements()->getId();

        $this->client->request('POST', '/admin/products/bundle/items/add', [
            '_token' => $this->token($bundle),
            'product_id' => $bundle->getId(),
            'product_sale_elements_id' => $saleElementsId,
            'quantity' => '2',
            'min_quantity' => '',
            'max_quantity' => '',
            'required' => '1',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('current_tab=bundle', (string) $this->client->getResponse()->headers->get('Location'));
        $item = ProductBundleItemQuery::create()->filterByBundleProductId($bundle->getId())->findOne();
        self::assertNotNull($item);
        self::assertSame($saleElementsId, (int) $item->getProductSaleElementsId());
        self::assertSame(2.0, (float) $item->getQuantity());
        self::assertSame(1, (int) $item->getRequired());
        self::assertNull($item->getMinQuantity());
    }

    public function testAnOptionalComponentKeepsItsBounds(): void
    {
        $bundle = $this->emptyBundle();
        $component = $this->component($this->factory, '20', 10);

        $this->client->request('POST', '/admin/products/bundle/items/add', [
            '_token' => $this->token($bundle),
            'product_id' => $bundle->getId(),
            'product_sale_elements_id' => $component->getDefaultSaleElements()->getId(),
            'quantity' => '1',
            'min_quantity' => '0',
            'max_quantity' => '3',
            'required' => '0',
        ]);

        $item = ProductBundleItemQuery::create()->filterByBundleProductId($bundle->getId())->findOne();
        self::assertNotNull($item);
        self::assertSame(0, (int) $item->getRequired());
        self::assertSame(0.0, (float) $item->getMinQuantity());
        self::assertSame(3.0, (float) $item->getMaxQuantity());
    }

    public function testAComponentIsModified(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);
        $bundle = $example['bundle'];

        $this->client->request('POST', '/admin/products/bundle/items/update', [
            '_token' => $this->token($bundle),
            'item_id' => $example['helmet']->getId(),
            'quantity' => '3',
            'min_quantity' => '',
            'max_quantity' => '',
            'required' => '1',
        ]);

        self::assertResponseRedirects();
        $helmet = ProductBundleItemQuery::create()->findPk($example['helmet']->getId());
        self::assertSame(3.0, (float) $helmet->getQuantity());
        self::assertSame((int) $example['helmetProduct']->getDefaultSaleElements()->getId(), (int) $helmet->getProductSaleElementsId(), 'The component itself is not changed.');
    }

    public function testAComponentIsRemoved(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);

        $this->client->request('POST', '/admin/products/bundle/items/delete', [
            '_token' => $this->token($example['bundle']),
            'item_id' => $example['lock']->getId(),
        ]);

        self::assertResponseRedirects();
        self::assertNull(ProductBundleItemQuery::create()->findPk($example['lock']->getId()));
        self::assertSame(2, ProductBundleItemQuery::create()->filterByBundleProductId($example['bundle']->getId())->count());
    }

    public function testDroppingAComponentOnAnotherReordersTheWholeList(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);
        $bundleId = (int) $example['bundle']->getId();
        // The lines are bike 1, helmet 2, lock 3: the lock is dropped on the bike.
        $this->setPositions($example, [1, 2, 3]);

        $this->client->request('POST', '/admin/products/bundle/items/position?item_id='.$example['lock']->getId().'&position=1', [
            '_token' => $this->token($example['bundle']),
        ]);

        self::assertResponseRedirects();
        self::assertSame(
            [(int) $example['lock']->getId() => 1, (int) $example['bike']->getId() => 2, (int) $example['helmet']->getId() => 3],
            $this->positions($bundleId),
        );
    }

    public function testDroppingAComponentDownwardPutsItBehindTheOneItIsDroppedOn(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);
        $this->setPositions($example, [1, 2, 3]);

        $this->client->request('POST', '/admin/products/bundle/items/position?item_id='.$example['bike']->getId().'&position=2', [
            '_token' => $this->token($example['bundle']),
        ]);

        self::assertSame(
            [(int) $example['helmet']->getId() => 1, (int) $example['bike']->getId() => 2, (int) $example['lock']->getId() => 3],
            $this->positions((int) $example['bundle']->getId()),
        );
    }

    public function testABundleIsRefusedAsAComponentWithTheMessageOfTheCore(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);
        $other = $this->emptyBundle();

        $this->client->request('POST', '/admin/products/bundle/items/add', [
            '_token' => $this->token($other),
            'product_id' => $other->getId(),
            'product_sale_elements_id' => $example['bundle']->getDefaultSaleElements()->getId(),
            'quantity' => '1',
            'required' => '1',
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, ProductBundleItemQuery::create()->filterByBundleProductId($other->getId())->count());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('a bundle cannot hold another bundle', $crawler->filter('body')->text());
    }

    public function testWithoutTheTokenNothingChanges(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);

        $this->client->request('POST', '/admin/products/bundle/items/delete', ['item_id' => $example['lock']->getId()]);
        $this->client->request('POST', '/admin/products/bundle/items/delete?_token='.$this->token($example['bundle']), ['item_id' => $example['lock']->getId()]);

        self::assertNotNull(ProductBundleItemQuery::create()->findPk($example['lock']->getId()), 'A token in the query does not count.');
    }

    public function testAnAdministratorWhoCannotUpdateProductsChangesNothing(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);
        $token = $this->token($example['bundle']);
        $admin = $this->factory->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->client->request('POST', '/admin/products/bundle/items/delete', ['_token' => $token, 'item_id' => $example['lock']->getId()]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/products/bundle/items/position?item_id='.$example['lock']->getId().'&position=1', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/products/bundle/items/add', ['_token' => $token, 'product_id' => $example['bundle']->getId(), 'product_sale_elements_id' => 1]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(3, ProductBundleItemQuery::create()->filterByBundleProductId($example['bundle']->getId())->count());
    }

    public function testTheProductsOfACategoryAreOfferedWithoutBundlesAndWithoutTheProductItself(): void
    {
        $category = $this->factory->category();
        $taxRule = $this->factory->taxRule();
        $currency = Currency::getDefaultCurrency();
        $simple = $this->factory->product($category, $taxRule, $currency);
        $bundle = $this->composition->setBundleSettings((int) $this->factory->product($category, $taxRule, $currency)->getId(), true);
        $otherBundle = $this->composition->setBundleSettings((int) $this->factory->product($category, $taxRule, $currency)->getId(), true);

        $this->client->request('GET', \sprintf('/admin/product/%d/available-accessories/%d.json?bundle_component=1', $bundle->getId(), $category->getId()));

        self::assertResponseIsSuccessful();
        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR), 'id');
        self::assertSame([(int) $simple->getId()], $ids, 'Neither the bundle being edited nor another bundle.');
        self::assertNotContains((int) $otherBundle->getId(), $ids);
    }

    public function testTheProductsEndpointStillOffersBundlesToTheOtherListsOfTheSheet(): void
    {
        $category = $this->factory->category();
        $taxRule = $this->factory->taxRule();
        $currency = Currency::getDefaultCurrency();
        $current = $this->factory->product($category, $taxRule, $currency);
        $bundle = $this->composition->setBundleSettings((int) $this->factory->product($category, $taxRule, $currency)->getId(), true);

        $this->client->request('GET', \sprintf('/admin/product/%d/available-accessories/%d.json', $current->getId(), $category->getId()));

        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR), 'id');
        self::assertContains((int) $bundle->getId(), $ids);
    }

    public function testTheCombinationsOfAProductAreListedWithoutThoseAlreadyInTheBundleAndNoneForABundle(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);
        $bundleId = (int) $example['bundle']->getId();
        $another = $this->component($this->factory, '12', 3);

        $this->client->request('GET', \sprintf('/admin/products/bundle/%d/component/%d/combinations.json', $bundleId, $another->getId()));
        $offered = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([(int) $another->getDefaultSaleElements()->getId()], array_column($offered, 'id'));
        self::assertSame('default', $offered[0]['title']);

        $this->client->request('GET', \sprintf('/admin/products/bundle/%d/component/%d/combinations.json', $bundleId, $example['bikeProduct']->getId()));
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR), 'The only combination is already a component.');

        $this->client->request('GET', \sprintf('/admin/products/bundle/%d/component/%d/combinations.json', $bundleId, $bundleId));
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR), 'The bundle itself.');

        $another = $this->emptyBundle();
        $this->client->request('GET', \sprintf('/admin/products/bundle/%d/component/%d/combinations.json', $bundleId, $another->getId()));
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR), 'Another bundle.');
    }

    public function testTheGeneralTabMakesAProductABundleAndSavesItsPricing(): void
    {
        $product = $this->simpleProduct();

        $form = $this->generalForm($product);
        $form['thelia_product_modification[is_bundle]']->tick();
        $form['thelia_product_modification[bundle_price_mode]']->select('computed');
        $form['thelia_product_modification[bundle_discount]']->setValue('15');
        $form['thelia_product_modification[bundle_discount_type]']->select('amount');
        $this->client->submit($form);

        self::assertResponseRedirects();
        $stored = ProductQuery::create()->findPk($product->getId());
        self::assertSame(1, (int) $stored->getIsBundle());
        self::assertSame('computed', $stored->getBundlePriceMode());
        self::assertSame(15.0, (float) $stored->getBundleDiscount());
        self::assertSame('amount', $stored->getBundleDiscountType());

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="product-tab-bundle"]'), 'The tab appears once the product is a bundle.');
    }

    public function testAProductThatIsNotABundleKeepsItsStoredPricingWhenSaved(): void
    {
        $bundle = $this->composition->setBundleSettings((int) $this->simpleProduct()->getId(), true, \Thelia\Domain\Catalog\Bundle\BundlePriceMode::Computed, '15', \Thelia\Domain\Catalog\Bundle\BundleDiscountType::Amount);
        $this->composition->setBundleSettings((int) $bundle->getId(), false);

        $bundle->setLocale('en_US')->setTitle('Was a bundle')->save();

        $form = $this->generalForm($bundle);
        self::assertFalse($form['thelia_product_modification[is_bundle]']->hasValue());
        // The pricing fields are posted all the same (hidden, not disabled): the form must accept that.
        self::assertTrue($form->has('thelia_product_modification[bundle_discount]'));
        $form['thelia_product_modification[title]']->setValue('Renamed product');
        $this->client->submit($form);

        self::assertResponseRedirects();
        $stored = ProductQuery::create()->findPk($bundle->getId());
        self::assertSame('Renamed product', $stored->setLocale('en_US')->getTitle(), 'The save went through.');
        self::assertSame(0, (int) $stored->getIsBundle());
        self::assertSame('computed', $stored->getBundlePriceMode(), 'The fields of a bundle are not sent for a product that is not one: they stay as they are.');
        self::assertSame(15.0, (float) $stored->getBundleDiscount());
    }

    public function testAProductThatIsAComponentCannotBecomeABundleAndTheMessageIsShown(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);
        // The form of the sheet asks for a title, which the fixture does not give.
        $example['bikeProduct']->setLocale('en_US')->setTitle('Bike')->save();

        $form = $this->generalForm($example['bikeProduct']);
        $form['thelia_product_modification[is_bundle]']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(0, (int) ProductQuery::create()->findPk($example['bikeProduct']->getId())->getIsBundle());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('it cannot become a bundle', $crawler->filter('body')->text());
    }

    public function testTheListFiltersTheBundlesAndMarksThem(): void
    {
        $category = $this->factory->category();
        $taxRule = $this->factory->taxRule();
        $currency = Currency::getDefaultCurrency();
        $simple = $this->factory->product($category, $taxRule, $currency, ['ref' => 'SIMPLE-ONE', 'baseQuantity' => 4]);
        $bundle = $this->composition->setBundleSettings((int) $this->factory->product($category, $taxRule, $currency, ['ref' => 'BUNDLE-ONE', 'baseQuantity' => 0])->getId(), true);

        $all = $this->client->request('GET', '/admin/products?category_id='.$category->getId());
        self::assertCount(2, $all->filter('[data-testid="datatable-products-row"]'));
        self::assertCount(1, $all->filter('[data-testid="bo-product-bundle-badge"]'));
        // The stock column is drawn twice (the row and its details on a narrow screen), once per row.
        self::assertGreaterThan(0, $all->filter('[data-testid="datatable-products-row"]:contains("BUNDLE-ONE") [data-testid="bo-product-bundle-stock"]')->count(), 'A bundle shows no stock of its own.');
        self::assertCount(0, $all->filter('[data-testid="datatable-products-row"]:contains("SIMPLE-ONE") [data-testid="bo-product-bundle-stock"]'));

        $bundles = $this->client->request('GET', '/admin/products?category_id='.$category->getId().'&bundle=with');
        self::assertCount(1, $bundles->filter('[data-testid="datatable-products-row"]'));
        self::assertStringContainsString('BUNDLE-ONE', $bundles->filter('[data-testid="datatable-products-row"]')->text());

        $others = $this->client->request('GET', '/admin/products?category_id='.$category->getId().'&bundle=without');
        self::assertCount(1, $others->filter('[data-testid="datatable-products-row"]'));
        self::assertStringContainsString('SIMPLE-ONE', $others->filter('[data-testid="datatable-products-row"]')->text());
        self::assertSame('bundle', $bundles->filter('[data-testid="product-filter-bundle"]')->attr('name'));
        self::assertNotNull($simple);
        self::assertNotNull($bundle);
    }

    public function testTheDashboardCountsTheBundlesThatCannotBeOrdered(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);

        $healthy = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $healthy->filter('[data-testid="dashboard-unsellable-bundles"]'));

        // The helmet runs out of stock: the bundle cannot be ordered any more.
        $helmetSaleElements = $example['helmetProduct']->getDefaultSaleElements();
        $helmetSaleElements->setQuantity(0)->save();

        $crawler = $this->client->request('GET', '/admin');
        self::assertCount(1, $crawler->filter('[data-testid="dashboard-unsellable-bundles"]'));
        self::assertSame('1', trim($crawler->filter('[data-testid="dashboard-unsellable-bundles"] .badge')->text()));
        self::assertStringContainsString('bundle=with', (string) $crawler->filter('[data-testid="dashboard-unsellable-bundles"] a')->attr('href'));
    }

    /**
     * Bike 1 (500), helmet 2 (30) and lock (20) that the default composition holds twice.
     *
     * @return array{bundle: Product, bike: ProductBundleItem, helmet: ProductBundleItem, lock: ProductBundleItem, bikeProduct: Product, helmetProduct: Product, lockProduct: Product}
     */
    private function exampleBundleWithTheLockInTheDefaultComposition(): array
    {
        $example = $this->exampleBundle($this->factory, $this->composition);
        $this->composition->updateItem((int) $example['lock']->getId(), (int) $example['lock']->getProductSaleElementsId(), '2', '2', '3', false);

        return $example;
    }

    private function simpleProduct(): Product
    {
        return $this->factory->product($this->factory->category(), $this->factory->taxRule(), Currency::getDefaultCurrency(), ['title' => 'Simple product']);
    }

    private function emptyBundle(): Product
    {
        return $this->bundleProduct($this->factory, $this->composition);
    }

    private function token(Product $product): string
    {
        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());

        return (string) $crawler->filter('meta[name="bo-token"]')->attr('content');
    }

    private function generalForm(Product $product): \Symfony\Component\DomCrawler\Form
    {
        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());

        return $crawler->filter('form[data-testid="product-edit-form"]')->form();
    }

    /**
     * @param array{bike: ProductBundleItem, helmet: ProductBundleItem, lock: ProductBundleItem} $example
     * @param list<int>                                                                          $positions bike, helmet, lock
     */
    private function setPositions(array $example, array $positions): void
    {
        foreach ([$example['bike'], $example['helmet'], $example['lock']] as $index => $item) {
            $item->setPosition($positions[$index])->save();
        }
    }

    /**
     * @return array<int, int>
     */
    private function positions(int $bundleId): array
    {
        $positions = [];
        foreach (ProductBundleItemQuery::create()->filterByBundleProductId($bundleId)->orderByPosition()->orderById()->find() as $item) {
            $positions[(int) $item->getId()] = (int) $item->getPosition();
        }

        return $positions;
    }
}
