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
use Thelia\Core\Event\Administrator\AdministratorSaleUnitsEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductBundleItemQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\SaleUnit;
use Thelia\Model\SaleUnitQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Integration\Domain\Catalog\Bundle\BuildsBundles;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * A bundle is a global piece of the catalogue and its composition sets its public price: an
 * administrator restricted to some sale units changes neither the composition nor the bundle
 * settings, as it changes no price of a combination.
 */
final class ProductBundleRestrictedAdminTest extends WebIntegrationTestCase
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
            self::markTestSkipped('The core does not carry the product bundles.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->composition = $this->getService(BundleCompositionService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testARestrictedAdministratorChangesNeitherTheCompositionNorItsOrder(): void
    {
        $example = $this->exampleBundle($this->factory, $this->composition);
        $lockId = (int) $example['lock']->getId();
        $before = $this->snapshot((int) $example['bundle']->getId());
        $this->actAsRestrictedAdministrator();
        $token = $this->token($example['bundle']);

        $this->client->request('POST', '/admin/products/bundle/items/add', ['_token' => $token, 'product_id' => $example['bundle']->getId(), 'product_sale_elements_id' => $example['helmetProduct']->getDefaultSaleElements()->getId(), 'quantity' => '1']);
        self::assertResponseStatusCodeSame(403, 'add');
        $this->client->request('POST', '/admin/products/bundle/items/update', ['_token' => $token, 'item_id' => $lockId, 'quantity' => '9']);
        self::assertResponseStatusCodeSame(403, 'update');
        $this->client->request('POST', '/admin/products/bundle/items/delete', ['_token' => $token, 'item_id' => $lockId]);
        self::assertResponseStatusCodeSame(403, 'delete');
        $this->client->request('POST', '/admin/products/bundle/items/position?item_id='.$lockId.'&position=1', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403, 'position');

        self::assertSame($before, $this->snapshot((int) $example['bundle']->getId()), 'The composition did not move.');
    }

    public function testARestrictedAdministratorDoesNotChangeTheBundleSettingsOfTheSheet(): void
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), \Thelia\Model\Currency::getDefaultCurrency(), ['title' => 'Simple product']);
        $this->actAsRestrictedAdministrator();

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());
        $form = $crawler->filter('form[data-testid="product-edit-form"]')->form();
        $form['thelia_product_modification[is_bundle]']->tick();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) ProductQuery::create()->findPk($product->getId())?->getIsBundle(), 'The product did not become a bundle.');
    }

    public function testARestrictedAdministratorStillSavesTheRestOfTheSheetOfABundle(): void
    {
        $bundle = $this->bundleProduct($this->factory, $this->composition, \Thelia\Domain\Catalog\Bundle\BundlePriceMode::Computed, '15', \Thelia\Domain\Catalog\Bundle\BundleDiscountType::Amount);
        $bundle->setLocale('en_US')->setTitle('A bundle')->save();
        $this->actAsRestrictedAdministrator();

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$bundle->getId());
        $form = $crawler->filter('form[data-testid="product-edit-form"]')->form();
        $form['thelia_product_modification[title]']->setValue('Renamed bundle');
        $this->client->submit($form);

        self::assertResponseRedirects();
        $stored = ProductQuery::create()->findPk($bundle->getId());
        self::assertSame('Renamed bundle', $stored?->setLocale('en_US')->getTitle());
        self::assertSame(1, (int) $stored->getIsBundle());
        self::assertSame('computed', $stored->getBundlePriceMode());
        self::assertSame(15.0, (float) $stored->getBundleDiscount());
        self::assertSame('amount', $stored->getBundleDiscountType());
    }

    /**
     * @return list<array{int, string, string|null, string|null, int}>
     */
    private function snapshot(int $bundleId): array
    {
        $rows = [];
        foreach (ProductBundleItemQuery::create()->filterByBundleProductId($bundleId)->orderById()->find() as $item) {
            $rows[] = [(int) $item->getId(), (string) $item->getQuantity(), $item->getMinQuantity(), $item->getMaxQuantity(), (int) $item->getPosition()];
        }

        return $rows;
    }

    private function actAsRestrictedAdministrator(): void
    {
        // The suite may run on a shop that already declares sale units.
        SaleUnitQuery::create()->find($this->getPropelConnection())->delete($this->getPropelConnection());
        $unit = (new SaleUnit())->setCode('north')->setActive(true)->setPosition(1)->setLocale('en_US')->setTitle('north title');
        $unit->save($this->getPropelConnection());

        $admin = $this->factory->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $admin->eraseCredentials();
        $this->getService(EventDispatcherInterface::class)->dispatch(
            new AdministratorSaleUnitsEvent((int) $admin->getId(), [(int) $unit->getId()]),
            TheliaEvents::ADMINISTRATOR_UPDATE_SALE_UNITS,
        );
        $this->injector->setAdmin($admin);
    }

    private function token(Product $product): string
    {
        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());

        return (string) $crawler->filter('meta[name="bo-token"]')->attr('content');
    }
}
