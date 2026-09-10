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

namespace BackOfficeDefaultTwigBundle\Service\Coupon;

use Thelia\Domain\Promotion\Coupon\Type\AbstractRemoveOnAttributeValues;
use Thelia\Domain\Promotion\Coupon\Type\AbstractRemoveOnCategories;
use Thelia\Domain\Promotion\Coupon\Type\AbstractRemoveOnProducts;
use Thelia\Domain\Promotion\Coupon\Type\BuyXGetY;
use Thelia\Domain\Promotion\Coupon\Type\CouponInterface;
use Thelia\Domain\Promotion\Coupon\Type\FreeProduct;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\AttributeQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\ProductQuery;
use Twig\Environment;

/**
 * Renders BO Twig inputs for the active coupon type.
 *
 * For native types, a dedicated Twig partial is rendered with explicit parameters.
 * For third-party types (unknown service ids), a generic amount-only fallback is rendered
 * so the field name expected by the legacy {@see CouponInterface::getEffects()} is preserved.
 *
 * The fragment is normally filled from what the coupon type made of its stored
 * effects. When a save was refused, it is filled from what the merchant just
 * posted instead: the type reads its own posted fields back through
 * setFieldsValue(), so the rules it applies to them live in one place only.
 */
final readonly class CouponInputsRenderer
{
    private const SERVICE_AMOUNT_AND_PERCENTAGE = [
        'thelia.coupon.type.remove_x_amount' => true,
    ];

    private const SERVICE_PERCENTAGE_ONLY = [
        'thelia.coupon.type.remove_x_percent' => true,
    ];

    private const SERVICE_ON_CATEGORIES_AMOUNT = [
        'thelia.coupon.type.remove_amount_on_categories' => true,
    ];

    private const SERVICE_ON_CATEGORIES_PERCENT = [
        'thelia.coupon.type.remove_percentage_on_categories' => true,
    ];

    private const SERVICE_ON_PRODUCTS_AMOUNT = [
        'thelia.coupon.type.remove_amount_on_products' => true,
    ];

    private const SERVICE_ON_PRODUCTS_PERCENT = [
        'thelia.coupon.type.remove_percentage_on_products' => true,
    ];

    private const SERVICE_ON_ATTRIBUTE_AV_AMOUNT = [
        'thelia.coupon.type.remove_amount_on_attribute_av' => true,
    ];

    private const SERVICE_ON_ATTRIBUTE_AV_PERCENT = [
        'thelia.coupon.type.remove_percentage_on_attribute_av' => true,
    ];

    private const SERVICE_FREE_PRODUCT = 'thelia.coupon.type.free_product';

    private const SERVICE_BUY_X_GET_Y = 'thelia.coupon.type.buy_x_get_y';

    public function __construct(
        private Environment $twig,
    ) {
    }

    /**
     * @param array<string, mixed> $postedEffects the raw coupon_specific payload of a refused save, empty otherwise
     */
    public function renderForServiceId(string $serviceId, ?CouponInterface $manager, array $postedEffects = []): string
    {
        if ($serviceId === '' || $serviceId === '0') {
            return '';
        }

        $manager = $this->hydratedFromPostedEffects($manager, $postedEffects);

        $params = [
            'currency_symbol' => $this->currencySymbol(),
            'amount_value' => $this->amountValue($manager),
            'percentage_value' => $this->percentageValue($manager),
        ];

        $template = $this->resolveTemplate($serviceId);
        $params['service_id'] = $serviceId;

        if ($this->needsCategoriesPicker($serviceId)) {
            $params['categories'] = $this->categoryChoices();
            $params['selected_categories'] = $this->postedIdList($postedEffects, AbstractRemoveOnCategories::CATEGORIES_LIST)
                ?? $this->selectedCategories($manager);
        }

        if ($this->needsProductsPicker($serviceId) || $serviceId === self::SERVICE_FREE_PRODUCT || $serviceId === self::SERVICE_BUY_X_GET_Y) {
            $params['categories'] ??= $this->categoryChoices();
            $params['products_by_category'] = $this->productChoicesByCategory();
            $params['selected_products'] = $this->postedIdList($postedEffects, AbstractRemoveOnProducts::PRODUCTS_LIST)
                ?? $this->selectedProducts($manager);
        }

        if ($serviceId === self::SERVICE_BUY_X_GET_Y) {
            $params['products'] = $this->flattenProductChoices($params['products_by_category']);
            $params += $this->buyXGetYValues($manager);

            // A refused save comes back with the figures the merchant typed, not the
            // clamped reading the type made of them: the refused value is the one to fix.
            foreach ([
                'trigger_quantity_value' => BuyXGetY::TRIGGER_QUANTITY_FIELD,
                'offered_quantity_value' => BuyXGetY::OFFERED_QUANTITY_FIELD,
                'discount_value_value' => BuyXGetY::DISCOUNT_VALUE_FIELD,
            ] as $param => $field) {
                if (isset($postedEffects[$field]) && \is_scalar($postedEffects[$field])) {
                    $params[$param] = (string) $postedEffects[$field];
                }
            }
        }

        if ($this->needsAttributeAvPicker($serviceId)) {
            $params['attributes'] = $this->attributeChoices();
            $params['attribute_value'] = $this->postedInt($postedEffects, AbstractRemoveOnAttributeValues::ATTRIBUTE)
                ?? ($manager instanceof AbstractRemoveOnAttributeValues ? (int) $manager->attribute : 0);
            $params['attribute_av_values'] = $this->postedIdList($postedEffects, AbstractRemoveOnAttributeValues::ATTRIBUTES_AV_LIST)
                ?? ($manager instanceof AbstractRemoveOnAttributeValues ? array_map('intval', $manager->attributeAvList) : []);
            $params['attribute_avs'] = $params['attribute_value'] > 0
                ? $this->attributeAvChoices($params['attribute_value'])
                : [];
        }

        if ($manager instanceof FreeProduct) {
            $params['offered_product_id'] = $this->reflectProperty($manager, 'offeredProductId');
            $params['offered_category_id'] = $this->reflectProperty($manager, 'offeredCategoryId');
        }

        return $this->twig->render($template, $params);
    }

    /**
     * The coupon type filled in with what was just posted, so a refused save
     * comes back with the merchant's own values instead of the stored ones.
     *
     * The type does the reading itself: every rule it applies to its fields
     * (unknown values falling back, quantities floored) stays where it is
     * written, and this renderer keeps no copy of it.
     *
     * @param array<string, mixed> $postedEffects
     */
    private function hydratedFromPostedEffects(?CouponInterface $manager, array $postedEffects): ?CouponInterface
    {
        if ($manager === null || $postedEffects === [] || !method_exists($manager, 'setFieldsValue')) {
            return $manager;
        }

        // Cloned: the coupon types are shared services, and this instance is
        // filled in for the sole purpose of drawing one form.
        $hydrated = clone $manager;

        try {
            $hydrated->setFieldsValue($postedEffects);
        } catch (\Throwable) {
            // A type stricter than the form about one of its fields: showing the
            // stored setup beats showing an empty fragment.
            return $manager;
        }

        return $hydrated;
    }

    /**
     * @param array<string, mixed> $postedEffects
     *
     * @return list<int>|null the posted ids, or null when the form posted no such field
     */
    private function postedIdList(array $postedEffects, string $key): ?array
    {
        if (!\array_key_exists($key, $postedEffects)) {
            return null;
        }

        $value = $postedEffects[$key];

        if (!\is_array($value)) {
            $value = ($value === null || $value === '') ? [] : [$value];
        }

        return array_values(array_map('\intval', $value));
    }

    /**
     * @param array<string, mixed> $postedEffects
     */
    private function postedInt(array $postedEffects, string $key): ?int
    {
        return \array_key_exists($key, $postedEffects) ? (int) $postedEffects[$key] : null;
    }

    private function needsCategoriesPicker(string $serviceId): bool
    {
        return isset(self::SERVICE_ON_CATEGORIES_AMOUNT[$serviceId])
            || isset(self::SERVICE_ON_CATEGORIES_PERCENT[$serviceId]);
    }

    private function needsProductsPicker(string $serviceId): bool
    {
        return isset(self::SERVICE_ON_PRODUCTS_AMOUNT[$serviceId])
            || isset(self::SERVICE_ON_PRODUCTS_PERCENT[$serviceId]);
    }

    private function needsAttributeAvPicker(string $serviceId): bool
    {
        return isset(self::SERVICE_ON_ATTRIBUTE_AV_AMOUNT[$serviceId])
            || isset(self::SERVICE_ON_ATTRIBUTE_AV_PERCENT[$serviceId]);
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function attributeChoices(): array
    {
        $locale = $this->defaultLocale();
        $items = [];
        foreach (AttributeQuery::create()->orderByPosition()->joinWithI18n($locale)->find() as $attribute) {
            $attribute->setLocale($locale);
            $items[] = ['id' => (int) $attribute->getId(), 'title' => (string) $attribute->getTitle()];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function attributeAvChoices(int $attributeId): array
    {
        if ($attributeId < 1) {
            return [];
        }

        $locale = $this->defaultLocale();
        $items = [];
        foreach (AttributeAvQuery::create()->filterByAttributeId($attributeId)->orderByPosition()->joinWithI18n($locale)->find() as $av) {
            $av->setLocale($locale);
            $items[] = ['id' => (int) $av->getId(), 'title' => (string) $av->getTitle()];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function categoryChoices(): array
    {
        $locale = $this->defaultLocale();
        $items = [];
        foreach (CategoryQuery::create()->orderByPosition()->joinWithI18n($locale)->find() as $category) {
            $category->setLocale($locale);
            $items[] = ['id' => (int) $category->getId(), 'title' => (string) $category->getTitle()];
        }

        return $items;
    }

    /**
     * The saved "Buy X, get Y" setup, read back from the coupon type so the form
     * shows exactly what the type itself made of the stored effects.
     *
     * @return array<string, mixed>
     */
    private function buyXGetYValues(?CouponInterface $manager): array
    {
        if (!$manager instanceof BuyXGetY) {
            return [
                'trigger_scope_value' => BuyXGetY::TRIGGER_SCOPE_PRODUCT,
                'trigger_ids_values' => [],
                'trigger_quantity_value' => 1,
                'target_mode_value' => BuyXGetY::TARGET_MODE_SAME,
                'target_product_id_value' => null,
                'offered_quantity_value' => 1,
                'discount_type_value' => BuyXGetY::DISCOUNT_TYPE_FREE,
                'discount_value_value' => '',
            ];
        }

        $targetProductId = (int) $this->reflectProperty($manager, 'targetProductId');
        $discountValue = (float) $this->reflectProperty($manager, 'discountValue');

        return [
            'trigger_scope_value' => $this->reflectProperty($manager, 'triggerScope'),
            'trigger_ids_values' => array_map('\intval', $this->reflectArray($manager, 'triggerIds')),
            'trigger_quantity_value' => (int) $this->reflectProperty($manager, 'triggerQuantity'),
            'target_mode_value' => $this->reflectProperty($manager, 'targetMode'),
            'target_product_id_value' => $targetProductId > 0 ? $targetProductId : null,
            'offered_quantity_value' => (int) $this->reflectProperty($manager, 'offeredQuantity'),
            'discount_type_value' => $this->reflectProperty($manager, 'discountType'),
            'discount_value_value' => $discountValue > 0.0 ? rtrim(rtrim(number_format($discountValue, 2, '.', ''), '0'), '.') : '',
        ];
    }

    /**
     * The flat product list, taken from the per-category map rather than from a
     * second query: the two always hold the same products.
     *
     * @param array<int, list<array{id: int, title: string}>> $productsByCategory
     *
     * @return list<array{id: int, title: string}>
     */
    private function flattenProductChoices(array $productsByCategory): array
    {
        $items = array_merge([], ...array_values($productsByCategory));

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $items;
    }

    /**
     * Two queries whatever the catalogue size: the products with their titles
     * joined in, and the product/category rows that say which category each
     * product belongs to by default.
     *
     * @return array<int, list<array{id: int, title: string}>>
     */
    private function productChoicesByCategory(): array
    {
        $locale = $this->defaultLocale();

        $defaultCategoryIds = [];
        foreach (ProductCategoryQuery::create()->filterByDefaultCategory(true)->find() as $productCategory) {
            $defaultCategoryIds[(int) $productCategory->getProductId()] = (int) $productCategory->getCategoryId();
        }

        $grouped = [];
        foreach (ProductQuery::create()->orderByPosition()->joinWithI18n($locale)->find() as $product) {
            $product->setLocale($locale);
            $productId = (int) $product->getId();
            $grouped[$defaultCategoryIds[$productId] ?? 0][] = ['id' => $productId, 'title' => (string) $product->getTitle()];
        }

        return $grouped;
    }

    /**
     * @return list<int>
     */
    private function selectedCategories(?CouponInterface $manager): array
    {
        if (!$manager instanceof AbstractRemoveOnCategories) {
            return [];
        }

        return array_map('intval', (array) $this->reflectArray($manager, 'category_list'));
    }

    /**
     * @return list<int>
     */
    private function selectedProducts(?CouponInterface $manager): array
    {
        if (!$manager instanceof AbstractRemoveOnProducts) {
            return [];
        }

        return array_map('intval', (array) $this->reflectArray($manager, 'product_list'));
    }

    private function reflectProperty(object $obj, string $name): string
    {
        try {
            $reflection = new \ReflectionClass($obj);
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);

                return (string) ($property->getValue($obj) ?? '');
            }
        } catch (\ReflectionException) {
        }

        return '';
    }

    private function reflectArray(object $obj, string $name): array
    {
        try {
            $reflection = new \ReflectionClass($obj);
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                $value = $property->getValue($obj);

                return \is_array($value) ? $value : [];
            }
        } catch (\ReflectionException) {
        }

        return [];
    }

    private function defaultLocale(): string
    {
        $lang = LangQuery::create()->findOneByByDefault(1);

        return $lang?->getLocale() ?? 'en_US';
    }

    private function resolveTemplate(string $serviceId): string
    {
        return match (true) {
            isset(self::SERVICE_AMOUNT_AND_PERCENTAGE[$serviceId]) => '@BackOfficeDefaultTwig/coupon/type-fragments/remove-x-amount.html.twig',
            isset(self::SERVICE_PERCENTAGE_ONLY[$serviceId]) => '@BackOfficeDefaultTwig/coupon/type-fragments/remove-x-percent.html.twig',
            isset(self::SERVICE_ON_CATEGORIES_AMOUNT[$serviceId]) => '@BackOfficeDefaultTwig/coupon/type-fragments/remove-amount-on-categories.html.twig',
            isset(self::SERVICE_ON_CATEGORIES_PERCENT[$serviceId]) => '@BackOfficeDefaultTwig/coupon/type-fragments/remove-percentage-on-categories.html.twig',
            isset(self::SERVICE_ON_PRODUCTS_AMOUNT[$serviceId]) => '@BackOfficeDefaultTwig/coupon/type-fragments/remove-amount-on-products.html.twig',
            isset(self::SERVICE_ON_PRODUCTS_PERCENT[$serviceId]) => '@BackOfficeDefaultTwig/coupon/type-fragments/remove-percentage-on-products.html.twig',
            isset(self::SERVICE_ON_ATTRIBUTE_AV_AMOUNT[$serviceId]) => '@BackOfficeDefaultTwig/coupon/type-fragments/remove-amount-on-attributes.html.twig',
            isset(self::SERVICE_ON_ATTRIBUTE_AV_PERCENT[$serviceId]) => '@BackOfficeDefaultTwig/coupon/type-fragments/remove-percentage-on-attributes.html.twig',
            $serviceId === self::SERVICE_FREE_PRODUCT => '@BackOfficeDefaultTwig/coupon/type-fragments/free-product.html.twig',
            $serviceId === self::SERVICE_BUY_X_GET_Y => '@BackOfficeDefaultTwig/coupon/type-fragments/buy-x-get-y.html.twig',
            default => '@BackOfficeDefaultTwig/coupon/type-fragments/remove-x.html.twig',
        };
    }

    private function amountValue(?CouponInterface $manager): string
    {
        if ($manager === null) {
            return '';
        }

        try {
            $reflection = new \ReflectionClass($manager);
            if ($reflection->hasProperty('amount')) {
                $property = $reflection->getProperty('amount');

                return (string) ($property->getValue($manager) ?? '');
            }
        } catch (\ReflectionException) {
            // fallthrough
        }

        return '';
    }

    private function percentageValue(?CouponInterface $manager): string
    {
        if ($manager === null) {
            return '';
        }

        try {
            $reflection = new \ReflectionClass($manager);
            if ($reflection->hasProperty('percentage')) {
                $property = $reflection->getProperty('percentage');

                return (string) ($property->getValue($manager) ?? '');
            }
        } catch (\ReflectionException) {
            // fallthrough
        }

        return '';
    }

    private function currencySymbol(): string
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1);

        return $currency === null ? '$' : (string) $currency->getSymbol();
    }
}
