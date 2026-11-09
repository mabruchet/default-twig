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

use BackOfficeDefaultTwigBundle\Repository\CatalogRestrictionRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\CatalogRestriction\BoundedSearch;
use BackOfficeDefaultTwigBundle\Service\CatalogRestriction\CatalogRestrictionDraft;
use BackOfficeDefaultTwigBundle\Service\CatalogRestriction\CatalogRestrictionListPresenter;
use BackOfficeDefaultTwigBundle\Service\CatalogRestriction\CatalogRestrictionRefusedException;
use BackOfficeDefaultTwigBundle\Service\CatalogRestriction\CatalogRestrictionWriter;
use BackOfficeDefaultTwigBundle\Service\CatalogRestriction\VisibleProductCounter;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The assortment rules: which categories, brands and products a customer group or a customer
 * may see, and which they may not. Everything is written under the pricing right; a rule
 * holds for every sale unit, so an administrator restricted to some of them only reads.
 */
#[Route('/admin/pricing/catalog-restrictions', name: 'admin.pricing.catalog_restriction.')]
final class CatalogRestrictionController
{
    private const RESOURCE = AdminResources::PRICING;
    private const LIST_ROUTE = 'admin.pricing.catalog_restriction.list';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/pricing/catalog-restriction/list.html.twig';
    private const NEW_TEMPLATE = '@BackOfficeDefaultTwig/pricing/catalog-restriction/new.html.twig';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flashes,
        private readonly CatalogRestrictionRepository $restrictions,
        private readonly CatalogRestrictionListPresenter $presenter,
        private readonly CatalogRestrictionWriter $writer,
        private readonly CustomerGroupRepository $customerGroups,
        private readonly BoundedSearch $search,
        private readonly VisibleProductCounter $counter,
        private readonly RestrictedAdminGuard $restricted,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $query = $request->query;
        $canPickCustomers = $this->access->canView(AdminResources::CUSTOMER);
        $scopeType = (string) $query->get('scope_type', '');
        $scopeType = \in_array($scopeType, [CatalogRestrictionDraft::SCOPE_GROUP, CatalogRestrictionDraft::SCOPE_CUSTOMER], true) ? $scopeType : null;
        $groupId = $this->id($query->get('group_id'));
        $customerSearch = $canPickCustomers ? trim((string) $query->get('customer_q', '')) : '';
        $page = max(1, (int) $query->get('page', 1));

