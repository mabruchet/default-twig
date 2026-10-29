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

use BackOfficeDefaultTwigBundle\Service\Bundle\BundleItemReorderPlanner;
use PHPUnit\Framework\TestCase;

final class BundleItemReorderPlannerTest extends TestCase
{
    public function testAComponentDroppedUpwardGoesBeforeTheOneItIsDroppedOn(): void
    {
        self::assertSame([30 => 1, 10 => 2, 20 => 3], (new BundleItemReorderPlanner())->plan([10 => 1, 20 => 2, 30 => 3], 30, 1));
    }

    public function testAComponentDroppedDownwardGoesBehindTheOneItIsDroppedOn(): void
    {
        self::assertSame([10 => 2, 20 => 1], (new BundleItemReorderPlanner())->plan([10 => 1, 20 => 2, 30 => 3], 10, 2));
    }

    public function testTheMovedComponentIsWrittenFirst(): void
    {
        self::assertSame(30, array_key_first((new BundleItemReorderPlanner())->plan([10 => 1, 20 => 2, 30 => 3], 30, 1)));
    }

    public function testGapsAreClosedAndTiesAreBroken(): void
    {
        // Two components share the position 2 and the last one is on 5: a move renumbers the list
        // from 1, and names the ones whose number changes (the first one keeps its 2).
        self::assertSame(
            [30 => 1, 20 => 3],
            (new BundleItemReorderPlanner())->plan([10 => 2, 20 => 2, 30 => 5], 30, 2),
        );
    }

    public function testNothingMovesWhenTheOrderIsTheOneAsked(): void
    {
        self::assertSame([], (new BundleItemReorderPlanner())->plan([10 => 1, 20 => 2, 30 => 3], 20, 2));
    }

    public function testAnUnknownComponentMovesNothing(): void
    {
        self::assertSame([], (new BundleItemReorderPlanner())->plan([10 => 1], 99, 1));
    }

    public function testAPositionBeyondTheEndPutsTheComponentLast(): void
    {
        self::assertSame([10 => 3, 20 => 1, 30 => 2], (new BundleItemReorderPlanner())->plan([10 => 1, 20 => 2, 30 => 3], 10, 99));
    }
}
