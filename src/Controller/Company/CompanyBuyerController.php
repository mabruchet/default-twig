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

namespace BackOfficeDefaultTwigBundle\Controller\Company;

use BackOfficeDefaultTwigBundle\Repository\CompanyRepository;
use BackOfficeDefaultTwigBundle\Repository\CompanyRoleRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyBuyerCreator;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyInput;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyRefusedException;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyRoleWriter;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyWriteGate;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Company\CompanyFacade;
use Thelia\Domain\Company\Service\CompanyLimits;
use Thelia\Model\Company;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;

/**
 * The buyers of a company: attaching an account, creating one, changing the role and the limits,
 * detaching; and the roles the company made for itself. Every write needs the right to update
 * the company and the token; the rules of the company (the last administrator, a guest, an
 * account already attached) are the core's, shown to the administrator as they refuse.
 */
#[Route('/admin/companies/{company_id}', name: 'admin.company.', requirements: ['company_id' => '\d+'])]
final class CompanyBuyerController
{
    public const ATTACH_LABEL = 'Buyer attachment';
    public const CREATION_LABEL = 'Buyer creation';
    public const CHANGE_LABEL = 'Buyer update';
    public const DETACH_LABEL = 'Buyer detachment';
    public const ROLE_LABEL = 'Role of the company';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly CompanyWriteGate $gate,
        private readonly CompanyRepository $companies,
        private readonly CompanyRoleRepository $roles,
        private readonly CompanyFacade $facade,
        private readonly CompanyBuyerCreator $creator,
        private readonly CompanyRoleWriter $roleWriter,
        private readonly EditLocaleResolver $editLocale,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/buyers/attach', name: 'buyer.attach', methods: ['POST'])]
    public function attach(int $company_id, Request $request): Response
    {
        // The account is found by what is typed: the administrator needs the right to see customers.
        if ($denied = $this->access->check(AdminResources::CUSTOMER, [], AccessManager::VIEW)) {
            return $denied;
        }

        return $this->gate->run($request, AccessManager::UPDATE, self::ATTACH_LABEL, $this->buyersUrl($company_id), $company_id, 'Buyer attached.', function () use ($company_id, $request): string {
            $company = $this->require($company_id);
            [$orderLimit, $periodLimit, $period] = CompanyInput::limits($request);

            $customer = $this->customerOf((string) $request->request->get('lookup', ''));
            $this->facade->attach($company, $customer, (string) $request->request->get('role_code', ''), $orderLimit, $periodLimit, $period);

            return \sprintf('Customer %d attached to company %d', (int) $customer->getId(), $company_id);
        });
    }

