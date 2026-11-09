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

use BackOfficeDefaultTwigBundle\Service\CatalogRestriction\BoundedSearch;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\BrandI18nQuery;
use Thelia\Model\CatalogRestriction;
use Thelia\Model\CatalogRestrictionQuery;
use Thelia\Model\CategoryI18nQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductQuery;

/**
 * Reads of the assortment rules (`catalog_restriction`): a page of them, filtered by who they
 * bind, and the names of the groups, customers and targets they point at, each read once for
 * the whole page.
 */
final readonly class CatalogRestrictionRepository
{
    public const PER_PAGE = 25;

    public function find(int $restrictionId): ?CatalogRestriction
    {
        return $restrictionId > 0 ? CatalogRestrictionQuery::create()->findPk($restrictionId) : null;
    }

    /**
     * @param 'group'|'customer'|null $scopeType
     *
     * @return array{rows: list<CatalogRestriction>, total: int}
     */
    public function page(?string $scopeType, ?int $customerGroupId, string $customerSearch, int $page): array
    {
        $query = CatalogRestrictionQuery::create();

        if ('group' === $scopeType) {
            $query->filterByCustomerId(null, Criteria::ISNULL);

            if (null !== $customerGroupId) {
                $query->filterByCustomerGroupId($customerGroupId);
            }
        } elseif ('customer' === $scopeType) {
            $query->filterByCustomerGroupId(null, Criteria::ISNULL);

            if (BoundedSearch::accepts($customerSearch)) {
                $matching = BoundedSearch::customersMatching($customerSearch)->limit(1000)->select('Id');

                $query->filterByCustomerId($matching->find()->toArray(), Criteria::IN);
            }
        }

        $total = (clone $query)->count();
        $rows = [];

        foreach ($query->orderById(Criteria::DESC)->offset(max(0, $page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->find() as $row) {
            $rows[] = $row;
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public function customer(int $customerId): ?Customer
    {
        return CustomerQuery::create()->findPk($customerId);
    }

    /**
     * Whether the very same rule (who, what, which way) is already written.
     */
    public function exists(?int $customerGroupId, ?int $customerId, ?int $categoryId, ?int $brandId, ?int $productId, string $mode): bool
    {
        return CatalogRestrictionQuery::create()
            ->filterByCustomerGroupId($customerGroupId)
            ->filterByCustomerId($customerId)
            ->filterByCategoryId($categoryId)
            ->filterByBrandId($brandId)
            ->filterByProductId($productId)
            ->filterByMode($mode)
            ->exists();
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> customer id => "Firstname Lastname (email)"
     */
    public function customerLabels(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $labels = [];
        foreach (CustomerQuery::create()->filterById($ids, Criteria::IN)->select(['Id', 'Firstname', 'Lastname', 'Email'])->find() as $row) {
            $labels[(int) $row['Id']] = BoundedSearch::customerLabel($row);
        }

        return $labels;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> category id => title in the locale
     */
    public function categoryTitles(array $ids, string $locale): array
    {
        return self::titles(CategoryI18nQuery::create(), $ids, $locale);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> brand id => title in the locale
     */
    public function brandTitles(array $ids, string $locale): array
    {
        return self::titles(BrandI18nQuery::create(), $ids, $locale);
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function brandChoices(string $locale): array
    {
        $choices = [];
        foreach (BrandI18nQuery::create()->filterByLocale($locale)->orderByTitle()->select(['Id', 'Title'])->find() as $row) {
            $choices[] = ['id' => (int) $row['Id'], 'label' => (string) $row['Title']];
        }

        return $choices;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> product id => "ref - title"
     */
    public function productLabels(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $titles = self::titles(ProductI18nQuery::create(), $ids, $locale);
        $labels = [];
        foreach (ProductQuery::create()->filterById($ids, Criteria::IN)->select(['Id', 'Ref'])->find() as $row) {
            $id = (int) $row['Id'];
            $labels[$id] = isset($titles[$id]) && '' !== $titles[$id] ? $row['Ref'].' - '.$titles[$id] : (string) $row['Ref'];
        }

        return $labels;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private static function titles(CategoryI18nQuery|BrandI18nQuery|ProductI18nQuery $query, array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $titles = [];
        foreach ($query->filterById($ids, Criteria::IN)->filterByLocale($locale)->select(['Id', 'Title'])->find() as $row) {
            $titles[(int) $row['Id']] = (string) $row['Title'];
        }

        return $titles;
    }
}
