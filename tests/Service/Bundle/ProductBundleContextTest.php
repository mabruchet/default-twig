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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Bundle;

use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use BackOfficeDefaultTwigBundle\Service\Bundle\ProductBundleContext;
use BackOfficeDefaultTwigBundle\Service\Bundle\UnsellableBundleCounter;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductPricingProvider;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\Service\Product\CombinationLabeler;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Domain\Catalog\Bundle\BundleCatalogState;
use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ProductQuery;
use Thelia\Test\IntegrationTestCase;
use Thelia\Tests\Integration\Domain\Catalog\Bundle\BuildsBundles;
use Thelia\Tools\TokenProvider;

/**
 * The context of the Composition tab and the counter of the dashboard read a whole bundle with
 * a number of queries that does not grow with its components, and without the core's bundles
 * they say nothing.
 */
final class ProductBundleContextTest extends IntegrationTestCase
{
    use BuildsBundles;

    public function testTheSummaryOfTheExampleBundleIsTheOneOfThePlan(): void
    {
        $factory = $this->createFixtureFactory();
        $composition = $this->getService(BundleCompositionService::class);
        $example = $this->exampleBundle($factory, $composition);
        $composition->updateItem((int) $example['lock']->getId(), (int) $example['lock']->getProductSaleElementsId(), '2', '2', '3', false);

        $context = $this->context()->build(ProductQuery::create()->findPk($example['bundle']->getId()), 'en_US');

        self::assertTrue($context['bundle_is']);
        self::assertCount(3, $context['bundle_items']);
        // 500 + 2 x 30 + 2 x 20 = 600, 10 % off: 540. Stock 7, 5 and 10 allow min(7, 2, 5) = 2 bundles.
        self::assertStringContainsString('600.00', (string) $context['bundle_summary']['full_price']);
        self::assertStringContainsString('540.00', (string) $context['bundle_summary']['price']);
        self::assertSame('10', $context['bundle_summary']['discount_percent']);
        self::assertSame(2, $context['bundle_summary']['availability']);
        self::assertSame('sellable', $context['bundle_summary']['state']);
    }

    public function testAnOptionalComponentTakenOfflineNeverBlocksTheBundleWhateverItsMinimum(): void
    {
        $factory = $this->createFixtureFactory();
        $composition = $this->getService(BundleCompositionService::class);
        $example = $this->exampleBundle($factory, $composition);
        // Optional, with at least 2 of them wanted; taken offline, the core leaves it out of the default composition.
        $composition->updateItem((int) $example['lock']->getId(), (int) $example['lock']->getProductSaleElementsId(), '2', '2', '3', false);
        $example['lockProduct']->setVisible(0)->save();

        $summary = $this->context()->build(ProductQuery::create()->findPk($example['bundle']->getId()), 'en_US')['bundle_summary'];

        self::assertSame('sellable', $summary['state']);
        self::assertSame(2, $summary['availability'], 'The same number as the core: the offline option is not counted.');
    }

    public function testARequiredComponentTakenOfflineBlocksTheBundle(): void
    {
        $factory = $this->createFixtureFactory();
        $composition = $this->getService(BundleCompositionService::class);
        $example = $this->exampleBundle($factory, $composition);
        $example['helmetProduct']->setVisible(0)->save();

        $summary = $this->context()->build(ProductQuery::create()->findPk($example['bundle']->getId()), 'en_US')['bundle_summary'];

        self::assertSame('component_unavailable', $summary['state']);
    }

