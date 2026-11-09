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

namespace BackOfficeDefaultTwigBundle\Service\PriceList;

use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use BackOfficeDefaultTwigBundle\Repository\PriceListAssignmentRepository;
use BackOfficeDefaultTwigBundle\Repository\PriceListItemRepository;
use BackOfficeDefaultTwigBundle\Repository\PriceListRepository;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Map\PriceListTableMap;
use Thelia\Model\PriceList;
use Thelia\Model\PriceListAssignment;
use Thelia\Model\PriceListAssignmentQuery;
use Thelia\Model\PriceListItem;

/**
 * Writes the price lists, their lines and their assignments. The rules are the ones of the
 * admin API (code, period, one amount per line, one line per sale element and quantity, one
 * assignment per customer or group), checked here before the database is asked.
 */
final readonly class PriceListWriter
{
    private const DECIMAL_PATTERN = '/^(\d{1,10})(?:\.(\d{1,6}))?$/';

    public function __construct(
        private PriceListRepository $priceLists,
        private PriceListItemRepository $items,
        private PriceListAssignmentRepository $assignments,
        private CustomerGroupRepository $customerGroups,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PriceListRefusedException
     */
    public function save(?PriceList $priceList, string $code, int $currencyId, int $priority, bool $exclusive, bool $active, ?\DateTimeInterface $startsAt, ?\DateTimeInterface $endsAt, string $locale, ?string $title): PriceList
    {
        $code = trim($code);
        $isNew = null === $priceList;
        $priceList ??= new PriceList();

        if ($this->priceLists->isCodeTaken($code, $isNew ? null : (int) $priceList->getId())) {
            throw new PriceListRefusedException($this->translator->trans('This code is already used by another price list.'));
        }

        if (null !== $startsAt && null !== $endsAt && $startsAt >= $endsAt) {
            throw new PriceListRefusedException($this->translator->trans('The list must start before it ends.'));
        }

        if (!CurrencyQuery::create()->filterById($currencyId)->exists()) {
            throw new PriceListRefusedException($this->translator->trans('The currency does not exist.'));
        }

        // The prices of the lines are amounts in the currency of the list: another currency would silently change what they mean.
        if (!$isNew && $currencyId !== (int) $priceList->getCurrencyId() && $this->priceLists->countLines((int) $priceList->getId()) > 0) {
            throw new PriceListRefusedException($this->translator->trans('The currency of a price list with lines cannot be changed: its prices are amounts in that currency.'));
        }

        $this->inTransaction(static function (ConnectionInterface $connection) use ($priceList, $code, $currencyId, $priority, $exclusive, $active, $startsAt, $endsAt, $locale, $title): void {
            $priceList
                ->setCode($code)
                ->setCurrencyId($currencyId)
                ->setPriority($priority)
                ->setExclusive($exclusive ? 1 : 0)
                ->setActive($active ? 1 : 0)
                ->setStartsAt($startsAt)
                ->setEndsAt($endsAt)
                ->setLocale($locale)
                ->setTitle(null === $title || '' === trim($title) ? null : trim($title))
                ->save($connection);
        });

        return $priceList;
    }

    /**
     * The lines and the assignments of the list go with it (cascade in the database).
     */
    public function delete(PriceList $priceList): void
    {
        $priceList->delete();
    }

    /**
     * Adds the line of a sale element, named by its reference, or updates the line given.
     *
     * @throws PriceListRefusedException
     */
    public function saveLine(PriceList $priceList, ?PriceListItem $item, string $saleElementReference, string $minimumQuantity, string $price, string $discountPercent): PriceListItem
    {
        $quantity = $this->quantity($minimumQuantity);
        [$price, $discountPercent] = $this->amounts($price, $discountPercent);

        if (null === $item) {
            $ids = $this->items->saleElementIdsByReference(trim($saleElementReference));
            if ([] === $ids) {
                throw new PriceListRefusedException($this->translator->trans('No sale element has this reference.'));
            }

            if (\count($ids) > 1) {
                throw new PriceListRefusedException($this->translator->trans('Several sale elements have this reference: it does not name one.'));
            }

            $saleElementId = $ids[0];
            $item = new PriceListItem();
            $item->setPriceListId((int) $priceList->getId())->setProductSaleElementsId($saleElementId);
        } else {
            $saleElementId = (int) $item->getProductSaleElementsId();
        }

        if ($this->items->isLineTaken((int) $priceList->getId(), $saleElementId, $quantity, $item->isNew() ? null : (int) $item->getId())) {
            throw new PriceListRefusedException($this->translator->trans('This price list already has a line for this sale element and this minimum quantity.'));
        }

        $item->setMinQuantity($quantity)->setPrice($price)->setDiscountPercent($discountPercent);
        $this->inTransaction(static function (ConnectionInterface $connection) use ($item): void {
            $item->save($connection);
        });

        return $item;
    }

    public function deleteLine(PriceListItem $item): void
    {
        $item->delete();
    }

    /**
     * Makes the groups the list is assigned to exactly the given ones; the assignments to
     * customers are left alone. A group that does not exist is dropped.
     *
     * @param list<int> $groupIds
     */
    public function syncGroups(PriceList $priceList, array $groupIds): void
    {
        $wanted = $this->customerGroups->existingIds($groupIds);
        $current = $this->assignments->groupIds($priceList);

        $this->inTransaction(static function (ConnectionInterface $connection) use ($priceList, $wanted, $current): void {
            foreach (array_diff($current, $wanted) as $groupId) {
                // One by one: a delete by query fires no event, and the caches are purged on the delete events.
                foreach (PriceListAssignmentQuery::create()->filterByPriceListId((int) $priceList->getId())->filterByCustomerGroupId($groupId)->find($connection) as $assignment) {
                    $assignment->delete($connection);
                }
            }

            foreach (array_diff($wanted, $current) as $groupId) {
                (new PriceListAssignment())->setPriceListId((int) $priceList->getId())->setCustomerGroupId($groupId)->save($connection);
            }
        });
    }

    /**
     * @throws PriceListRefusedException
     */
    public function assignCustomer(PriceList $priceList, int $customerId): PriceListAssignment
    {
        if (!CustomerQuery::create()->filterById($customerId)->exists()) {
            throw new PriceListRefusedException($this->translator->trans('The customer does not exist.'));
        }

        if ($this->assignments->isCustomerAssigned((int) $priceList->getId(), $customerId)) {
            throw new PriceListRefusedException($this->translator->trans('This price list is already assigned to this customer.'));
        }

        $assignment = (new PriceListAssignment())->setPriceListId((int) $priceList->getId())->setCustomerId($customerId);
        $assignment->save();

        return $assignment;
    }

    public function unassign(PriceListAssignment $assignment): void
    {
        $assignment->delete();
    }

    /**
     * @throws PriceListRefusedException
     */
    private function quantity(string $value): int
    {
        $value = trim($value);
        if ('' === $value) {
            return 1;
        }

        if (1 !== preg_match('/^\d{1,9}$/', $value) || (int) $value < 1) {
            throw new PriceListRefusedException($this->translator->trans('The minimum quantity is a whole number, 1 or more.'));
        }

        return (int) $value;
    }

    /**
     * @return array{0: ?string, 1: ?string} the price and the discount, one of them set
     *
     * @throws PriceListRefusedException
     */
    private function amounts(string $price, string $discountPercent): array
    {
        $price = $this->decimal($price);
        $discountPercent = $this->decimal($discountPercent);

        if (null === $price && null === $discountPercent) {
            throw new PriceListRefusedException($this->translator->trans('A line needs a price or a discount percentage.'));
        }

        if (null !== $price && null !== $discountPercent) {
            throw new PriceListRefusedException($this->translator->trans('A line is a price or a discount percentage, not both.'));
        }

        if (null !== $discountPercent && ((float) $discountPercent <= 0.0 || (float) $discountPercent > 100.0)) {
            throw new PriceListRefusedException($this->translator->trans('The discount is more than 0 and 100 at most.'));
        }

        return [$price, $discountPercent];
    }

    /**
     * A decimal typed with a comma or a point, six decimals at most; null when empty.
     *
     * @throws PriceListRefusedException
     */
    private function decimal(string $value): ?string
    {
        $value = str_replace(',', '.', trim($value));
        if ('' === $value) {
            return null;
        }

        if (1 !== preg_match(self::DECIMAL_PATTERN, $value)) {
            throw new PriceListRefusedException($this->translator->trans('An amount is a positive number with six decimals at most.'));
        }

        return $value;
    }

    private function inTransaction(callable $write): void
    {
        $connection = Propel::getConnection(PriceListTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $write($connection);
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }
    }
}
