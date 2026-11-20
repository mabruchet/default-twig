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

namespace BackOfficeDefaultTwigBundle\Service\Company;

/**
 * A refusal worded for the administrator, already translated.
 */
final class CompanyRefusedException extends \RuntimeException
{
    /**
     * @param string|null $redirectUrl where the administrator lands instead of the screen the action came from
     */
    public function __construct(string $message, public readonly ?string $redirectUrl = null)
    {
        parent::__construct($message);
    }
}