    public function testTheQueriesDoNotGrowWithTheNumberOfComponents(): void
    {
        $factory = $this->createFixtureFactory();
        $composition = $this->getService(BundleCompositionService::class);
        $small = $this->bundleProduct($factory, $composition);
        $large = $this->bundleProduct($factory, $composition);
        $composition->addItem((int) $small->getId(), (int) $this->component($factory, '10', 5)->getDefaultSaleElements()->getId());
        for ($i = 0; $i < 6; ++$i) {
            $composition->addItem((int) $large->getId(), (int) $this->component($factory, '10', 5)->getDefaultSaleElements()->getId());
        }
        $context = $this->context();

        $one = QueryCounter::count(static fn () => $context->build(ProductQuery::create()->findPk($small->getId()), 'en_US'));
        $many = QueryCounter::count(static fn () => $context->build(ProductQuery::create()->findPk($large->getId()), 'en_US'));

        self::assertSame($one, $many, 'One component or seven, the same number of queries.');
    }

    public function testTheCounterReadsEveryBundleInAFixedNumberOfQueries(): void
    {
        $factory = $this->createFixtureFactory();
        $composition = $this->getService(BundleCompositionService::class);
        $counter = new UnsellableBundleCounter($this->core());

        $this->soldOutBundle($factory, $composition);
        $one = QueryCounter::count(static fn () => $counter->count());
        $before = $counter->count();
        for ($i = 0; $i < 4; ++$i) {
            $this->soldOutBundle($factory, $composition);
        }
        $many = QueryCounter::count(static fn () => $counter->count());

        self::assertSame($before + 4, $counter->count());
        self::assertSame($one, $many);
    }

    public function testTheCounterAsksNothingOfTheDatabaseWhenTheShopSellsNoBundle(): void
    {
        $factory = $this->createFixtureFactory();
        $this->soldOutBundle($factory, $this->getService(BundleCompositionService::class));
        ConfigQuery::write(BundleCatalogState::CONFIG_KEY, '0');
        $counter = new UnsellableBundleCounter($this->core());

        self::assertSame(0, QueryCounter::count(static fn () => $counter->count()));
        self::assertSame(0, $counter->count());
    }

    public function testTheCounterIgnoresAnOfflineBundleAndOneThatCanBeOrdered(): void
    {
        $factory = $this->createFixtureFactory();
        $composition = $this->getService(BundleCompositionService::class);
        $counter = new UnsellableBundleCounter($this->core());
        $before = $counter->count();

        $this->exampleBundle($factory, $composition);
        self::assertSame($before, $counter->count(), 'A bundle with stock.');

        $offline = $this->soldOutBundle($factory, $composition);
        $offline->setVisible(0)->save();
        self::assertSame($before, $counter->count(), 'A bundle that is offline is not on sale anyway.');
    }

    public function testWithoutTheBundlesOfTheCoreTheContextIsNeutralAndTheCounterIsZero(): void
    {
        $core = new BundleCore(new ServiceLocator([]), 'Not\\A\\Thelia\\Class');
        $factory = $this->createFixtureFactory();
        $bundle = $this->bundleProduct($factory, $this->getService(BundleCompositionService::class));

        $context = $this->context($core)->build(ProductQuery::create()->findPk($bundle->getId()), 'en_US');

        self::assertFalse($core->isAvailable());
        self::assertFalse($context['bundle_available']);
        self::assertFalse($context['bundle_is'], 'Even a product that is a bundle in the database.');
        self::assertSame([], $context['bundle_items']);
        self::assertNull($context['bundle_summary']);
        self::assertSame(0, (new UnsellableBundleCounter($core))->count());
    }

    private function soldOutBundle(\Thelia\Test\FixtureFactory $factory, BundleCompositionService $composition): \Thelia\Model\Product
    {
        $bundle = $this->bundleProduct($factory, $composition);
        $composition->addItem((int) $bundle->getId(), (int) $this->component($factory, '10', 0)->getDefaultSaleElements()->getId());

        return $bundle;
    }

    private function core(): BundleCore
    {
        return $this->getService(BundleCore::class);
    }

    private function context(?BundleCore $core = null): ProductBundleContext
    {
        return new ProductBundleContext(
            $core ?? $this->core(),
            new CombinationLabeler(),
            new ProductPricingProvider(),
            new MoneyFormatter(),
            $this->getService(UrlGeneratorInterface::class),
            $this->getService(TokenProvider::class),
        );
    }
}
