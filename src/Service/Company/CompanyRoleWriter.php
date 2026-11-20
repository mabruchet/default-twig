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

use BackOfficeDefaultTwigBundle\Repository\CompanyRoleRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Company\Service\CompanyPermissionRegistry;
use Thelia\Model\CompanyQuery;
use Thelia\Model\CompanyRole;

/**
 * Writes the buyer roles: a code and a list of permissions the shop knows, with a title in one
 * language. The roles the shop installs are never changed or deleted, a role never changes its
 * code or its company, and a company keeps clear of the codes the shared roles use.
 */
final readonly class CompanyRoleWriter
{
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,99}$/';

    public function __construct(
        private CompanyRoleRepository $roles,
        private CompanyPermissionRegistry $permissionRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<string> $permissions
     *
     * @throws CompanyRefusedException
     */
    public function create(?int $companyId, string $code, string $locale, string $title, array $permissions): CompanyRole
    {
        $code = trim($code);
        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new CompanyRefusedException($this->translator->trans('The code of a role is made of lowercase letters, digits, dots, dashes and underscores.'));
        }

        $permissions = $this->checked($permissions);

        if (null !== $companyId && !CompanyQuery::create()->filterById($companyId)->exists()) {
            throw new CompanyRefusedException($this->translator->trans('This company does not exist.'));
        }

        if (null !== $companyId && $this->roles->sharedCodeExists($code)) {
            throw new CompanyRefusedException($this->translator->trans('A role of a company cannot take the code of a role every company shares.'));
        }

        if (null === $companyId && $this->roles->codeUsedByAnyCompany($code)) {
            throw new CompanyRefusedException($this->translator->trans('A role of a company already uses this code: a shared role would never be the one found.'));
        }

        if (null === $companyId ? $this->roles->sharedCodeExists($code) : $this->roles->ownedCodeExists($code, $companyId)) {
            throw new CompanyRefusedException($this->translator->trans('A role with this code already exists.'));
        }

        $role = (new CompanyRole())->setCode($code)->setCompanyId($companyId);

        return $this->store($role, $locale, $title, $permissions);
    }

    /**
     * @param list<string> $permissions
     *
     * @throws CompanyRefusedException
     */
    public function update(CompanyRole $role, string $locale, string $title, array $permissions): CompanyRole
    {
        if ($this->roles->isShipped($role)) {
            throw new CompanyRefusedException($this->translator->trans('The roles the shop installs cannot be changed.'));
        }

        return $this->store($role, $locale, $title, $this->checked($permissions));
    }

    /**
     * @throws CompanyRefusedException
     */
    public function delete(CompanyRole $role): void
    {
        if ($this->roles->isShipped($role)) {
            throw new CompanyRefusedException($this->translator->trans('The roles the shop installs cannot be deleted.'));
        }

        if ($this->roles->countBuyers((int) $role->getId()) > 0) {
            throw new CompanyRefusedException($this->translator->trans('Buyers still hold this role: give them another role first.'));
        }

        $role->delete();
    }

    /**
     * @param list<string> $permissions
     */
    private function store(CompanyRole $role, string $locale, string $title, array $permissions): CompanyRole
    {
        $role->setPermissions(json_encode($permissions, \JSON_THROW_ON_ERROR));

        $title = trim($title);
        if ('' !== $title) {
            $role->setLocale($locale)->setTitle(mb_substr($title, 0, 255));
        }

        $role->save();

        return $role;
    }

    /**
     * @param list<string> $permissions
     *
     * @return list<string>
     *
     * @throws CompanyRefusedException
     */
    private function checked(array $permissions): array
    {
        foreach ($permissions as $permission) {
            if (!$this->permissionRegistry->isKnown($permission)) {
                throw new CompanyRefusedException($this->translator->trans('This permission is not one of this shop.'));
            }
        }

        return array_values(array_unique($permissions));
    }
}
