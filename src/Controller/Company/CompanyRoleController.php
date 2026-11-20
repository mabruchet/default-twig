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

use BackOfficeDefaultTwigBundle\Repository\CompanyRoleRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyInput;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyRefusedException;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyRoleWriter;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyWriteGate;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Company\Service\CompanyPermissionRegistry;
use Twig\Environment;

/**
 * The roles every company may give its buyers. The roles the shop installs are listed and read,
 * the others are created, edited and deleted here; a role that belongs to one company is made
 * on the sheet of that company.
 */
#[Route('/admin/configuration/company-roles', name: 'admin.configuration.company-roles.')]
final class CompanyRoleController
{
    public const ROLE_LABEL = 'Buyer role';

    private const LIST_ROUTE = 'admin.configuration.company-roles.list';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly CompanyWriteGate $gate,
        private readonly CompanyRoleRepository $roles,
        private readonly CompanyRoleWriter $writer,
        private readonly CompanyPermissionRegistry $permissions,
        private readonly EditLocaleResolver $editLocale,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(CompanyWriteGate::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/company-role/list.html.twig', [
            'roles' => $this->roles->shared($request->getLocale()),
            'permission_labels' => $this->permissions->all(),
            'can_create' => $this->access->can(CompanyWriteGate::RESOURCE, AccessManager::CREATE),
            'can_update' => $this->access->can(CompanyWriteGate::RESOURCE, AccessManager::UPDATE),
            'can_delete' => $this->access->can(CompanyWriteGate::RESOURCE, AccessManager::DELETE),
        ]));
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if ($denied = $this->access->check(CompanyWriteGate::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        return $this->edit(null, $request);
    }

    #[Route('/{role_id}', name: 'edit', methods: ['GET'], requirements: ['role_id' => '\d+'])]
    public function view(int $role_id, Request $request): Response
    {
        if ($denied = $this->access->check(CompanyWriteGate::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $role = $this->roles->find($role_id);
        if (null === $role || null !== $role->getCompanyId()) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->edit($role_id, $request);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $roleId = CompanyInput::id($request->request->get('role_id'));

        return $this->gate->run($request, null === $roleId ? AccessManager::CREATE : AccessManager::UPDATE, self::ROLE_LABEL, $this->urls->generate(self::LIST_ROUTE), $roleId, 'Role saved.', function () use ($request, $roleId): array {
            $locale = $this->editLocale->resolveLocale((int) $request->request->get('edit_language_id', 0));
            $title = (string) $request->request->get('title', '');
            $permissions = array_values(array_filter((array) $request->request->all('permissions'), '\is_string'));

            if (null === $roleId) {
                $role = $this->writer->create(null, (string) $request->request->get('code', ''), $locale, $title, $permissions);
            } else {
                $role = $this->roles->find($roleId);
                if (null === $role || null !== $role->getCompanyId()) {
                    throw new CompanyRefusedException($this->translator->trans('This role does not exist, or belongs to another company.'));
                }

                $this->writer->update($role, $locale, $title, $permissions);
            }

            return [
                \sprintf('Role %s saved', (string) $role->getCode()),
                $this->urls->generate('admin.configuration.company-roles.edit', ['role_id' => $role->getId()]),
            ];
        });
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $roleId = (int) $request->request->get('role_id', 0);

        return $this->gate->run($request, AccessManager::DELETE, self::ROLE_LABEL, $this->urls->generate(self::LIST_ROUTE), $roleId > 0 ? $roleId : null, 'Role deleted.', function () use ($roleId): string {
            $role = $this->roles->find($roleId);
            if (null === $role || null !== $role->getCompanyId()) {
                throw new CompanyRefusedException($this->translator->trans('This role does not exist, or belongs to another company.'));
            }

            $code = (string) $role->getCode();
            $this->writer->delete($role);

            return \sprintf('Role %s deleted', $code);
        });
    }

    private function edit(?int $roleId, Request $request): Response
    {
        $lang = $this->editLocale->resolveFromRequest($request);
        $locale = $lang->getLocale() ?? 'en_US';
        $role = null === $roleId ? null : $this->roles->find($roleId);

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/company-role/edit.html.twig', [
            'role' => $role,
            'shipped' => null !== $role && $this->roles->isShipped($role),
            'granted' => null === $role ? [] : $role->getPermissionList(),
            'title' => null === $role ? '' : ($this->roles->titles((int) $role->getId())[$locale] ?? ''),
            'buyers' => null === $role ? 0 : $this->roles->countBuyers((int) $role->getId()),
            'permission_labels' => $this->permissions->all(),
            'edit_language_id' => (int) $lang->getId(),
            'can_update' => $this->access->can(CompanyWriteGate::RESOURCE, null === $role ? AccessManager::CREATE : AccessManager::UPDATE),
        ]));
    }
}
