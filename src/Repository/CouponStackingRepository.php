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
use Thelia\Model\CouponExclusionQuery;
use Thelia\Model\CouponQuery;

final readonly class CouponStackingRepository
{
    public const EXCLUSION_CHOICES_LIMIT = 200;

    /**
     * The promotions a promotion can be made incompatible with: every other one. The ones
     * already selected are read apart, so the bound never cuts them; the others are cut at
     * the bound. Each read takes the three columns the label needs, and no raw SQL.
     *
     * @param list<int> $selectedIds
     *
     * @return list<array{id: int, label: string}>
     */
    public function exclusionChoices(?int $currentCouponId, array $selectedIds, string $locale): array
    {
        $selectedIds = array_values(array_unique($selectedIds));
        $choices = [];

        if ([] !== $selectedIds) {
            $choices = $this->choicesOf($this->query($currentCouponId, $locale)->filterById($selectedIds, Criteria::IN));
        }

        $others = $this->query($currentCouponId, $locale)->limit(self::EXCLUSION_CHOICES_LIMIT);
        if ([] !== $selectedIds) {
            $others->filterById($selectedIds, Criteria::NOT_IN);
        }

        $choices = [...$choices, ...$this->choicesOf($others)];
        usort($choices, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $choices;
    }

    /** @return list<int> */
    public function excludedIds(int $couponId): array
    {
        return CouponExclusionQuery::create()->excludedCouponIdsFor($couponId);
    }

    /**
     * One grouped count for a whole page of the list.
     *
     * @param list<int> $couponIds
     *
     * @return array<int, int>
     */
    public function exclusionCounts(array $couponIds): array
    {
        return CouponExclusionQuery::create()->countByCoupons($couponIds);
    }

    private function query(?int $currentCouponId, string $locale): CouponQuery
    {
        $query = CouponQuery::create()
            ->leftJoinCouponI18n('CouponI18n')
            ->addJoinCondition('CouponI18n', 'CouponI18n.Locale = ?', $locale)
            ->withColumn('CouponI18n.Title', 'PromotionTitle')
            ->orderByCode(Criteria::ASC)
            ->orderById(Criteria::ASC);

        if (null !== $currentCouponId) {
            $query->filterById($currentCouponId, Criteria::NOT_EQUAL);
        }

        return $query;
    }

    /** @return list<array{id: int, label: string}> */
    private function choicesOf(CouponQuery $query): array
    {
        $choices = [];
        foreach ($query->select(['Id', 'Code', 'PromotionTitle'])->find() as $row) {
            $code = (string) $row['Code'];
            $title = (string) $row['PromotionTitle'];
            $choices[] = [
                'id' => (int) $row['Id'],
                'label' => '' === $code ? $title : ('' === $title ? $code : $code.' ('.$title.')'),
            ];
        }

        return $choices;
    }
}
