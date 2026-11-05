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

use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Propel;
use Psr\Log\AbstractLogger;

/**
 * How many SQL queries a piece of back-office code sends.
 *
 * A screen that reads a list one row at a time still renders correctly, so no
 * assertion on its output can tell that it costs three hundred queries. Counting
 * them is the only way to keep a batched read batched: the tests state the budget
 * a screen is allowed, and going back to one query per row breaks them.
 *
 * Propel keeps the counter on the connection wrapper and only increments it in
 * debug mode, which is off by default. Every test on Thelia's IntegrationTestCase
 * runs with instance pooling disabled, so nothing is served from a previous test's
 * memory and the count is the count.
 */
final class QueryCounter
{
    /**
     * The number of queries $work sends. Returned rather than asserted so the
     * caller decides what the budget is.
     */
    public static function count(callable $work): int
    {
        $connection = Propel::getConnection('TheliaMain');

        if (!$connection instanceof ConnectionWrapper) {
            throw new \RuntimeException('Counting queries needs a Propel ConnectionWrapper, got '.get_debug_type($connection).'.');
        }

        $wasDebug = $connection->isInDebugMode();
        $connection->useDebug(true);

        $before = $connection->getQueryCount();

        try {
            $work();
        } finally {
            $after = $connection->getQueryCount();
            $connection->useDebug($wasDebug);
        }

        return $after - $before;
    }

    /**
     * The SQL statements $work sends, as the connection logs them. For a test that
     * must tell one kind of query from the others, where a total could hide a
     * query that moved from one place to another.
     *
     * @return list<string>
     */
    public static function statements(callable $work): array
    {
        $connection = Propel::getConnection('TheliaMain');

        if (!$connection instanceof ConnectionWrapper) {
            throw new \RuntimeException('Listing queries needs a Propel ConnectionWrapper, got '.get_debug_type($connection).'.');
        }

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $statements = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->statements[] = (string) $message;
            }
        };

        $wasDebug = $connection->isInDebugMode();
        $previousLogger = $connection->getLogger();
        $connection->setLogger($logger);
        $connection->useDebug(true);

        try {
            $work();
        } finally {
            $connection->useDebug($wasDebug);
            $connection->setLogger($previousLogger);
        }

        return $logger->statements;
    }
}
