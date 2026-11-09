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

namespace BackOfficeDefaultTwigBundle\Service\CatalogRestriction;

/**
 * A rule the back office refuses to write: the message is already translated, fit to show.
 */
final class CatalogRestrictionRefusedException extends \RuntimeException
{
}
