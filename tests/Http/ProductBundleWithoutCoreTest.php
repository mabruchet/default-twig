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

use BackOfficeDefaultTwigBundle\Controller\Catalog\ProductBundleController;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use BackOfficeDefaultTwigBundle\Service\Bundle\BundleItemReorderPlanner;
use BackOfficeDefaultTwigBundle\Service\Product\CombinationLabeler;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * On a core that predates the product bundles the back office is left as it was: no tab, no
 * field, no filter, and no route of the composition that answers.
 *
 * The core of the test shop carries the bundles, so the cases that need the real absence skip
 * themselves there; the controller is also built on a door that reads a class which does not
 * exist, which is what an older core looks like to it.
 */
final class ProductBundleWithoutCoreTest extends WebIntegrationTestCase
{
    private ?AdminSessionInjector $injector = null;

    protected function tearDown(): void
    {
        if (null !== $this->injector) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testEveryRouteOfTheCompositionAnswersNotFound(): void
    {
        $controller = new ProductBundleController(
            $this->getService(AdminFormAction::class),
            $this->getService(AdminAccessChecker::class),
            new BundleCore(new ServiceLocator([]), 'Not\\A\\Thelia\\Class'),
            new BundleItemReorderPlanner(),
            new CombinationLabeler(),
            $this->getService(RestrictedAdminGuard::class),
            $this->getService(EventDispatcherInterface::class),
            $this->getService(UrlGeneratorInterface::class),
        );

        foreach (['add', 'update', 'delete', 'position'] as $action) {
            try {
                $controller->{$action}(new Request());
                self::fail($action.' answered on a core without bundles.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $controller->combinations(1, 2, new Request());
            self::fail('The combinations answered on a core without bundles.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTheSheetOfAProductHasNeitherTheTabNorTheFieldsOnACoreWithoutBundles(): void
    {
        if (BundleCore::present()) {
            self::markTestSkipped('The core carries the product bundles: ProductBundleBackOfficeTest covers them.');
        }

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $factory = new FixtureFactory($this->getPropelConnection());
        $admin = $factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['title' => 'Plain']);

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId().'&current_tab=bundle');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="product-tab-bundle"]'));
        self::assertCount(0, $crawler->filter('[data-testid="product-edit-bundle"]'));

        $list = $this->client->request('GET', '/admin/products?bundle=with');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $list->filter('[data-testid="product-filter-bundle"]'));

        $this->client->request('POST', '/admin/products/bundle/items/add');
        self::assertResponseStatusCodeSame(404);
    }
}
