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

namespace BackOfficeDefaultTwigBundle\Service\CatalogRestriction;

use BackOfficeDefaultTwigBundle\Repository\CatalogRestrictionRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\CatalogRestriction;

/**
 * The rows of the assortment list: who the rule binds, what it is about, which way it goes.
 * The names are read once for the page, whatever the number of rows.
 */
final readonly class CatalogRestrictionListPresenter
{
    public function __construct(
        private CatalogRestrictionRepository $restrictions,
        private CustomerGroupRepository $customerGroups,
        private RestrictedAdminGuard $restricted,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<CatalogRestriction> $rules
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $rules, string $locale, bool $namesCustomers = true): array
    {
        $groups = array_column($this->customerGroups->findChoices($locale), 'label', 'id');
        // Who may not see the customers reads their number, never their name or their e-mail.
        $customers = $namesCustomers ? $this->restrictions->customerLabels($this->ids($rules, static fn (CatalogRestriction $rule): ?int => $rule->getCustomerId())) : [];
        $categories = $this->restrictions->categoryTitles($this->ids($rules, static fn (CatalogRestriction $rule): ?int => $rule->getCategoryId()), $locale);
        $brands = $this->restrictions->brandTitles($this->ids($rules, static fn (CatalogRestriction $rule): ?int => $rule->getBrandId()), $locale);
        $products = $this->restrictions->productLabels($this->ids($rules, static fn (CatalogRestriction $rule): ?int => $rule->getProductId()), $locale);

        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = $this->row($rule, $groups, $customers, $categories, $brands, $products);
        }

        return $rows;
    }

    /**
     * @param array<int|string, string> $groups
     * @param array<int, string>        $customers
     * @param array<int, string>        $categories
     * @param array<int, string>        $brands
     * @param array<int, string>        $products
     *
     * @return array<string, mixed>
     */
    private function row(CatalogRestriction $rule, array $groups, array $customers, array $categories, array $brands, array $products): array
    {
        $id = (int) $rule->getId();

        if (null !== $rule->getCustomerId()) {
            $scope = $this->translator->trans('Customer').': '.($customers[(int) $rule->getCustomerId()] ?? '#'.$rule->getCustomerId());
        } else {
            $scope = $this->translator->trans('Group').': '.($groups[(int) $rule->getCustomerGroupId()] ?? '#'.$rule->getCustomerGroupId());
        }

        if (null !== $rule->getCategoryId()) {
            $target = $this->translator->trans('Category').': '.($categories[(int) $rule->getCategoryId()] ?? '#'.$rule->getCategoryId());
        } elseif (null !== $rule->getBrandId()) {
            $target = $this->translator->trans('Brand').': '.($brands[(int) $rule->getBrandId()] ?? '#'.$rule->getBrandId());
        } else {
            $target = $this->translator->trans('Product').': '.($products[(int) $rule->getProductId()] ?? '#'.$rule->getProductId());
        }

        $allow = CatalogRestrictionDraft::MODE_ALLOW === $rule->getMode();

        return [
            'id' => $id,
            'scope' => $scope,
            'target' => $target,
            'mode' => $allow ? 'allow' : 'deny',
            'mode_label' => $allow ? $this->translator->trans('Allow') : $this->translator->trans('Deny'),
            '_actions' => $this->restricted->isRestricted() ? [] : [
                new RowAction(
                    kind: 'delete',
                    label: $this->translator->trans('Delete'),
                    modalTarget: '#catalog-restriction-delete-modal',
                    grantedAttribute: AccessManager::DELETE,
                    grantedSubject: AdminResources::PRICING,
                    dataAttributes: ['restriction-id' => $id],
                ),
            ],
        ];
    }

    /**
     * @param list<CatalogRestriction>           $rules
     * @param callable(CatalogRestriction): ?int $read
     *
     * @return list<int>
     */
    private function ids(array $rules, callable $read): array
    {
        return array_values(array_unique(array_filter(array_map($read, $rules), static fn (?int $id): bool => null !== $id)));
    }
}
