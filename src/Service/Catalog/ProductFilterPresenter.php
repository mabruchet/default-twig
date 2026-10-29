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

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Flattens a ProductFilters value object into a Twig-friendly array (chips,
 * option lists, rehydration values). Keeps the template declarative.
 */
final readonly class ProductFilterPresenter
{
    private const LIST_ROUTE = 'admin.products.default';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private ProductFilterCatalog $catalog,
    ) {
    }

    /**
     * @param list<array{id: int, title: string}> $categories
     *
     * @return array<string, mixed>
     */
    public function present(ProductFilters $filters, string $locale, array $categories): array
    {
        $brands = $this->catalog->brands($locale);
        $templates = $this->catalog->templates($locale);
        $features = $this->catalog->features($locale);
        $attributes = $this->catalog->attributes($locale);

        return [
            'is_empty' => $filters->isEmpty(),
            'advanced_count' => $this->countAdvancedFilters($filters),
            'active_chips' => $this->buildActiveChips($filters, $categories, $brands, $templates, $features, $attributes),
            'clear_all_url' => $this->urls->generate(self::LIST_ROUTE),

            'category_options' => $categories,
            'brand_options' => $brands,
            'template_options' => $templates,
            'feature_options' => $features,
            'attribute_options' => $attributes,
            'tristate_options' => $this->tristateOptions(),

            'selected_category_id' => $filters->categoryId,
            'selected_brand_id' => $filters->brandId,
            'selected_template_id' => $filters->templateId,
            'selected_feature_ids' => $filters->featureIds,
            'selected_attribute_ids' => $filters->attributeIds,
            'visible_value' => $this->triStateValue($filters->visible),
            'promo_value' => $this->triStateValue($filters->promo),
            'new_value' => $this->triStateValue($filters->new),
            'bundle_value' => $this->triStateValue($filters->bundle),
            'bundles_available' => BundleCore::present(),

            'min_price_input' => $this->formatNumber($filters->minPrice),
            'max_price_input' => $this->formatNumber($filters->maxPrice),
            'min_quantity_input' => $this->formatNumber($filters->minQuantity),
            'max_quantity_input' => $this->formatNumber($filters->maxQuantity),
            'price_slider' => $this->catalog->priceBounds(),
            'quantity_slider' => $this->catalog->quantityBounds(),
            'search_input' => $filters->search,

            'sort_field' => $filters->sort,
            'sort_direction' => $filters->direction,
        ];
    }

    /**
     * @param list<array{id: int, title: string}> $categories
     * @param list<array{id: int, title: string}> $brands
     * @param list<array{id: int, title: string}> $templates
     * @param list<array{id: int, title: string}> $features
     * @param list<array{id: int, title: string}> $attributes
     *
     * @return list<array{key: string, label: string, value: string, remove_url: string}>
     */
    private function buildActiveChips(
        ProductFilters $filters,
        array $categories,
        array $brands,
        array $templates,
        array $features,
        array $attributes,
    ): array {
        $chips = [];

        if ($filters->search !== '') {
            $chips[] = $this->chip($filters, ProductFilters::KEY_SEARCH, 'Search', $filters->search);
        }
        if ($filters->categoryId !== null) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_CATEGORY, 'Category', $this->titleFor($categories, $filters->categoryId));
        }
        if ($filters->brandId !== null) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_BRAND, 'Brand', $this->titleFor($brands, $filters->brandId));
        }
        if ($filters->templateId !== null) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_TEMPLATE, 'Template', $this->titleFor($templates, $filters->templateId));
        }
        if ($filters->visible !== null) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_VISIBLE, 'Online', $this->translator->trans($filters->visible ? 'Yes' : 'No'));
        }
        if ($filters->promo !== null) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_PROMO, 'On sale', $this->translator->trans($filters->promo ? 'Yes' : 'No'));
        }
        if ($filters->new !== null) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_NEW, 'New', $this->translator->trans($filters->new ? 'Yes' : 'No'));
        }
        if ($filters->bundle !== null) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_BUNDLE, 'Bundle', $this->translator->trans($filters->bundle ? 'Yes' : 'No'));
        }
        if ($filters->minPrice !== null || $filters->maxPrice !== null) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_PRICE, 'Price', $this->rangeLabel($filters->minPrice, $filters->maxPrice));
        }
        if ($filters->minQuantity !== null || $filters->maxQuantity !== null) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_QUANTITY, 'Stock', $this->rangeLabel($filters->minQuantity, $filters->maxQuantity));
        }
        if ($filters->featureIds !== []) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_FEATURES, 'Features', $this->titlesFor($features, $filters->featureIds));
        }
        if ($filters->attributeIds !== []) {
            $chips[] = $this->chip($filters, ProductFilters::KEY_ATTRIBUTES, 'Attributes', $this->titlesFor($attributes, $filters->attributeIds));
        }

        return $chips;
    }

    /**
     * @return array{key: string, label: string, value: string, remove_url: string}
     */
    private function chip(ProductFilters $filters, string $key, string $label, string $value): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans($label),
            'value' => $value,
            'remove_url' => $this->urls->generate(self::LIST_ROUTE, $filters->withoutFilter($key)->toQueryParams()),
        ];
    }

    private function countAdvancedFilters(ProductFilters $filters): int
    {
        $count = 0;
        foreach ([
            $filters->brandId !== null,
            $filters->templateId !== null,
            $filters->visible !== null,
            $filters->promo !== null,
            $filters->new !== null,
            $filters->bundle !== null,
            $filters->minPrice !== null || $filters->maxPrice !== null,
            $filters->minQuantity !== null || $filters->maxQuantity !== null,
            $filters->featureIds !== [],
            $filters->attributeIds !== [],
        ] as $isSet) {
            if ($isSet) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function tristateOptions(): array
    {
        return [
            ['value' => '', 'label' => $this->translator->trans('All')],
            ['value' => ProductFilters::TRISTATE_WITH, 'label' => $this->translator->trans('Yes')],
            ['value' => ProductFilters::TRISTATE_WITHOUT, 'label' => $this->translator->trans('No')],
        ];
    }

    private function triStateValue(?bool $value): string
    {
        return match ($value) {
            true => ProductFilters::TRISTATE_WITH,
            false => ProductFilters::TRISTATE_WITHOUT,
            default => '',
        };
    }

    /**
     * @param list<array{id: int, title: string}> $options
     */
    private function titleFor(array $options, int $id): string
    {
        foreach ($options as $option) {
            if ($option['id'] === $id) {
                return $option['title'];
            }
        }

        return '#'.$id;
    }

    /**
     * @param list<array{id: int, title: string}> $options
     * @param list<int>                           $ids
     */
    private function titlesFor(array $options, array $ids): string
    {
        $titles = array_map(fn (int $id): string => $this->titleFor($options, $id), $ids);

        return implode(', ', $titles);
    }

    private function rangeLabel(?float $min, ?float $max): string
    {
        if ($min !== null && $max !== null) {
            return $this->formatNumber($min).' - '.$this->formatNumber($max);
        }
        if ($min !== null) {
            return '≥ '.$this->formatNumber($min);
        }

        return '≤ '.$this->formatNumber($max);
    }

    private function formatNumber(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return $value === floor($value) ? (string) (int) $value : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
