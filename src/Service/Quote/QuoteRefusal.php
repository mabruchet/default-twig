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

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Quote\Exception\QuoteActionRefusedException;
use Thelia\Domain\Quote\Exception\QuoteDraftRefusedException;
use Thelia\Domain\Quote\Exception\QuoteException;
use Thelia\Domain\Quote\Exception\QuoteTransitionRefusedException;
use Thelia\Domain\Quote\Exception\UnknownQuoteException;

/**
 * Words what the quote rules of the core refuse, in the language of the administrator: the
 * messages of the core are written in English, and a refusal never shows as a server error.
 */
final readonly class QuoteRefusal
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function messageOf(QuoteException $refusal): string
    {
        return match (true) {
            $refusal instanceof UnknownQuoteException => $this->translator->trans('This quote does not exist.'),
            $refusal instanceof QuoteTransitionRefusedException => $this->translator->trans('The quote is not in a state that allows this.'),
            $refusal instanceof QuoteActionRefusedException => $this->translator->trans('You are not allowed to do this on this quote.'),
            $refusal instanceof QuoteDraftRefusedException => $this->draft($refusal),
            default => $this->translator->trans('The quote could not be saved. Please try again.'),
        };
    }

    private function draft(QuoteDraftRefusedException $refusal): string
    {
        return match ($refusal->reason()) {
            QuoteDraftRefusedException::REVISION_LOCKED => $this->translator->trans('A revision that was sent cannot be changed any more.'),
            QuoteDraftRefusedException::CUSTOMER_REFUSED => $this->translator->trans('A quote can only be written for an account that can sign in.'),
            QuoteDraftRefusedException::UNKNOWN_LINE => $this->translator->trans('A line of the quote is not one of this quote.'),
            QuoteDraftRefusedException::UNKNOWN_SALE_ELEMENT, QuoteDraftRefusedException::LINE_UNAVAILABLE => $this->translator->trans('A line of the quote names a product that is no longer in the catalog.'),
            QuoteDraftRefusedException::NO_CATALOG_PRICE => $this->translator->trans('A line of the quote names a product that has no price in the currency of the quote.'),
            QuoteDraftRefusedException::INVALID_QUANTITY => $this->translator->trans('The quantity of a line must be a positive number.'),
            QuoteDraftRefusedException::INVALID_PRICE => $this->translator->trans('The price of a line must be a positive amount.'),
            QuoteDraftRefusedException::INVALID_DISCOUNT => $this->translator->trans('The discount must be a positive amount.'),
            QuoteDraftRefusedException::INVALID_POSTAGE => $this->translator->trans('The postage must be a positive amount.'),
            QuoteDraftRefusedException::UNKNOWN_DELIVERY_MODULE => $this->translator->trans('The delivery module of the quote is not a delivery module of the shop.'),
            QuoteDraftRefusedException::NOTE_TOO_LONG => $this->translator->trans('The note to the buyer is too long.'),
            QuoteDraftRefusedException::VALIDITY_IN_THE_PAST => $this->translator->trans('A quote cannot be valid until a day that is already past.'),
            QuoteDraftRefusedException::NOTHING_TO_SEND => $this->translator->trans('There is no revision waiting to be sent.'),
            QuoteDraftRefusedException::NO_LINES => $this->translator->trans('A quote cannot be sent without a line.'),
            QuoteDraftRefusedException::DISCOUNT_ABOVE_TOTAL => $this->translator->trans('The discount cannot be higher than the total of the lines.'),
            default => $this->translator->trans('The quote could not be saved. Please try again.'),
        };
    }
}
