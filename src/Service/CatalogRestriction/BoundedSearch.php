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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductQuery;

/**
 * The search a screen offers to pick a customer or a product without listing the directory:
 * three characters at least, twenty answers at most, nothing for a shorter term.
 */
final readonly class BoundedSearch
{
    public const MINIMUM_LENGTH = 3;
    public const LIMIT = 20;

    public static function accepts(string $term): bool
    {
        return mb_strlen(trim($term)) >= self::MINIMUM_LENGTH;
    }

    /**
     * The one definition of "a customer matches the term" of the screens that pick a customer: the
     * name, the e-mail or the reference holds it. The term is not bounded here: callers ask
     * {@see accepts()} first and set their own limit.
     */
    public static function customersMatching(string $term): CustomerQuery
    {
        $like = self::like($term);

        return CustomerQuery::create()
            ->condition('byEmail', CustomerTableMap::COL_EMAIL.' LIKE ?', $like)
            ->condition('byFirstname', CustomerTableMap::COL_FIRSTNAME.' LIKE ?', $like)
            ->condition('byLastname', CustomerTableMap::COL_LASTNAME.' LIKE ?', $like)
            ->condition('byRef', CustomerTableMap::COL_REF.' LIKE ?', $like)
            ->where(['byEmail', 'byFirstname', 'byLastname', 'byRef'], Criteria::LOGICAL_OR);
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function customers(string $term): array
    {
        if (!self::accepts($term)) {
            return [];
        }

        $rows = self::customersMatching($term)
            ->orderByLastname()
            ->orderById()
            ->limit(self::LIMIT)
            ->select(['Id', 'Firstname', 'Lastname', 'Email'])
            ->find();

        $customers = [];
        foreach ($rows as $row) {
            $customers[] = ['id' => (int) $row['Id'], 'label' => self::customerLabel($row)];
        }

        return $customers;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function products(string $term, string $locale): array
    {
        if (!self::accepts($term)) {
            return [];
        }

        $like = self::like($term);
        $rows = ProductQuery::create()
            ->leftJoinProductI18n('search_i18n')
            ->addJoinCondition('search_i18n', 'search_i18n.locale = ?', $locale)
            ->condition('byRef', ProductTableMap::COL_REF.' LIKE ?', $like)
            ->condition('byTitle', 'search_i18n.title LIKE ?', $like)
            ->where(['byRef', 'byTitle'], Criteria::LOGICAL_OR)
            ->orderByRef()
            ->limit(self::LIMIT)
            ->select(['Id', 'Ref'])
            ->distinct()
            ->find();

        $found = [];
        foreach ($rows as $row) {
            $found[(int) $row['Id']] = (string) $row['Ref'];
        }

        $products = [];
        $titles = $this->productTitles(array_keys($found), $locale);
        foreach ($found as $id => $ref) {
            $title = $titles[$id] ?? '';
            $products[] = ['id' => $id, 'label' => '' === $title ? $ref : $ref.' - '.$title];
        }

        return $products;
    }

    /**
     * @param array{Firstname?: mixed, Lastname?: mixed, Email?: mixed} $row
     */
    public static function customerLabel(array $row): string
    {
        $name = trim(((string) ($row['Firstname'] ?? '')).' '.((string) ($row['Lastname'] ?? '')));

        return '' === $name ? (string) ($row['Email'] ?? '') : $name.' ('.($row['Email'] ?? '').')';
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
        foreach (ProductI18nQuery::create()->filterById($productIds, Criteria::IN)->filterByLocale($locale)->select(['Id', 'Title'])->find() as $row) {
            $titles[(int) $row['Id']] = (string) $row['Title'];
        }

        return $titles;
    }

    private static function like(string $term): string
    {
        return '%'.addcslashes(trim($term), '%_\\').'%';
    }
}
