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
use Thelia\Model\Map\PriceListItemTableMap;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\PriceListItem;
use Thelia\Model\PriceListItemQuery;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Reads of the lines of a price list: one page at a time, searched in the database by the
 * reference of the sale element or of its product, never by loading every line.
 */
final readonly class PriceListItemRepository
{
    public const PAGE_SIZE = 25;

    public function findInList(int $priceListId, int $itemId): ?PriceListItem
    {
        return PriceListItemQuery::create()->filterByPriceListId($priceListId)->filterById($itemId)->findOne();
    }

    public function isLineTaken(int $priceListId, int $saleElementId, int $minimumQuantity, ?int $exceptId = null): bool
    {
        $query = PriceListItemQuery::create()
            ->filterByPriceListId($priceListId)
            ->filterByProductSaleElementsId($saleElementId)
            ->filterByMinQuantity($minimumQuantity);

        if (null !== $exceptId) {
            $query->filterById($exceptId, Criteria::NOT_EQUAL);
        }

        return $query->exists();
    }

    /**
     * The ids of the sale elements that carry the reference, two at most: more than one means
     * the reference does not name a sale element.
     *
     * @return list<int>
     */
    public function saleElementIdsByReference(string $reference): array
    {
        $ids = [];
        foreach (ProductSaleElementsQuery::create()->filterByRef($reference)->limit(2)->find() as $saleElement) {
            $ids[] = (int) $saleElement->getId();
        }

        return $ids;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, lastPage: int}
     */
    public function search(int $priceListId, ?string $term, int $page, string $locale): array
    {
        $query = PriceListItemQuery::create()
            ->filterByPriceListId($priceListId)
            ->addJoin(PriceListItemTableMap::COL_PRODUCT_SALE_ELEMENTS_ID, ProductSaleElementsTableMap::COL_ID, Criteria::INNER_JOIN)
            ->addJoin(ProductSaleElementsTableMap::COL_PRODUCT_ID, ProductTableMap::COL_ID, Criteria::INNER_JOIN);

        $term = trim((string) $term);
        if ('' !== $term) {
            $like = '%'.PriceListRepository::escapeLike($term).'%';
            $query
                ->condition('pse_ref_like', ProductSaleElementsTableMap::COL_REF.' LIKE ?', $like)
                ->condition('product_ref_like', ProductTableMap::COL_REF.' LIKE ?', $like)
                ->where(['pse_ref_like', 'product_ref_like'], Criteria::LOGICAL_OR);
        }

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        $found = $query
            ->withColumn(ProductSaleElementsTableMap::COL_REF, 'pse_ref')
            ->withColumn(ProductTableMap::COL_ID, 'product_id')
            ->withColumn(ProductTableMap::COL_REF, 'product_ref')
            ->select(['Id', 'ProductSaleElementsId', 'MinQuantity', 'Price', 'DiscountPercent', 'pse_ref', 'product_id', 'product_ref'])
            ->orderBy(ProductTableMap::COL_REF)
            ->orderBy(ProductSaleElementsTableMap::COL_REF)
            ->orderByMinQuantity()
            ->orderById()
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->find()
            ->getData();

        $titles = $this->productTitles(array_values(array_unique(array_map(static fn (array $row): int => (int) $row['product_id'], $found))), $locale);

        $rows = [];
        foreach ($found as $row) {
            $rows[] = [
                'id' => (int) $row['Id'],
                'saleElementId' => (int) $row['ProductSaleElementsId'],
                'saleElementReference' => (string) $row['pse_ref'],
                'productReference' => (string) $row['product_ref'],
                'productTitle' => $titles[(int) $row['product_id']] ?? '',
                'minQuantity' => (int) $row['MinQuantity'],
                'price' => null === $row['Price'] ? null : self::trimDecimal((string) $row['Price']),
                'discountPercent' => null === $row['DiscountPercent'] ? null : self::trimDecimal((string) $row['DiscountPercent']),
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, string>
     */
    private function productTitles(array $productIds, string $locale): array
    {
        if ([] === $productIds) {
            return [];
        }

        $titles = [];
        foreach (ProductI18nQuery::create()->filterById($productIds, Criteria::IN)->filterByLocale($locale)->find() as $i18n) {
            $titles[(int) $i18n->getId()] = (string) $i18n->getTitle();
        }

        return $titles;
    }

    /**
     * "10.500000" reads "10.5", "9.000000" reads "9": the value the admin typed, not the scale of the column.
     */
    public static function trimDecimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
