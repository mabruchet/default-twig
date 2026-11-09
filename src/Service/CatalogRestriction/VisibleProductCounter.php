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

namespace BackOfficeDefaultTwigBundle\Service\CatalogRestriction;

use Thelia\Domain\Catalog\Restriction\CatalogRestrictionResolver;
use Thelia\Domain\Catalog\Restriction\CatalogRestrictionRule;
use Thelia\Domain\Customer\Service\CustomerGroupResolver;
use Thelia\Domain\Pricing\CatalogActivityProbe;
use Thelia\Model\Customer;
use Thelia\Model\ProductQuery;

/**
 * How many products the assortment rules leave to a customer group or to a customer: the
 * clause the storefront narrows its queries with ({@see CatalogRestrictionRule}) counted by the
 * database, never a collection loaded. What the groups list of categories closes (customer
 * groups screen) is another mechanism and is not counted here.
 *
 * Every question builds its own resolver: the answer of the core one is remembered for the
 * request, and a rule written a moment ago must be seen.
 */
final readonly class VisibleProductCounter
{
    public function __construct(
        private CatalogActivityProbe $catalogActivityProbe,
        private CustomerGroupResolver $customerGroupResolver,
    ) {
    }

    /**
     * @return array{visible: int, total: int}
     */
    public function forGroup(int $customerGroupId): array
    {
        return $this->count(new CatalogRestrictionRule(new CatalogRestrictionResolver(new GroupViewpointResolver($this->catalogActivityProbe, $customerGroupId), $this->catalogActivityProbe), $this->catalogActivityProbe), null);
    }

    /**
     * @return array{visible: int, total: int}
     */
    public function forCustomer(Customer $customer): array
    {
        return $this->count(new CatalogRestrictionRule(new CatalogRestrictionResolver($this->customerGroupResolver, $this->catalogActivityProbe), $this->catalogActivityProbe), $customer);
    }

    /**
     * @return array{visible: int, total: int}
     */
    private function count(CatalogRestrictionRule $rule, ?Customer $customer): array
    {
        $total = ProductQuery::create()->count();
        $clause = $rule->visibleProductClause('product.id', $customer);

        return [
            'visible' => null === $clause ? $total : ProductQuery::create()->where($clause)->count(),
            'total' => $total,
        ];
    }
}
