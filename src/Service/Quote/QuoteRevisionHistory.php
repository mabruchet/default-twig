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
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Model\Quote;
use Thelia\Model\QuoteRevision;

/**
 * The revisions of a quote as the history tab lists them: who wrote each one, when, in which
 * state and for how much. A revision that was sent shows the total frozen with it, the one the
 * shop is still writing the total it comes to now.
 */
final readonly class QuoteRevisionHistory
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuoteFacade $facade,
        private MoneyFormatter $money,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param bool $canUpdate whether the administrator may open the revision being written
     * @param bool $canResume whether the administrator may start a revision from an earlier one right now
     *
     * @return list<array{number: int, author: string, author_label: string, state: string, state_label: string, date: \DateTimeInterface|null, total: string|null, is_current: bool, is_shown: bool, url: string|null, can_resume: bool}>
     */
    public function rows(Quote $quote, string $locale, ?string $currencyCode, ?int $shownNumber, bool $canUpdate, bool $canResume): array
    {
        $rows = [];

        foreach ($this->quotes->revisions($quote) as $revision) {
            $number = (int) $revision->getNumber();
            $isDraft = 'draft' === $revision->getState();

            $rows[] = [
                'number' => $number,
                'author' => (string) $revision->getAuthor(),
                'author_label' => 'customer' === $revision->getAuthor() ? $this->translator->trans('Buyer') : $this->translator->trans('Shop'),
                'state' => (string) $revision->getState(),
                'state_label' => $isDraft ? $this->translator->trans('Draft') : $this->translator->trans('Sent'),
                'date' => $isDraft ? $revision->getCreatedAt() : ($revision->getSentAt() ?? $revision->getCreatedAt()),
                'total' => $this->total($revision, $locale, $currencyCode),
                'is_current' => $number === (int) $quote->getCurrentRevision(),
                'is_shown' => $number === $shownNumber,
                'url' => match (true) {
                    !$isDraft => $this->urls->generate('admin.quote.revision', ['id' => $quote->getId(), 'number' => $number]),
                    $canUpdate => $this->urls->generate('admin.quote.edit', ['id' => $quote->getId()]),
                    default => null,
                },
                'can_resume' => $canResume && !$isDraft,
            ];
        }

        return $rows;
    }

    private function total(QuoteRevision $revision, string $locale, ?string $currencyCode): ?string
    {
        if ('draft' === $revision->getState()) {
            return $this->money->format((float) $this->facade->totals($revision)->totalTaxed, $locale, $currencyCode);
        }

        return null === $revision->getTotalTaxed() ? null : $this->money->format((float) $revision->getTotalTaxed(), $locale, $currencyCode);
    }
}
