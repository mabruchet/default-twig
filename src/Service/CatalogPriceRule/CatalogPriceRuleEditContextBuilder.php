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

namespace BackOfficeDefaultTwigBundle\Service\CatalogPriceRule;

use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerChoiceProvider;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Pricing\Rule\Overview\CatalogPriceRuleOverviewQuery;
use Thelia\Model\AttributeQuery;
use Thelia\Model\BrandQuery;
use Thelia\Model\CatalogPriceRule;
use Thelia\Model\CatalogPriceRuleCustomer;
use Thelia\Model\CategoryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\TemplateQuery;

/**
 * Everything the edit screen of a rule shows: the form data, the choices of every
 * criterion type with what the rule names, the per-currency values, the audience,
 * and what the rule covers today.
 */
final readonly class CatalogPriceRuleEditContextBuilder
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private CustomerChoiceProvider $customerChoices,
        private AdminAccessChecker $access,
        private CatalogPriceRuleOverviewQuery $overview,
        private CustomerGroupRepository $customerGroups,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(CatalogPriceRule $rule, string $locale): array
    {
        $criteria = $rule->getCriteriaByType();
        $selectedCustomerIds = $this->customerIds($rule);
        $selectedGroupIds = $rule->getCustomerGroupIds();
        $canTargetCustomers = $this->canTargetCustomers();
        $overview = $this->overview->forRule((int) $rule->getId());

        return [
            'form_data' => [
                'id' => (int) $rule->getId(),
                'locale' => $locale,
                'title' => (string) $rule->getTitle(),
                'description' => (string) $rule->getDescription(),
                'active' => (bool) $rule->getActive(),
                'priority' => (int) $rule->getPriority(),
                'stop_processing' => (bool) $rule->getStopProcessing(),
                'start_date' => $rule->getStartDate('Y-m-d H:i:s'),
                'end_date' => $rule->getEndDate('Y-m-d H:i:s'),
                'effect_type' => (int) $rule->getEffectType(),
                'percentage_value' => null === $rule->getPercentageValue() ? null : (float) $rule->getPercentageValue(),
                'display_initial_price' => (bool) $rule->getDisplayInitialPrice(),
                'include_subcategories' => (bool) $rule->getIncludeSubcategories(),
                'audience_mode' => (int) $rule->getAudienceMode(),
            ],
            'criteria' => [
                'category' => ['choices' => $this->categoryChoices($locale), 'selected' => $criteria[CatalogPriceRule::CRITERION_CATEGORY] ?? []],
                'brand' => ['choices' => $this->brandChoices($locale), 'selected' => $criteria[CatalogPriceRule::CRITERION_BRAND] ?? []],
                'template' => ['choices' => $this->templateChoices($locale), 'selected' => $criteria[CatalogPriceRule::CRITERION_TEMPLATE] ?? []],
                'feature_av' => ['groups' => $this->featureValueGroups($locale), 'selected' => $criteria[CatalogPriceRule::CRITERION_FEATURE_AV] ?? []],
                'attribute_av' => ['groups' => $this->attributeValueGroups($locale), 'selected' => $criteria[CatalogPriceRule::CRITERION_ATTRIBUTE_AV] ?? []],
            ],
            'unknown_criterion_types' => null === $overview ? [] : $overview->unknownCriterionTypes,
            'selected_products' => $this->products($criteria[CatalogPriceRule::CRITERION_PRODUCT] ?? [], $locale),
            'currencies' => $this->currencyValues($rule->getEffectValuesByCurrency()),
            'products_url' => $this->urls->generate('admin.catalog-price-rule.products-by-categories'),
            'preview_url' => $this->urls->generate('admin.catalog-price-rule.preview', ['rule_id' => (int) $rule->getId()]),
            'can_target_customers' => $canTargetCustomers,
            'rule_is_reserved' => $rule->isReserved(),
            'customer_choices' => $canTargetCustomers ? $this->customerChoices->choices($selectedCustomerIds) : [],
            'selected_customer_ids' => $selectedCustomerIds,
            'customer_group_choices' => $canTargetCustomers ? $this->customerGroups->findChoices($locale) : [],
            'selected_customer_group_ids' => $selectedGroupIds,
            'reserved_for_groups' => CatalogPriceRule::AUDIENCE_MODE_CUSTOMER_GROUPS === (int) $rule->getAudienceMode(),
            'latest_customers_limit' => CustomerChoiceProvider::LATEST_LIMIT,
            'coverage' => [
                'products' => null === $overview ? 0 : $overview->productCount,
                'sale_elements' => null === $overview ? 0 : $overview->saleElementCount,
                'state' => null === $overview ? CatalogPriceRule::STATE_INACTIVE : $overview->state,
                'dirty' => (bool) $rule->getDirty(),
                'computed_at' => $rule->getComputedAt('Y-m-d H:i'),
            ],
        ];
    }

    public function canTargetCustomers(): bool
    {
        return $this->access->canView(AdminResources::CUSTOMER);
    }

    /**
     * @return list<int>
     */
    private function customerIds(CatalogPriceRule $rule): array
    {
        $ids = [];

        foreach ($rule->getCatalogPriceRuleCustomers() as $ruleCustomer) {
            \assert($ruleCustomer instanceof CatalogPriceRuleCustomer);
            $ids[] = (int) $ruleCustomer->getCustomerId();
        }

        return $ids;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function categoryChoices(string $locale): array
    {
        $items = [];

        foreach (CategoryQuery::create()->orderByPosition()->find() as $category) {
            $category->setLocale($locale);
            $items[] = ['id' => (int) $category->getId(), 'title' => (string) $category->getTitle()];
        }

        return $this->sorted($items);
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function brandChoices(string $locale): array
    {
        $items = [];

        foreach (BrandQuery::create()->orderByPosition()->find() as $brand) {
            $brand->setLocale($locale);
            $items[] = ['id' => (int) $brand->getId(), 'title' => (string) $brand->getTitle()];
        }

        return $this->sorted($items);
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function templateChoices(string $locale): array
    {
        $items = [];

        foreach (TemplateQuery::create()->find() as $template) {
            $template->setLocale($locale);
            $items[] = ['id' => (int) $template->getId(), 'title' => (string) $template->getName()];
        }

        return $this->sorted($items);
    }

    /**
     * @return list<array{title: string, values: list<array{id: int, title: string}>}>
     */
    private function featureValueGroups(string $locale): array
    {
        $groups = [];

        foreach (FeatureQuery::create()->orderByPosition()->find() as $feature) {
            $feature->setLocale($locale);
            $values = [];

            foreach ($feature->getFeatureAvs() as $featureAv) {
                $featureAv->setLocale($locale);
                $values[] = ['id' => (int) $featureAv->getId(), 'title' => (string) $featureAv->getTitle()];
            }

            if ([] !== $values) {
                $groups[] = ['title' => (string) $feature->getTitle(), 'values' => $this->sorted($values)];
            }
        }

        return $groups;
    }

    /**
     * @return list<array{title: string, values: list<array{id: int, title: string}>}>
     */
    private function attributeValueGroups(string $locale): array
    {
        $groups = [];

        foreach (AttributeQuery::create()->orderByPosition()->find() as $attribute) {
            $attribute->setLocale($locale);
            $values = [];

            foreach ($attribute->getAttributeAvs() as $attributeAv) {
                $attributeAv->setLocale($locale);
                $values[] = ['id' => (int) $attributeAv->getId(), 'title' => (string) $attributeAv->getTitle()];
            }

            if ([] !== $values) {
                $groups[] = ['title' => (string) $attribute->getTitle(), 'values' => $this->sorted($values)];
            }
        }

        return $groups;
    }

    /**
     * @param list<int> $productIds
     *
     * @return list<array{id: int, ref: string, title: string}>
     */
    private function products(array $productIds, string $locale): array
    {
        if ([] === $productIds) {
            return [];
        }

        $items = [];

        foreach (ProductQuery::create()->filterById($productIds, Criteria::IN)->orderByRef()->find() as $product) {
            $product->setLocale($locale);
            $items[] = ['id' => (int) $product->getId(), 'ref' => (string) $product->getRef(), 'title' => (string) $product->getTitle()];
        }

        return $items;
    }

    /**
     * @param array<int, float> $values
     *
     * @return list<array{id: int, code: string, symbol: string, value: string}>
     */
    private function currencyValues(array $values): array
    {
        $items = [];

        foreach (CurrencyQuery::create()->filterByVisible(1)->orderByPosition()->find() as $currency) {
            $id = (int) $currency->getId();
            $items[] = [
                'id' => $id,
                'code' => (string) $currency->getCode(),
                'symbol' => (string) $currency->getSymbol(),
                'value' => isset($values[$id]) ? rtrim(rtrim(number_format($values[$id], 6, '.', ''), '0'), '.') : '',
            ];
        }

        return $items;
    }

    /**
     * @param list<array{id: int, title: string}> $items
     *
     * @return list<array{id: int, title: string}>
     */
    private function sorted(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $items;
    }
}
