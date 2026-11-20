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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyInput;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyListPresenter;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyRefusedException;
use BackOfficeDefaultTwigBundle\Service\Company\CompanySheetContext;
use BackOfficeDefaultTwigBundle\Service\Company\CompanyWriteGate;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Company\CompanyFacade;
use Thelia\Domain\Company\Exception\CompanyHasOrdersException;
use Thelia\Model\Company;
use Twig\Environment;

/**
 * The companies of the shop: the list, and the sheet of one company with its details, its
 * buyers, its shared addresses and its orders. The buyers and their roles have their own controller.
 */
#[Route('/admin/companies', name: 'admin.company.')]
final class CompanyController
{
    public const CREATION_LABEL = 'Company creation';
    public const UPDATE_LABEL = 'Company update';
    public const DELETION_LABEL = 'Company deletion';
    public const SUSPENSION_LABEL = 'Company suspension';

    private const LIST_ROUTE = 'admin.company.list';
    private const VIEW_ROUTE = 'admin.company.view';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly CompanyWriteGate $gate,
        private readonly CompanyRepository $companies,
        private readonly CompanyFacade $facade,
        private readonly CompanyListPresenter $presenter,
        private readonly CompanySheetContext $sheet,
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

        $sort = ListSort::fromRequest($request, CompanyRepository::SORTABLE_FIELDS, 'name');
        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'active' => match ((string) $request->query->get('active')) {
                '1' => true,
                '0' => false,
                default => null,
            },
        ];

        $result = $this->companies->search(
            '' === $filters['q'] ? null : $filters['q'],
            $filters['active'],
            $sort->field,
            $sort->direction,
            (int) $request->query->get('page', 1),
        );

        return new Response($this->twig->render('@BackOfficeDefaultTwig/company/list.html.twig', [
            'rows' => $this->presenter->rows($result['rows']),
            'total' => $result['total'],
            'current_page' => $result['page'],
            'last_page' => $result['lastPage'],
            'filters' => $filters,
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->gate->run($request, AccessManager::CREATE, self::CREATION_LABEL, $this->urls->generate(self::LIST_ROUTE), null, 'Company created.', function () use ($request): array {
            $company = $this->facade->create(CompanyInput::company($request, null));

            return [
                \sprintf('Company %s created', (string) $company->getName()),
                $this->urls->generate(self::VIEW_ROUTE, ['company_id' => $company->getId()]),
            ];
        });
    }

    #[Route('/{company_id}', name: 'view', methods: ['GET'], requirements: ['company_id' => '\d+'])]
    public function view(int $company_id, Request $request): Response
    {
        if ($denied = $this->access->check(CompanyWriteGate::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $company = $this->companies->find($company_id);
        if (null === $company) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $tab = (string) $request->query->get('tab', 'infos');
        $tab = \in_array($tab, CompanySheetContext::TABS, true) ? $tab : 'infos';
        $locale = $request->getLocale();

        $context = [
            'company' => $company,
            'tab' => $tab,
            'buyer_count' => $this->companies->countBuyers($company_id),
            'can_update' => $this->access->can(CompanyWriteGate::RESOURCE, AccessManager::UPDATE),
            'can_delete' => $this->access->can(CompanyWriteGate::RESOURCE, AccessManager::DELETE),
        ];

        $editLang = $this->editLocale->resolveFromRequest($request);
        $context['edit_language_id'] = (int) $editLang->getId();

        $context += match ($tab) {
            'buyers' => $this->sheet->buyers($company, (int) $request->query->get('page', 1), $locale, $editLang->getLocale() ?? 'en_US'),
            'addresses' => $this->sheet->addresses($company),
            'orders' => $this->sheet->orders($company, (int) $request->query->get('page', 1), $locale),
            default => $this->sheet->infos($company),
        };

        return new Response($this->twig->render('@BackOfficeDefaultTwig/company/view.html.twig', $context));
    }

    #[Route('/{company_id}/save', name: 'save', methods: ['POST'], requirements: ['company_id' => '\d+'])]
    public function save(int $company_id, Request $request): Response
    {
        $redirect = $this->urls->generate(self::VIEW_ROUTE, ['company_id' => $company_id, 'tab' => 'infos']);

        return $this->gate->run($request, AccessManager::UPDATE, self::UPDATE_LABEL, $redirect, $company_id, 'Company saved.', function () use ($company_id, $request): string {
            $company = $this->require($company_id);
            $data = CompanyInput::company($request, CompanyInput::billingAddressId($request));

            if (null !== $data->parentId && ($data->parentId === $company_id || null === $this->companies->find($data->parentId))) {
                throw new CompanyRefusedException($this->translator->trans('The parent of a company is another company of the shop.'));
            }

            $this->facade->update($company, $data);

            return \sprintf('Company %s updated', (string) $company->getName());
        });
    }

    #[Route('/{company_id}/suspend', name: 'suspend', methods: ['POST'], requirements: ['company_id' => '\d+'])]
    public function suspend(int $company_id, Request $request): Response
    {
        $redirect = $this->urls->generate(self::VIEW_ROUTE, ['company_id' => $company_id]);

        return $this->gate->run($request, AccessManager::UPDATE, self::SUSPENSION_LABEL, $redirect, $company_id, 'Company suspended.', function () use ($company_id): string {
            $this->facade->suspend($this->require($company_id));

            return \sprintf('Company %d suspended', $company_id);
        });
    }

    #[Route('/{company_id}/reactivate', name: 'reactivate', methods: ['POST'], requirements: ['company_id' => '\d+'])]
    public function reactivate(int $company_id, Request $request): Response
    {
        $redirect = $this->urls->generate(self::VIEW_ROUTE, ['company_id' => $company_id]);

        return $this->gate->run($request, AccessManager::UPDATE, self::SUSPENSION_LABEL, $redirect, $company_id, 'Company reactivated.', function () use ($company_id): string {
            $this->facade->reactivate($this->require($company_id));

            return \sprintf('Company %d reactivated', $company_id);
        });
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $companyId = (int) $request->request->get('company_id', 0);

        return $this->gate->run($request, AccessManager::DELETE, self::DELETION_LABEL, $this->urls->generate(self::LIST_ROUTE), $companyId > 0 ? $companyId : null, 'Company deleted.', function () use ($companyId): string {
            $company = $this->require($companyId);
            $name = (string) $company->getName();

            try {
                $this->facade->delete($company);
            } catch (CompanyHasOrdersException) {
                throw new CompanyRefusedException($this->translator->trans('This company has orders and cannot be deleted. Suspend it instead.'), $this->urls->generate(self::VIEW_ROUTE, ['company_id' => $companyId]));
            }

            return \sprintf('Company %s deleted', $name);
        });
    }

    private function require(int $companyId): Company
    {
        return $this->companies->find($companyId)
            ?? throw new CompanyRefusedException($this->translator->trans('This company does not exist.'));
    }
}
