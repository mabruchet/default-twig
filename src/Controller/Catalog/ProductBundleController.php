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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use BackOfficeDefaultTwigBundle\Service\Bundle\BundleItemReorderPlanner;
use BackOfficeDefaultTwigBundle\Service\Bundle\ProductBundleContext;
use BackOfficeDefaultTwigBundle\Service\Product\CombinationLabeler;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Product\ProductBundleItemEvent;
use Thelia\Core\Event\Product\ProductBundleItemPositionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ProductBundleItemQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * The composition of a product bundle, edited from the Composition tab of the product sheet.
 *
 * Every change is an event of the core, which validates the composition (a component is
 * never a bundle, a bundle is never its own component, the quantities and their bounds hold).
 * A refusal comes back as the message of the error and the sheet is shown again.
 *
 * A bundle is a global piece of the catalogue and its composition sets its public price: an
 * administrator restricted to some sale units changes none of it, as for the prices of the
 * combinations.
 *
 * On a core that predates the bundles none of these routes answers.
 */
#[Route('/admin/products/bundle', name: 'admin.products.bundle.')]
final class ProductBundleController
{
    private const RESOURCE = AdminResources::PRODUCT;
    private const EDIT_ROUTE = 'admin.products.update';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly BundleCore $core,
        private readonly BundleItemReorderPlanner $planner,
        private readonly CombinationLabeler $labeler,
        private readonly RestrictedAdminGuard $restricted,
        private readonly EventDispatcherInterface $events,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/items/add', name: 'items.add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->assertCoreHasBundles();
        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::UPDATE)) {
            return $denied;
        }

        $productId = (int) $request->request->get('product_id', 0);
        if (null === ProductQuery::create()->findPk($productId)) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: ProductBundleItemEvent::add(
                $productId,
                (int) $request->request->get('product_sale_elements_id', 0),
                $this->quantity($request, 'quantity'),
                $this->optionalQuantity($request, 'min_quantity'),
                $this->optionalQuantity($request, 'max_quantity'),
                $this->isRequired($request),
            ),
            eventName: TheliaEvents::PRODUCT_BUNDLE_ITEM_ADD,
            actionLabel: 'Product bundle component added',
            successRoute: self::EDIT_ROUTE,
            successParameters: $this->tabParameters($productId),
            describeForLog: static fn (ProductBundleItemEvent $event): array => ['Component added to a product bundle', $event->getBundleProductId()],
        );
    }

    #[Route('/items/update', name: 'items.update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $this->assertCoreHasBundles();
        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::UPDATE)) {
            return $denied;
        }

        $item = ProductBundleItemQuery::create()->findPk((int) $request->request->get('item_id', 0));
        if (null === $item) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $productId = (int) $item->getBundleProductId();

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: ProductBundleItemEvent::update(
                (int) $item->getId(),
                // The component itself is not changed from here: remove the line and add another.
                (int) $item->getProductSaleElementsId(),
                $this->quantity($request, 'quantity'),
                $this->optionalQuantity($request, 'min_quantity'),
                $this->optionalQuantity($request, 'max_quantity'),
                $this->isRequired($request),
            ),
            eventName: TheliaEvents::PRODUCT_BUNDLE_ITEM_UPDATE,
            actionLabel: 'Product bundle component update',
            successRoute: self::EDIT_ROUTE,
            successParameters: $this->tabParameters($productId),
            describeForLog: static fn (): array => ['Component of a product bundle modified', $productId],
        );
    }

    #[Route('/items/delete', name: 'items.delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $this->assertCoreHasBundles();
        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::UPDATE)) {
            return $denied;
        }

        $item = ProductBundleItemQuery::create()->findPk((int) $request->request->get('item_id', 0));
        if (null === $item) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $productId = (int) $item->getBundleProductId();

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: ProductBundleItemEvent::delete((int) $item->getId()),
            eventName: TheliaEvents::PRODUCT_BUNDLE_ITEM_DELETE,
            actionLabel: 'Product bundle component removed',
            successRoute: self::EDIT_ROUTE,
            successParameters: $this->tabParameters($productId),
            describeForLog: static fn (): array => ['Component removed from a product bundle', $productId],
        );
    }

    /**
     * Sent by the drag and drop of the table: the moved line and the position of the line it was
     * dropped on, in the query (the token is in the body). The mode the other lists send does not
     * exist here, a position is always an absolute one.
     */
    #[Route('/items/position', name: 'items.position', methods: ['POST'])]
    public function position(Request $request): Response
    {
        $this->assertCoreHasBundles();
        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, AccessManager::UPDATE)) {
            return $denied;
        }

        $item = ProductBundleItemQuery::create()->findPk((int) ($request->query->get('item_id') ?? $request->request->get('item_id', 0)));
        if (null === $item) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $productId = (int) $item->getBundleProductId();
        $positionsById = [];
        foreach (ProductBundleItemQuery::create()->filterByBundleProductId($productId)->orderByPosition()->orderById()->find() as $sibling) {
            $positionsById[(int) $sibling->getId()] = (int) $sibling->getPosition();
        }

        $plan = $this->planner->plan(
            $positionsById,
            (int) $item->getId(),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );
        $movedPosition = $plan[(int) $item->getId()] ?? (int) $item->getPosition();
        unset($plan[(int) $item->getId()]);

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ProductBundleItemPositionEvent((int) $item->getId(), $movedPosition),
            eventName: TheliaEvents::PRODUCT_BUNDLE_ITEM_UPDATE_POSITION,
            actionLabel: 'Product bundle component reorder',
            successRoute: self::EDIT_ROUTE,
            successParameters: $this->tabParameters($productId),
            afterDispatch: function () use ($plan): void {
                foreach ($plan as $itemId => $position) {
                    $this->events->dispatch(new ProductBundleItemPositionEvent($itemId, $position), TheliaEvents::PRODUCT_BUNDLE_ITEM_UPDATE_POSITION);
                }
            },
        );
    }

    /**
     * The combinations of a product the picker offers once the product is chosen: none for a
     * bundle or for the bundle being edited, and none that is already one of its components.
     */
    #[Route('/{bundleProductId}/component/{productId}/combinations.json', name: 'component.combinations', methods: ['GET'], requirements: ['bundleProductId' => '\d+', 'productId' => '\d+'])]
    public function combinations(int $bundleProductId, int $productId, Request $request): JsonResponse
    {
        $this->assertCoreHasBundles();

        if ($this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        if ($productId === $bundleProductId || !ProductQuery::create()->filterById($productId)->filterByIsBundle(0)->exists()) {
            return new JsonResponse([]);
        }

        $alreadyComponents = [];
        foreach (ProductBundleItemQuery::create()->filterByBundleProductId($bundleProductId)->find() as $item) {
            $alreadyComponents[(int) $item->getProductSaleElementsId()] = true;
        }

        $saleElementsIds = [];
        foreach (ProductSaleElementsQuery::create()->filterByProductId($productId)->orderByPosition()->orderById()->find() as $saleElements) {
            if (!isset($alreadyComponents[(int) $saleElements->getId()])) {
                $saleElementsIds[] = (int) $saleElements->getId();
            }
        }

        $labels = $this->labeler->labelsFor($saleElementsIds, $request->getLocale());

        return new JsonResponse(array_map(
            static fn (int $id): array => ['id' => $id, 'title' => $labels[$id] ?? ''],
            $saleElementsIds,
        ));
    }

    private function assertCoreHasBundles(): void
    {
        if (!$this->core->isAvailable()) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * @return array{product_id: int, current_tab: string}
     */
    private function tabParameters(int $productId): array
    {
        return ['product_id' => $productId, 'current_tab' => ProductBundleContext::TAB];
    }

    private function quantity(Request $request, string $key): string
    {
        return $this->optionalQuantity($request, $key) ?? '1';
    }

    private function optionalQuantity(Request $request, string $key): ?string
    {
        $value = str_replace(',', '.', trim((string) $request->request->get($key, '')));

        return '' === $value ? null : $value;
    }

    private function isRequired(Request $request): bool
    {
        return '1' === (string) $request->request->get('required', '0');
    }
}
