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

use BackOfficeDefaultTwigBundle\Repository\CustomerGroupPriceRepository;
use Propel\Runtime\Propel;
use Thelia\Model\CustomerGroupProductPrice;
use Thelia\Model\Map\CustomerGroupProductPriceTableMap;

/**
 * Writes the prices the customer groups keep of their own for the combinations of a
 * product. An empty value is no price of its own, stored as NULL and never as zero: a
 * cell with neither a price nor a promo price loses its row, and the catalog applies again.
 * The two prices are independent, as they are for the core.
 */
final readonly class GroupPricesWriter
{
    public function __construct(
        private CustomerGroupPriceRepository $prices,
    ) {
    }

    /**
     * Existing rows are read in one statement, and the whole grid is written in one
     * transaction. A combination with an amount that is not a number is left untouched
     * and reported, the others are saved.
     *
     * @param array<int, array<int, array{price?: mixed, promo_price?: mixed}>> $cells indexed by combination id, then group id
     *
     * @return array{changed: int, refused: list<int>} the number of rows written or deleted, the combinations refused
     */
    public function save(int $currencyId, array $cells): array
    {
        $groupIds = [];
        foreach ($cells as $groups) {
            foreach (array_keys($groups) as $groupId) {
                $groupIds[(int) $groupId] = (int) $groupId;
            }
        }
        $groupIds = $this->prices->findExistingGroupIds(array_values($groupIds));

        $refused = [];
        $normalized = [];
        foreach ($cells as $pseId => $groups) {
            foreach ($groups as $groupId => $values) {
                if (!\in_array((int) $groupId, $groupIds, true)) {
                    continue;
                }

                try {
                    $normalized[(int) $pseId][(int) $groupId] = [
                        self::decimal($values['price'] ?? null),
                        self::decimal($values['promo_price'] ?? null),
                    ];
                } catch (\InvalidArgumentException) {
                    $refused[(int) $pseId] = (int) $pseId;
                }
            }
        }
        foreach ($refused as $pseId) {
            unset($normalized[$pseId]);
        }

        $existing = $this->prices->findPrices(array_keys($normalized), [$currencyId]);

        $connection = Propel::getConnection(CustomerGroupProductPriceTableMap::DATABASE_NAME);
        $connection->beginTransaction();
        $changed = 0;

        try {
            foreach ($normalized as $pseId => $groups) {
                foreach ($groups as $groupId => [$price, $promoPrice]) {
                    $row = $existing[$groupId][$pseId][$currencyId] ?? null;

                    if (null === $price && null === $promoPrice) {
                        if (null !== $row) {
                            $row->delete($connection);
                            ++$changed;
                        }

                        continue;
                    }

                    ($row ?? (new CustomerGroupProductPrice())
                        ->setCustomerGroupId($groupId)
                        ->setProductSaleElementsId($pseId)
                        ->setCurrencyId($currencyId))
                        ->setPrice($price)
                        ->setPromoPrice($promoPrice)
                        ->setFromDefaultCurrency(0)
                        ->save($connection);
                    ++$changed;
                }
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        return ['changed' => $changed, 'refused' => array_values($refused)];
    }

    /**
     * The DECIMAL columns take a string: a float handed over comes back through PHP's own
     * formatting. A comma typed as the decimal separator is read as one. Empty is null.
     */
    private static function decimal(mixed $value): ?string
    {
        if (!\is_scalar($value) && null !== $value) {
            throw new \InvalidArgumentException('An amount is a scalar.');
        }

        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ('' === $value) {
            return null;
        }

        if (!is_numeric($value) || (float) $value < 0 || (float) $value >= 1e10) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid amount.', $value));
        }

        return number_format((float) $value, 6, '.', '');
    }
}
