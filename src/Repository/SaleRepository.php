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
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\Map\SaleCustomerTableMap;
use Thelia\Model\Map\SaleTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Model\Sale;
use Thelia\Model\SaleQuery;

final readonly class SaleRepository
{
    /**
     * How many customers a sale is reserved for, counted in the list query itself.
     * Read it off a row with {@see Sale::getVirtualColumn()}.
     */
    public const TARGETED_CUSTOMERS_COUNT = 'targeted_customers_count';

    public function findById(int $saleId): ?Sale
    {
        return SaleQuery::create()->findPk($saleId);
    }

    /**
     * @return ObjectCollection<int, Sale>
     */
    public function findAllOrderedByStartDate(): ObjectCollection
    {
        /** @var ObjectCollection<int, Sale> $result */
        $result = SaleQuery::create()
            ->orderByStartDate(Criteria::DESC)
            ->find();

        return $result;
    }

    /**
     * Every row also carries {@see self::TARGETED_CUSTOMERS_COUNT}, the number of
     * customers the sale is reserved for. `sale_customer.customer_id` cascades on
     * delete, so purging the last targeted customer empties the audience of a reserved
     * sale without touching the sale itself: the list has to be able to say so. The
     * count is a correlated sub-select inside the list query — never a read per row.
     *
     * @return ObjectCollection<int, Sale>
     */
    public function findAllSorted(string $field, string $direction, string $locale): ObjectCollection
    {
        $criteria = strtoupper($direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = SaleQuery::create()
            ->withColumn(
                \sprintf(
                    '(SELECT COUNT(*) FROM %s WHERE %s = %s)',
                    SaleCustomerTableMap::TABLE_NAME,
                    SaleCustomerTableMap::COL_SALE_ID,
                    SaleTableMap::COL_ID,
                ),
                self::TARGETED_CUSTOMERS_COUNT,
            );

        match ($field) {
            'id' => $query->orderById($criteria),
            'start_date' => $query->orderByStartDate($criteria),
            'end_date' => $query->orderByEndDate($criteria),
            'active' => $query->orderByActive($criteria),
            'title' => $query
                ->useSaleI18nQuery(null, Criteria::LEFT_JOIN)
                    ->filterByLocale($locale)
                    ->orderByTitle($criteria)
                ->endUse(),
            'label' => $query
                ->useSaleI18nQuery(null, Criteria::LEFT_JOIN)
                    ->filterByLocale($locale)
                    ->orderBySaleLabel($criteria)
                ->endUse(),
            default => $query->orderByStartDate($criteria),
        };

        /** @var ObjectCollection<int, Sale> $result */
        $result = $query->find();

        return $result;
    }

    /**
     * @param list<int> $categoryIds
     *
     * @return list<array{id: int, ref: string, title: string}>
     */
    public function findProductsInCategories(array $categoryIds, string $locale): array
    {
        if ($categoryIds === []) {
            return [];
        }

        /** @var ObjectCollection<int, Product> $products */
        $products = ProductQuery::create()
            ->useProductCategoryQuery()
                ->filterByCategoryId($categoryIds, Criteria::IN)
            ->endUse()
            ->distinct()
            ->orderByRef()
            ->find();

        $items = [];
        foreach ($products as $product) {
            $product->setLocale($locale);
            $items[] = [
                'id' => (int) $product->getId(),
                'ref' => (string) $product->getRef(),
                'title' => (string) $product->getTitle(),
            ];
        }

        return $items;
    }
}
