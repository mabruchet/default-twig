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

namespace BackOfficeDefaultTwigBundle\Service\CatalogRestriction;

use BackOfficeDefaultTwigBundle\Repository\CatalogRestrictionRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use Propel\Runtime\Propel;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\BrandQuery;
use Thelia\Model\CatalogRestriction;
use Thelia\Model\CategoryQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Map\CatalogRestrictionTableMap;
use Thelia\Model\ProductQuery;

/**
 * Writes the assortment rules the way the admin API does: one scope (a group, or a customer),
 * one target (a category, a brand or a product), a way (allow or deny). A form that names
 * several groups writes one rule per group, all or none; a rule that already exists is not
 * written twice.
 */
final readonly class CatalogRestrictionWriter
{
    public function __construct(
        private CatalogRestrictionRepository $restrictions,
        private CustomerGroupRepository $customerGroups,
        private TranslatorInterface $translator,
    ) {
    }

    public function delete(CatalogRestriction $restriction): void
    {
        $restriction->delete();
    }

    /**
     * @return array{created: list<int>, existing: int} the ids of the rules written, and how many were already there
     *
     * @throws CatalogRestrictionRefusedException
     */
    public function create(CatalogRestrictionDraft $draft): array
    {
        $scopes = $this->scopes($draft);
        [$categoryId, $brandId, $productId] = $this->target($draft);

        $created = [];
        $existing = 0;
        $connection = Propel::getWriteConnection(CatalogRestrictionTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            foreach ($scopes as [$groupId, $customerId]) {
                if ($this->restrictions->exists($groupId, $customerId, $categoryId, $brandId, $productId, $draft->mode)) {
                    ++$existing;

                    continue;
                }

                $restriction = new CatalogRestriction();
                $restriction
                    ->setCustomerGroupId($groupId)
                    ->setCustomerId($customerId)
                    ->setCategoryId($categoryId)
                    ->setBrandId($brandId)
                    ->setProductId($productId)
                    ->setMode($draft->mode)
                    ->save($connection);

                $created[] = (int) $restriction->getId();
            }

            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * @return list<array{int|null, int|null}> (group id, customer id) of every rule to write
     */
    private function scopes(CatalogRestrictionDraft $draft): array
    {
        if (CatalogRestrictionDraft::SCOPE_CUSTOMER === $draft->scopeType) {
            if (null === $draft->customerId || !CustomerQuery::create()->filterById($draft->customerId)->exists()) {
                throw new CatalogRestrictionRefusedException($this->translator->trans('Choose the customer the rule applies to.'));
            }

            return [[null, $draft->customerId]];
        }

        $groupIds = $this->customerGroups->existingIds($draft->groupIds);

        if ([] === $groupIds) {
            throw new CatalogRestrictionRefusedException($this->translator->trans('Choose at least one customer group the rule applies to.'));
        }

        return array_map(static fn (int $groupId): array => [$groupId, null], $groupIds);
    }

    /**
     * @return array{int|null, int|null, int|null} category id, brand id, product id: one of them set
     */
    private function target(CatalogRestrictionDraft $draft): array
    {
        $id = $draft->targetId();
        $exists = null !== $id && match ($draft->targetType) {
            CatalogRestrictionDraft::TARGET_CATEGORY => CategoryQuery::create()->filterById($id)->exists(),
            CatalogRestrictionDraft::TARGET_BRAND => BrandQuery::create()->filterById($id)->exists(),
            default => ProductQuery::create()->filterById($id)->exists(),
        };

        if (!$exists) {
            throw new CatalogRestrictionRefusedException($this->translator->trans('Choose the category, the brand or the product the rule is about.'));
        }

        return match ($draft->targetType) {
            CatalogRestrictionDraft::TARGET_CATEGORY => [$id, null, null],
            CatalogRestrictionDraft::TARGET_BRAND => [null, $id, null],
            default => [null, null, $id],
        };
    }
}
