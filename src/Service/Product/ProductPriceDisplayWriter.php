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

use Thelia\Domain\Pricing\PriceVisibility\PriceDisplayMode;
use Thelia\Model\Product;

/**
 * Writes the display of the prices of one product: the setter of the one column, never the
 * product update event, which writes every field the form does not send as empty. Null is
 * "like the shop".
 */
final readonly class ProductPriceDisplayWriter
{
    public function save(Product $product, ?PriceDisplayMode $mode): void
    {
        $product->setPriceDisplayMode($mode?->value)->save();
    }
}
