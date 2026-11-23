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

use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\AddressQuery;
use Thelia\Model\Company;
use Thelia\Model\CompanyCustomerQuery;
use Thelia\Model\CompanyQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\OrderQuery;

/**
 * Reads of the companies: one page of a list at a time, the figures of each row counted by one
 * grouped query for the whole page, never by loading the buyers or the orders.
 */
final readonly class CompanyRepository
{
    public const PAGE_SIZE = 20;

    public const BUYER_PAGE_SIZE = 20;

    public const ORDER_PAGE_SIZE = 20;

    public const ADDRESS_LIMIT = 100;

    public const CHOICE_LIMIT = 200;

    public const LOOKUP_LIMIT = 5;

    public const SORTABLE_FIELDS = ['id', 'name', 'siret', 'created_at'];

    public function __construct(
        private OrderAccess $orderAccess,
    ) {
    }

    public function find(int $companyId): ?Company
    {
        return $companyId > 0 ? CompanyQuery::create()->findPk($companyId) : null;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, lastPage: int}
     */
    public function search(?string $term, ?bool $active, string $sortField, string $direction, int $page): array
    {
        $query = CompanyQuery::create();

        $term = trim((string) $term);
        if ('' !== $term) {
            $like = '%'.self::escapeLike($term).'%';
            $query
                ->condition('name_like', 'company.name LIKE ?', $like)
                ->condition('siret_like', 'company.siret LIKE ?', $like)
                ->where(['name_like', 'siret_like'], Criteria::LOGICAL_OR);
        }

        if (null !== $active) {
            $query->filterByActive($active ? 1 : 0);
        }

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);
        $criteria = 'DESC' === strtoupper($direction) ? Criteria::DESC : Criteria::ASC;

        match ($sortField) {
            'id' => $query->orderById($criteria),
            'siret' => $query->orderBySiret($criteria)->orderById(),
            'created_at' => $query->orderByCreatedAt($criteria)->orderById($criteria),
            default => $query->orderByName($criteria)->orderById(),
        };

        $companies = $query->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)->find();

        $ids = [];
        foreach ($companies as $company) {
            $ids[] = (int) $company->getId();
        }

        $buyers = $this->buyerCounts($ids);
        $orders = $this->orderCounts($ids);

        $rows = [];
        foreach ($companies as $company) {
            $id = (int) $company->getId();
            $rows[] = [
                'id' => $id,
                'name' => (string) $company->getName(),
                'siret' => (string) $company->getSiret(),
                'active' => 1 === $company->getActive(),
                'buyers' => $buyers[$id] ?? 0,
                'orders' => $orders[$id] ?? 0,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }

    /**
     * @return list<array{id: int, name: string}> the first companies by name, for a choice list
     */
    public function choices(): array
    {
        $choices = [];
        $rows = CompanyQuery::create()->orderByName()->limit(self::CHOICE_LIMIT)->select(['Id', 'Name'])->find();

        foreach ($rows as $row) {
            $choices[] = ['id' => (int) $row['Id'], 'name' => (string) $row['Name']];
        }

        return $choices;
    }

    public function countBuyers(int $companyId): int
    {
        return CompanyCustomerQuery::create()->filterByCompanyId($companyId)->count();
    }

    public function countOrders(int $companyId): int
    {
        $query = OrderQuery::create()->filterByCompanyId($companyId);
        $this->orderAccess->restrict($query);

        return $query->count();
    }

    /**
     * One page of the buyers, with the customer and the role each holds, read by one query.
     *
     * @return list<array{linkId: int, customerId: int, roleId: int, roleCode: string, roleCompanyId: int|null, ref: string, firstname: string, lastname: string, email: string, enabled: bool, orderLimit: string|null, periodLimit: string|null, period: string|null}>
     */
    public function buyersPage(int $companyId, int $page): array
    {
        $rows = CompanyCustomerQuery::create()
            ->filterByCompanyId($companyId)
            ->joinCustomer()
            ->joinCompanyRole()
            ->withColumn('Customer.Ref', 'CustomerRef')
            ->withColumn('Customer.Firstname', 'CustomerFirstname')
            ->withColumn('Customer.Lastname', 'CustomerLastname')
            ->withColumn('Customer.Email', 'CustomerEmail')
            ->withColumn('Customer.Enable', 'CustomerEnable')
            ->withColumn('CompanyRole.Code', 'RoleCode')
            ->withColumn('CompanyRole.CompanyId', 'RoleCompanyId')
            ->orderById()
            ->offset((max(1, $page) - 1) * self::BUYER_PAGE_SIZE)
            ->limit(self::BUYER_PAGE_SIZE)
            ->select(['Id', 'CustomerId', 'CompanyRoleId', 'OrderLimit', 'PeriodLimit', 'Period', 'CustomerRef', 'CustomerFirstname', 'CustomerLastname', 'CustomerEmail', 'CustomerEnable', 'RoleCode', 'RoleCompanyId'])
            ->find();

        $buyers = [];
        foreach ($rows as $row) {
            $buyers[] = [
                'linkId' => (int) $row['Id'],
                'customerId' => (int) $row['CustomerId'],
                'roleId' => (int) $row['CompanyRoleId'],
                'roleCode' => (string) $row['RoleCode'],
                'roleCompanyId' => null === $row['RoleCompanyId'] ? null : (int) $row['RoleCompanyId'],
                'ref' => (string) $row['CustomerRef'],
                'firstname' => (string) $row['CustomerFirstname'],
                'lastname' => (string) $row['CustomerLastname'],
                'email' => (string) $row['CustomerEmail'],
                'enabled' => 1 === (int) $row['CustomerEnable'],
                'orderLimit' => null === $row['OrderLimit'] ? null : (string) $row['OrderLimit'],
                'periodLimit' => null === $row['PeriodLimit'] ? null : (string) $row['PeriodLimit'],
                'period' => null === $row['Period'] ? null : (string) $row['Period'],
            ];
        }

        return $buyers;
    }

    /**
     * The accounts whose e-mail address or reference is exactly the text: a fragment finds
     * nothing, and no more than a handful come back, so the search is no directory.
     *
     * @return list<Customer>
     */
    public function customersByExactLookup(string $lookup): array
    {
        $lookup = trim($lookup);
        if ('' === $lookup) {
            return [];
        }

        $customers = CustomerQuery::create()
            ->condition('by_email', 'customer.email = ?', $lookup)
            ->condition('by_ref', 'customer.ref = ?', $lookup)
            ->where(['by_email', 'by_ref'], Criteria::LOGICAL_OR)
            ->filterByAnonymizedAt(null, Criteria::ISNULL)
            ->orderById()
            ->limit(self::LOOKUP_LIMIT)
            ->find();

        return array_values(iterator_to_array($customers));
    }

    public function isCustomerInACompany(int $customerId): bool
    {
        return CompanyCustomerQuery::create()->filterByCustomerId($customerId)->exists();
    }

    /**
     * @return list<array{id: int, label: string, name: string, address: string, creator: int, billing: bool}>
     */
    public function sharedAddresses(Company $company): array
    {
        $rows = AddressQuery::create()
            ->filterByCompanyId((int) $company->getId())
            ->orderById()
            ->limit(self::ADDRESS_LIMIT)
            ->select(['Id', 'Label', 'Firstname', 'Lastname', 'Address1', 'Zipcode', 'City', 'CustomerId'])
            ->find();

        $addresses = [];
        foreach ($rows as $row) {
            $addresses[] = [
                'id' => (int) $row['Id'],
                'label' => (string) $row['Label'],
                'name' => trim($row['Firstname'].' '.$row['Lastname']),
                'address' => trim($row['Address1'].', '.$row['Zipcode'].' '.$row['City'], ', '),
                'creator' => (int) $row['CustomerId'],
                'billing' => (int) $company->getBillingAddressId() === (int) $row['Id'],
            ];
        }

        return $addresses;
    }

    /**
     * @return list<array{id: int, label: string}> the addresses shared with the company, to choose its billing address from
     */
    public function billingAddressChoices(Company $company): array
    {
        $choices = [];
        foreach ($this->sharedAddresses($company) as $address) {
            $choices[] = ['id' => $address['id'], 'label' => trim(('' !== $address['label'] ? $address['label'].' - ' : '').$address['address'])];
        }

        return $choices;
    }

    /**
     * @return array{rows: list<array{id: int, ref: string, createdAt: \DateTimeInterface|null, statusId: int, customerId: int, placedBy: int|null, currency: string}>, total: int, page: int, lastPage: int}
     */
    public function ordersPage(int $companyId, int $page): array
    {
        $count = OrderQuery::create()->filterByCompanyId($companyId);
        $this->orderAccess->restrict($count);
        $total = $count->count();
        $lastPage = max(1, (int) ceil($total / self::ORDER_PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        $query = OrderQuery::create()->filterByCompanyId($companyId)->joinCurrency()->withColumn('Currency.Code', 'CurrencyCode');
        $this->orderAccess->restrict($query);

        $found = $query
            ->orderById(Criteria::DESC)
            ->offset(($page - 1) * self::ORDER_PAGE_SIZE)
            ->limit(self::ORDER_PAGE_SIZE)
            ->select(['Id', 'Ref', 'CreatedAt', 'StatusId', 'CustomerId', 'PlacedByCustomerId', 'CurrencyCode'])
            ->find();

        $rows = [];
        foreach ($found as $row) {
            $rows[] = [
                'id' => (int) $row['Id'],
                'ref' => (string) $row['Ref'],
                'createdAt' => $row['CreatedAt'] instanceof \DateTimeInterface ? $row['CreatedAt'] : (null === $row['CreatedAt'] ? null : new \DateTimeImmutable((string) $row['CreatedAt'])),
                'statusId' => (int) $row['StatusId'],
                'customerId' => (int) $row['CustomerId'],
                'placedBy' => null === $row['PlacedByCustomerId'] ? null : (int) $row['PlacedByCustomerId'],
                'currency' => (string) $row['CurrencyCode'],
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }

    /**
     * An order whose placing buyer was deleted carries a null id: it is skipped.
     *
     * @param list<int|null> $customerIds
     *
     * @return array<int, string> the name of each customer, by id
     */
    public function customerNames(array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter($customerIds, static fn (?int $id): bool => null !== $id && $id > 0)));
        if ([] === $ids) {
            return [];
        }

        $names = [];
        $rows = CustomerQuery::create()->filterById($ids, Criteria::IN)->select(['Id', 'Firstname', 'Lastname', 'Email'])->find();

        foreach ($rows as $row) {
            $name = trim($row['Firstname'].' '.$row['Lastname']);
            $names[(int) $row['Id']] = '' === $name ? (string) $row['Email'] : $name;
        }

        return $names;
    }

    /**
     * The company of each customer of a page, by one query.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function companiesOfCustomers(array $customerIds): array
    {
        if ([] === $customerIds) {
            return [];
        }

        $rows = CompanyCustomerQuery::create()
            ->filterByCustomerId($customerIds, Criteria::IN)
            ->joinCompany()
            ->withColumn('Company.Name', 'CompanyName')
            ->select(['CustomerId', 'CompanyId', 'CompanyName'])
            ->find();

        $companies = [];
        foreach ($rows as $row) {
            $companies[(int) $row['CustomerId']] = ['id' => (int) $row['CompanyId'], 'name' => (string) $row['CompanyName']];
        }

        return $companies;
    }

    /**
     * What a customer holds in a company: the company, the role and the limits, or null.
     *
     * @return array{companyId: int, companyName: string, companyActive: bool, roleCode: string, roleId: int, orderLimit: string|null, periodLimit: string|null, period: string|null}|null
     */
    public function membershipOfCustomer(int $customerId): ?array
    {
        $row = CompanyCustomerQuery::create()
            ->filterByCustomerId($customerId)
            ->joinCompany()
            ->joinCompanyRole()
            ->withColumn('Company.Name', 'CompanyName')
            ->withColumn('Company.Active', 'CompanyActive')
            ->withColumn('CompanyRole.Code', 'RoleCode')
            ->select(['CompanyId', 'CompanyRoleId', 'OrderLimit', 'PeriodLimit', 'Period', 'CompanyName', 'CompanyActive', 'RoleCode'])
            ->findOne();

        if (null === $row) {
            return null;
        }

        return [
            'companyId' => (int) $row['CompanyId'],
            'companyName' => (string) $row['CompanyName'],
            'companyActive' => 1 === (int) $row['CompanyActive'],
            'roleCode' => (string) $row['RoleCode'],
            'roleId' => (int) $row['CompanyRoleId'],
            'orderLimit' => null === $row['OrderLimit'] ? null : (string) $row['OrderLimit'],
            'periodLimit' => null === $row['PeriodLimit'] ? null : (string) $row['PeriodLimit'],
            'period' => null === $row['Period'] ? null : (string) $row['Period'],
        ];
    }

    /**
     * @return array{id: int, name: string, active: bool}|null
     */
    public function summary(int $companyId): ?array
    {
        $row = CompanyQuery::create()->filterById($companyId)->select(['Id', 'Name', 'Active'])->findOne();

        return null === $row ? null : ['id' => (int) $row['Id'], 'name' => (string) $row['Name'], 'active' => 1 === (int) $row['Active']];
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * @param list<int> $companyIds
     *
     * @return array<int, int>
     */
    private function buyerCounts(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }

        $counts = [];
        $rows = CompanyCustomerQuery::create()
            ->filterByCompanyId($companyIds, Criteria::IN)
            ->groupByCompanyId()
            ->withColumn('COUNT(*)', 'buyer_count')
            ->select(['CompanyId', 'buyer_count'])
            ->find();

        foreach ($rows as $row) {
            $counts[(int) $row['CompanyId']] = (int) $row['buyer_count'];
        }

        return $counts;
    }

    /**
     * @param list<int> $companyIds
     *
     * @return array<int, int>
     */
    private function orderCounts(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }

        $query = OrderQuery::create()->filterByCompanyId($companyIds, Criteria::IN);
        $this->orderAccess->restrict($query);

        $counts = [];
        $rows = $query
            ->groupByCompanyId()
            ->withColumn('COUNT(*)', 'order_count')
            ->select(['CompanyId', 'order_count'])
            ->find();

        foreach ($rows as $row) {
            $counts[(int) $row['CompanyId']] = (int) $row['order_count'];
        }

        return $counts;
    }
}
