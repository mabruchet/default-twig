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

use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Catalog\Product\ClosedCategoryProductRule;
use Thelia\Domain\Catalog\Restriction\CatalogRestrictionRule;
use Thelia\Domain\Pricing\CustomerPrice\CustomerPriceContextInterface;
use Thelia\Domain\Pricing\CustomerPrice\ResolvedPrice;
use Thelia\Domain\Pricing\EffectivePrice;
use Thelia\Domain\Pricing\PriceList\PriceListResolverInterface;
use Thelia\Domain\Pricing\PriceVisibility\PriceVisibilityResolver;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\PriceListQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * What a customer (or a visitor) pays for each combination of a product, laid out for the
 * back office: the catalog price, the price the shop resolves for this customer, where it comes
 * from, whether it can be bought and the tiers of the price list that prices it.
 *
 * Nothing is computed here: the prices are the answer of {@see CustomerPriceContextInterface}, the
 * one resolver the storefront, the cart and the orders ask. The tiers come from the same resolver
 * of the price lists ({@see PriceListResolverInterface::tiersFor()}), which answers them whatever
 * quantity the price is resolved at.
 *
 * What the customer is shown comes first: the price display mode ({@see PriceVisibilityResolver}) and
 * the assortment (the two rules the storefront narrows its queries with) decide whether the price
 * shows and whether the product is reachable at all. A hidden price is not resolved here, and a
 * combination is bought only when the mode lets the customer buy it and the resolver does.
 */
final readonly class EffectivePricePresenter
{
    public const STATUS_SHOWN = 'shown';

    public const STATUS_OUTSIDE_ASSORTMENT = 'outside_assortment';

    public function __construct(
        private CustomerPriceContextInterface $prices,
        private PriceListResolverInterface $priceLists,
        private CombinationLabeler $labeler,
        private CustomerGroupRepository $customerGroups,
        private PriceVisibilityResolver $visibility,
        private CatalogRestrictionRule $assortmentRule,
        private ClosedCategoryProductRule $closedCategoryRule,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{group: string, status: string, rows: list<array<string, mixed>>}
     */
    public function present(Product $product, Currency $currency, ?Customer $customer, string $locale): array
    {
        $saleElements = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->orderByIsDefault(Criteria::DESC)
            ->orderById()
            ->find();

        $pseIds = [];
        foreach ($saleElements as $saleElement) {
            $pseIds[] = (int) $saleElement->getId();
        }

        $mode = $this->visibility->modeFor($product, $customer);
        $status = match (true) {
            !$this->isInAssortment($product, $customer) => self::STATUS_OUTSIDE_ASSORTMENT,
            !$mode->showsPrice($customer) => $mode->value,
            default => self::STATUS_SHOWN,
        };
        $shown = self::STATUS_SHOWN === $status;

        $resolved = $shown ? $this->prices->resolve($pseIds, $currency, $customer) : [];
        $tiersByCombination = $shown ? $this->priceLists->tiersFor($pseIds, $currency, $customer) : [];
        $labels = $this->labeler->labelsFor($pseIds, $locale);
        $priceListCodes = $this->priceListCodes($resolved);

        $rows = [];
        foreach ($saleElements as $saleElement) {
            $pseId = (int) $saleElement->getId();

            try {
                $catalog = $saleElement->getPricesByCurrency($currency);
            } catch (\RuntimeException) {
                $catalog = null;
            }

            $price = $resolved[$pseId] ?? null;
            $tiers = $tiersByCombination[$pseId] ?? [];

            $rows[] = [
                'id' => $pseId,
                'ref' => (string) $saleElement->getRef(),
                'label' => $labels[$pseId] ?? CombinationLabeler::DEFAULT_LABEL,
                'catalog_price' => self::number($catalog?->getPrice()),
                'catalog_promo_price' => null !== $catalog && 1 === (int) $saleElement->getPromo() && $catalog->getPromoPrice() > 0.0 && $catalog->getPromoPrice() !== $catalog->getPrice() ? self::number($catalog->getPromoPrice()) : null,
                'price' => null === $price ? null : self::number($price->chargedPrice()),
                'source' => null === $price ? null : $this->sourceLabel($price, $priceListCodes),
                'purchasable' => null !== $price && $price->purchasable,
                'tiers' => array_map(static fn (array $tier): array => ['quantity' => $tier['minQuantity'], 'price' => self::number($tier['untaxedPrice'])], $tiers),
            ];
        }

        return [
            'group' => CustomerGroupRepository::titleOf($this->prices->customerGroupOf($customer), $locale),
            'status' => $status,
            'rows' => $rows,
        ];
    }

    /**
     * Whether the product is open to the customer: the two rules the storefront narrows its
     * queries with, the categories closed to the group and the assortment rules, asked for this
     * customer (a visitor when null).
     */
    private function isInAssortment(Product $product, ?Customer $customer): bool
    {
        $query = ProductQuery::create()->filterById($product->getId());
        $narrowed = false;

        foreach ([$this->closedCategoryRule, $this->assortmentRule] as $rule) {
            $clause = $rule->isActive() ? $rule->visibleProductClause('product.id', $customer) : null;

            if (null !== $clause) {
                $query->where(\sprintf('(%s)', $clause));
                $narrowed = true;
            }
        }

        return !$narrowed || $query->exists();
    }

    /**
     * @param array<int, ResolvedPrice> $resolved
     *
     * @return array<int, string> price list id => code
     */
    private function priceListCodes(array $resolved): array
    {
        $ids = [];
        foreach ($resolved as $price) {
            if (null !== $price->priceListId) {
                $ids[$price->priceListId] = $price->priceListId;
            }
        }

        if ([] === $ids) {
            return [];
        }

        $codes = [];
        foreach (PriceListQuery::create()->filterById(array_values($ids), Criteria::IN)->select(['Id', 'Code'])->find() as $row) {
            $codes[(int) $row['Id']] = (string) $row['Code'];
        }

        return $codes;
    }

    /**
     * @param array<int, string> $priceListCodes
     */
    private function sourceLabel(ResolvedPrice $price, array $priceListCodes): string
    {
        $code = null === $price->priceListId ? '' : ($priceListCodes[$price->priceListId] ?? '#'.$price->priceListId);

        if (ResolvedPrice::SOURCE_PRICE_LIST === $price->source) {
            return $this->translator->trans('Price list %code%', ['%code%' => $code]);
        }

        // An exclusive list that does not price the combination closes it: the price shown is the one the catalog would give.
        $source = match ($price->source) {
            ResolvedPrice::SOURCE_CUSTOMER_GROUP => $this->translator->trans('Customer group price'),
            EffectivePrice::SOURCE_RULE => $this->translator->trans('Catalog price rule'),
            EffectivePrice::SOURCE_RESERVED_SALE => $this->translator->trans('Reserved sale'),
            EffectivePrice::SOURCE_SALE_UNIT => $this->translator->trans('Sale unit price'),
            EffectivePrice::SOURCE_BUNDLE => $this->translator->trans('Bundle'),
            default => $this->translator->trans('Catalog price'),
        };

        return '' === $code ? $source : $source.' ('.$this->translator->trans('closed by the exclusive price list %code%', ['%code%' => $code]).')';
    }

    private static function number(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $text = rtrim(number_format((float) $value, 6, '.', ''), '0');
        $decimals = \strlen($text) - (int) strpos($text, '.') - 1;

        return $decimals >= 2 ? $text : number_format((float) $value, 2, '.', '');
    }
}
