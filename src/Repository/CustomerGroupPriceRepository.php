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

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\CustomerGroupProductPrice;
use Thelia\Model\CustomerGroupProductPriceQuery;
use Thelia\Model\CustomerGroupQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Reads the prices the customer groups keep of their own for a batch of combinations, in
 * one statement, whatever the number of groups and combinations, and the combinations of
 * a product they are written for.
 */
final readonly class CustomerGroupPriceRepository
{
    /**
     * @param list<int> $productSaleElementsIds
     * @param list<int> $currencyIds
     *
     * @return array<int, array<int, array<int, CustomerGroupProductPrice>>> indexed by group id, combination id, then currency id
     */
    public function findPrices(array $productSaleElementsIds, array $currencyIds): array
    {
        if ([] === $productSaleElementsIds || [] === $currencyIds) {
            return [];
        }

        $prices = [];
        $rows = CustomerGroupProductPriceQuery::create()
            ->filterByProductSaleElementsId($productSaleElementsIds, Criteria::IN)
            ->filterByCurrencyId($currencyIds, Criteria::IN)
            ->find();

        foreach ($rows as $row) {
            $prices[(int) $row->getCustomerGroupId()][(int) $row->getProductSaleElementsId()][(int) $row->getCurrencyId()] = $row;
        }

        return $prices;
    }

    /**
     * @return array<int, string> the reference of each combination of the product, by combination id
     */
    public function combinationRefsOfProduct(int $productId): array
    {
        $refs = [];
        foreach (ProductSaleElementsQuery::create()->filterByProductId($productId)->find() as $pse) {
            $refs[(int) $pse->getId()] = (string) $pse->getRef();
        }

        return $refs;
    }

    /**
     * @param list<int> $groupIds
     *
     * @return list<int> the ids among $groupIds that are customer groups
     */
    public function findExistingGroupIds(array $groupIds): array
    {
        if ([] === $groupIds) {
            return [];
        }

        return array_map('intval', CustomerGroupQuery::create()->filterById($groupIds, Criteria::IN)->select('Id')->find()->toArray());
    }
}
