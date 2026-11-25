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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Quote\Enum\QuoteState;
use Thelia\Domain\Quote\QuoteTransitions;

/**
 * Turns the rows of the list of quotes into the cells of the table. The state shown is the
 * effective one: a quote waiting for the buyer past its last valid day reads expired.
 */
final readonly class QuoteListRowPresenter
{
    private const VIEW_ROUTE = 'admin.quote.view';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private MoneyFormatter $money,
        private QuoteTransitions $transitions,
        private QuoteStateCatalog $states,
        private AdminAccessChecker $access,
    ) {
    }

    /**
     * @param list<array{id: int, ref: string, state: string, validUntil: \DateTimeImmutable|null, createdAt: \DateTimeImmutable|null, currentRevision: int, customerId: int, companyId: int|null, customerName: string, customerEmail: string, customerRef: string, companyName: string|null, currencyCode: string, totalTaxed: string|null, amountAskedByBuyer: bool}> $quotes the rows of QuoteRepository::search()
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $quotes, string $locale, \DateTimeImmutable $today): array
    {
        $canViewCustomers = $this->access->canView(AdminResources::CUSTOMER);
        $canViewCompanies = $this->access->canView(AdminResources::COMPANY);

        $rows = [];
        foreach ($quotes as $quote) {
            $state = $this->transitions->effectiveState(QuoteState::from($quote['state']), $quote['validUntil'], $today);

            $rows[] = [
                'id' => $quote['id'],
                'ref_html' => \sprintf(
                    '<a href="%s" class="text-decoration-none fw-semibold" data-testid="quote-ref-link">%s</a>',
                    htmlspecialchars($this->urls->generate(self::VIEW_ROUTE, ['id' => $quote['id']])),
                    htmlspecialchars($quote['ref']),
                ),
                'customer_html' => $this->customer($quote, $canViewCustomers),
                'company_html' => $this->company($quote, $canViewCompanies),
                'state' => $state->value,
                'state_label' => $this->states->label($state),
                'total_html' => null === $quote['totalTaxed']
                    ? '<span class="text-muted">-</span>'
                    : '<span class="fw-semibold" dir="ltr">'.htmlspecialchars($this->money->format((float) $quote['totalTaxed'], $locale, '' === $quote['currencyCode'] ? null : $quote['currencyCode'])).'</span>'
                        .($quote['amountAskedByBuyer'] ? '<div class="text-muted small" data-testid="quote-total-asked">'.htmlspecialchars($this->translator->trans('Requested amount')).'</div>' : ''),
                'valid_until_html' => $this->day($quote['validUntil']),
                'created_html' => $this->day($quote['createdAt']),
                '_actions' => [
                    new RowAction(
                        kind: 'edit',
                        label: $this->translator->trans('View'),
                        href: $this->urls->generate(self::VIEW_ROUTE, ['id' => $quote['id']]),
                        grantedAttribute: AccessManager::VIEW,
                        grantedSubject: AdminResources::QUOTE,
                    ),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param array{customerId: int, customerName: string, customerEmail: string} $quote
     */
    private function customer(array $quote, bool $link): string
    {
        $name = htmlspecialchars($quote['customerName']);
        if ($link) {
            $name = \sprintf('<a href="%s" class="text-decoration-none">%s</a>', htmlspecialchars($this->urls->generate('admin.customer.update.view', ['customer_id' => $quote['customerId']])), $name);
        }

        return \sprintf('<div class="fw-semibold">%s</div><div class="text-muted small text-truncate">%s</div>', $name, htmlspecialchars($quote['customerEmail']));
    }

    /**
     * @param array{companyId: int|null, companyName: string|null} $quote
     */
    private function company(array $quote, bool $link): string
    {
        if (null === $quote['companyId'] || null === $quote['companyName']) {
            return '<span class="text-muted">-</span>';
        }

        $name = htmlspecialchars($quote['companyName']);

        return $link
            ? \sprintf('<a href="%s" class="text-decoration-none">%s</a>', htmlspecialchars($this->urls->generate('admin.company.view', ['company_id' => $quote['companyId']])), $name)
            : $name;
    }

    private function day(?\DateTimeImmutable $day): string
    {
        if (null === $day) {
            return '<span class="text-muted">-</span>';
        }

        return \sprintf('<time datetime="%s">%s</time>', htmlspecialchars($day->format('Y-m-d')), htmlspecialchars($day->format('d/m/Y')));
    }
}
