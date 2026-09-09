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

namespace BackOfficeDefaultTwigBundle\Service\Sale;

use BackOfficeDefaultTwigBundle\Repository\SaleRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\Sale;
use Thelia\Tools\TokenProvider;

final readonly class SaleListPresenter
{
    public function __construct(
        private SaleRepository $sales,
        private UrlGeneratorInterface $urls,
        private TokenProvider $tokens,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     label: string,
     *     start_date: ?string,
     *     end_date: ?string,
     *     active: bool,
     *     reserved: bool,
     *     targeted_customers_count: int,
     *     offset_type_label: string,
     *     products_count: int,
     *     edit_url: string,
     *     toggle_url: string
     * }>
     */
    public function build(string $locale, string $sortField = 'start_date', string $sortDirection = 'desc'): array
    {
        $rows = [];
        foreach ($this->sales->findAllSorted($sortField, $sortDirection, $locale) as $sale) {
            $sale->setLocale($locale);
            $id = (int) $sale->getId();
            $rows[] = [
                'id' => $id,
                'title' => (string) $sale->getTitle(),
                'label' => (string) $sale->getSaleLabel(),
                'start_date' => $sale->getStartDate('Y-m-d'),
                'end_date' => $sale->getEndDate('Y-m-d'),
                'active' => (bool) $sale->getActive(),
                // Both read off the row already loaded above: no extra query per line.
                // A reserved sale down to zero customers is invisible to the whole shop,
                // which is what the list has to be able to show.
                'reserved' => $sale->isReserved(),
                'targeted_customers_count' => (int) $sale->getVirtualColumn(SaleRepository::TARGETED_CUSTOMERS_COUNT),
                'offset_type_label' => $this->offsetTypeLabel((int) $sale->getPriceOffsetType()),
                'products_count' => $sale->getSaleProductList()->count(),
                'edit_url' => $this->urls->generate('admin.sale.update', ['sale_id' => $id]),
                'toggle_url' => $this->tokenizedUrl('admin.sale.toggle', ['sale_id' => $id]),
            ];
        }

        return $rows;
    }

    /**
     * @return array{reset_url: string, check_url: string, delete_url: string, delete_token: string}
     */
    public function globalActions(): array
    {
        return [
            'reset_url' => $this->tokenizedUrl('admin.sale.reset-status', []),
            'check_url' => $this->tokenizedUrl('admin.sale.check-activation', []),
            'delete_url' => $this->urls->generate('admin.sale.delete'),
            'delete_token' => $this->tokens->assignToken(),
        ];
    }

    private function offsetTypeLabel(int $type): string
    {
        return $type === Sale::OFFSET_TYPE_AMOUNT
            ? $this->translator->trans('Constant amount')
            : $this->translator->trans('Percentage');
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function tokenizedUrl(string $route, array $parameters): string
    {
        $url = $this->urls->generate($route, $parameters);

        return $url.(str_contains($url, '?') ? '&' : '?').'_token='.$this->tokens->assignToken();
    }
}
