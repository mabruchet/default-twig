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

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Domain\Order\Enum\OrderHistoryEventType;
use Thelia\Model\OrderHistory;
use Thelia\Model\OrderHistoryQuery;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatusQuery;

/**
 * The Propel reads behind the history block of the order sheet.
 *
 * Not readonly: the status titles are asked for once per entry rendered on the page and
 * the answer only depends on the locale, so the map is memoised for the request.
 */
final class OrderHistoryRepository
{
    /** @var array<string, array<string, string>> */
    private array $statusTitles = [];

    /** @var array<string, array<string, string>> */
    private array $returnStatusTitles = [];

    public function countForOrder(int $orderId): int
    {
        return OrderHistoryQuery::create()->filterByOrderId($orderId)->count();
    }

    /**
     * One page of the timeline, newest first.
     *
     * Ordered on the primary key rather than on created_at, for the reason the core
     * states on OrderHistoryQuery::findLatestOfType(): two entries written in the same
     * second are indistinguishable by their timestamp, and the last row written is the
     * one that has to come first.
     *
     * @return ObjectCollection<int, OrderHistory>
     */
    public function findPageForOrder(int $orderId, int $page, int $perPage): ObjectCollection
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        /** @var ObjectCollection<int, OrderHistory> $result */
        $result = OrderHistoryQuery::create()
            ->filterByOrderId($orderId)
            ->orderById(Criteria::DESC)
            ->offset($offset)
            ->limit($perPage)
            ->find();

        return $result;
    }

    /**
     * One manual note of one order, or null.
     *
     * The order is part of the filter, not checked afterwards: an id that belongs to
     * another order must not resolve at all, whatever the caller then does with it.
     */
    public function findNoteOfOrder(int $orderId, int $historyId): ?OrderHistory
    {
        return OrderHistoryQuery::create()
            ->filterByOrderId($orderId)
            ->filterByEventType(OrderHistoryEventType::NOTE->value)
            ->filterById($historyId)
            ->findOne();
    }

    /**
     * Status code to status title, in the interface language: what a status_changed
     * payload carries are codes, and the merchant reads titles.
     *
     * @return array<string, string>
     */
    public function statusTitlesByCode(string $locale): array
    {
        if (isset($this->statusTitles[$locale])) {
            return $this->statusTitles[$locale];
        }

        $titles = [];
        $statuses = OrderStatusQuery::create()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        foreach ($statuses as $status) {
            $status->setLocale($locale);
            $code = (string) $status->getCode();
            $title = (string) $status->getTitle();
            $titles[$code] = '' !== $title ? $title : $code;
        }

        return $this->statusTitles[$locale] = $titles;
    }

    /**
     * The same map for the statuses of a return, whose codes are what the lines about
     * a return carry.
     *
     * A return status may declare itself the equivalent of a canonical one, and what
     * the history stores is that canonical code. Own codes are written first, so a
     * shop that renamed "accepted" keeps its own wording; an equivalence only answers
     * for a code no status carries under its own name, and the first one wins rather
     * than the last, so the page does not change wording when a status is added.
     *
     * @return array<string, string>
     */
    public function returnStatusTitlesByCode(string $locale): array
    {
        if (isset($this->returnStatusTitles[$locale])) {
            return $this->returnStatusTitles[$locale];
        }

        $statuses = OrderReturnStatusQuery::create()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        $titles = [];
        $byEquivalence = [];

        foreach ($statuses as $status) {
            $status->setLocale($locale);
            $code = (string) $status->getCode();
            $title = (string) $status->getTitle();
            $title = '' !== $title ? $title : $code;

            $titles[$code] = $title;
            $byEquivalence[$status->getEffectiveCode()] ??= $title;
        }

        return $this->returnStatusTitles[$locale] = $titles + $byEquivalence;
    }
}
