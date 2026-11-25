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

namespace BackOfficeDefaultTwigBundle\Controller\Quote;

use BackOfficeDefaultTwigBundle\Repository\CompanyRepository;
use BackOfficeDefaultTwigBundle\Repository\QuoteRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteFilters;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteListRowPresenter;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteRefusedException;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteSheetContext;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteStateCatalog;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteWriteGate;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Model\Admin;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Quote;
use Twig\Environment;

/**
 * The quotes of the shop: the list, the sheet of a quote, and the actions that need no
 * negotiation: creating a quote for a customer, extending its validity, reminding the buyer and
 * starting a new quote from an old one. Every write goes through the quote facade.
 */
#[Route('/admin/quotes', name: 'admin.quote.')]
final class QuoteController
{
    public const CREATION_LABEL = 'Quote creation';
    public const EXTENSION_LABEL = 'Quote extension';
    public const REMINDER_LABEL = 'Quote reminder';
    public const DUPLICATION_LABEL = 'Quote duplication';

    public const EDIT_ROUTE = QuoteEditController::ROUTE;

    private const LIST_ROUTE = 'admin.quote.list';
    private const VIEW_ROUTE = 'admin.quote.view';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly QuoteWriteGate $gate,
        private readonly QuoteRepository $quotes,
        private readonly QuoteFacade $facade,
        private readonly QuoteListRowPresenter $presenter,
        private readonly QuoteSheetContext $sheet,
        private readonly QuoteStateCatalog $states,
        private readonly CompanyRepository $companies,
        private readonly SecurityContext $securityContext,
        private readonly AdminFlash $flash,
        private readonly ClockInterface $clock,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(QuoteWriteGate::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $filters = QuoteFilters::fromRequest($request);
        $result = $this->quotes->search($filters, (int) $request->query->get('page', 1));
        $today = \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);

        return new Response($this->twig->render('@BackOfficeDefaultTwig/quote/list.html.twig', [
            'rows' => $this->presenter->rows($result['rows'], $request->getLocale(), $today),
            'total' => $result['total'],
            'current_page' => $result['page'],
            'last_page' => $result['lastPage'],
            'filters' => $filters,
            'route_params' => $filters->toQueryParams(),
            'state_options' => $this->states->options(),
            'company_options' => $this->access->canView(AdminResources::COMPANY) ? $this->companies->choices() : [],
            'sort_field' => $filters->sort,
            'sort_direction' => $filters->direction,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customerId = (int) $request->request->get('customer_id', 0);
        $customer = $customerId > 0 ? CustomerQuery::create()->findPk($customerId) : null;
        $back = null === $customer ? $this->urls->generate(self::LIST_ROUTE) : $this->urls->generate('admin.customer.update.view', ['customer_id' => $customerId]);

        return $this->gate->run($request, AccessManager::CREATE, self::CREATION_LABEL, $back, null === $customer ? null : $customerId, 'Quote created.', function () use ($customer, $customerId): array {
            if (null === $customer) {
                throw new QuoteRefusedException($this->translator->trans('This customer does not exist.'));
            }

            $quote = $this->facade->createForCustomer($customer, $this->admin());

            return [
                \sprintf('Quote %s created for customer %d', (string) $quote->getRef(), $customerId),
                $this->urls->generate(self::EDIT_ROUTE, ['id' => $quote->getId()]),
            ];
        });
    }

    #[Route('/{id}', name: 'view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(int $id, Request $request): Response
    {
        if ($denied = $this->access->check(QuoteWriteGate::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $quote = $this->quotes->find($id);
        if (null === $quote) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/quote/view.html.twig', $this->sheet->sheet($quote, $request->getLocale()) + ['tab' => QuoteSheetContext::tabOf($request)]));
    }

    #[Route('/{id}/extend', name: 'extend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function extend(int $id, Request $request): Response
    {
        $redirect = $this->urls->generate(self::VIEW_ROUTE, ['id' => $id]);

        return $this->gate->run($request, AccessManager::UPDATE, self::EXTENSION_LABEL, $redirect, $id, 'Quote extended.', function () use ($id, $request): string {
            $quote = $this->require($id);
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) $request->request->get('valid_until', '')));
            if (false === $day) {
                throw new QuoteRefusedException($this->translator->trans('The last valid day is not a valid date.'));
            }

            $this->facade->extend($quote, $day);

            return \sprintf('Quote %s extended until %s', (string) $quote->getRef(), $day->format('Y-m-d'));
        });
    }

    #[Route('/{id}/remind', name: 'remind', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remind(int $id, Request $request): Response
    {
        $redirect = $this->urls->generate(self::VIEW_ROUTE, ['id' => $id]);

        return $this->gate->run($request, AccessManager::UPDATE, self::REMINDER_LABEL, $redirect, $id, 'The buyer was reminded.', function () use ($id): string {
            $quote = $this->require($id);
            $this->facade->remind($quote);

            return \sprintf('Buyer reminded of quote %s', (string) $quote->getRef());
        });
    }

    #[Route('/{id}/duplicate', name: 'duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $request): Response
    {
        $redirect = $this->urls->generate(self::VIEW_ROUTE, ['id' => $id]);

        return $this->gate->run($request, AccessManager::CREATE, self::DUPLICATION_LABEL, $redirect, $id, 'Quote duplicated.', function () use ($id, $request): array {
            $quote = $this->require($id);
            $duplication = $this->facade->duplicate($quote, $this->admin());

            if ($duplication->hasOmittedLines()) {
                $references = implode(', ', array_map(static fn (array $line): string => '' === $line['reference'] ? $line['title'] : $line['reference'], $duplication->omittedLines));
                $this->flash->add($request, 'warning', $this->translator->trans('Some lines name a product that can no longer be ordered: they were left out of the new quote (%lines%).', ['%lines%' => $references]));
            }

            $copy = $duplication->quote;

            return [
                \sprintf('Quote %s duplicated as %s', (string) $quote->getRef(), (string) $copy->getRef()),
                $this->urls->generate(self::EDIT_ROUTE, ['id' => $copy->getId()]),
            ];
        });
    }

    private function require(int $id): Quote
    {
        return $this->quotes->find($id)
            ?? throw new QuoteRefusedException($this->translator->trans('This quote does not exist.'), $this->urls->generate(self::LIST_ROUTE));
    }

    private function admin(): ?Admin
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? $admin : null;
    }
}
