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

namespace BackOfficeDefaultTwigBundle\Controller\Catalog;

use BackOfficeDefaultTwigBundle\Repository\CurrencyRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupPriceRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Product\GroupPricesWriter;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Tools\TokenProvider;

/**
 * Saves, from the combinations tab of a product, the prices the customer groups keep of
 * their own for its combinations. The grid prices the catalog for the groups: it is a
 * product write and a customer group write at once, and both rights are required.
 */
final class ProductGroupPricesController
{
    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly CurrencyRepository $currencies,
        private readonly CustomerGroupPriceRepository $prices,
        private readonly RestrictedAdminGuard $restricted,
        private readonly GroupPricesWriter $writer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/product/group-prices/save', name: 'admin.product.group-prices.save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check([AdminResources::PRODUCT, AdminResources::CUSTOMER_GROUP], [], AccessManager::UPDATE)) {
            return $denied;
        }

        // The grid prices every sale unit at once: a restricted administrator does not write it.
        if ($denied = $this->restricted->denyIfRestricted(AdminResources::PRODUCT, AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $request->request;
        $productId = (int) $form->get('product_id', 0);
        $currency = $this->currencies->find((int) $form->get('currency_id', 0));

        if (null === $currency || $productId <= 0) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $redirect = new RedirectResponse($this->urls->generate('admin.products.update', [
            'product_id' => $productId,
            'current_tab' => 'pse',
            'combinations_currency_id' => $currency->getId(),
        ]));

        try {
            $this->tokens->checkToken((string) $form->get('_token', ''));
        } catch (TokenAuthenticationException) {
            $this->flash($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $redirect;
        }

        // Only the combinations of this product: an id posted for another one is not written.
        $refs = $this->prices->combinationRefsOfProduct($productId);

        $posted = $form->all('group_price');
        $cells = [];
        $foreign = false;
        foreach ($posted as $pseId => $groups) {
            if (!isset($refs[(int) $pseId])) {
                $foreign = true;

                continue;
            }

            if (\is_array($groups)) {
                $cells[(int) $pseId] = array_filter($groups, \is_array(...));
            }
        }

        $result = $this->writer->save((int) $currency->getId(), $cells);

        if ($result['changed'] > 0) {
            $this->adminLogger->log(
                AdminResources::PRODUCT,
                AccessManager::UPDATE,
                \sprintf('Customer group prices of product %d saved', $productId),
                $productId,
            );
        }

        if ($foreign) {
            $this->flash($request, 'warning', $this->translator->trans('Not saved: some combinations do not belong to this product.'));
        }

        if ([] !== $result['refused']) {
            $this->flash($request, 'warning', $this->translator->trans(
                'Not saved for %refs%: amounts must be numbers.',
                ['%refs%' => implode(', ', array_map(static fn (int $pseId): string => $refs[$pseId], $result['refused']))],
            ));
        }

        if (!$foreign && [] === $result['refused']) {
            $this->flash($request, 'success', $this->translator->trans('The customer group prices have been saved.'));
        }

        return $redirect;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof Session) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
