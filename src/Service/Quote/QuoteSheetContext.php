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

namespace BackOfficeDefaultTwigBundle\Service\Quote;

use BackOfficeDefaultTwigBundle\Repository\QuoteRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Quote\Enum\QuoteState;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Domain\Quote\QuoteTransitions;
use Thelia\Model\Address;
use Thelia\Model\CountryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Quote;
use Thelia\Model\QuoteItem;
use Thelia\Model\QuoteItemRequest;
use Thelia\Model\QuoteRevision;
use Thelia\Model\QuoteRevisionAddress;

/**
 * What the sheet of a quote shows: the header, the lines of the revision the quote stands at with
 * what the buyer asked for next to them, the totals and the buttons the administrator may use.
 */
final readonly class QuoteSheetContext
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuoteFacade $facade,
        private QuoteTransitions $transitions,
        private QuoteStateCatalog $states,
        private QuoteRevisionHistory $history,
        private AdminAccessChecker $access,
        private MoneyFormatter $money,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The tab of the screen the request asks for: the lines or the history of the revisions.
     */
    public static function tabOf(Request $request): string
    {
        return 'history' === $request->query->get('tab') ? 'history' : 'lines';
    }

    /**
     * @param QuoteRevision|null $shown     an earlier revision to read instead of the one the quote stands at
     * @param bool               $withLines false when the screen reads the lines and the totals itself
     *
     * @return array<string, mixed>
     */
    public function sheet(Quote $quote, string $locale, ?QuoteRevision $shown = null, bool $withLines = true): array
    {
        $today = \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
        $state = $this->facade->effectiveState($quote, $today);
        $revision = $shown ?? $this->quotes->currentRevision($quote);
        $currency = (string) CurrencyQuery::create()->findPk($quote->getCurrencyId())?->getCode();
        $currencyCode = '' === $currency ? null : $currency;

        $canUpdate = $this->access->can(AdminResources::QUOTE, AccessManager::UPDATE);
        $canCreate = $this->access->can(AdminResources::QUOTE, AccessManager::CREATE);
        $canRevise = $canUpdate && $this->transitions->canBeDrafted($state);
        $draft = $this->quotes->draftRevision($quote);
        $addresses = $this->addresses($quote, $revision, $locale);
        $canResume = $canRevise && !$draft instanceof QuoteRevision;

        return [
            'quote' => $quote,
            'state' => $state->value,
            'state_label' => $this->states->label($state),
            'state_variant' => $this->states->variant($state),
            'customer' => $this->customer($quote),
            'company' => $this->company($quote),
            'invoice_address' => $addresses['invoice'],
            'delivery_address' => $addresses['delivery'],
            'valid_until' => $revision?->getValidUntil() ?? $quote->getValidUntil(),
            'reverse_charge' => null !== $revision && 'sent' === $revision->getState() && 1 === (int) $revision->getReverseCharge(),
            'revision' => $revision,
            'revision_is_earlier' => null !== $shown && (int) $shown->getNumber() < (int) $quote->getCurrentRevision(),
            'revision_count' => $this->quotes->countRevisions($quote),
            'history' => $this->history->rows($quote, $locale, $currencyCode, null === $revision ? null : (int) $revision->getNumber(), $canUpdate, $canResume),
            'can_resume' => $canResume,
            'current_revision_number' => (int) $quote->getCurrentRevision(),
            'has_pending_draft' => $this->quotes->hasPendingDraft($quote),
            'lines' => null === $revision || !$withLines ? [] : $this->lines($revision, $locale, $currencyCode),
            'totals' => null === $revision || !$withLines ? null : $this->formattedTotals($revision, $locale, $currencyCode),
            'order' => $this->order($quote),
            'can_negotiate' => $canRevise,
            'can_extend' => $canUpdate && \in_array($state, [QuoteState::Quoted, QuoteState::Expired], true),
            'can_remind' => $canUpdate && QuoteState::Quoted === $state,
            'can_duplicate' => $canCreate,
            'extend_from' => $this->extendFrom($quote, $today),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string, ref: string, url: string|null}|null
     */
    private function customer(Quote $quote): ?array
    {
        $customer = CustomerQuery::create()->findPk($quote->getCustomerId());
        if (null === $customer) {
            return null;
        }

        $name = trim($customer->getFirstname().' '.$customer->getLastname());

        return [
            'id' => (int) $customer->getId(),
            'name' => '' === $name ? (string) $customer->getEmail() : $name,
            'email' => (string) $customer->getEmail(),
            'ref' => (string) $customer->getRef(),
            'url' => $this->access->canView(AdminResources::CUSTOMER) ? $this->urls->generate('admin.customer.update.view', ['customer_id' => $customer->getId()]) : null,
        ];
    }

    /**
     * @return array{id: int, name: string, url: string|null}|null
     */
    private function company(Quote $quote): ?array
    {
        $company = $quote->getCompany();
        if (null === $company) {
            return null;
        }

        return [
            'id' => (int) $company->getId(),
            'name' => (string) $company->getName(),
            'url' => $this->access->canView(AdminResources::COMPANY) ? $this->urls->generate('admin.company.view', ['company_id' => $company->getId()]) : null,
        ];
    }

    /**
     * @return array{id: int, ref: string, url: string|null}|null
     */
    private function order(Quote $quote): ?array
    {
        if (QuoteState::Ordered->value !== $quote->getState()) {
            return null;
        }

        $order = $this->quotes->orderOf($quote);
        if (null === $order) {
            return null;
        }

        return [
            'id' => (int) $order->getId(),
            'ref' => (string) $order->getRef(),
            'url' => $this->access->canView(AdminResources::ORDER) ? $this->urls->generate('admin.order.update.view', ['order_id' => $order->getId()]) : null,
        ];
    }

    /**
     * The addresses of the revision: the copies written when it was sent, which the address book
     * no longer changes. A revision that was never sent, or one sent before the copies existed,
     * reads the addresses the quote points at.
     *
     * @return array{invoice: list<string>, delivery: list<string>}
     */
    private function addresses(Quote $quote, ?QuoteRevision $revision, string $locale): array
    {
        $copies = null !== $revision && 'sent' === $revision->getState() ? $this->quotes->revisionAddresses($revision) : [];

        if ([] === $copies) {
            return [
                'invoice' => $this->addressLines($quote->getInvoiceAddress(), $locale),
                'delivery' => $this->addressLines($quote->getDeliveryAddress(), $locale),
            ];
        }

        return [
            'invoice' => $this->copyLines($copies['invoice'] ?? null),
            'delivery' => $this->copyLines($copies['delivery'] ?? null),
        ];
    }

    /**
     * @return list<string>
     */
    private function copyLines(?QuoteRevisionAddress $copy): array
    {
        if (null === $copy) {
            return [];
        }

        $lines = [
            trim($copy->getFirstname().' '.$copy->getLastname()),
            (string) $copy->getCompany(),
            (string) $copy->getAddress1(),
            (string) $copy->getAddress2(),
            (string) $copy->getAddress3(),
            trim($copy->getZipcode().' '.$copy->getCity()),
            (string) $copy->getCountryTitle(),
            (string) $copy->getVatNumber(),
        ];

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));
    }

    /**
     * @return list<string>
     */
    private function addressLines(?Address $address, string $locale): array
    {
        if (null === $address) {
            return [];
        }

        $country = CountryQuery::create()->findPk($address->getCountryId());
        $lines = [
            trim($address->getFirstname().' '.$address->getLastname()),
            (string) $address->getCompany(),
            (string) $address->getAddress1(),
            (string) $address->getAddress2(),
            trim($address->getZipcode().' '.$address->getCity()),
            null === $country ? '' : (string) $country->setLocale($locale)->getTitle(),
        ];

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(QuoteRevision $revision, string $locale, ?string $currencyCode): array
    {
        $lines = [];
        foreach ($this->quotes->lines($revision) as ['line' => $line, 'request' => $request]) {
            $lines[] = $this->line($line, $request, $locale, $currencyCode);
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(QuoteItem $line, ?QuoteItemRequest $request, string $locale, ?string $currencyCode): array
    {
        $quantity = (float) $line->getQuantity();

        return [
            'id' => (int) $line->getId(),
            'title' => (string) $line->getTitle(),
            'ref' => (string) $line->getProductSaleElementsRef(),
            'quantity' => self::quantity($quantity),
            'catalog_price' => $this->money->format((float) $line->getCatalogPrice(), $locale, $currencyCode),
            'price' => $this->money->format((float) $line->getPrice(), $locale, $currencyCode),
            'discounted' => (float) $line->getPrice() < (float) $line->getCatalogPrice(),
            'line_total' => $this->money->format($quantity * (float) $line->getPrice(), $locale, $currencyCode),
            'unavailable' => null === $line->getProductSaleElementsId(),
            'request' => null === $request ? null : [
                'quantity' => null === $request->getQuantity() ? null : self::quantity((float) $request->getQuantity()),
                'price' => null === $request->getPrice() ? null : $this->money->format((float) $request->getPrice(), $locale, $currencyCode),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formattedTotals(QuoteRevision $revision, string $locale, ?string $currencyCode): array
    {
        $totals = $this->facade->totals($revision);

        return [
            'items_untaxed' => $this->money->format((float) $totals->itemsUntaxed, $locale, $currencyCode),
            'items_tax' => $this->money->format((float) $totals->itemsTax, $locale, $currencyCode),
            'postage_untaxed' => $this->money->format((float) $totals->postageUntaxed, $locale, $currencyCode),
            'postage_tax' => $this->money->format((float) $totals->postageTax, $locale, $currencyCode),
            'discount' => $this->money->format((float) $totals->discount, $locale, $currencyCode),
            'total_untaxed' => $this->money->format((float) $totals->totalUntaxed, $locale, $currencyCode),
            'total_taxed' => $this->money->format((float) $totals->totalTaxed, $locale, $currencyCode),
        ];
    }

    /**
     * The day the date of the extension field starts from: the validity of the quote when it is
     * still ahead, tomorrow otherwise.
     */
    private function extendFrom(Quote $quote, \DateTimeImmutable $today): string
    {
        $validUntil = $quote->getValidUntil();
        $from = $validUntil instanceof \DateTimeInterface && $validUntil->format('Y-m-d') > $today->format('Y-m-d')
            ? \DateTimeImmutable::createFromInterface($validUntil)
            : $today;

        return $from->modify('+1 day')->format('Y-m-d');
    }

    private static function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
    }
}
