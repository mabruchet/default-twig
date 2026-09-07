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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Form\Configuration\ConsentType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Consent\ConsentCreateEvent;
use Thelia\Core\Event\Consent\ConsentDeleteEvent;
use Thelia\Core\Event\Consent\ConsentToggleActiveEvent;
use Thelia\Core\Event\Consent\ConsentUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Consent;
use Thelia\Model\ConsentQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\ContentI18nTableMap;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/consent', name: 'admin.consent.')]
final class ConsentController
{
    private const RESOURCE = AdminResources::CONSENT;
    private const LIST_ROUTE = 'admin.consent.default';
    private const EDIT_ROUTE = 'admin.consent.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/consent/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/consent/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly EditLocaleResolver $editLocale,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, $this->buildListContext($request)));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $locale = $request->getLocale();
        $form = $this->formFactory->createNamed('thelia_consent_creation', ConsentType::class, [
            'locale' => $locale,
            'active' => true,
        ], [
            'content_choices' => $this->contentChoiceMap($locale),
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::CONSENT_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Consent creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update/{consent_id}', name: 'update', methods: ['GET'], requirements: ['consent_id' => '\d+'])]
    public function updateView(int $consent_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $consent = ConsentQuery::create()->findPk($consent_id);
        if ($consent === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $consent->setLocale($locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'consent' => $consent,
            'form' => $this->buildUpdateForm($consent, $locale)->createView(),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save/{consent_id}', name: 'save', methods: ['POST'], requirements: ['consent_id' => '\d+'])]
    public function processUpdate(int $consent_id, Request $request): Response
    {
        $consent = ConsentQuery::create()->findPk($consent_id);
        $locked = $consent !== null && !$consent->isDeletable();

        $form = $this->formFactory->createNamed('thelia_consent_modification', ConsentType::class, null, [
            'include_id' => true,
            'locked' => $locked,
            'content_choices' => $this->contentChoiceMap($request->getLocale()),
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::CONSENT_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Consent update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['consent_id' => $consent_id],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['consent_id' => $consent_id])),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST', 'GET'])]
    public function delete(Request $request): Response
    {
        $consentId = (int) ($request->query->get('consent_id') ?? $request->request->get('consent_id', 0));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new ConsentDeleteEvent($consentId),
            eventName: TheliaEvents::CONSENT_DELETE,
            actionLabel: 'Consent deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/toggle-active', name: 'toggle-active', methods: ['GET', 'POST'])]
    public function toggleActive(Request $request): Response
    {
        $consentId = (int) ($request->query->get('consent_id') ?? $request->request->get('consent_id', 0));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ConsentToggleActiveEvent($consentId),
            eventName: TheliaEvents::CONSENT_TOGGLE_ACTIVE,
            actionLabel: 'Consent activation toggle',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['GET', 'POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('consent_id') ?? $request->request->get('consent_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::CONSENT_UPDATE_POSITION,
            actionLabel: 'Consent reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    private function createEvent(FormInterface $validated): ConsentCreateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new ConsentCreateEvent();
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setCode((string) ($data['code'] ?? ''))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDescription($this->nullableString($data['description'] ?? null))
            ->setContentId($this->nullableInt($data['content_id'] ?? null))
            ->setMandatory((int) (bool) ($data['mandatory'] ?? false))
            ->setActive((int) (bool) ($data['active'] ?? true));

        return $event;
    }

    private function updateEvent(FormInterface $validated): ConsentUpdateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new ConsentUpdateEvent((int) ($data['id'] ?? 0));
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDescription($this->nullableString($data['description'] ?? null))
            ->setContentId($this->nullableInt($data['content_id'] ?? null))
            ->setMandatory((int) (bool) ($data['mandatory'] ?? false))
            ->setActive((int) (bool) ($data['active'] ?? false));

        return $event;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = (string) ($value ?? '');

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContext(Request $request): array
    {
        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['id', 'code', 'title', 'position'], 'position');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = ConsentQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'code' => $query->orderByCode($criteria),
            'title' => $query->useConsentI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderByPosition($criteria),
        };
        $consents = $query->find();
        $rows = [];
        foreach ($consents as $consent) {
            \assert($consent instanceof Consent);
            $consent->setLocale($locale);
            $rows[] = $this->consentToRow($consent);
        }

        $createForm = $this->formFactory->createNamed('thelia_consent_creation', ConsentType::class, [
            'locale' => $locale,
            'active' => true,
        ], [
            'content_choices' => $this->contentChoiceMap($locale),
        ]);

        return [
            'rows' => $rows,
            'create_form' => $createForm->createView(),
            'update_position_url' => $this->urls->generate('admin.consent.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function consentToRow(Consent $consent): array
    {
        $id = (int) $consent->getId();
        $deletable = $consent->isDeletable();

        $actions = [
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit'),
                href: $this->urls->generate(self::EDIT_ROUTE, ['consent_id' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: self::RESOURCE,
            ),
        ];

        if ($deletable) {
            $actions[] = new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete'),
                modalTarget: '#consent-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: self::RESOURCE,
                dataAttributes: ['consent-id' => $id, 'consent-label' => (string) $consent->getTitle()],
            );
        }

        return [
            'id' => $id,
            'code' => (string) $consent->getCode(),
            'title' => (string) $consent->getTitle(),
            'mandatory' => $consent->isMandatory(),
            'active' => $consent->isActive(),
            // A consent the shop cannot do without keeps its switch readonly: null here
            // renders the toggle cell inert instead of a clickable link (see toggle.html.twig).
            'toggle_active_url' => $deletable
                ? $this->tokenizedUrl('admin.consent.toggle-active', ['consent_id' => $id])
                : null,
            'position' => (int) $consent->getPosition(),
            '_actions' => $actions,
        ];
    }

    private function buildUpdateForm(Consent $consent, string $locale): FormInterface
    {
        $locked = !$consent->isDeletable();

        return $this->formFactory->createNamed('thelia_consent_modification', ConsentType::class, [
            'id' => $consent->getId(),
            'locale' => $locale,
            'code' => $consent->getCode(),
            'title' => $consent->getTitle(),
            'description' => $consent->getDescription(),
            'content_id' => $consent->getContentId(),
            'mandatory' => $consent->isMandatory(),
            'active' => $consent->isActive(),
        ], [
            'include_id' => true,
            'locked' => $locked,
            'content_choices' => $this->contentChoiceMap($locale),
        ]);
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function contentChoices(string $locale): array
    {
        $contents = ContentQuery::create()
            ->filterByVisible(1)
            ->joinWithI18n($locale)
            ->orderBy(ContentI18nTableMap::COL_TITLE)
            ->find();
        $rows = [];
        foreach ($contents as $content) {
            $content->setLocale($locale);
            $title = (string) $content->getTitle();
            if ($title === '') {
                continue;
            }
            $rows[] = ['id' => (int) $content->getId(), 'title' => $title];
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function contentChoiceMap(string $locale): array
    {
        $map = [];
        foreach ($this->contentChoices($locale) as $choice) {
            $map[\sprintf('%s (#%d)', $choice['title'], $choice['id'])] = $choice['id'];
        }

        return $map;
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function tokenizedUrl(string $route, array $parameters): string
    {
        $url = $this->urls->generate($route, $parameters);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'_token='.$this->tokens->assignToken();
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
