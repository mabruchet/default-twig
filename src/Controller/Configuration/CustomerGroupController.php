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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Form\Configuration\CustomerGroupType;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\CustomerGroup\CustomerGroupListPresenter;
use BackOfficeDefaultTwigBundle\Service\CustomerGroup\CustomerGroupRefusedException;
use BackOfficeDefaultTwigBundle\Service\CustomerGroup\CustomerGroupWriter;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
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
use Thelia\Model\ConfigQuery;
use Thelia\Model\CustomerGroup;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The customer groups of the shop: their discount, their price display, the payment
 * modules and the categories they are allowed. The default group is what a new account
 * and a visitor belong to; it cannot be deleted.
 */
#[Route('/admin/configuration/customer-groups', name: 'admin.configuration.customer-groups.')]
final class CustomerGroupController
{
    /**
     * Texts the screen translates by constant, which the reading of the sources for the
     * catalogues does not see: the test of the translations lists them.
     */
    public const REORDER_LABEL = 'Customer group reorder';
    public const DELETION_LABEL = 'Customer group deletion';
    public const REGISTRATION_OPENING_LABEL = 'Customer group registration link';
    public const RESTRICTION_LIFT_LABEL = 'Customer group restriction';
    public const GENERIC_FAILURE = 'The customer group could not be saved. Please try again.';

    /** Path of the sign-up form of a group on the storefront, token appended. */
    private const REGISTRATION_PATH = '/register/';

