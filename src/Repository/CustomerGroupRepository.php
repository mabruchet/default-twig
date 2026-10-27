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

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\CategoryQuery;
use Thelia\Model\CustomerGroup;
use Thelia\Model\CustomerGroupCategory;
use Thelia\Model\CustomerGroupCategoryQuery;
use Thelia\Model\CustomerGroupPaymentModule;
use Thelia\Model\CustomerGroupPaymentModuleQuery;
use Thelia\Model\CustomerGroupQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Map\CategoryI18nTableMap;
use Thelia\Model\Map\CustomerGroupI18nTableMap;
use Thelia\Model\Map\ModuleI18nTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

/**
 * Reads of the customer groups, and of what each one is linked to: the payment modules
 * and the categories it is allowed. Both links follow the same rule in the core: the list
 * of a group counts only when the group is flagged restricted (restricts_categories,
 * restricts_payment_modules), and then it is the whole of what the group is allowed, an
 * empty list meaning nothing; an unflagged group is allowed everything.
 */
final readonly class CustomerGroupRepository
{
    /**
     * The title in the asked language, read by the list query itself: a group without a
     * translation in that language costs no query of its own, and falls back to its code.
     */
    private const TITLE_COLUMN = 'localized_title';

    public function find(int $customerGroupId): ?CustomerGroup
    {
        return $customerGroupId > 0 ? CustomerGroupQuery::create()->findPk($customerGroupId) : null;
    }

    public function isCodeTaken(string $code, ?int $exceptId = null): bool
    {
        $query = CustomerGroupQuery::create()->filterByCode($code);
        if (null !== $exceptId) {
            $query->filterById($exceptId, Criteria::NOT_EQUAL);
        }

        return $query->exists();
    }

    public function lastPosition(): int
    {
        return (int) CustomerGroupQuery::create()->orderByPosition(Criteria::DESC)->findOne()?->getPosition();
    }

    public function countCustomers(int $customerGroupId): int
    {
        return CustomerQuery::create()->filterByCustomerGroupId($customerGroupId)->count();
    }

    /**
     * @return ObjectCollection<int, CustomerGroup>
     */
    public function findSorted(string $locale, string $field, string $direction): ObjectCollection
    {
        $criteria = 'DESC' === strtoupper($direction) ? Criteria::DESC : Criteria::ASC;
        $query = self::withLocalizedTitle(CustomerGroupQuery::create(), $locale);

        match ($field) {
            'id' => $query->orderById($criteria),
            'code' => $query->orderByCode($criteria),
            'title' => $query->orderBy(self::TITLE_COLUMN, $criteria)->orderByCode($criteria),
            'discount' => $query->orderByDiscount($criteria),
            default => $query->orderByPosition($criteria)->orderById(),
        };

        /** @var ObjectCollection<int, CustomerGroup> $groups */
        $groups = $query->find();

        return $groups;
    }

    /**
     * Every group, in the merchant's order, with the title of the given language and the
     * code in its place when that title is missing.
     *
     * @return list<array{id: int, label: string}>
     */
    public function findChoices(string $locale): array
    {
        $choices = [];
        foreach (self::withLocalizedTitle(CustomerGroupQuery::create(), $locale)->orderByPosition()->orderById()->find() as $group) {
            $choices[] = ['id' => (int) $group->getId(), 'label' => self::titleOf($group, $locale)];
        }

        return $choices;
    }

    /**
     * The given ids that are groups: an id a stale form still posts is dropped here rather
     * than refused by the database.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function existingIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $existing = [];
        foreach (CustomerGroupQuery::create()->filterById($ids, Criteria::IN)->orderById()->find() as $group) {
            $existing[] = (int) $group->getId();
        }

        return $existing;
    }

    /**
     * The active payment modules: the only ones a group can be restricted to.
     *
     * @return list<array{id: int, label: string}>
     */
    public function paymentModuleChoices(string $locale): array
    {
        $choices = [];
        foreach (ModuleQuery::create()
            ->filterByType(BaseModule::PAYMENT_MODULE_TYPE)
            ->filterByActivate(BaseModule::IS_ACTIVATED)
            ->orderByPosition()
            ->joinI18n($locale, null, Criteria::LEFT_JOIN)
            ->withColumn(ModuleI18nTableMap::COL_TITLE, self::TITLE_COLUMN)
            ->find() as $module) {
            $choices[] = ['id' => (int) $module->getId(), 'label' => trim((string) $module->getVirtualColumn(self::TITLE_COLUMN)) ?: (string) $module->getCode()];
        }

        return $choices;
    }

    /**
     * @return list<int>
     */
    public function activePaymentModuleIds(): array
    {
        return array_map('intval', ModuleQuery::create()
            ->filterByType(BaseModule::PAYMENT_MODULE_TYPE)
            ->filterByActivate(BaseModule::IS_ACTIVATED)
            ->select('Id')
            ->find()
            ->getData());
    }

    /**
     * Every category, children under their parent and indented, in the merchant's order.
     *
     * @return list<array{id: int, label: string}>
     */
    public function categoryChoices(string $locale): array
    {
        $byParent = [];
        // The title is read by the same query, as a virtual column: a category with no
        // translation in this language costs no query of its own, as joinWithI18n() would.
        $categories = CategoryQuery::create()
            ->joinI18n($locale, null, Criteria::LEFT_JOIN)
            ->withColumn(CategoryI18nTableMap::COL_TITLE, self::TITLE_COLUMN)
            ->orderByPosition()
            ->orderById()
            ->find();
        foreach ($categories as $category) {
            $byParent[(int) $category->getParent()][] = $category;
        }

        $choices = [];
        $walk = static function (int $parentId, int $level) use (&$walk, &$choices, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $choices[] = ['id' => (int) $category->getId(), 'label' => str_repeat('- ', $level).trim((string) $category->getVirtualColumn(self::TITLE_COLUMN))];
                $walk((int) $category->getId(), $level + 1);
            }
        };
        $walk(0, 0);

        return $choices;
    }

    /**
     * @return list<int>
     */
    public function paymentModuleIdsOf(int $customerGroupId): array
    {
        $ids = [];
        foreach (CustomerGroupPaymentModuleQuery::create()->filterByCustomerGroupId($customerGroupId)->orderByModuleId()->find() as $link) {
            \assert($link instanceof CustomerGroupPaymentModule);
            $ids[] = (int) $link->getModuleId();
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    public function categoryIdsOf(int $customerGroupId): array
    {
        $ids = [];
        foreach (CustomerGroupCategoryQuery::create()->filterByCustomerGroupId($customerGroupId)->orderByCategoryId()->find() as $link) {
            \assert($link instanceof CustomerGroupCategory);
            $ids[] = (int) $link->getCategoryId();
        }

        return $ids;
    }

    /**
     * The other groups, in the merchant's order: the ones an action may swap places with.
     *
     * @return list<CustomerGroup>
     */
    public function findSiblingsOf(CustomerGroup $customerGroup): array
    {
        /** @var list<CustomerGroup> $siblings */
        $siblings = CustomerGroupQuery::create()
            ->filterById($customerGroup->getId(), Criteria::NOT_EQUAL)
            ->orderByPosition()
            ->orderById()
            ->find()
            ->getData();

        return $siblings;
    }

    public static function titleOf(CustomerGroup $customerGroup, string $locale): string
    {
        $title = $customerGroup->hasVirtualColumn(self::TITLE_COLUMN)
            ? $customerGroup->getVirtualColumn(self::TITLE_COLUMN)
            : $customerGroup->setLocale($locale)->getTitle();
        $title = trim((string) $title);

        return '' !== $title ? $title : (string) $customerGroup->getCode();
    }

    /**
     * The locale is a condition of the join, not of the WHERE: a group without a title in
     * this language is still listed.
     */
    private static function withLocalizedTitle(CustomerGroupQuery $query, string $locale): CustomerGroupQuery
    {
        return $query
            ->joinI18n($locale, null, Criteria::LEFT_JOIN)
            ->withColumn(CustomerGroupI18nTableMap::COL_TITLE, self::TITLE_COLUMN);
    }
}
