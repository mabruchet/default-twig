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

use BackOfficeDefaultTwigBundle\Repository\CurrencyRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupPriceRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use Thelia\Domain\Pricing\CurrencyConverter;
use Thelia\Model\Currency;

/**
 * The prices the customer groups keep of their own for the combinations of a product, laid
 * out as one cell per combination and group. An empty field means the group has no price of
 * its own: when the group priced the combination in the default currency, the field then shows
 * that price converted, in grey; else the field says the catalog price applies.
 * A shop without any customer group gets no section at all.
 *
 * Each field carries the hint shown under it as a translation key and its parameters.
 */
final readonly class GroupPricesContextBuilder
{
    public const HINT_NONE = 'No price of its own';
    public const HINT_CONVERTED = 'Converted from %currency%';

    public function __construct(
        private CustomerGroupRepository $customerGroups,
        private CustomerGroupPriceRepository $prices,
        private CurrencyRepository $currencies,
        private CurrencyConverter $converter,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $combinationRows the rows of the combinations tab, catalog prices in $currencyId
     *
     * @return array{
     *     groups: list<array{id: int, label: string}>,
     *     currency_id: int,
     *     rows: list<array{id: int, label: string, ref: string, price: ?string, sale_price: ?string, cells: list<array{group_id: int, fields: list<array<string, mixed>>}>}>
     * }|null
     */
    public function build(array $combinationRows, int $currencyId, string $locale): ?array
    {
        $groups = $this->customerGroups->findChoices($locale);
        if ([] === $groups) {
            return null;
        }

        $currency = $this->currencies->find($currencyId);
        $default = $this->currencies->findDefault();
        $converts = null !== $currency && null !== $default && $currency->getId() !== $default->getId();
        $defaultId = (int) $default?->getId();
        $defaultCode = (string) $default?->getCode();

        $pseIds = array_map(static fn (array $row): int => (int) $row['id'], $combinationRows);
        $prices = $this->prices->findPrices($pseIds, $converts ? [$currencyId, $defaultId] : [$currencyId]);

        $rows = [];
        foreach ($combinationRows as $row) {
            $pseId = (int) $row['id'];
            $cells = [];
            foreach ($groups as $group) {
                $own = $prices[$group['id']][$pseId][$currencyId] ?? null;
                // A row flagged as taken from the default currency is not a price typed here.
                if ($converts && $own?->getFromDefaultCurrency()) {
                    $own = null;
                }
                $fromDefault = $converts ? ($prices[$group['id']][$pseId][$defaultId] ?? null) : null;

                $cells[] = [
                    'group_id' => $group['id'],
                    'fields' => [
                        $this->field('price', $own?->getPrice(), $fromDefault?->getPrice(), $currency, $defaultCode),
                        $this->field('promo_price', $own?->getPromoPrice(), $fromDefault?->getPromoPrice(), $currency, $defaultCode),
                    ],
                ];
            }

            $rows[] = [
                'id' => $pseId,
                'label' => (string) $row['label'],
                'ref' => (string) $row['ref'],
                'price' => self::number($row['price'] ?? null),
                'sale_price' => self::number($row['sale_price'] ?? null),
                'cells' => $cells,
            ];
        }

        return ['groups' => $groups, 'currency_id' => $currencyId, 'rows' => $rows];
    }

    /**
     * Each of the two prices is read on its own, as the core does: one typed here wins,
     * else the one of the default currency converted at the rates of the shop.
     *
     * @return array{name: string, value: ?string, placeholder: ?string, hint: string, hint_parameters: array<string, string>}
     */
    private function field(string $name, mixed $own, mixed $inDefaultCurrency, ?Currency $currency, string $defaultCode): array
    {
        $value = self::number($own);
        if (null !== $value) {
            return ['name' => $name, 'value' => $value, 'placeholder' => null, 'hint' => '', 'hint_parameters' => []];
        }

        if (null === $inDefaultCurrency || null === $currency) {
            return ['name' => $name, 'value' => null, 'placeholder' => null, 'hint' => self::HINT_NONE, 'hint_parameters' => []];
        }

        $converted = $this->converter->fromDefaultCurrency((float) $inDefaultCurrency, $currency);

        return [
            'name' => $name,
            'value' => null,
            'placeholder' => self::number($converted),
            'hint' => null === $converted ? self::HINT_NONE : self::HINT_CONVERTED,
            'hint_parameters' => null === $converted ? [] : ['%currency%' => $defaultCode],
        ];
    }

    /**
     * A decimal column read back without its trailing zeros, null when there is no value:
     * an empty field is what says "no price of its own".
     */
    private static function number(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
}
