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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Product\ProductPriceDisplayWriter;
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
use Thelia\Domain\Pricing\PriceVisibility\PriceDisplayMode;
use Thelia\Model\ProductQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The display of the prices of one product when it differs from the shop: "like the shop" (the
 * column is empty) or one of the three modes. Written through the setter of the one column,
 * never through the product update event, which writes every field the form does not send
 * as empty.
 */
final class ProductPriceDisplayController
{
    private const RESOURCE = AdminResources::PRODUCT;

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flashes,
        private readonly ProductPriceDisplayWriter $writer,
        private readonly RestrictedAdminGuard $restricted,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/products/price-display-mode/panel', name: 'admin.product.price-display-mode.panel', methods: ['GET'])]
    public function panel(Request $request): Response
    {
        $productId = (int) ($request->attributes->get('product_id') ?? $request->query->get('product_id', 0));
        $product = ProductQuery::create()->findPk($productId);

        if (null === $product) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $override = PriceDisplayMode::tryFrom((string) $product->getPriceDisplayMode());

        return new Response($this->twig->render('@BackOfficeDefaultTwig/catalog/product/_price_display_mode.html.twig', [
            'product_id' => $productId,
            'override' => $override?->value,
            'shop_mode' => PriceDisplayMode::fromShopConfiguration()->value,
            'modes' => array_map(static fn (PriceDisplayMode $mode): string => $mode->value, PriceDisplayMode::cases()),
            'can_edit' => !$this->restricted->isRestricted() && $this->access->can(self::RESOURCE, AccessManager::UPDATE),
        ]));
    }

    #[Route('/admin/product/price-display-mode/save', name: 'admin.product.price-display-mode.save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        // The display holds for every sale unit of the product: a restricted administrator does not write it.
        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $request->request;
        $productId = (int) $form->get('product_id', 0);
        $product = ProductQuery::create()->findPk($productId);

        if (null === $product) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $redirect = new RedirectResponse($this->urls->generate('admin.products.update', ['product_id' => $productId, 'current_tab' => 'pse']));

        try {
            $this->tokens->checkToken((string) $form->get('_token', ''));
        } catch (TokenAuthenticationException) {
            $this->flashes->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $redirect;
        }

        $posted = (string) $form->get('price_display_mode', '');
        $mode = '' === $posted ? null : PriceDisplayMode::tryFrom($posted);

        if ('' !== $posted && null === $mode) {
            $this->flashes->add($request, 'danger', $this->translator->trans('This display of the prices is not known.'));

            return $redirect;
        }

        $this->writer->save($product, $mode);

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf('Price display of product %d set to %s', $productId, $mode?->value ?? 'the shop default'),
            $productId,
        );
        $this->flashes->add($request, 'success', $this->translator->trans('The display of the prices has been saved.'));

        return $redirect;
    }
}
