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

namespace BackOfficeDefaultTwigBundle\Service\Company;

use BackOfficeDefaultTwigBundle\Repository\CompanyRepository;
use BackOfficeDefaultTwigBundle\Repository\CompanyRoleRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Company\Service\CompanyLimits;
use Thelia\Domain\Company\Service\CompanyPermissionRegistry;
use Thelia\Model\Company;

/**
 * What each tab of the sheet of a company shows, read for the tab that is open only.
 */
final readonly class CompanySheetContext
{
    public const TABS = ['infos', 'buyers', 'addresses', 'orders'];

    public function __construct(
        private CompanyRepository $companies,
        private CompanyRoleRepository $roles,
        private OrderRepository $orders,
        private AdminAccessChecker $access,
        private MoneyFormatter $money,
        private UrlGeneratorInterface $urls,
        private CompanyPermissionRegistry $permissions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function infos(Company $company): array
    {
        $parents = array_values(array_filter(
            $this->companies->choices(),
            static fn (array $choice): bool => $choice['id'] !== (int) $company->getId(),
        ));

        // A parent beyond the first choices stays selectable: the one the company has is always offered.
        $parentId = $company->getParentId();
        if (null !== $parentId && [] === array_filter($parents, static fn (array $choice): bool => $choice['id'] === (int) $parentId)) {
            $summary = $this->companies->summary((int) $parentId);
            if (null !== $summary) {
                $parents[] = ['id' => $summary['id'], 'name' => $summary['name']];
            }
        }

        return [
            'parent_choices' => $parents,
            'billing_address_choices' => $this->companies->billingAddressChoices($company),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buyers(Company $company, int $page, string $locale, string $editLocale): array
    {
        $companyId = (int) $company->getId();
        $total = $this->companies->countBuyers($companyId);
        $lastPage = max(1, (int) ceil($total / CompanyRepository::BUYER_PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        $roles = $this->roles->availableTo($companyId, $locale);
        $titleOf = [];
        foreach ($roles as $role) {
            $titleOf[$role['id']] = $role['title'];
        }

        $canViewCustomers = $this->access->canView(AdminResources::CUSTOMER);

        $rows = [];
        foreach ($this->companies->buyersPage($companyId, $page) as $buyer) {
            $name = trim($buyer['firstname'].' '.$buyer['lastname']);
            $rows[] = $buyer + [
                'name' => '' === $name ? $buyer['email'] : $name,
                'roleTitle' => $titleOf[$buyer['roleId']] ?? $buyer['roleCode'],
                'orderLimitInput' => self::amountInput($buyer['orderLimit']),
                'periodLimitInput' => self::amountInput($buyer['periodLimit']),
                'url' => $canViewCustomers ? $this->urls->generate('admin.customer.update.view', ['customer_id' => $buyer['customerId']]) : null,
            ];
        }

        return [
            'buyers' => $rows,
            'buyers_total' => $total,
            'buyers_page' => $page,
            'buyers_last_page' => $lastPage,
            'roles' => $roles,
            'own_roles' => array_map(
                fn (array $role): array => $role + ['editTitle' => $this->roles->titles($role['id'])[$editLocale] ?? ''],
                array_values(array_filter($roles, static fn (array $role): bool => $role['companyId'] === $companyId)),
            ),
            'permission_labels' => $this->permissions->all(),
            'periods' => CompanyLimits::PERIODS,
            'can_pick_customers' => $canViewCustomers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function addresses(Company $company): array
    {
        return [
            'addresses' => $this->companies->sharedAddresses($company),
            'can_view_customers' => $this->access->canView(AdminResources::CUSTOMER),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orders(Company $company, int $page, string $locale): array
    {
        $result = $this->companies->ordersPage((int) $company->getId(), $page);

        $totals = $this->orders->findTotalAmountByOrder(array_column($result['rows'], 'id'));
        $statuses = [];
        foreach ($this->orders->findStatusesLocalized($locale) as $status) {
            $statuses[$status['id']] = $status;
        }

        $names = $this->companies->customerNames(array_column($result['rows'], 'placedBy'));
        $canViewOrders = $this->access->canView(AdminResources::ORDER);

        $rows = [];
        foreach ($result['rows'] as $order) {
            $status = $statuses[$order['statusId']] ?? null;
            $rows[] = [
                'id' => $order['id'],
                'ref' => $order['ref'],
                'url' => $canViewOrders ? $this->urls->generate('admin.order.update.view', ['order_id' => $order['id']]) : null,
                'createdAt' => $order['createdAt'],
                'status' => $status['title'] ?? '',
                'statusColor' => $status['color'] ?? '#6c757d',
                'total' => $this->money->format($totals[$order['id']] ?? 0.0, $locale, '' === $order['currency'] ? null : $order['currency']),
                'placedBy' => null === $order['placedBy'] ? null : ($names[$order['placedBy']] ?? null),
            ];
        }

        return [
            'company_orders' => $rows,
            'orders_total' => $result['total'],
            'orders_page' => $result['page'],
            'orders_last_page' => $result['lastPage'],
        ];
    }

    /**
     * An amount as it is typed back in a field: no useless decimals.
     */
    private static function amountInput(?string $amount): string
    {
        if (null === $amount) {
            return '';
        }

        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
