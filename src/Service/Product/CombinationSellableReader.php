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

use Thelia\Domain\Catalog\Stock\ReservationDetailReader;
use Thelia\Domain\Catalog\Stock\SellableStock;
use Thelia\Model\ProductSaleElements;

/**
 * What the combinations of a product may still sell, and what the carts hold of it, for the
 * whole tab in two reads (the sellable answers, the reservation detail), whatever the number
 * of combinations. The figures are those of the national counter, the one the stock field of
 * the tab edits.
 */
final readonly class CombinationSellableReader
{
    public function __construct(
        private SellableStock $sellableStock,
        private ReservationDetailReader $reservationDetail,
    ) {
    }

    /**
     * @param list<ProductSaleElements> $combinations
     *
     * @return array<int, array{unlimited: bool, available: float, reserved: float, cart_count: int, next_expiry: ?\DateTimeImmutable}> by combination id
     */
    public function of(array $combinations): array
    {
        if ([] === $combinations) {
            return [];
        }

        $answers = $this->sellableStock->answersFor($combinations);
        $detail = $this->reservationDetail->detailOf(array_keys($answers));
        $figures = [];

        foreach ($answers as $id => $answer) {
            $held = $detail[$id] ?? null;

            $figures[$id] = [
                'unlimited' => null === $answer->available,
                'available' => $answer->available ?? 0.0,
                'reserved' => $held->quantity ?? 0.0,
                'cart_count' => $held->cartCount ?? 0,
                'next_expiry' => $held?->nextExpiry,
            ];
        }

        return $figures;
    }
}
