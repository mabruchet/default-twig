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

use BackOfficeDefaultTwigBundle\Service\CatalogRestriction\BoundedSearch;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\CustomerQuery;
use Thelia\Model\PriceList;
use Thelia\Model\PriceListAssignment;
use Thelia\Model\PriceListAssignmentQuery;

/**
 * The assignments of a price list, to customer groups or to customers. The customer search
 * is bounded: three characters at least, twenty results at most, so the screen is never a
 * directory of the customers.
 */
final readonly class PriceListAssignmentRepository
{
    public const SEARCH_MINIMUM_LENGTH = 3;

    public const SEARCH_LIMIT = 20;

    public const CUSTOMERS_PAGE_SIZE = 25;

    public function findInList(int $priceListId, int $assignmentId): ?PriceListAssignment
    {
        return PriceListAssignmentQuery::create()->filterByPriceListId($priceListId)->filterById($assignmentId)->findOne();
    }

    /**
     * @return list<int>
     */
    public function groupIds(PriceList|int $priceList): array
    {
        $ids = [];
        foreach (PriceListAssignmentQuery::create()
            ->filterByPriceListId($priceList instanceof PriceList ? (int) $priceList->getId() : $priceList)
            ->filterByCustomerGroupId(null, Criteria::ISNOTNULL)
            ->orderByCustomerGroupId()
            ->find() as $assignment) {
            $ids[] = (int) $assignment->getCustomerGroupId();
        }

        return $ids;
    }

    public function isCustomerAssigned(int $priceListId, int $customerId): bool
    {
        return PriceListAssignmentQuery::create()->filterByPriceListId($priceListId)->filterByCustomerId($customerId)->exists();
    }

    public function countCustomers(int $priceListId): int
    {
        return PriceListAssignmentQuery::create()->filterByPriceListId($priceListId)->filterByCustomerId(null, Criteria::ISNOTNULL)->count();
    }

    /**
     * Without the identity (an admin who may not see the customers) the rows carry the ids only,
     * so an assignment can still be removed.
     *
     * The assignments to customers a page at a time, the latest first: every one can be reached and removed.
     *
     * @return array{rows: list<array{assignmentId: int, customerId: int, name: string, email: string}>, page: int, lastPage: int}
     */
    public function customersPage(int $priceListId, bool $withIdentity, int $page): array
    {
        $lastPage = max(1, (int) ceil($this->countCustomers($priceListId) / self::CUSTOMERS_PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        $assignments = PriceListAssignmentQuery::create()
            ->filterByPriceListId($priceListId)
            ->filterByCustomerId(null, Criteria::ISNOTNULL)
            ->orderById(Criteria::DESC)
            ->offset(($page - 1) * self::CUSTOMERS_PAGE_SIZE)
            ->limit(self::CUSTOMERS_PAGE_SIZE)
            ->find();

        $customerIds = [];
        foreach ($assignments as $assignment) {
            $customerIds[] = (int) $assignment->getCustomerId();
        }

        if (!$withIdentity) {
            $rows = [];
            foreach ($assignments as $assignment) {
                $rows[] = ['assignmentId' => (int) $assignment->getId(), 'customerId' => (int) $assignment->getCustomerId(), 'name' => '', 'email' => ''];
            }

            return ['rows' => $rows, 'page' => $page, 'lastPage' => $lastPage];
        }

        $customers = [];
        if ([] !== $customerIds) {
            foreach (CustomerQuery::create()->filterById($customerIds, Criteria::IN)->find() as $customer) {
                $customers[(int) $customer->getId()] = $customer;
            }
        }

        $rows = [];
        foreach ($assignments as $assignment) {
            $customer = $customers[(int) $assignment->getCustomerId()] ?? null;
            if (null === $customer) {
                continue;
            }

            $rows[] = [
                'assignmentId' => (int) $assignment->getId(),
                'customerId' => (int) $customer->getId(),
                'name' => trim($customer->getFirstname().' '.$customer->getLastname()),
                'email' => (string) $customer->getEmail(),
            ];
        }

        return ['rows' => $rows, 'page' => $page, 'lastPage' => $lastPage];
    }

    /**
     * The customers whose name, e-mail or reference holds the term, those already assigned
     * to the list left out. Nothing for a term shorter than the minimum.
     *
     * @return list<array{customerId: int, name: string, email: string}>
     */
    public function searchCustomers(int $priceListId, string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::SEARCH_MINIMUM_LENGTH) {
            return [];
        }

        $query = BoundedSearch::customersMatching($term);

        $assigned = [];
        foreach (PriceListAssignmentQuery::create()->filterByPriceListId($priceListId)->filterByCustomerId(null, Criteria::ISNOTNULL)->find() as $assignment) {
            $assigned[] = (int) $assignment->getCustomerId();
        }

        if ([] !== $assigned) {
            $query->filterById($assigned, Criteria::NOT_IN);
        }

        $rows = [];
        foreach ($query->orderByLastname()->orderByFirstname()->orderById()->limit(self::SEARCH_LIMIT)->find() as $customer) {
            $rows[] = [
                'customerId' => (int) $customer->getId(),
                'name' => trim($customer->getFirstname().' '.$customer->getLastname()),
                'email' => (string) $customer->getEmail(),
            ];
        }

        return $rows;
    }
}