    #[Route('/buyers/create', name: 'buyer.create', methods: ['POST'])]
    public function create(int $company_id, Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::CUSTOMER, [], AccessManager::CREATE)) {
            return $denied;
        }

        return $this->gate->run($request, AccessManager::UPDATE, self::CREATION_LABEL, $this->buyersUrl($company_id), $company_id, 'Buyer created.', function () use ($company_id, $request): string {
            $company = $this->require($company_id);
            [$orderLimit, $periodLimit, $period] = CompanyInput::limits($request);

            $customerId = $this->creator->create(
                $company,
                (string) $request->request->get('firstname', ''),
                (string) $request->request->get('lastname', ''),
                (string) $request->request->get('email', ''),
                (string) $request->request->get('role_code', ''),
                $orderLimit,
                $periodLimit,
                $period,
            );

            return \sprintf('Customer %d created and attached to company %d', $customerId, $company_id);
        });
    }

    #[Route('/buyers/{customer_id}/update', name: 'buyer.update', methods: ['POST'], requirements: ['customer_id' => '\d+'])]
    public function update(int $company_id, int $customer_id, Request $request): Response
    {
        return $this->gate->run($request, AccessManager::UPDATE, self::CHANGE_LABEL, $this->buyersUrl($company_id), $company_id, 'Buyer saved.', function () use ($company_id, $customer_id, $request): string {
            $membership = $this->membership($company_id, $customer_id);
            $customer = $this->customer($customer_id);
            [$orderLimit, $periodLimit, $period] = CompanyInput::limits($request);

            CompanyLimits::assertValid($orderLimit, $periodLimit, $period);

            $roleCode = (string) $request->request->get('role_code', '');
            if ($roleCode !== $membership['roleCode']) {
                $this->facade->changeRole($customer, $roleCode);
            }

            $this->facade->changeLimits($customer, $orderLimit, $periodLimit, $period);

            return \sprintf('Buyer %d of company %d updated', $customer_id, $company_id);
        });
    }

    #[Route('/buyers/{customer_id}/detach', name: 'buyer.detach', methods: ['POST'], requirements: ['customer_id' => '\d+'])]
    public function detach(int $company_id, int $customer_id, Request $request): Response
    {
        return $this->gate->run($request, AccessManager::UPDATE, self::DETACH_LABEL, $this->buyersUrl($company_id), $company_id, 'Buyer detached.', function () use ($company_id, $customer_id): string {
            $this->membership($company_id, $customer_id);
            $this->facade->detach($this->customer($customer_id));

            return \sprintf('Customer %d detached from company %d', $customer_id, $company_id);
        });
    }

    #[Route('/roles/save', name: 'role.save', methods: ['POST'])]
    public function saveRole(int $company_id, Request $request): Response
    {
        return $this->gate->run($request, AccessManager::UPDATE, self::ROLE_LABEL, $this->buyersUrl($company_id), $company_id, 'Role saved.', function () use ($company_id, $request): string {
            $this->require($company_id);

            $locale = $this->editLocale->resolveLocale((int) $request->request->get('edit_language_id', 0));
            $title = (string) $request->request->get('title', '');
            $permissions = array_values(array_filter((array) $request->request->all('permissions'), '\is_string'));
            $roleId = CompanyInput::id($request->request->get('role_id'));

            if (null === $roleId) {
                $role = $this->roleWriter->create($company_id, (string) $request->request->get('code', ''), $locale, $title, $permissions);
            } else {
                $role = $this->roleOf($company_id, $roleId);
                $this->roleWriter->update($role, $locale, $title, $permissions);
            }

            return \sprintf('Role %s of company %d saved', (string) $role->getCode(), $company_id);
        });
    }

    #[Route('/roles/{role_id}/delete', name: 'role.delete', methods: ['POST'], requirements: ['role_id' => '\d+'])]
    public function deleteRole(int $company_id, int $role_id, Request $request): Response
    {
        return $this->gate->run($request, AccessManager::UPDATE, self::ROLE_LABEL, $this->buyersUrl($company_id), $company_id, 'Role deleted.', function () use ($company_id, $role_id): string {
            $role = $this->roleOf($company_id, $role_id);
            $code = (string) $role->getCode();
            $this->roleWriter->delete($role);

            return \sprintf('Role %s of company %d deleted', $code, $company_id);
        });
    }

    private function buyersUrl(int $company_id): string
    {
        return $this->urls->generate('admin.company.view', ['company_id' => $company_id, 'tab' => 'buyers']);
    }

    private function require(int $companyId): Company
    {
        return $this->companies->find($companyId)
            ?? throw new CompanyRefusedException($this->translator->trans('This company does not exist.'));
    }

    private function customer(int $customerId): Customer
    {
        return CustomerQuery::create()->findPk($customerId)
            ?? throw new CompanyRefusedException($this->translator->trans('This customer does not exist.'));
    }

    /**
     * The account an administrator typed the e-mail address or the reference of, exactly.
     */
    private function customerOf(string $lookup): Customer
    {
        $matches = $this->companies->customersByExactLookup($lookup);

        if ([] === $matches) {
            throw new CompanyRefusedException($this->translator->trans('No account has exactly this e-mail address or reference.'));
        }

        $registered = array_values(array_filter($matches, static fn (Customer $customer): bool => !$customer->isGuest()));

        if (\count($registered) > 1) {
            throw new CompanyRefusedException($this->translator->trans('Several accounts match: use the reference of the customer.'));
        }

        return $registered[0] ?? $matches[0];
    }

    /**
     * @return array{companyId: int, companyName: string, companyActive: bool, roleCode: string, roleId: int, orderLimit: string|null, periodLimit: string|null, period: string|null}
     */
    private function membership(int $companyId, int $customerId): array
    {
        $membership = $this->companies->membershipOfCustomer($customerId);

        if (null === $membership || $membership['companyId'] !== $companyId) {
            throw new CompanyRefusedException($this->translator->trans('This customer does not belong to this company.'));
        }

        return $membership;
    }

    private function roleOf(int $companyId, int $roleId): \Thelia\Model\CompanyRole
    {
        $role = $this->roles->find($roleId);

        if (null === $role || $role->getCompanyId() !== $companyId) {
            throw new CompanyRefusedException($this->translator->trans('This role does not exist, or belongs to another company.'));
        }

        return $role;
    }
}
