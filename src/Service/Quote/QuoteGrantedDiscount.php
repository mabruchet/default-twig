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

namespace BackOfficeDefaultTwigBundle\Service\Quote;

use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Thelia\Model\QuoteItem;

/**
 * What the shop gives up on the lines of a revision: the gap between the catalogue price of
 * each line and the price proposed, over the quantity proposed, without taxes. A price above
 * the catalogue gives a negative amount.
 */
final readonly class QuoteGrantedDiscount
{
    public function __construct(
        private MoneyFormatter $money,
    ) {
    }

    /**
     * @param iterable<QuoteItem> $lines
     *
     * @return array{amount: string, percent: string}
     */
    public function of(iterable $lines, string $locale, ?string $currencyCode): array
    {
        $catalog = 0.0;
        $granted = 0.0;

        foreach ($lines as $line) {
            $quantity = (float) $line->getQuantity();
            $catalog += (float) $line->getCatalogPrice() * $quantity;
            $granted += ((float) $line->getCatalogPrice() - (float) $line->getPrice()) * $quantity;
        }

        $granted = round($granted, 2);
        $percent = $catalog > 0.0 ? round($granted / $catalog * 100, 2) : 0.0;

        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return [
            'amount' => $this->money->format($granted, $locale, $currencyCode),
            'percent' => $formatter->format($percent).' %',
        ];
    }
}
