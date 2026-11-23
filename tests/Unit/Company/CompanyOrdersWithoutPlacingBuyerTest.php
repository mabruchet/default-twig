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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Company;

use BackOfficeDefaultTwigBundle\Repository\CompanyRepository;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use PHPUnit\Framework\TestCase;

/**
 * The orders tab of a company sheet lists the orders of a company, including the ones whose
 * placing buyer was deleted (placedBy is null): the names lookup must ignore the null instead of
 * raising a TypeError that ends in a 500.
 */
final class CompanyOrdersWithoutPlacingBuyerTest extends TestCase
{
    public function testCustomerNamesIgnoresAnOrderWhosePlacingBuyerWasDeleted(): void
    {
        $orderAccess = (new \ReflectionClass(OrderAccess::class))->newInstanceWithoutConstructor();
        $repository = new CompanyRepository($orderAccess);

        $names = null;
        $failure = null;
        try {
            $names = $repository->customerNames([null]);
        } catch (\TypeError $typeError) {
            $failure = $typeError->getMessage();
        }

        self::assertNull($failure, 'customerNames() raised: '.$failure);
        self::assertSame([], $names);
    }
}
