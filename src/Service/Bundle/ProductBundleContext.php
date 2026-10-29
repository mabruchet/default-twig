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

use BackOfficeDefaultTwigBundle\Service\Catalog\ProductPricingProvider;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\Service\Product\CombinationLabeler;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Domain\Catalog\Bundle\BundleAvailability;
use Thelia\Domain\Catalog\Bundle\BundleComponent;
use Thelia\Domain\Catalog\Bundle\BundleCompositionResolver;
use Thelia\Domain\Catalog\Bundle\BundleDecimal;
use Thelia\Domain\Catalog\Bundle\BundleDiscountType;
use Thelia\Domain\Catalog\Bundle\BundlePriceMode;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Model\ProductBundleItem;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Tools\TokenProvider;

/**
 * What the product sheet shows of a bundle: its components, and the card that sums them up.
 *
 * The components, their combinations, their titles and their sellability are read in a
 * handful of queries whatever their number, and the price and the availability of the
 * bundle come from one call each to the core, which owns both calculations.
 *
 * The theme installs on a core that may predate the bundles: the context is then neutral
 * and the sheet shows neither the tab nor the fields.
 */
final readonly class ProductBundleContext
{
    public const TAB = 'bundle';

    public function __construct(
        private BundleCore $core,
        private CombinationLabeler $labeler,
        private ProductPricingProvider $pricing,
        private MoneyFormatter $money,
        private UrlGeneratorInterface $urls,
        private TokenProvider $tokens,
    ) {
    }

    /**
     * @return array{
     *     bundle_available: bool,
     *     bundle_is: bool,
     *     bundle_items: list<array<string, mixed>>,
     *     bundle_summary: array<string, mixed>|null,
     *     bundle_urls: array<string, string>,
     *     bundle_token: string|null,
     * }
     */
    public function build(Product $product, string $uiLocale): array
    {
        $neutral = [
            'bundle_available' => false,
            'bundle_is' => false,
            'bundle_items' => [],
            'bundle_summary' => null,
            'bundle_urls' => [],
            'bundle_token' => null,
        ];

        if (!$this->core->isAvailable()) {
            return $neutral;
        }

        if (!$product->getIsBundle()) {
            return ['bundle_available' => true] + $neutral;
        }

        $productId = (int) $product->getId();
        $items = $this->core->items()->forBundles([$productId])[$productId] ?? [];
        $components = $this->components($items);
        $rows = $this->rows($items, $components, $uiLocale);

        return [
            'bundle_available' => true,
            'bundle_is' => true,
            'bundle_items' => $rows,
            'bundle_summary' => $this->summary($product, $items, $components, $uiLocale),
            'bundle_urls' => [
                'add' => $this->urls->generate('admin.products.bundle.items.add'),
                'update' => $this->urls->generate('admin.products.bundle.items.update'),
                'delete' => $this->urls->generate('admin.products.bundle.items.delete'),
                'position' => $this->urls->generate('admin.products.bundle.items.position'),
                'products' => $this->urls->generate('admin.product.accessories-content', ['productId' => $productId, 'categoryId' => 0, '_format' => 'json']).'?bundle_component=1',
                'combinations' => $this->urls->generate('admin.products.bundle.component.combinations', ['bundleProductId' => $productId, 'productId' => 0]),
            ],
            'bundle_token' => $this->tokens->assignToken(),
        ];
    }

    /**
     * @param list<ProductBundleItem> $items
     *
     * @return array<int, BundleComponent>
     */
    private function components(array $items): array
    {
        $saleElementsIds = [];
        foreach ($items as $item) {
            if (null !== $item->getProductSaleElementsId()) {
                $saleElementsIds[] = (int) $item->getProductSaleElementsId();
            }
        }

        return [] === $saleElementsIds ? [] : $this->core->components()->read($saleElementsIds);
    }

    /**
     * @param list<ProductBundleItem>     $items
     * @param array<int, BundleComponent> $components
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $items, array $components, string $uiLocale): array
    {
        if ([] === $items) {
            return [];
        }

        $saleElementsIds = [];
        foreach ($items as $item) {
            if (null !== $item->getProductSaleElementsId()) {
                $saleElementsIds[] = (int) $item->getProductSaleElementsId();
            }
        }

        $productIdBySaleElements = [];
        foreach (ProductSaleElementsQuery::create()->filterById($saleElementsIds, Criteria::IN)->find() as $saleElements) {
            $productIdBySaleElements[(int) $saleElements->getId()] = (int) $saleElements->getProductId();
        }

        // The titles are read apart: a product with no translation in this language has none to
        // join, and asking the model for it would cost one query per such product.
        $productIds = array_values($productIdBySaleElements);
        $refs = [];
        foreach (ProductQuery::create()->filterById($productIds, Criteria::IN)->select(['Id', 'Ref'])->find() as $component) {
            $refs[(int) $component['Id']] = (string) $component['Ref'];
        }
        $titles = [];
        foreach (ProductI18nQuery::create()->filterById($productIds, Criteria::IN)->filterByLocale($uiLocale)->find() as $translation) {
            $titles[(int) $translation->getId()] = (string) $translation->getTitle();
        }

        $labels = $this->labeler->labelsFor($saleElementsIds, $uiLocale);

        $rows = [];
        foreach ($items as $item) {
            $saleElementsId = $item->getProductSaleElementsId();
            $deleted = null === $saleElementsId || !isset($productIdBySaleElements[(int) $saleElementsId]);
            $componentId = $deleted ? null : $productIdBySaleElements[(int) $saleElementsId];

            $rows[] = [
                'id' => (int) $item->getId(),
                'sale_elements_id' => $deleted ? null : (int) $saleElementsId,
                'product_id' => $componentId,
                'product_title' => null === $componentId ? '' : (($titles[$componentId] ?? '') ?: ($refs[$componentId] ?? '')),
                'product_url' => null === $componentId ? null : $this->urls->generate('admin.products.update', ['product_id' => $componentId]),
                'combination' => $deleted ? '' : ($labels[(int) $saleElementsId] ?? ''),
                'quantity' => $this->trimDecimal((string) $item->getQuantity()),
                'min_quantity' => null === $item->getMinQuantity() ? '' : $this->trimDecimal((string) $item->getMinQuantity()),
                'max_quantity' => null === $item->getMaxQuantity() ? '' : $this->trimDecimal((string) $item->getMaxQuantity()),
                'required' => (bool) $item->getRequired(),
                'position' => (int) $item->getPosition(),
                'deleted' => $deleted,
                'sellable' => !$deleted && ($components[(int) $saleElementsId]->sellable ?? false),
            ];
        }

        return $rows;
    }

    /**
     * @param list<ProductBundleItem>     $items
     * @param array<int, BundleComponent> $components
     *
     * @return array<string, mixed>
     */
    private function summary(Product $product, array $items, array $components, string $uiLocale): array
    {
        $productId = (int) $product->getId();
        $currency = Currency::getDefaultCurrency();
        $computed = BundlePriceMode::Computed->value === $product->getBundlePriceMode();

        $price = $computed ? ($this->core->calculator()->forBundles([$productId], $currency)[$productId] ?? null) : null;
        $availability = $this->core->availability()->forBundles([$productId])[$productId] ?? 0;

        // The default composition is the core's own: an optional component that cannot be sold is
        // left out of it, so it never blocks the bundle; a retained one that cannot be sold does.
        $blockingComponent = false;
        foreach (BundleCompositionResolver::defaultComposition($items, $components)->lines as $line) {
            $saleElementsId = null;
            foreach ($items as $item) {
                if ((int) $item->getId() === $line->bundleItemId) {
                    $saleElementsId = (int) $item->getProductSaleElementsId();
                }
            }
            if (BundleDecimal::toMicro($line->quantity) > 0 && !($components[$saleElementsId]->sellable ?? false)) {
                $blockingComponent = true;
            }
        }

        $state = match (true) {
            [] === $items => 'empty',
            $blockingComponent => 'component_unavailable',
            0 === $availability => 'out_of_stock',
            default => 'sellable',
        };

        $fixedPrice = $computed ? null : ($this->pricing->forProducts([$productId])[$productId]->price ?? null);

        return [
            'computed' => $computed,
            'full_price' => $price ? $this->money->format((float) $price->untaxedFullPrice, $uiLocale) : null,
            'price' => $price ? $this->money->format((float) $price->untaxedPrice, $uiLocale) : (null === $fixedPrice ? null : $this->money->format($fixedPrice, $uiLocale)),
            'has_discount' => $price?->hasDiscount() ?? false,
            'discount_percent' => $price?->hasDiscount() && BundleDiscountType::Percent->value === $product->getBundleDiscountType() ? $this->trimDecimal((string) $product->getBundleDiscount()) : null,
            'discount_amount' => $price?->hasDiscount() && BundleDiscountType::Amount->value === $product->getBundleDiscountType() ? $this->money->format((float) $product->getBundleDiscount(), $uiLocale) : null,
            'availability' => BundleAvailability::UNLIMITED === $availability ? null : $availability,
            'state' => $state,
            'sellable' => 'sellable' === $state,
        ];
    }

    private function trimDecimal(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}
