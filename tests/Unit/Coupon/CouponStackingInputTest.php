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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Coupon;

use BackOfficeDefaultTwigBundle\Service\Coupon\CouponStackingInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Thelia\Domain\Promotion\Coupon\Exception\InvalidCouponExclusionException;

final class CouponStackingInputTest extends TestCase
{
    public function testAConcurrentEditAsksToSaveAgainInsteadOfBlamingTheChoice(): void
    {
        $failure = new \RuntimeException('save failed', 0, InvalidCouponExclusionException::concurrentEdit(new \RuntimeException('deadlock')));

        self::assertSame(
            'Another administrator changed the promotions at the same time, so nothing was saved: save again.',
            CouponStackingInput::refusalMessage($failure, new IdentityTranslator()),
        );
    }

    public function testAnInvalidChoiceIsStillToldAsInvalid(): void
    {
        $message = CouponStackingInput::refusalMessage(InvalidCouponExclusionException::selfExclusion(3), new IdentityTranslator());

        self::assertStringStartsWith('The incompatible promotions are not valid', (string) $message);
    }

    public function testAnotherFailureIsLeftToTheGenericMessage(): void
    {
        self::assertNull(CouponStackingInput::refusalMessage(new \RuntimeException('other'), new IdentityTranslator()));
    }
}
