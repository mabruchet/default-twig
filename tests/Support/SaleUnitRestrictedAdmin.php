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

namespace BackOfficeDefaultTwigBundle\Tests\Support;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Administrator\AdministratorSaleUnitsEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Admin;
use Thelia\Model\SaleUnit;
use Thelia\Model\SaleUnitQuery;
use Thelia\Test\FixtureFactory;

/**
 * An administrator with the given grants that is restricted to one sale unit: what the
 * screens that write for every sale unit at once must refuse it, whatever its profile.
 */
final class SaleUnitRestrictedAdmin
{
    /**
     * @param array<string, list<string>> $grants
     */
    public static function create(FixtureFactory $factory, EventDispatcherInterface $dispatcher, ConnectionInterface $connection, array $grants): Admin
    {
        $unit = (new SaleUnit())
            ->setCode('restricted-'.uniqid())
            ->setActive(true)
            ->setPosition(SaleUnitQuery::create()->count($connection) + 1)
            ->setLocale('en_US')
            ->setTitle('Restricted unit');
        $unit->save($connection);

        $admin = $factory->restrictedAdmin($grants);
        $admin->eraseCredentials();

        $dispatcher->dispatch(
            new AdministratorSaleUnitsEvent((int) $admin->getId(), [(int) $unit->getId()]),
            TheliaEvents::ADMINISTRATOR_UPDATE_SALE_UNITS,
        );

        return $admin;
    }
}
