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

namespace BackOfficeDefaultTwigBundle\Service\CustomerGroup;

/**
 * A write on a customer group the merchant has to correct: its message is written for
 * them and shown as it is.
 */
final class CustomerGroupRefusedException extends \RuntimeException
{
}
