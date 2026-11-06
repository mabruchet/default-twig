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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use BackOfficeDefaultTwigBundle\Repository\PreorderTrackingRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The rows of the preorder follow-up screen: what the repository reads, shaped for the table.
 */
final readonly class PreorderTrackingPresenter
{
    private const PRODUCT_ROUTE = 'admin.products.update';
    private const COMBINATIONS_TAB = 'pse';

    public function __construct(
        private PreorderTrackingRepository $preorders,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, current_page: int}
     */
    public function page(string $locale, int $page, int $perPage): array
    {
        $found = $this->preorders->findPaginated($locale, $page, $perPage);
        $rows = [];

        foreach ($found['rows'] as $row) {
            $rows[] = [
                'id' => $row['id'],
                'ref' => $row['ref'],
                'product_html' => \sprintf(
                    '<a href="%s" class="text-decoration-none" data-testid="preorder-product-link">%s</a>',
                    htmlspecialchars($this->urls->generate(self::PRODUCT_ROUTE, ['product_id' => $row['product_id'], 'current_tab' => self::COMBINATIONS_TAB]), \ENT_QUOTES | \ENT_HTML5),
                    htmlspecialchars($row['product_title'], \ENT_QUOTES | \ENT_HTML5),
                ),
                'to_order' => self::quantity($row['to_order']),
                'open_orders' => (string) $row['open_orders'],
                'restock_date' => $row['restock_date'] ?? '-',
            ];
        }

        return [
            'rows' => $rows,
            'total' => $found['total'],
            'pages' => $found['lastPage'],
            'current_page' => min(max(1, $page), $found['lastPage']),
        ];
    }

    private static function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }
}
