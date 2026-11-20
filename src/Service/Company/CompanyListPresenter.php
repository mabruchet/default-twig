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

use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;

final readonly class CompanyListPresenter
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $companies the rows of CompanyRepository::search()
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $companies): array
    {
        $rows = [];
        foreach ($companies as $company) {
            $id = (int) $company['id'];
            $rows[] = [
                'id' => $id,
                'name' => (string) $company['name'],
                'siret' => '' === $company['siret'] ? '-' : (string) $company['siret'],
                'buyers' => (int) $company['buyers'],
                'orders' => (int) $company['orders'],
                'active' => $company['active'] ? 'active' : 'suspended',
                'active_label' => $company['active'] ? $this->translator->trans('Active') : $this->translator->trans('Suspended'),
                '_actions' => [
                    new RowAction(
                        kind: 'edit',
                        label: $this->translator->trans('Edit'),
                        href: $this->urls->generate('admin.company.view', ['company_id' => $id]),
                        grantedAttribute: AccessManager::VIEW,
                        grantedSubject: AdminResources::COMPANY,
                    ),
                    new RowAction(
                        kind: 'delete',
                        label: $this->translator->trans('Delete'),
                        modalTarget: '#company-delete-modal',
                        grantedAttribute: AccessManager::DELETE,
                        grantedSubject: AdminResources::COMPANY,
                        dataAttributes: ['company-id' => $id, 'company-label' => (string) $company['name']],
                    ),
                ],
            ];
        }

        return $rows;
    }
}
