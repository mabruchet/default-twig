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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Bundle;

use BackOfficeDefaultTwigBundle\Service\Product\CombinationLabeler;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The name of a combination is written once, for the Combinations tab and for the picker of
 * the components of a bundle.
 */
final class CombinationLabelerTest extends IntegrationTestCase
{
    public function testTheBatchReadsTheLabelOfEveryCombinationAndTheOneByOneReadAgrees(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $color = $factory->attribute(['title' => 'Color']);
        $size = $factory->attribute(['title' => 'Size']);
        $red = $factory->attributeAv($color, ['title' => 'Red']);
        $large = $factory->attributeAv($size, ['title' => 'L']);

        $withTwoValues = $factory->productSaleElement($product);
        $factory->attributeCombination($withTwoValues, $red);
        $factory->attributeCombination($withTwoValues, $large);
        $withOneValue = $factory->productSaleElement($product);
        $factory->attributeCombination($withOneValue, $red);
        $withNone = $product->getDefaultSaleElements();

        $ids = [(int) $withTwoValues->getId(), (int) $withOneValue->getId(), (int) $withNone->getId()];
        $labeler = new CombinationLabeler();

        $labels = $labeler->labelsFor($ids, 'en_US');

        self::assertSame('Color: Red / Size: L', $labels[$ids[0]]);
        self::assertSame('Color: Red', $labels[$ids[1]]);
        self::assertSame('default', $labels[$ids[2]]);
        foreach ([$withTwoValues, $withOneValue] as $saleElements) {
            self::assertSame($labels[(int) $saleElements->getId()], $labeler->describe(ProductSaleElementsQuery::create()->findPk($saleElements->getId()), 'en_US')['label']);
        }
    }

    public function testTwoAttributesWrittenInTheReverseOrderOfTheirIdAreNamedTheSameWayByBothPaths(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $color = $factory->attribute(['title' => 'Color']);
        $size = $factory->attribute(['title' => 'Size']);
        $red = $factory->attributeAv($color, ['title' => 'Red']);
        $medium = $factory->attributeAv($size, ['title' => 'M']);

        $saleElements = $factory->productSaleElement($product);
        // The attribute with the higher id first: the table reads them back by attribute id whatever the order written.
        $factory->attributeCombination($saleElements, $medium);
        $factory->attributeCombination($saleElements, $red);
        $labeler = new CombinationLabeler();

        $batch = $labeler->labelsFor([(int) $saleElements->getId()], 'en_US')[(int) $saleElements->getId()];
        $oneByOne = $labeler->describe(ProductSaleElementsQuery::create()->findPk($saleElements->getId()), 'en_US')['label'];

        self::assertSame('Color: Red / Size: M', $batch);
        self::assertSame($batch, $oneByOne);
    }

    public function testTheBatchCostsAFixedNumberOfQueriesWhateverTheNumberOfCombinations(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $color = $factory->attribute(['title' => 'Color']);
        $ids = [];
        for ($i = 0; $i < 6; ++$i) {
            $saleElements = $factory->productSaleElement($product);
            $factory->attributeCombination($saleElements, $factory->attributeAv($color, ['title' => 'Value '.$i]));
            $ids[] = (int) $saleElements->getId();
        }

        $few = QueryCounter::count(static fn () => (new CombinationLabeler())->labelsFor(\array_slice($ids, 0, 2), 'en_US'));
        $many = QueryCounter::count(static fn () => (new CombinationLabeler())->labelsFor($ids, 'en_US'));

        self::assertSame(3, $many);
        self::assertSame($few, $many);
    }
}