    private const RESOURCE = AdminResources::CUSTOMER_GROUP;
    private const LIST_ROUTE = 'admin.configuration.customer-groups.default';
    private const EDIT_ROUTE = 'admin.configuration.customer-groups.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/customer-group/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/customer-group/edit.html.twig';
    private const CREATION_FORM = 'thelia_customer_group_creation';
    private const MODIFICATION_FORM = 'thelia_customer_group_modification';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminFormValidator $validator,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly CustomerGroupRepository $customerGroups,
        private readonly CustomerGroupWriter $writer,
        private readonly CustomerGroupListPresenter $presenter,
        private readonly EditLocaleResolver $editLocale,
        private readonly RestrictedAdminGuard $restricted,
        private readonly FormFactoryInterface $formFactory,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $sort = ListSort::fromRequest($request, CustomerGroupListPresenter::SORTABLE_FIELDS, 'position');

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->presenter->rows($request->getLocale(), $sort->field, $sort->direction),
            'create_form' => $this->creationForm($request->getLocale())->createView(),
            'update_position_url' => $this->urls->generate('admin.configuration.customer-groups.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        // A group changes the discount and the prices of every sale unit at once.
        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::CREATE)) {
            return $denied;
        }

        $form = $this->creationForm($request->getLocale());

        try {
            $data = $this->validator->validate($form)->getData();
            $customerGroup = $this->writer->create(
                (string) $data['code'],
                (string) $data['locale'],
                (string) $data['title'],
                $data['description'] ?? null,
                (float) ($data['discount'] ?? 0),
                (bool) ($data['display_taxed_price'] ?? false),
                (bool) ($data['is_default'] ?? false),
                $this->ids($data['payment_module_ids'] ?? []),
                $this->ids($data['category_ids'] ?? []),
            );
        } catch (FormValidationException|CustomerGroupRefusedException $refusal) {
            $this->errorRenderer->setup($this->translator->trans('Customer group creation'), $refusal->getMessage(), null, $refusal);

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        } catch (\Throwable $failure) {
            $this->errorRenderer->setup($this->translator->trans('Customer group creation'), $this->translator->trans(self::GENERIC_FAILURE), null, $failure);

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::CREATE, \sprintf('Customer group %s created', $customerGroup->getCode()), (int) $customerGroup->getId());

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['customer_group_id' => $customerGroup->getId()]));
    }

    #[Route('/update/{customer_group_id}', name: 'update', methods: ['GET'], requirements: ['customer_group_id' => '\d+'])]
    public function updateView(int $customer_group_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $customerGroup = $this->customerGroups->find($customer_group_id);
        if (null === $customerGroup) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'customer_group' => $customerGroup->setLocale($locale),
            'customer_group_customers' => $this->customerGroups->countCustomers($customer_group_id),
            'categories_list_emptied' => 1 === $customerGroup->getRestrictsCategories() && [] === $this->customerGroups->categoryIdsOf($customer_group_id),
            'payment_modules_list_emptied' => 1 === $customerGroup->getRestrictsPaymentModules() && [] === $this->customerGroups->paymentModuleIdsOf($customer_group_id),
            'registration_available' => 1 !== $customerGroup->getIsDefault(),
            'registration_url' => $this->registrationUrl($customerGroup, $request),
            'form' => $this->modificationForm($customerGroup, $locale, $request->getLocale())->createView(),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save/{customer_group_id}', name: 'save', methods: ['POST'], requirements: ['customer_group_id' => '\d+'])]
    public function processUpdate(int $customer_group_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        // A group changes the discount and the prices of every sale unit at once.
        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::UPDATE)) {
            return $denied;
        }

        $customerGroup = $this->customerGroups->find($customer_group_id);
        if (null === $customerGroup) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $redirect = new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['customer_group_id' => $customer_group_id]));
        $form = $this->formFactory->createNamed(self::MODIFICATION_FORM, CustomerGroupType::class, null, [
            'include_id' => true,
            'choices_locale' => $request->getLocale(),
        ]);

        try {
            $data = $this->validator->validate($form)->getData();
            $this->writer->update(
                $customerGroup,
                (string) $data['code'],
                (string) $data['locale'],
                (string) $data['title'],
                $data['description'] ?? null,
                (float) ($data['discount'] ?? 0),
                (bool) ($data['display_taxed_price'] ?? false),
                (bool) ($data['is_default'] ?? false),
                $this->ids($data['payment_module_ids'] ?? []),
                $this->ids($data['category_ids'] ?? []),
            );
        } catch (FormValidationException|CustomerGroupRefusedException $refusal) {
            $this->errorRenderer->setup($this->translator->trans('Customer group update'), $refusal->getMessage(), null, $refusal);

            return $redirect;
        } catch (\Throwable $failure) {
            $this->errorRenderer->setup($this->translator->trans('Customer group update'), $this->translator->trans(self::GENERIC_FAILURE), null, $failure);

            return $redirect;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Customer group %s updated', $customerGroup->getCode()), $customer_group_id);

        return $redirect;
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $position = (int) ($request->query->get('position') ?? $request->request->get('position', 0));

        return $this->tokenWrite($request, AccessManager::UPDATE, self::REORDER_LABEL, function (CustomerGroup $customerGroup) use ($position): string {
            $this->writer->moveTo($customerGroup, $position);

            return \sprintf('Customer group %s moved to position %d', $customerGroup->getCode(), $position);
        });
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->tokenWrite($request, AccessManager::DELETE, self::DELETION_LABEL, function (CustomerGroup $customerGroup): string {
            $code = (string) $customerGroup->getCode();
            $this->writer->delete($customerGroup);

            return \sprintf('Customer group %s deleted', $code);
        });
    }

    #[Route('/registration/open', name: 'registration-open', methods: ['POST'])]
    public function openRegistration(Request $request): Response
    {
        return $this->tokenWrite($request, AccessManager::UPDATE, self::REGISTRATION_OPENING_LABEL, function (CustomerGroup $customerGroup): string {
            $this->writer->openRegistration($customerGroup);

            return \sprintf('Registration link of the customer group %s opened', $customerGroup->getCode());
        }, true);
    }

    #[Route('/registration/regenerate', name: 'registration-regenerate', methods: ['POST'])]
    public function regenerateRegistration(Request $request): Response
    {
        return $this->tokenWrite($request, AccessManager::UPDATE, self::REGISTRATION_OPENING_LABEL, function (CustomerGroup $customerGroup): string {
            $this->writer->regenerateRegistration($customerGroup);

            return \sprintf('Registration link of the customer group %s regenerated', $customerGroup->getCode());
        }, true);
    }

    #[Route('/registration/close', name: 'registration-close', methods: ['POST'])]
    public function closeRegistration(Request $request): Response
    {
        return $this->tokenWrite($request, AccessManager::UPDATE, self::REGISTRATION_OPENING_LABEL, function (CustomerGroup $customerGroup): string {
            $this->writer->closeRegistration($customerGroup);

            return \sprintf('Registration link of the customer group %s closed', $customerGroup->getCode());
        }, true);
    }

    #[Route('/lift-restriction', name: 'lift-restriction', methods: ['POST'])]
    public function liftRestriction(Request $request): Response
    {
        $categories = 'categories' === $request->request->get('list');

        return $this->tokenWrite($request, AccessManager::UPDATE, self::RESTRICTION_LIFT_LABEL, function (CustomerGroup $customerGroup) use ($categories): string {
            $this->writer->liftRestriction($customerGroup, $categories);

            return \sprintf('Restriction on the %s of the customer group %s lifted', $categories ? 'categories' : 'payment modules', $customerGroup->getCode());
        }, true);
    }

    /**
     * The sign-up form of the group on the storefront, as an absolute URL: the shop URL of the
     * configuration, or the host this screen is served from when none is set.
     */
    private function registrationUrl(CustomerGroup $customerGroup, Request $request): ?string
    {
        $token = $customerGroup->getRegistrationToken();
        if (null === $token || 1 === $customerGroup->getIsDefault()) {
            return null;
        }

        $shopUrl = rtrim((string) ConfigQuery::getConfiguredShopUrl(), '/');

        return ('' === $shopUrl ? $request->getSchemeAndHttpHost() : $shopUrl).self::REGISTRATION_PATH.$token;
    }

    /**
     * The road of the token-protected writes on one group of the list: right, token read
     * from the body, write, admin log, back to the list with the refusal as a message.
     *
     * @param callable(CustomerGroup): string $write      returns the line of the admin log
     * @param bool                            $backToEdit comes back to the screen of the group instead of the list
     */
    private function tokenWrite(Request $request, string $access, string $actionLabel, callable $write, bool $backToEdit = false): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], $access)) {
            return $denied;
        }

        // A group changes the discount and the prices of every sale unit at once.
        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, $access)) {
            return $denied;
        }

        $redirect = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        $customerGroupId = (int) ($request->query->get('customer_group_id') ?? $request->request->get('customer_group_id', 0));
        $customerGroup = $this->customerGroups->find($customerGroupId);
        if (null === $customerGroup) {
            return $redirect;
        }

        if ($backToEdit) {
            $redirect = new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['customer_group_id' => $customerGroupId]));
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
            $logLine = $write($customerGroup);
        } catch (CustomerGroupRefusedException $refusal) {
            $this->errorRenderer->setup($this->translator->trans($actionLabel), $refusal->getMessage(), null, $refusal);

            return $redirect;
        } catch (\Throwable $failure) {
            $this->errorRenderer->setup($this->translator->trans($actionLabel), $this->translator->trans(self::GENERIC_FAILURE), null, $failure);

            return $redirect;
        }

        $this->adminLogger->log(self::RESOURCE, $access, $logLine, $customerGroupId);

        return $redirect;
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $values): array
    {
        return array_values(array_unique(array_map('intval', (array) $values)));
    }

    private function creationForm(string $locale): FormInterface
    {
        return $this->formFactory->createNamed(self::CREATION_FORM, CustomerGroupType::class, [
            'locale' => $locale,
            'discount' => 0,
            'display_taxed_price' => true,
        ], ['choices_locale' => $locale, 'with_links' => false]);
    }

    private function modificationForm(CustomerGroup $customerGroup, string $editLocale, string $interfaceLocale): FormInterface
    {
        $id = (int) $customerGroup->getId();

        return $this->formFactory->createNamed(self::MODIFICATION_FORM, CustomerGroupType::class, [
            'id' => $id,
            'locale' => $editLocale,
            'code' => $customerGroup->getCode(),
            'title' => $customerGroup->getTitle(),
            'description' => $customerGroup->getDescription(),
            'discount' => (float) $customerGroup->getDiscount(),
            'display_taxed_price' => 1 === $customerGroup->getDisplayTaxedPrice(),
            'is_default' => 1 === $customerGroup->getIsDefault(),
            'payment_module_ids' => $this->customerGroups->paymentModuleIdsOf($id),
            'category_ids' => $this->customerGroups->categoryIdsOf($id),
        ], ['include_id' => true, 'choices_locale' => $interfaceLocale]);
    }
}
