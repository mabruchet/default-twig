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

namespace BackOfficeDefaultTwigBundle\Service\CustomerGroup;

use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\CustomerGroup;

final readonly class CustomerGroupListPresenter
{
    public const SORTABLE_FIELDS = ['id', 'code', 'title', 'discount', 'position'];

    public function __construct(
        private CustomerGroupRepository $customerGroups,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private RestrictedAdminGuard $restricted,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $locale, string $sortField, string $sortDirection): array
    {
        $rows = [];
        foreach ($this->customerGroups->findSorted($locale, $sortField, $sortDirection) as $customerGroup) {
            $rows[] = $this->row($customerGroup, $locale);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CustomerGroup $customerGroup, string $locale): array
    {
        $id = (int) $customerGroup->getId();
        $title = CustomerGroupRepository::titleOf($customerGroup, $locale);
        $isDefault = 1 === $customerGroup->getIsDefault();

        $actions = [
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit'),
                href: $this->urls->generate('admin.configuration.customer-groups.update', ['customer_group_id' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: AdminResources::CUSTOMER_GROUP,
            ),
        ];

        // The default group cannot be deleted: the action is not offered for it.
        if (!$isDefault) {
            $actions[] = new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete'),
                modalTarget: '#customer-group-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: AdminResources::CUSTOMER_GROUP,
                dataAttributes: ['customer-group-id' => $id, 'customer-group-label' => $title],
            );
        }

        return [
            'id' => $id,
            'code' => (string) $customerGroup->getCode(),
            'title' => $title,
            'discount' => rtrim(rtrim(number_format((float) $customerGroup->getDiscount(), 6, '.', ''), '0'), '.').' %',
            'display' => 1 === $customerGroup->getDisplayTaxedPrice() ? $this->translator->trans('Taxes included') : $this->translator->trans('Taxes excluded'),
            'default' => $isDefault ? $this->translator->trans('Default') : '',
            'position' => (int) $customerGroup->getPosition(),
            // An administrator restricted to some sale units only reads the groups.
            '_actions' => $this->restricted->isRestricted() ? [] : $actions,
        ];
    }
}
