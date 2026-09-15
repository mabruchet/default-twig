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

namespace BackOfficeDefaultTwigBundle\Service\Order;

use Thelia\Model\Order;
use Thelia\Model\OrderHistory;

/**
 * Who may still rewrite a note of an order history, and until when.
 *
 * One class rather than a condition repeated in the builder that hides the button and
 * in the action that refuses the post: the button and the refusal have to agree, and
 * they only do so while they read the same rule.
 */
final readonly class OrderHistoryNotePolicy
{
    /**
     * Long enough for the longest memo a merchant writes on an order, short enough that
     * a forged post cannot fill the column with megabytes.
     */
    public const MAX_COMMENT_LENGTH = 2000;

    /**
     * A note is a working memo, not an archive entry, and it stays editable as long as
     * the order is still being worked on. Once the order is settled - cancelled or
     * refunded - nothing is being worked on any more and what was written stays written.
     */
    public function isSettled(Order $order): bool
    {
        return $order->isCancelled() || $order->isRefunded();
    }

    /**
     * The author is compared on the admin id, never on the label: the label is a
     * snapshot of a login, and two admins may well have carried the same one over time.
     *
     * An entry whose admin_id was nulled by the deletion of its author is nobody's any
     * more, so nobody may rewrite it.
     */
    public function mayEdit(OrderHistory $entry, Order $order, ?int $adminId): bool
    {
        if (null === $adminId || $this->isSettled($order)) {
            return false;
        }

        $authorId = $entry->getAdminId();

        return null !== $authorId && $authorId === $adminId;
    }
}
