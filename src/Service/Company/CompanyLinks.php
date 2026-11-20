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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Order;

/**
 * The company shown on the sheet of a customer and on the sheet of an order. An administrator
 * who may not view the companies sees none of it: the company is no part of what the customer
 * and order rights open.
 */
final readonly class CompanyLinks
{
    public function __construct(
        private CompanyRepository $companies,
        private CompanyRoleRepository $roles,
        private AdminAccessChecker $access,
        private MoneyFormatter $money,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function isVisible(): bool
    {
        return $this->access->canView(AdminResources::COMPANY);
    }

    /**
     * @return array{company: array{id: int, name: string, active: bool, url: string}, role: string, orderLimit: string|null, periodLimit: string|null, period: string|null}|null
     */
    public function ofCustomer(int $customerId, string $locale): ?array
    {
        $membership = $this->isVisible() ? $this->companies->membershipOfCustomer($customerId) : null;
        if (null === $membership) {
            return null;
        }

        $role = $membership['roleCode'];
        foreach ($this->roles->availableTo($membership['companyId'], $locale) as $candidate) {
            if ($candidate['id'] === $membership['roleId']) {
                $role = $candidate['title'];
            }
        }

        return [
            'company' => [
                'id' => $membership['companyId'],
                'name' => $membership['companyName'],
                'active' => $membership['companyActive'],
                'url' => $this->urls->generate('admin.company.view', ['company_id' => $membership['companyId']]),
            ],
            'role' => $role,
            'orderLimit' => $this->amount($membership['orderLimit'], $locale),
            'periodLimit' => $this->amount($membership['periodLimit'], $locale),
            'period' => $membership['period'],
        ];
    }

    /**
     * @return array{company: array{id: int, name: string, url: string}, placedBy: array{name: string, url: string|null}|null}|null
     */
    public function ofOrder(Order $order): ?array
    {
        $companyId = $order->getCompanyId();
        if (null === $companyId || !$this->isVisible()) {
            return null;
        }

        $company = $this->companies->summary((int) $companyId);
        if (null === $company) {
            return null;
        }

        $placedById = $order->getPlacedByCustomerId();
        $names = null === $placedById ? [] : $this->companies->customerNames([(int) $placedById]);

        return [
            'company' => [
                'id' => $company['id'],
                'name' => $company['name'],
                'url' => $this->urls->generate('admin.company.view', ['company_id' => $company['id']]),
            ],
            'placedBy' => null === $placedById || !isset($names[(int) $placedById]) ? null : [
                'name' => $names[(int) $placedById],
                'url' => $this->access->canView(AdminResources::CUSTOMER) ? $this->urls->generate('admin.customer.update.view', ['customer_id' => (int) $placedById]) : null,
            ],
        ];
    }

    private function amount(?string $amount, string $locale): ?string
    {
        return null === $amount ? null : $this->money->format((float) $amount, $locale);
    }
}
