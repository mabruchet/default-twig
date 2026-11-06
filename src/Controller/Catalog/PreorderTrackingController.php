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
use BackOfficeDefaultTwigBundle\Service\Product\PreorderTrackingPresenter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Twig\Environment;

/**
 * What the shop sold below zero stock and still has to order from its suppliers.
 */
final class PreorderTrackingController
{
    public const ROUTE = 'admin.preorders.list';

    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly PreorderTrackingPresenter $presenter,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/admin/preorders', name: self::ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::PRODUCT, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/catalog/preorders.html.twig', $this->presenter->page(
            $request->getLocale(),
            max(1, (int) $request->query->get('page', 1)),
            self::PAGE_SIZE,
        )));
    }
}
