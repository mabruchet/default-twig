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

namespace BackOfficeDefaultTwigBundle\Controller\Pricing;

use BackOfficeDefaultTwigBundle\Form\Pricing\PriceListType;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use BackOfficeDefaultTwigBundle\Repository\PriceListAssignmentRepository;
use BackOfficeDefaultTwigBundle\Repository\PriceListItemRepository;
use BackOfficeDefaultTwigBundle\Repository\PriceListRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListListPresenter;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListRefusedException;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListWriteGate;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListWriter;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Currency;
use Thelia\Model\PriceList;

/**
 * The price lists of the shop: the list screen, and the sheet of one list with its settings,
 * its lines and its assignments. The lines, the assignments and the CSV have their own controllers.
 */
#[Route('/admin/pricing/price-lists', name: 'admin.pricing.price_list.')]
final class PriceListController
{
    public const DELETION_LABEL = 'Price list deletion';
    public const CREATION_LABEL = 'Price list creation';
    public const UPDATE_LABEL = 'Price list update';

    public const TABS = ['settings', 'lines', 'assignments'];

    private const RESOURCE = PriceListWriteGate::RESOURCE;
    private const LIST_ROUTE = 'admin.pricing.price_list.list';
    private const VIEW_ROUTE = 'admin.pricing.price_list.view';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/pricing/price-list/list.html.twig';
    private const VIEW_TEMPLATE = '@BackOfficeDefaultTwig/pricing/price-list/view.html.twig';
    private const CREATION_FORM = 'thelia_price_list_creation';
    private const MODIFICATION_FORM = 'thelia_price_list_modification';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminFormValidator $validator,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly PriceListRepository $priceLists,
        private readonly PriceListItemRepository $items,
        private readonly PriceListAssignmentRepository $assignments,
        private readonly CustomerGroupRepository $customerGroups,
        private readonly PriceListWriter $writer,
        private readonly PriceListWriteGate $gate,
        private readonly PriceListListPresenter $presenter,
        private readonly EditLocaleResolver $editLocale,
        private readonly RestrictedAdminGuard $restricted,
        private readonly FormFactoryInterface $formFactory,
        private readonly \Twig\Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $sort = ListSort::fromRequest($request, PriceListRepository::SORTABLE_FIELDS, 'priority');
        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'currency' => (int) $request->query->get('currency', 0),
            'active' => $this->tristate($request->query->get('active')),
            'exclusive' => $this->tristate($request->query->get('exclusive')),
        ];

        $result = $this->priceLists->search(
            '' === $filters['q'] ? null : $filters['q'],
            $filters['currency'] > 0 ? $filters['currency'] : null,
            $filters['active'],
            $filters['exclusive'],
            $sort->field,
            $sort->direction,
            (int) $request->query->get('page', 1),
            $request->getLocale(),
        );

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->presenter->rows($result['rows']),
            'total' => $result['total'],
            'current_page' => $result['page'],
            'last_page' => $result['lastPage'],
            'filters' => $filters,
            'currencies' => $this->priceLists->currencyCodes(),
            'create_form' => $this->creationForm($request->getLocale())->createView(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
            'can_write' => !$this->restricted->isRestricted(),
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::CREATE)) {
            return $denied;
        }

        $listRedirect = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        $form = $this->creationForm($request->getLocale());

        try {
            $data = $this->validator->validate($form)->getData();
            $priceList = $this->write(null, $data);
        } catch (FormValidationException|PriceListRefusedException $refusal) {
            $this->errorRenderer->refuse($this->translator->trans(self::CREATION_LABEL), $refusal->getMessage(), null, $refusal);

            return $listRedirect;
        } catch (\Throwable $failure) {
            $this->errorRenderer->refuse($this->translator->trans(self::CREATION_LABEL), $this->translator->trans(PriceListWriteGate::GENERIC_FAILURE), null, $failure);

            return $listRedirect;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::CREATE, \sprintf('Price list %s created', $priceList->getCode()), (int) $priceList->getId());

        return new RedirectResponse($this->urls->generate(self::VIEW_ROUTE, ['price_list_id' => $priceList->getId()]));
    }

    #[Route('/{price_list_id}', name: 'view', methods: ['GET'], requirements: ['price_list_id' => '\d+'])]
    public function view(int $price_list_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $priceList = $this->priceLists->find($price_list_id);
        if (null === $priceList) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $tab = (string) $request->query->get('tab', 'lines');
        $tab = \in_array($tab, self::TABS, true) ? $tab : 'lines';

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $currencies = $this->priceLists->currencyCodes();

        $context = [
            'price_list' => $priceList->setLocale($locale),
            'currency_code' => $currencies[(int) $priceList->getCurrencyId()] ?? '',
            'tab' => $tab,
            'can_write' => !$this->restricted->isRestricted(),
            'edit_language_id' => (int) $editLang->getId(),
            'line_count' => $this->priceLists->countLines($price_list_id),
        ];

        if ('settings' === $tab) {
            $context['form'] = $this->modificationForm($priceList, $locale)->createView();
        }

        if ('lines' === $tab) {
            $search = trim((string) $request->query->get('q', ''));
            $result = $this->items->search($price_list_id, '' === $search ? null : $search, (int) $request->query->get('page', 1), $request->getLocale());
            $context += [
                'lines' => $result['rows'],
                'lines_total' => $result['total'],
                'current_page' => $result['page'],
                'last_page' => $result['lastPage'],
                'search' => $search,
            ];
        }

        if ('assignments' === $tab) {
            // The customers are named on this tab (list and search): the right to see them is needed, as on the assortments.
            $canPickCustomers = $this->access->canView(AdminResources::CUSTOMER);
            $term = $canPickCustomers ? trim((string) $request->query->get('customer_search', '')) : '';
            $assigned = $this->assignments->customersPage($price_list_id, $canPickCustomers, (int) $request->query->get('assigned_page', 1));
            $context += [
                'can_pick_customers' => $canPickCustomers,
                'assigned_page' => $assigned['page'],
                'assigned_last_page' => $assigned['lastPage'],
                'group_choices' => $this->customerGroups->findChoices($request->getLocale()),
                'assigned_group_ids' => $this->assignments->groupIds($price_list_id),
                'assigned_customers' => $assigned['rows'],
                'assigned_customers_total' => $this->assignments->countCustomers($price_list_id),
                'customer_search' => $term,
                'customer_search_minimum' => PriceListAssignmentRepository::SEARCH_MINIMUM_LENGTH,
                'customer_search_limit' => PriceListAssignmentRepository::SEARCH_LIMIT,
                'customer_search_too_short' => '' !== $term && mb_strlen($term) < PriceListAssignmentRepository::SEARCH_MINIMUM_LENGTH,
                'customer_results' => $this->assignments->searchCustomers($price_list_id, $term),
            ];
        }

        return new Response($this->twig->render(self::VIEW_TEMPLATE, $context));
    }

    #[Route('/{price_list_id}/save', name: 'save', methods: ['POST'], requirements: ['price_list_id' => '\d+'])]
    public function save(int $price_list_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::UPDATE)) {
            return $denied;
        }

        $priceList = $this->priceLists->find($price_list_id);
        if (null === $priceList) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $redirect = new RedirectResponse($this->urls->generate(self::VIEW_ROUTE, ['price_list_id' => $price_list_id, 'tab' => 'settings']));
        $form = $this->formFactory->createNamed(self::MODIFICATION_FORM, PriceListType::class, null, ['include_id' => true]);

        try {
            $data = $this->validator->validate($form)->getData();
            $this->write($priceList, $data);
        } catch (FormValidationException|PriceListRefusedException $refusal) {
            $this->errorRenderer->refuse($this->translator->trans(self::UPDATE_LABEL), $refusal->getMessage(), null, $refusal);

            return $redirect;
        } catch (\Throwable $failure) {
            $this->errorRenderer->refuse($this->translator->trans(self::UPDATE_LABEL), $this->translator->trans(PriceListWriteGate::GENERIC_FAILURE), null, $failure);

            return $redirect;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Price list %s updated', $priceList->getCode()), $price_list_id);

        return $redirect;
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $priceListId = (int) $request->request->get('price_list_id', 0);
        $priceList = $this->priceLists->find($priceListId);

        return $this->gate->run($request, AccessManager::DELETE, self::DELETION_LABEL, $this->urls->generate(self::LIST_ROUTE), $priceListId > 0 ? $priceListId : null, 'Price list deleted.', function () use ($priceList): string {
            if (null === $priceList) {
                throw new PriceListRefusedException($this->translator->trans('This price list does not exist.'));
            }

            $code = (string) $priceList->getCode();
            $this->writer->delete($priceList);

            return \sprintf('Price list %s deleted', $code);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(?PriceList $priceList, array $data): PriceList
    {
        return $this->writer->save(
            $priceList,
            (string) $data['code'],
            (int) $data['currency_id'],
            (int) ($data['priority'] ?? 100),
            (bool) ($data['exclusive'] ?? false),
            (bool) ($data['active'] ?? false),
            $data['starts_at'] ?? null,
            $data['ends_at'] ?? null,
            (string) $data['locale'],
            isset($data['title']) ? (string) $data['title'] : null,
        );
    }

    private function tristate(mixed $value): ?bool
    {
        return match ((string) $value) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    private function creationForm(string $locale): FormInterface
    {
        return $this->formFactory->createNamed(self::CREATION_FORM, PriceListType::class, [
            'locale' => $locale,
            'currency_id' => (int) Currency::getDefaultCurrency()->getId(),
            'priority' => 100,
            'active' => true,
            'exclusive' => false,
        ]);
    }

    private function modificationForm(PriceList $priceList, string $editLocale): FormInterface
    {
        return $this->formFactory->createNamed(self::MODIFICATION_FORM, PriceListType::class, [
            'id' => (int) $priceList->getId(),
            'locale' => $editLocale,
            'code' => $priceList->getCode(),
            'title' => $priceList->getTitle(),
            'currency_id' => (int) $priceList->getCurrencyId(),
            'priority' => (int) $priceList->getPriority(),
            'exclusive' => 1 === $priceList->getExclusive(),
            'active' => 1 === $priceList->getActive(),
            'starts_at' => $priceList->getStartsAt(),
            'ends_at' => $priceList->getEndsAt(),
        ], ['include_id' => true]);
    }
}
