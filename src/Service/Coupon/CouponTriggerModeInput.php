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
use Thelia\Model\Coupon;

/**
 * The "with a code / automatic" choice posted by the coupon form, with the rule
 * that goes with it: a coupon with a code needs its code, an automatic promotion
 * needs the title it will be shown under in the cart and carries no code at all.
 *
 * A form that posts no trigger mode at all (a module hook, an older theme
 * overriding the screen, a script) is read as "with a code", the only mode that
 * existed before automatic promotions.
 */
final readonly class CouponTriggerModeInput
{
    public const FIELD_NAME = 'trigger_mode';

    private function __construct(
        public string $triggerMode,
        public ?string $code,
        public string $title,
    ) {
    }

    /**
     * @param array<string, mixed> $postedData the raw POST payload of the coupon form
     */
    public static function fromPostedData(array $postedData): self
    {
        $posted = $postedData[self::FIELD_NAME] ?? null;
        $triggerMode = \is_string($posted) && $posted === Coupon::TRIGGER_MODE_AUTOMATIC
            ? Coupon::TRIGGER_MODE_AUTOMATIC
            : Coupon::TRIGGER_MODE_CODE;

        $code = trim((string) ($postedData['code'] ?? ''));
        $title = trim((string) ($postedData['title'] ?? ''));

        return new self(
            $triggerMode,
            $triggerMode === Coupon::TRIGGER_MODE_AUTOMATIC ? null : $code,
            $title,
        );
    }

    public function isAutomatic(): bool
    {
        return $this->triggerMode === Coupon::TRIGGER_MODE_AUTOMATIC;
    }

    /**
     * The message to show above the form, or null when the mode and its
     * mandatory field agree.
     */
    public function validationError(TranslatorInterface $translator): ?string
    {
        if ($this->isAutomatic()) {
            return $this->title === ''
                ? $translator->trans('An automatic promotion needs a title: it is the label shown to the buyer in the cart.')
                : null;
        }

        return $this->code === null || $this->code === ''
            ? $translator->trans('A coupon with a code needs a code: your customers have nothing to type otherwise.')
            : null;
    }
}
