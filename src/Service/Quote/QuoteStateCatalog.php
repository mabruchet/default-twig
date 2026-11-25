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
use Thelia\Domain\Quote\Enum\QuoteState;

/**
 * How a state of a quote reads on the screens: its label and the colour of its badge.
 */
final readonly class QuoteStateCatalog
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function label(QuoteState $state): string
    {
        return match ($state) {
            QuoteState::Draft => $this->translator->trans('Draft'),
            QuoteState::Requested => $this->translator->trans('Requested'),
            QuoteState::Quoted => $this->translator->trans('Quoted'),
            QuoteState::Countered => $this->translator->trans('Counter-proposal'),
            QuoteState::Accepted => $this->translator->trans('Accepted'),
            QuoteState::Rejected => $this->translator->trans('Rejected'),
            QuoteState::Expired => $this->translator->trans('Expired'),
            QuoteState::Ordered => $this->translator->trans('Ordered'),
        };
    }

    /**
     * The Bootstrap colour of the badge of a state.
     */
    public function variant(QuoteState $state): string
    {
        return match ($state) {
            QuoteState::Draft => 'secondary',
            QuoteState::Requested => 'info',
            QuoteState::Quoted => 'primary',
            QuoteState::Countered => 'warning',
            QuoteState::Accepted, QuoteState::Ordered => 'success',
            QuoteState::Rejected => 'danger',
            QuoteState::Expired => 'dark',
        };
    }

    /**
     * @return list<array{value: string, label: string}> every state, for the filter
     */
    public function options(): array
    {
        return array_map(
            fn (QuoteState $state): array => ['value' => $state->value, 'label' => $this->label($state)],
            QuoteState::cases(),
        );
    }
}
