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

namespace BackOfficeDefaultTwigBundle\Service\Order;

use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Flattens an OrderFilters value object into a Twig-friendly array (chips,
 * option lists, rehydration values). Keeps the template declarative.
 */
final readonly class OrderFilterPresenter
{
    private const LIST_ROUTE = 'admin.order.list';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private OrderFilterCatalog $catalog,
        private MoneyFormatter $money,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(OrderFilters $filters, string $locale): array
    {
        $statuses = $this->catalog->statuses($locale);
        $paymentModules = $this->catalog->paymentModules($locale);
        $deliveryModules = $this->catalog->deliveryModules($locale);
        $countries = $this->catalog->deliveryCountries($locale);
        $saleUnits = $this->catalog->saleUnits($locale);

        $statusIndex = $this->indexById($statuses);
        $paymentIndex = $this->indexById($paymentModules);
        $deliveryIndex = $this->indexById($deliveryModules);
        $countryIndex = $this->indexCountries($countries);

        return [
            'is_empty' => $filters->isEmpty(),
            'has_any_filter' => !$filters->isEmpty() || $filters->search !== '' || $filters->period !== OrderFilters::PERIOD_ALL,
            'advanced_count' => $this->countAdvancedFilters($filters),
            'active_chips' => $this->buildActiveChips(
                $filters,
                $statusIndex,
                $paymentIndex,
                $deliveryIndex,
                $countryIndex,
                $this->indexById($saleUnits),
                $locale,
            ),
            'clear_all_url' => $this->urls->generate(self::LIST_ROUTE),

            'status_options' => $statuses,
            'payment_module_options' => $paymentModules,
            'delivery_module_options' => $deliveryModules,
            'country_options' => $countries,
            'sale_unit_options' => $saleUnits,
            'tristate_options' => $this->tristateOptions(),

            'selected_status_ids' => $filters->statusIds,
            'selected_payment_module_ids' => $filters->paymentModuleIds,
            'selected_delivery_module_ids' => $filters->deliveryModuleIds,
            'selected_country_id' => $filters->countryId,
            'selected_sale_unit_ids' => $filters->saleUnitIds,

            'created_from_input' => $filters->createdFrom?->format('Y-m-d') ?? '',
            'created_to_input' => $filters->createdTo?->format('Y-m-d') ?? '',
            'min_amount_input' => $this->formatNumber($filters->minAmount),
            'max_amount_input' => $this->formatNumber($filters->maxAmount),
            'min_items_input' => $filters->minItems !== null ? (string) $filters->minItems : '',
            'max_items_input' => $filters->maxItems !== null ? (string) $filters->maxItems : '',
            'amount_slider' => $this->catalog->amountBounds(),
            'items_slider' => $this->catalog->itemsBounds(),
            'coupon_value' => $this->triStateValue($filters->hasCoupon),
            'tracking_value' => $this->triStateValue($filters->hasTracking),
            'preorder_value' => $this->triStateValue($filters->hasPreorder),
            'search_input' => $filters->search,

            'sort_field' => $filters->sort,
            'sort_direction' => $filters->direction,
            'period' => $filters->period,

            'quick_chips' => $this->buildQuickChips($filters),
        ];
    }

    /**
     * @param array<int, array{id: int, title: string, code: string, color: string}> $statusIndex
     * @param array<int, array{id: int, title: string, code: string}>                $paymentIndex
     * @param array<int, array{id: int, title: string, code: string}>                $deliveryIndex
     * @param array<int, array{id: int, title: string, flag: string, iso: string}>   $countryIndex
     * @param array<int, array{id: int, code: string, title: string, active: bool}>  $saleUnitIndex
     *
     * @return list<array{key: string, label: string, value: string, remove_url: string}>
     */
    private function buildActiveChips(
        OrderFilters $filters,
        array $statusIndex,
        array $paymentIndex,
        array $deliveryIndex,
        array $countryIndex,
        array $saleUnitIndex,
        string $locale,
    ): array {
        $chips = [];

        if ($filters->statusIds !== []) {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_STATUS_IDS,
                $this->translator->trans('Status'),
                $this->joinTitles($filters->statusIds, $statusIndex),
            );
        }

        $rangeLabel = $this->dateRangeLabel($filters->createdFrom, $filters->createdTo);
        if ($rangeLabel !== '') {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_CREATED_RANGE,
                $this->translator->trans('Date'),
                $rangeLabel,
            );
        }

        $amountLabel = $this->amountRangeLabel($filters->minAmount, $filters->maxAmount, $locale);
        if ($amountLabel !== '') {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_AMOUNT_RANGE,
                $this->translator->trans('Amount'),
                $amountLabel,
            );
        }

        if ($filters->paymentModuleIds !== []) {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_PAYMENT_MODULE_IDS,
                $this->translator->trans('Payment'),
                $this->joinTitles($filters->paymentModuleIds, $paymentIndex),
            );
        }

        if ($filters->deliveryModuleIds !== []) {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_DELIVERY_MODULE_IDS,
                $this->translator->trans('Delivery'),
                $this->joinTitles($filters->deliveryModuleIds, $deliveryIndex),
            );
        }

        if ($filters->countryId !== null && isset($countryIndex[$filters->countryId])) {
            $country = $countryIndex[$filters->countryId];
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_COUNTRY_ID,
                $this->translator->trans('Delivery country'),
                trim($country['flag'].' '.$country['title']),
            );
        }

        if ($filters->saleUnitIds !== []) {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_SALE_UNIT_IDS,
                $this->translator->trans('Sale unit'),
                $this->joinTitles($filters->saleUnitIds, $saleUnitIndex),
            );
        }

        $itemsLabel = $this->itemsRangeLabel($filters->minItems, $filters->maxItems);
        if ($itemsLabel !== '') {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_ITEMS_RANGE,
                $this->translator->trans('Items'),
                $itemsLabel,
            );
        }

        if ($filters->hasCoupon !== null) {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_COUPON,
                $this->translator->trans('Coupon'),
                $filters->hasCoupon
                    ? $this->translator->trans('with')
                    : $this->translator->trans('without'),
            );
        }

        if ($filters->hasPreorder !== null) {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_PREORDER,
                $this->translator->trans('Preorder'),
                $filters->hasPreorder
                    ? $this->translator->trans('with')
                    : $this->translator->trans('without'),
            );
        }

        if ($filters->hasTracking !== null) {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_TRACKING,
                $this->translator->trans('Tracking'),
                $filters->hasTracking
                    ? $this->translator->trans('with')
                    : $this->translator->trans('without'),
            );
        }

        if ($filters->search !== '') {
            $chips[] = $this->chip(
                $filters,
                OrderFilters::KEY_SEARCH,
                $this->translator->trans('Search'),
                $filters->search,
            );
        }

        return $chips;
    }

    /**
     * @return array{key: string, label: string, value: string, remove_url: string}
     */
    private function chip(OrderFilters $filters, string $key, string $label, string $value): array
    {
        $afterRemoval = $filters->withoutFilter($key);

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'remove_url' => $this->urls->generate(self::LIST_ROUTE, $afterRemoval->toQueryParams()),
        ];
    }

    /**
     * @param list<int>                                               $ids
     * @param array<int, array{id: int, title: string, code: string}> $index
     */
    private function joinTitles(array $ids, array $index): string
    {
        $titles = [];
        foreach ($ids as $id) {
            if (isset($index[$id])) {
                $titles[] = $index[$id]['title'];
            }
        }

        return implode(', ', $titles);
    }

    /**
     * @template TItem of array{id: int}
     *
     * @param list<TItem> $items
     *
     * @return array<int, TItem>
     */
    private function indexById(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            $index[$item['id']] = $item;
        }

        return $index;
    }

    /**
     * @param list<array{id: int, title: string, flag: string, iso: string}> $countries
     *
     * @return array<int, array{id: int, title: string, flag: string, iso: string}>
     */
    private function indexCountries(array $countries): array
    {
        $index = [];
        foreach ($countries as $country) {
            $index[$country['id']] = $country;
        }

        return $index;
    }

    private function dateRangeLabel(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): string
    {
        if ($from === null && $to === null) {
            return '';
        }

        if ($from !== null && $to !== null) {
            return $from->format('d/m/Y').' - '.$to->format('d/m/Y');
        }

        if ($from !== null) {
            return $this->translator->trans('from %date%', ['%date%' => $from->format('d/m/Y')]);
        }

        return $this->translator->trans('until %date%', ['%date%' => $to->format('d/m/Y')]);
    }

    private function amountRangeLabel(?float $min, ?float $max, string $locale): string
    {
        if ($min === null && $max === null) {
            return '';
        }

        if ($min !== null && $max !== null) {
            return $this->money->format($min, $locale).' - '.$this->money->format($max, $locale);
        }

        if ($min !== null) {
            return '≥ '.$this->money->format($min, $locale);
        }

        return '≤ '.$this->money->format($max, $locale);
    }

    private function itemsRangeLabel(?int $min, ?int $max): string
    {
        if ($min === null && $max === null) {
            return '';
        }

        if ($min !== null && $max !== null) {
            return $min.' - '.$max;
        }

        return $min !== null ? '≥ '.$min : '≤ '.$max;
    }

    private function formatNumber(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function triStateValue(?bool $value): string
    {
        if ($value === null) {
            return '';
        }

        return $value ? OrderFilters::TRISTATE_WITH : OrderFilters::TRISTATE_WITHOUT;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function tristateOptions(): array
    {
        return [
            ['value' => '', 'label' => $this->translator->trans('All')],
            ['value' => OrderFilters::TRISTATE_WITH, 'label' => $this->translator->trans('With')],
            ['value' => OrderFilters::TRISTATE_WITHOUT, 'label' => $this->translator->trans('Without')],
        ];
    }

    private function countAdvancedFilters(OrderFilters $filters): int
    {
        $count = 0;

        if ($filters->statusIds !== []) {
            ++$count;
        }
        if ($filters->createdFrom !== null || $filters->createdTo !== null) {
            ++$count;
        }
        if ($filters->minAmount !== null || $filters->maxAmount !== null) {
            ++$count;
        }
        if ($filters->paymentModuleIds !== []) {
            ++$count;
        }
        if ($filters->deliveryModuleIds !== []) {
            ++$count;
        }
        if ($filters->countryId !== null) {
            ++$count;
        }
        if ($filters->saleUnitIds !== []) {
            ++$count;
        }
        if ($filters->minItems !== null || $filters->maxItems !== null) {
            ++$count;
        }
        if ($filters->hasCoupon !== null) {
            ++$count;
        }
        if ($filters->hasTracking !== null) {
            ++$count;
        }
        if ($filters->hasPreorder !== null) {
            ++$count;
        }

        return $count;
    }

    /**
     * @return list<array{code: string, label: string, icon: string, url: string, active: bool}>
     */
    private function buildQuickChips(OrderFilters $filters): array
    {
        $chips = [];
        foreach (OrderFilters::QUICK_CHIPS as $definition) {
            $code = $definition['code'];
            $afterApply = $filters->cloneForPeriodShortcut($code);
            $chips[] = [
                'code' => $code,
                'label' => $this->translator->trans($definition['label']),
                'icon' => $definition['icon'],
                'url' => $this->urls->generate(self::LIST_ROUTE, $afterApply->toQueryParams()),
                'active' => $filters->period === $code,
            ];
        }

        return $chips;
    }
}
