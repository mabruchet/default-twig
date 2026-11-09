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

namespace BackOfficeDefaultTwigBundle\Service\PriceList;

use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;

/**
 * The rows of the list screen of the price lists, as the data table reads them.
 */
final readonly class PriceListListPresenter
{
    private const DATE_FORMAT = 'Y-m-d H:i';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private RestrictedAdminGuard $restricted,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $lists the rows of PriceListRepository::search()
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $lists): array
    {
        $rows = [];
        foreach ($lists as $list) {
            $rows[] = $this->row($list);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $list
     *
     * @return array<string, mixed>
     */
    private function row(array $list): array
    {
        $id = (int) $list['id'];
        $startsAt = $list['startsAt'] instanceof \DateTimeInterface ? $list['startsAt']->format(self::DATE_FORMAT) : null;
        $endsAt = $list['endsAt'] instanceof \DateTimeInterface ? $list['endsAt']->format(self::DATE_FORMAT) : null;

        $actions = [
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit'),
                href: $this->urls->generate('admin.pricing.price_list.view', ['price_list_id' => $id]),
                grantedAttribute: AccessManager::VIEW,
                grantedSubject: AdminResources::PRICING,
            ),
            new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete'),
                modalTarget: '#price-list-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: AdminResources::PRICING,
                dataAttributes: ['price-list-id' => $id, 'price-list-label' => (string) $list['code']],
            ),
        ];

        return [
            'id' => $id,
            'code' => (string) $list['code'],
            'title' => (string) $list['title'],
            'currency' => (string) $list['currency'],
            'priority' => (int) $list['priority'],
            'exclusive' => $list['exclusive'] ? $this->translator->trans('Yes') : $this->translator->trans('No'),
            'period' => $this->period($startsAt, $endsAt),
            'active' => $list['active'] ? 'active' : 'inactive',
            'active_label' => $list['active'] ? $this->translator->trans('Active') : $this->translator->trans('Inactive'),
            'lines' => (int) $list['lines'],
            '_actions' => $this->restricted->isRestricted() ? \array_slice($actions, 0, 1) : $actions,
        ];
    }

    private function period(?string $startsAt, ?string $endsAt): string
    {
        if (null === $startsAt && null === $endsAt) {
            return $this->translator->trans('Always');
        }

        return trim(($startsAt ?? '...').' - '.($endsAt ?? '...'));
    }
}
