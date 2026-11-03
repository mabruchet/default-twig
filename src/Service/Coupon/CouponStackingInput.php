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

namespace BackOfficeDefaultTwigBundle\Service\Coupon;

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Coupon\CouponCreateOrUpdateEvent;
use Thelia\Domain\Promotion\Coupon\Exception\InvalidCouponExclusionException;

/**
 * The stacking fields of the coupon form (choice, priority, incompatible promotions),
 * read once from the POST: the save and the redisplay of a refused form share this
 * reading, so they cannot disagree on what was posted.
 *
 * A field the form did not carry is null: the core reads "not set" as "untouched".
 */
final readonly class CouponStackingInput
{
    public const EXCLUSIONS_FIELD = 'excludedCouponIds';

    /** An empty multiple select posts nothing: this marker tells a cleared list from a form that never carried it. */
    public const EXCLUSIONS_MARKER = 'excludedCouponIdsPresent';

    /**
     * @param list<int>|null $excludedCouponIds null when the form did not carry the list
     */
    private function __construct(
        public ?CouponStackingMode $mode,
        public ?int $priority,
        public ?string $typedPriority,
        public ?array $excludedCouponIds,
    ) {
    }

    /**
     * @param array<string, mixed> $postedData the raw POST payload of the coupon form
     */
    public static function fromPostedData(array $postedData): self
    {
        $priority = $postedData['priority'] ?? null;
        $typedPriority = \is_scalar($priority) && '' !== trim((string) $priority) ? trim((string) $priority) : null;
        // The bounds of the signed INTEGER column: a wider number would fail at the database.
        $validPriority = null === $typedPriority ? false : filter_var($typedPriority, \FILTER_VALIDATE_INT, [
            'options' => ['min_range' => -2147483648, 'max_range' => 2147483647],
        ]);

        return new self(
            CouponStackingMode::fromPostedData($postedData),
            false === $validPriority ? null : $validPriority,
            $typedPriority,
            isset($postedData[self::EXCLUSIONS_MARKER]) ? self::idList($postedData[self::EXCLUSIONS_FIELD] ?? []) : null,
        );
    }

    /**
     * The message to show above the form, or null when every posted field is usable.
     */
    public function validationError(TranslatorInterface $translator): ?string
    {
        return null !== $this->typedPriority && null === $this->priority
            ? $translator->trans('The priority must be a whole number.')
            : null;
    }

    /**
     * What the core refused of the incompatible promotions, in the language of the screen
     * instead of the words of the exception.
     */
    public static function refusalMessage(\Throwable $failure, TranslatorInterface $translator): ?string
    {
        for ($cause = $failure; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof InvalidCouponExclusionException) {
                return $translator->trans('The incompatible promotions are not valid: a promotion cannot be incompatible with itself, and each one chosen must still exist.');
            }
        }

        return null;
    }

    /** Only what the form carried goes to the event; the rest stays as stored. */
    public function applyTo(CouponCreateOrUpdateEvent $event): void
    {
        if (null !== $this->priority) {
            $event->setPriority($this->priority);
        }
        if (null !== $this->mode) {
            $event->setStopProcessing($this->mode->stopsProcessing());
        }
        if (null !== $this->excludedCouponIds) {
            $event->setExcludedCouponIds($this->excludedCouponIds);
        }
    }

    /**
     * A lone value is a list of one: reading it as "no list" would clear every exclusion.
     *
     * @return list<int>
     */
    private static function idList(mixed $posted): array
    {
        if (!\is_array($posted)) {
            $posted = \is_scalar($posted) && '' !== (string) $posted ? [$posted] : [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $id): int => \is_scalar($id) ? (int) $id : 0,
            $posted,
        )));
    }
}
