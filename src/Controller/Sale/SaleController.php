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

namespace BackOfficeDefaultTwigBundle\Controller\Sale;

use BackOfficeDefaultTwigBundle\Form\Sale\SaleCreateType;
use BackOfficeDefaultTwigBundle\Form\Sale\SaleType;
use BackOfficeDefaultTwigBundle\Repository\SaleRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\Service\Sale\SaleEditContextBuilder;
use BackOfficeDefaultTwigBundle\Service\Sale\SaleEventFactory;
use BackOfficeDefaultTwigBundle\Service\Sale\SaleListPresenter;
use BackOfficeDefaultTwigBundle\Service\Sale\SaleProductAttributesProvider;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\Sale\SaleActiveStatusCheckEvent;
use Thelia\Core\Event\Sale\SaleClearStatusEvent;
use Thelia\Core\Event\Sale\SaleCreateEvent;
use Thelia\Core\Event\Sale\SaleDeleteEvent;
use Thelia\Core\Event\Sale\SaleToggleActivityEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\LangQuery;
use Twig\Environment;

#[Route('/admin', name: 'admin.sale.')]
final class SaleController
{
    private const RESOURCE = AdminResources::SALES;
    private const LIST_ROUTE = 'admin.sale.default';
    private const EDIT_ROUTE = 'admin.sale.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/sale/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/sale/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly SaleRepository $sales,
        private readonly SaleListPresenter $listPresenter,
        private readonly SaleEditContextBuilder $editContextBuilder,
        private readonly SaleEventFactory $eventFactory,
        private readonly SaleProductAttributesProvider $productAttributesProvider,
        private readonly EditLocaleResolver $editLocale,
    ) {
    }

    #[Route('/sales', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['id', 'title', 'label', 'start_date', 'end_date', 'active'], 'start_date', 'desc');

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->listPresenter->build($locale, $sort->field, $sort->direction),
            'global_actions' => $this->listPresenter->globalActions(),
            'create_form' => $this->buildCreateForm($locale)->createView(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/sale/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $locale = $request->getLocale();

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $this->buildCreateForm($locale),
            eventName: TheliaEvents::SALE_CREATE,
            eventFactory: fn (FormInterface $validated): SaleCreateEvent => $this->eventFactory->createEvent((array) $validated->getData(), $locale),
            actionLabel: 'Sale creation',
            successRoute: self::EDIT_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
            successParametersResolver: static fn (SaleCreateEvent $event): array => ['sale_id' => (int) $event->getSale()?->getId()],
            describeForLog: static fn (SaleCreateEvent $event): array => ['Sale created', $event->hasSale() ? (int) $event->getSale()?->getId() : null],
        );
    }

    #[Route('/sale/update/{sale_id}', name: 'update', methods: ['GET'], requirements: ['sale_id' => '\d+'])]
    public function updateView(int $sale_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $sale = $this->sales->findById($sale_id);
        if ($sale === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $sale->setLocale($locale);
        $context = $this->editContextBuilder->build($sale, $locale);
        $form = $this->formFactory->createNamed('thelia_sale', SaleType::class, $context['form_data'], [
            'can_target_customers' => $context['can_target_customers'],
        ]);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, array_merge(
            $context,
            ['sale' => $sale, 'form' => $form->createView(), 'edit_language_id' => (int) $editLang->getId()],
        )));
    }

    #[Route('/sale/save/{sale_id}', name: 'save', methods: ['POST'], requirements: ['sale_id' => '\d+'])]
    public function save(int $sale_id, Request $request): Response
    {
        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $this->formFactory->createNamed('thelia_sale', SaleType::class, null, [
                'can_target_customers' => $this->editContextBuilder->canTargetCustomers(),
            ]),
            eventName: TheliaEvents::SALE_UPDATE,
            eventFactory: fn (FormInterface $validated) => $this->eventFactory->updateEvent($sale_id, (array) $validated->getData(), $request, $this->defaultLocale()),
            actionLabel: 'Sale update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['sale_id' => $sale_id],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['sale_id' => $sale_id])),
        );
    }

    #[Route('/sale/delete', name: 'delete', methods: ['POST', 'GET'])]
    public function delete(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new SaleDeleteEvent((int) ($request->query->get('sale_id') ?? $request->request->get('sale_id', 0))),
            eventName: TheliaEvents::SALE_DELETE,
            actionLabel: 'Sale deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/sales/toggle/{sale_id}', name: 'toggle', methods: ['GET', 'POST'], requirements: ['sale_id' => '\d+'])]
    public function toggle(int $sale_id, Request $request): Response
    {
        $sale = $this->sales->findById($sale_id);
        if ($sale === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new SaleToggleActivityEvent($sale),
            eventName: TheliaEvents::SALE_TOGGLE_ACTIVITY,
            actionLabel: 'Sale activity toggled',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/sales/reset-status', name: 'reset-status', methods: ['GET', 'POST'])]
    public function resetStatus(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new SaleClearStatusEvent(),
            eventName: TheliaEvents::SALE_CLEAR_SALE_STATUS,
            actionLabel: 'Sales status reset',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/sales/check-activation', name: 'check-activation', methods: ['GET', 'POST'])]
    public function checkActivation(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new SaleActiveStatusCheckEvent(),
            eventName: TheliaEvents::CHECK_SALE_ACTIVATION_EVENT,
            actionLabel: 'Sales activation checked',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/sales/products-by-categories.{_format}', name: 'products-by-categories', methods: ['GET'], defaults: ['_format' => 'json'])]
    public function productsByCategories(Request $request): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        $categoryIds = array_values(array_filter(array_map('intval', explode(',', (string) $request->query->get('categories', '')))));

        return new JsonResponse(['products' => $this->sales->findProductsInCategories($categoryIds, $this->defaultLocale())]);
    }

    #[Route('/sales/product-attributes/{product_id}', name: 'product-attributes', methods: ['GET'], requirements: ['product_id' => '\d+'])]
    public function productAttributes(int $product_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $selected = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $request->query->get('selected', '')),
        )));

        $groups = $this->productAttributesProvider->optionsForProduct($product_id, $this->defaultLocale());

        return new Response($this->twig->render('@BackOfficeDefaultTwig/sale/_product_attributes_modal.html.twig', [
            'product_id' => $product_id,
            'groups' => $groups,
            'selected' => $selected,
        ]));
    }

    private function buildCreateForm(string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_sale_creation', SaleCreateType::class, [
            'locale' => $locale,
        ]);
    }

    private function defaultLocale(): string
    {
        return LangQuery::create()->findOneByByDefault(1)?->getLocale() ?? 'en_US';
    }
}
