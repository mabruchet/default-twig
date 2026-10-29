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

namespace BackOfficeDefaultTwigBundle\Service\Bundle;

/**
 * Where the components of a bundle end up when one is dropped on the position of another.
 *
 * The core stores the position it is given and keeps no gap-free order: two components on
 * the same position would be told apart by their id, not by the move the merchant made.
 * The plan therefore renumbers the whole list from 1, and names only the components whose
 * position changes, the moved one first.
 */
final readonly class BundleItemReorderPlanner
{
    /**
     * @param array<int, int> $positionsById     the components of one bundle in their current order (position, then id), position by id
     * @param int             $requestedPosition the position of the component the moved one was dropped on
     *
     * @return array<int, int> the new position of each component that moves, by id, the moved one first
     */
    public function plan(array $positionsById, int $movedId, int $requestedPosition): array
    {
        if (!isset($positionsById[$movedId])) {
            return [];
        }

        $movedPosition = $positionsById[$movedId];
        $others = $positionsById;
        unset($others[$movedId]);

        $below = 0;
        $equal = 0;
        foreach ($others as $position) {
            if ($position < $requestedPosition) {
                ++$below;
            } elseif ($position === $requestedPosition) {
                ++$equal;
            }
        }

        // Dragged down, the component goes behind the one it is dropped on; up, before it.
        $index = $movedPosition < $requestedPosition ? $below + $equal : $below;

        $ordered = array_keys($others);
        array_splice($ordered, $index, 0, [$movedId]);

        $plan = [];
        foreach ($ordered as $rank => $id) {
            $newPosition = $rank + 1;
            if ($positionsById[$id] !== $newPosition) {
                $plan[$id] = $newPosition;
            }
        }

        // The moved component is written first, even when its own number did not change.
        if (isset($plan[$movedId])) {
            $plan = [$movedId => $plan[$movedId]] + $plan;
        }

        return $plan;
    }
}
