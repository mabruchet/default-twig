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

use BackOfficeDefaultTwigBundle\Repository\PriceListItemRepository;
use BackOfficeDefaultTwigBundle\Repository\PriceListRepository;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListRefusedException;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListWriteGate;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListWriter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;

/**
 * Adds, changes and removes one line of a price list: the screen posts a token with every write.
 */
#[Route('/admin/pricing/price-lists/{price_list_id}/lines', name: 'admin.pricing.price_list.line_', requirements: ['price_list_id' => '\d+'], methods: ['POST'])]
final class PriceListLineController
{
    public const ADD_LABEL = 'Price list line addition';
    public const UPDATE_LABEL = 'Price list line update';
    public const DELETION_LABEL = 'Price list line deletion';

    public function __construct(
        private readonly PriceListRepository $priceLists,
        private readonly PriceListItemRepository $items,
        private readonly PriceListWriter $writer,
        private readonly PriceListWriteGate $gate,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/add', name: 'add')]
    public function add(int $price_list_id, Request $request): Response
    {
        return $this->write($price_list_id, $request, AccessManager::CREATE, self::ADD_LABEL, 'Line added.', function ($priceList) use ($request): string {
            $item = $this->writer->saveLine(
                $priceList,
                null,
                (string) $request->request->get('pse_ref', ''),
                (string) $request->request->get('min_quantity', ''),
                (string) $request->request->get('price', ''),
                (string) $request->request->get('discount_percent', ''),
            );

            return \sprintf('Line %d added to the price list %s', $item->getId(), $priceList->getCode());
        });
    }

    #[Route('/{item_id}/update', name: 'update', requirements: ['item_id' => '\d+'])]
    public function update(int $price_list_id, int $item_id, Request $request): Response
    {
        return $this->write($price_list_id, $request, AccessManager::UPDATE, self::UPDATE_LABEL, 'Line saved.', function ($priceList) use ($item_id, $request): string {
            $item = $this->items->findInList((int) $priceList->getId(), $item_id)
                ?? throw new PriceListRefusedException($this->translator->trans('This line does not exist.'));

            $this->writer->saveLine(
                $priceList,
                $item,
                '',
                (string) $request->request->get('min_quantity', ''),
                (string) $request->request->get('price', ''),
                (string) $request->request->get('discount_percent', ''),
            );

            return \sprintf('Line %d of the price list %s updated', $item_id, $priceList->getCode());
        });
    }

    #[Route('/{item_id}/delete', name: 'delete', requirements: ['item_id' => '\d+'])]
    public function delete(int $price_list_id, int $item_id, Request $request): Response
    {
        return $this->write($price_list_id, $request, AccessManager::DELETE, self::DELETION_LABEL, 'Line deleted.', function ($priceList) use ($item_id): string {
            $item = $this->items->findInList((int) $priceList->getId(), $item_id)
                ?? throw new PriceListRefusedException($this->translator->trans('This line does not exist.'));

            $this->writer->deleteLine($item);

            return \sprintf('Line %d of the price list %s deleted', $item_id, $priceList->getCode());
        });
    }

    /**
     * @param callable(\Thelia\Model\PriceList): string $write
     */
    private function write(int $priceListId, Request $request, string $access, string $label, string $success, callable $write): Response
    {
        $back = $this->urls->generate('admin.pricing.price_list.view', array_filter([
            'price_list_id' => $priceListId,
            'tab' => 'lines',
            'q' => trim((string) $request->request->get('q', '')),
            'page' => max(1, (int) $request->request->get('page', 1)),
        ], static fn (mixed $value): bool => '' !== $value && 1 !== $value));

        return $this->gate->run($request, $access, $label, $back, $priceListId, $success, function () use ($priceListId, $write): string {
            $priceList = $this->priceLists->find($priceListId)
                ?? throw new PriceListRefusedException($this->translator->trans('This price list does not exist.'));

            return $write($priceList);
        });
    }
}
