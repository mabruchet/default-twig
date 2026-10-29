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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\AttributeAvI18nQuery;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\AttributeI18nQuery;
use Thelia\Model\ProductSaleElements;

/**
 * The name of a combination ("Color: Red / Size: M"), written the same way by the
 * Combinations tab and by every screen that lets the merchant pick a combination.
 */
final readonly class CombinationLabeler
{
    public const DEFAULT_LABEL = 'default';

    /**
     * The label of one combination and the position of its attributes, which orders the
     * combinations of a product that are tied on their own position.
     *
     * @param array<int, int> $attributePositions position of each attribute in the template of the product, by attribute id
     *
     * @return array{label: string, order: list<array{int, int}>}
     */
    public function describe(ProductSaleElements $saleElements, string $locale, array $attributePositions = []): array
    {
        $parts = [];
        $order = [];
        foreach ($saleElements->getAttributeCombinations() as $combination) {
            $attribute = $combination->getAttribute();
            $attributeAv = $combination->getAttributeAv();
            if ($attribute === null || $attributeAv === null) {
                continue;
            }
            $attribute->setLocale($locale);
            $attributeAv->setLocale($locale);
            $parts[] = $this->part((string) $attribute->getTitle(), (string) $attributeAv->getTitle());
            $order[] = [
                $attributePositions[(int) $attribute->getId()] ?? (int) $attribute->getPosition(),
                (int) $attributeAv->getPosition(),
            ];
        }
        sort($order);

        return ['label' => $this->join($parts), 'order' => $order];
    }

    /**
     * The labels of many combinations in three queries, whatever their number.
     *
     * @param list<int> $saleElementsIds
     *
     * @return array<int, string> keyed by sale element id
     */
    public function labelsFor(array $saleElementsIds, string $locale): array
    {
        if ([] === $saleElementsIds) {
            return [];
        }

        $combinations = AttributeCombinationQuery::create()
            ->filterByProductSaleElementsId($saleElementsIds, Criteria::IN)
            ->orderByAttributeId()
            ->orderByAttributeAvId()
            ->find();

        $attributeIds = [];
        $valueIds = [];
        foreach ($combinations as $combination) {
            $attributeIds[] = (int) $combination->getAttributeId();
            $valueIds[] = (int) $combination->getAttributeAvId();
        }

        $attributeTitles = [];
        $valueTitles = [];
        if ([] !== $attributeIds) {
            foreach (AttributeI18nQuery::create()->filterById($attributeIds, Criteria::IN)->filterByLocale($locale)->find() as $translation) {
                $attributeTitles[(int) $translation->getId()] = (string) $translation->getTitle();
            }
            foreach (AttributeAvI18nQuery::create()->filterById($valueIds, Criteria::IN)->filterByLocale($locale)->find() as $translation) {
                $valueTitles[(int) $translation->getId()] = (string) $translation->getTitle();
            }
        }

        $partsBySaleElements = [];
        foreach ($combinations as $combination) {
            $partsBySaleElements[(int) $combination->getProductSaleElementsId()][] = $this->part(
                $attributeTitles[(int) $combination->getAttributeId()] ?? '',
                $valueTitles[(int) $combination->getAttributeAvId()] ?? '',
            );
        }

        $labels = [];
        foreach ($saleElementsIds as $saleElementsId) {
            $labels[$saleElementsId] = $this->join($partsBySaleElements[$saleElementsId] ?? []);
        }

        return $labels;
    }

    private function part(string $attributeTitle, string $valueTitle): string
    {
        return $attributeTitle.': '.$valueTitle;
    }

    /**
     * @param list<string> $parts
     */
    private function join(array $parts): string
    {
        return [] === $parts ? self::DEFAULT_LABEL : implode(' / ', $parts);
    }
}
