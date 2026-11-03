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

/**
 * The single choice of the editor that replaces the "combinable" box: how a
 * promotion stacks with the others. The core holds two flags; this is only their
 * three allowed pairs, so the screen never invents a fourth.
 */
enum CouponStackingMode: string
{
    case Cumulative = 'cumulative';
    case Stop = 'stop';
    case Alone = 'alone';

    public const FIELD = 'stackingMode';

    public static function fromFlags(bool $isCumulative, bool $stopProcessing): self
    {
        if (!$isCumulative) {
            return self::Alone;
        }

        return $stopProcessing ? self::Stop : self::Cumulative;
    }

    /** The value of the posted radio, or null when the form did not carry the choice (an older client). */
    public static function fromPostedData(array $posted): ?self
    {
        $value = $posted[self::FIELD] ?? null;

        return \is_string($value) ? self::tryFrom($value) : null;
    }

    public function isCumulative(): bool
    {
        return self::Alone !== $this;
    }

    public function stopsProcessing(): bool
    {
        return self::Cumulative !== $this;
    }
}
