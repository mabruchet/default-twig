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
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\PriceListI18nTableMap;
use Thelia\Model\Map\PriceListTableMap;
use Thelia\Model\PriceList;
use Thelia\Model\PriceListItemQuery;
use Thelia\Model\PriceListQuery;

/**
 * Reads of the price lists: one page of the list screen at a time, the number of lines of
 * each list of the page counted by one grouped query, never by loading the lines.
 */
final readonly class PriceListRepository
{
    public const PAGE_SIZE = 20;

    public const SORTABLE_FIELDS = ['id', 'code', 'title', 'priority'];

    private const TITLE_COLUMN = 'localized_title';

    public function find(int $priceListId): ?PriceList
    {
        return $priceListId > 0 ? PriceListQuery::create()->findPk($priceListId) : null;
    }

    public function isCodeTaken(string $code, ?int $exceptId = null): bool
    {
        $query = PriceListQuery::create()->filterByCode($code);
        if (null !== $exceptId) {
            $query->filterById($exceptId, Criteria::NOT_EQUAL);
        }

        return $query->exists();
    }

    public function countLines(int $priceListId): int
    {
        return PriceListItemQuery::create()->filterByPriceListId($priceListId)->count();
    }

    /**
     * @return array<int, string> currency code by id
     */
    public function currencyCodes(): array
    {
        $codes = [];
        foreach (CurrencyQuery::create()->orderByCode()->find() as $currency) {
            $codes[(int) $currency->getId()] = (string) $currency->getCode();
        }

        return $codes;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, lastPage: int}
     */
    public function search(?string $term, ?int $currencyId, ?bool $active, ?bool $exclusive, string $sortField, string $direction, int $page, string $locale): array
    {
        $query = PriceListQuery::create()
            ->joinI18n($locale, null, Criteria::LEFT_JOIN)
            ->withColumn(PriceListI18nTableMap::COL_TITLE, self::TITLE_COLUMN);

        $term = trim((string) $term);
        if ('' !== $term) {
            $like = '%'.self::escapeLike($term).'%';
            $query
                ->condition('code_like', PriceListTableMap::COL_CODE.' LIKE ?', $like)
                ->condition('title_like', PriceListI18nTableMap::COL_TITLE.' LIKE ?', $like)
                ->where(['code_like', 'title_like'], Criteria::LOGICAL_OR);
        }

        if (null !== $currencyId) {
            $query->filterByCurrencyId($currencyId);
        }

        if (null !== $active) {
            $query->filterByActive($active ? 1 : 0);
        }

        if (null !== $exclusive) {
            $query->filterByExclusive($exclusive ? 1 : 0);
        }

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);
        $criteria = 'DESC' === strtoupper($direction) ? Criteria::DESC : Criteria::ASC;

        match ($sortField) {
            'id' => $query->orderById($criteria),
            'title' => $query->orderBy(self::TITLE_COLUMN, $criteria)->orderByCode($criteria),
            'priority' => $query->orderByPriority($criteria)->orderById(),
            default => $query->orderByCode($criteria),
        };

        $lists = $query->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)->find();

        $ids = [];
        foreach ($lists as $list) {
            $ids[] = (int) $list->getId();
        }

        $counts = $this->lineCounts($ids);
        $currencies = $this->currencyCodes();

        $rows = [];
        foreach ($lists as $list) {
            $id = (int) $list->getId();
            $title = trim((string) $list->getVirtualColumn(self::TITLE_COLUMN));
            $rows[] = [
                'id' => $id,
                'code' => (string) $list->getCode(),
                'title' => '' === $title ? (string) $list->getCode() : $title,
                'currency' => $currencies[(int) $list->getCurrencyId()] ?? '',
                'priority' => (int) $list->getPriority(),
                'exclusive' => 1 === $list->getExclusive(),
                'startsAt' => $list->getStartsAt(),
                'endsAt' => $list->getEndsAt(),
                'active' => 1 === $list->getActive(),
                'lines' => $counts[$id] ?? 0,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }

    /**
     * @param list<int> $priceListIds
     *
     * @return array<int, int>
     */
    private function lineCounts(array $priceListIds): array
    {
        if ([] === $priceListIds) {
            return [];
        }

        $counts = [];
        $rows = PriceListItemQuery::create()
            ->filterByPriceListId($priceListIds, Criteria::IN)
            ->groupByPriceListId()
            ->withColumn('COUNT(*)', 'line_count')
            ->select(['PriceListId', 'line_count'])
            ->find();

        foreach ($rows as $row) {
            $counts[(int) $row['PriceListId']] = (int) $row['line_count'];
        }

        return $counts;
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
