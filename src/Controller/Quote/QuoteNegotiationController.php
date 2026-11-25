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

use BackOfficeDefaultTwigBundle\Repository\QuoteRepository;
use BackOfficeDefaultTwigBundle\Repository\SearchRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteDraftReader;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteNegotiationContext;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteRefusedException;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteWriteGate;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Quote\DTO\QuoteDraftInput;
use Thelia\Domain\Quote\DTO\QuoteDraftLine;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Domain\Quote\Service\QuoteCatalogPrices;
use Thelia\Model\Admin;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Quote;
use Thelia\Model\QuoteRevision;

/**
 * The writes of the negotiation screen: saving the draft, sending it, starting a revision from
 * an earlier one, and the search of the products to add. Every write goes through the quote
 * facade, which refuses a revision that was sent whatever the form says.
 */
#[Route('/admin/quotes/{id}', name: 'admin.quote.', requirements: ['id' => '\d+'])]
final class QuoteNegotiationController
{
    public const SAVE_LABEL = 'Quote saving';
    public const SEND_LABEL = 'Quote sending';
    public const RESUME_LABEL = 'New quote revision';

    /** The most rows a search of products answers. */
    public const SEARCH_LIMIT = 20;

    private const EDIT_ROUTE = QuoteEditController::ROUTE;
    private const VIEW_ROUTE = 'admin.quote.view';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly QuoteWriteGate $gate,
        private readonly QuoteRepository $quotes,
        private readonly QuoteFacade $facade,
        private readonly QuoteDraftReader $reader,
        private readonly SearchRepository $search,
        private readonly QuoteCatalogPrices $catalogPrices,
        private readonly MoneyFormatter $money,
        private readonly SecurityContext $securityContext,
        private readonly AdminFlash $flash,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/draft', name: 'save', methods: ['POST'])]
    public function save(int $id, Request $request): Response
    {
        $redirect = $this->urls->generate(self::EDIT_ROUTE, ['id' => $id]);

        return $this->gate->run($request, AccessManager::UPDATE, self::SAVE_LABEL, $redirect, $id, 'Quote saved.', function () use ($id, $request): string {
            $quote = $this->require($id);
            $this->facade->saveDraft($quote, $this->admin(), $this->reader->read($request));

            return \sprintf('Draft of quote %s saved', (string) $quote->getRef());
        });
    }

    #[Route('/send', name: 'send', methods: ['POST'])]
    public function send(int $id, Request $request): Response
    {
        $redirect = $this->urls->generate(self::EDIT_ROUTE, ['id' => $id]);

        return $this->gate->run($request, AccessManager::UPDATE, self::SEND_LABEL, $redirect, $id, 'Quote sent to the buyer.', function () use ($id, $request): array {
            $quote = $this->require($id);
            $admin = $this->admin();

            $this->facade->saveAndSend($quote, $admin, $this->reader->read($request));

            return [
                \sprintf('Quote %s sent', (string) $quote->getRef()),
                $this->urls->generate(self::VIEW_ROUTE, ['id' => $id]),
            ];
        });
    }

    /**
     * A new draft with the lines of a revision that was sent: the one the quote stands at, to
     * write the next one, or an earlier one, to take it up again. A draft being written is never
     * replaced.
     */
    #[Route('/revisions/{number}/resume', name: 'resume', methods: ['POST'], requirements: ['number' => '\d+'])]
    public function resume(int $id, int $number, Request $request): Response
    {
        $redirect = $this->urls->generate(self::VIEW_ROUTE, ['id' => $id]);

        return $this->gate->run($request, AccessManager::UPDATE, self::RESUME_LABEL, $redirect, $id, 'A new revision is ready to be written.', function () use ($id, $number, $request): array {
            $quote = $this->require($id);
            $revision = $this->quotes->revision($quote, $number);

            if (!$revision instanceof QuoteRevision || 'sent' !== $revision->getState()) {
                throw new QuoteRefusedException($this->translator->trans('This revision does not exist.'));
            }

            if ($this->quotes->draftRevision($quote) instanceof QuoteRevision) {
                throw new QuoteRefusedException($this->translator->trans('A revision is already being written: save or send it first.'), $this->urls->generate(self::EDIT_ROUTE, ['id' => $id]));
            }

            $lines = [];
            $left = 0;
            foreach ($this->quotes->lines($revision) as ['line' => $line]) {
                if (null === $line->getProductSaleElementsId()) {
                    ++$left;
                    continue;
                }

                $lines[] = new QuoteDraftLine((int) $line->getId(), (int) $line->getProductSaleElementsId(), (float) $line->getQuantity(), (string) $line->getPrice());
            }

            $validUntil = $revision->getValidUntil();
            $today = \DateTimeImmutable::createFromInterface($this->clock->now())->format('Y-m-d');

            $this->facade->saveDraft($quote, $this->admin(), new QuoteDraftInput(
                $lines,
                (string) $revision->getDiscount(),
                (string) $revision->getPostage(),
                $revision->getDeliveryModuleId(),
                $revision->getMerchantNote(),
                $validUntil instanceof \DateTimeInterface && $validUntil->format('Y-m-d') >= $today ? \DateTimeImmutable::createFromInterface($validUntil) : null,
            ));

            if ($left > 0) {
                $this->flash->add($request, 'warning', $this->translator->trans('Some lines name a product that is no longer in the catalog: they were left out of the new revision.'));
            }

            return [
                \sprintf('Revision %d of quote %s taken up as a new draft', $number, (string) $quote->getRef()),
                $this->urls->generate(self::EDIT_ROUTE, ['id' => $id]),
            ];
        });
    }

    /**
     * The combinations the shop can add to the quote: a term of two characters at least, twenty
     * rows at most, each with the catalogue price the buyer of the quote would pay for one.
     */
    #[Route('/products', name: 'product_search', methods: ['GET'])]
    public function searchProducts(int $id, Request $request): JsonResponse
    {
        if (null !== $this->access->check(QuoteWriteGate::RESOURCE, [], AccessManager::UPDATE)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        $quote = $this->quotes->find($id);
        if (null === $quote) {
            return new JsonResponse([], Response::HTTP_NOT_FOUND);
        }

        $term = mb_substr(trim((string) $request->query->get('q', '')), 0, 100);
        $found = \array_slice($this->search->findSellableCombinations(
            $term,
            LangQuery::create()->findOneByByDefault(1)?->getLocale() ?? 'en_US',
            self::SEARCH_LIMIT,
            $request->getLocale(),
        ), 0, self::SEARCH_LIMIT);

        $currency = CurrencyQuery::create()->findPk($quote->getCurrencyId());
        if ([] === $found || null === $currency) {
            return new JsonResponse([]);
        }

        $prices = $this->catalogPrices->resolve(
            array_fill_keys(array_map(static fn (array $row): int => $row['pse_id'], $found), 1.0),
            $currency,
            $quote->getCustomer(),
        );

        $rows = [];
        foreach ($found as $row) {
            if (!isset($prices[$row['pse_id']])) {
                continue;
            }

            $rows[] = $row + [
                'price' => QuoteNegotiationContext::amount($prices[$row['pse_id']]),
                'price_label' => $this->money->format((float) $prices[$row['pse_id']], $request->getLocale(), (string) $currency->getCode()),
            ];
        }

        return new JsonResponse($rows);
    }

    private function require(int $id): Quote
    {
        return $this->quotes->find($id)
            ?? throw new QuoteRefusedException($this->translator->trans('This quote does not exist.'), $this->urls->generate('admin.quote.list'));
    }

    private function admin(): ?Admin
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? $admin : null;
    }
}
