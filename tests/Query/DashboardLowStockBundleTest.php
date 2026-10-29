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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\ProductRepository;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Model\Currency;
use Thelia\Test\IntegrationTestCase;

/**
 * The combination of a bundle holds no stock of its own: its 0 is not a shortage, and the
 * low stock panel never lists it.
 */
final class DashboardLowStockBundleTest extends IntegrationTestCase
{
    public function testTheCombinationOfABundleIsNotListedAsLowStock(): void
    {
        $factory = $this->createFixtureFactory();
        $category = $factory->category();
        $taxRule = $factory->taxRule();
        $currency = Currency::getDefaultCurrency();
        $simple = $factory->product($category, $taxRule, $currency, ['baseQuantity' => 0, 'title' => 'Simple']);
        $bundle = $factory->product($category, $taxRule, $currency, ['baseQuantity' => 0, 'title' => 'Bundle']);
        $this->getService(BundleCompositionService::class)->setBundleSettings((int) $bundle->getId(), true);

        $listed = [];
        $queries = QueryCounter::count(static function () use (&$listed): void {
            foreach ((new ProductRepository())->findLowStock(1000, 1000, 'en_US') as $saleElements) {
                $listed[] = (int) $saleElements->getProductId();
            }
        });

        self::assertContains((int) $simple->getId(), $listed);
        self::assertNotContains((int) $bundle->getId(), $listed);
        self::assertSame(1, $queries, 'Still one joined query.');
    }
}