        $result = $this->restrictions->page($scopeType, $groupId, $customerSearch, $page);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->presenter->rows($result['rows'], $locale, $canPickCustomers),
            'groups' => $this->customerGroups->findChoices($locale),
            'filter_scope_type' => $scopeType,
            'filter_group_id' => $groupId,
            'filter_customer_search' => $customerSearch,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($result['total'] / CatalogRestrictionRepository::PER_PAGE)),
            'total' => $result['total'],
            'can_write' => !$this->restricted->isRestricted(),
            'can_pick_customers' => $canPickCustomers,
            'counter' => $this->counterContext($request, $canPickCustomers),
        ]));
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::CREATE)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $canPickCustomers = $this->access->canView(AdminResources::CUSTOMER);
        $draft = CatalogRestrictionDraft::fromInput($request->query);

        // A rule for one customer is written by who may see the customers: the picking names them.
        if (!$canPickCustomers && CatalogRestrictionDraft::SCOPE_CUSTOMER === $draft->scopeType) {
            $draft = new CatalogRestrictionDraft(groupIds: $draft->groupIds, targetType: $draft->targetType, categoryId: $draft->categoryId, brandId: $draft->brandId, productId: $draft->productId, mode: $draft->mode, productSearch: $draft->productSearch);
        }

        $groups = $this->customerGroups->findChoices($locale);
        $groupLabels = array_column($groups, 'label', 'id');

        $customerLabel = null === $draft->customerId || !$canPickCustomers ? null : ($this->restrictions->customerLabels([$draft->customerId])[$draft->customerId] ?? null);
        $productLabel = null === $draft->productId ? null : ($this->restrictions->productLabels([$draft->productId], $locale)[$draft->productId] ?? null);
        $categories = $this->customerGroups->categoryChoices($locale);
        $brands = $this->restrictions->brandChoices($locale);

        $targetLabel = match ($draft->targetType) {
            CatalogRestrictionDraft::TARGET_CATEGORY => array_column($categories, 'label', 'id')[$draft->categoryId] ?? null,
            CatalogRestrictionDraft::TARGET_BRAND => array_column($brands, 'label', 'id')[$draft->brandId] ?? null,
            default => $productLabel,
        };
        $scopeLabel = CatalogRestrictionDraft::SCOPE_CUSTOMER === $draft->scopeType
            ? $customerLabel
            : implode(', ', array_filter(array_map(static fn (int $id): ?string => $groupLabels[$id] ?? null, $draft->groupIds)));

        return new Response($this->twig->render(self::NEW_TEMPLATE, [
            'draft' => $draft,
            'groups' => $groups,
            'categories' => $categories,
            'brands' => $brands,
            'can_pick_customers' => $canPickCustomers,
            'customer_results' => $canPickCustomers ? $this->search->customers($draft->customerSearch) : [],
            'product_results' => $this->search->products($draft->productSearch, $locale),
            'search_minimum' => BoundedSearch::MINIMUM_LENGTH,
            'customer_label' => $customerLabel,
            'product_label' => $productLabel,
            'scope_label' => '' === (string) $scopeLabel ? null : $scopeLabel,
            'target_label' => $targetLabel,
            'complete' => '' !== (string) $scopeLabel && null !== $targetLabel,
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

        if (!$this->tokenIsValid($request)) {
            $this->flashes->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return new RedirectResponse($this->urls->generate('admin.pricing.catalog_restriction.new'));
        }

        $draft = CatalogRestrictionDraft::fromInput($request->request);

        // The customer a rule names is picked among the customers: the right to see them is needed.
        if (CatalogRestrictionDraft::SCOPE_CUSTOMER === $draft->scopeType && ($denied = $this->access->check(AdminResources::CUSTOMER, [], AccessManager::VIEW))) {
            return $denied;
        }

        try {
            $result = $this->writer->create($draft);
        } catch (CatalogRestrictionRefusedException $refusal) {
            $this->flashes->add($request, 'danger', $refusal->getMessage());

            return new RedirectResponse($this->urls->generate('admin.pricing.catalog_restriction.new', $draft->toQuery()));
        }

        foreach ($result['created'] as $restrictionId) {
            $this->adminLogger->log(self::RESOURCE, AccessManager::CREATE, \sprintf('Assortment rule %d created', $restrictionId), $restrictionId);
        }

        if ([] !== $result['created']) {
            $this->flashes->add($request, 'success', $this->translator->trans('The assortment rule has been saved.'));
        }

        if ($result['existing'] > 0) {
            $this->flashes->add($request, 'warning', $this->translator->trans('Some of these rules already existed and were left as they were.'));
        }

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::DELETE)) {
            return $denied;
        }

        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::DELETE)) {
            return $denied;
        }

        $redirect = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));

        if (!$this->tokenIsValid($request)) {
            $this->flashes->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $redirect;
        }

        $restriction = $this->restrictions->find((int) $request->request->get('restriction_id', 0));

        if (null === $restriction) {
            return $redirect;
        }

        $restrictionId = (int) $restriction->getId();
        $this->writer->delete($restriction);

        $this->adminLogger->log(self::RESOURCE, AccessManager::DELETE, \sprintf('Assortment rule %d deleted', $restrictionId), $restrictionId);
        $this->flashes->add($request, 'success', $this->translator->trans('The assortment rule has been deleted.'));

        return $redirect;
    }

    /**
     * What the counter block shows: the products left by the rules to the group or to the
     * customer picked on the page, the customers a search proposes, or nothing when no choice
     * is made.
     *
     * @return array<string, mixed>
     */
    private function counterContext(Request $request, bool $canPickCustomers): array
    {
        $query = $request->query;
        $locale = $request->getLocale();
        $groupId = $this->id($query->get('view_group_id'));
        $customerId = $canPickCustomers ? $this->id($query->get('view_customer_id')) : null;
        $search = $canPickCustomers ? trim((string) $query->get('view_customer_q', '')) : '';
        $context = ['group_id' => $groupId, 'customer_search' => $search, 'customer_results' => $this->search->customers($search), 'result' => null, 'label' => null];

        if (null !== $customerId) {
            $customer = $this->restrictions->customer($customerId);

            if (null !== $customer) {
                $context['result'] = $this->counter->forCustomer($customer);
                $context['label'] = $this->restrictions->customerLabels([$customerId])[$customerId] ?? '#'.$customerId;
            }
        } elseif (null !== $groupId && [] !== $this->customerGroups->existingIds([$groupId])) {
            $context['result'] = $this->counter->forGroup($groupId);
            $context['label'] = array_column($this->customerGroups->findChoices($locale), 'label', 'id')[$groupId] ?? '#'.$groupId;
        }

        return $context;
    }

    private function tokenIsValid(Request $request): bool
    {
        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return false;
        }

        return true;
    }

    private function id(mixed $value): ?int
    {
        $id = \is_scalar($value) ? (int) $value : 0;

        return $id > 0 ? $id : null;
    }
}
