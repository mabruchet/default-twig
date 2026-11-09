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

use Thelia\Domain\Customer\Service\CustomerGroupResolver;
use Thelia\Domain\Pricing\CatalogActivityProbe;
use Thelia\Model\Customer;

/**
 * Answers every question about the group of a customer with one given group: the way to ask
 * the catalog restrictions what a customer GROUP sees, whoever the customer is. The core
 * resolver reads the group of a stored customer from the database and gives the default group
 * to anybody else, so a group cannot be asked about without a member.
 */
final class GroupViewpointResolver extends CustomerGroupResolver
{
    public function __construct(CatalogActivityProbe $catalogActivityProbe, private readonly int $groupId)
    {
        parent::__construct($catalogActivityProbe);
    }

    public function groupIdOf(?Customer $customer): int
    {
        return $this->groupId;
    }
}
