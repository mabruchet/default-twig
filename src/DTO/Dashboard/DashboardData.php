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

namespace BackOfficeDefaultTwigBundle\DTO\Dashboard;

use Thelia\Model\Order;
use Thelia\Model\ProductSaleElements;

final readonly class DashboardData
{
    /**
     * @param list<Kpi>                                                                          $kpis
     * @param array{labels: list<string>, revenue: list<float>, orders: list<int>}               $chart
     * @param list<array{id: int, code: string, title: string, color: string, count: int}>       $statusBreakdown
     * @param list<Order>                                                                        $recentOrders
     * @param array<int, float>                                                                  $recentOrderAmounts amount of each recent order, by order id
     * @param list<array{id: int, ref: string, title: string, quantity: int, revenue: float}>    $topProducts
     * @param list<ProductSaleElements>                                                          $lowStockProducts
     * @param list<array{label: string, count: int, href: ?string, icon: string, level: string}> $alerts
     * @param list<array{value: string, label: string, active: bool, url: string}>               $periodOptions
     */
    public function __construct(
        public DateRange $range,
        public array $kpis,
        public array $chart,
        public array $statusBreakdown,
        public array $recentOrders,
        public array $recentOrderAmounts,
        public array $topProducts,
        public array $lowStockProducts,
        public int $lowStockThreshold,
        public array $alerts,
        public array $periodOptions,
        public string $locale,
        public string $currencyCode,
        public bool $showRevenueChart = true,
        public bool $showOrderStatus = true,
        public bool $showRecentOrders = true,
        public bool $showTopProducts = true,
        public bool $showLowStock = true,
        public int $unsellableBundles = 0,
        public string $unsellableBundlesUrl = '',
    ) {
    }
}
