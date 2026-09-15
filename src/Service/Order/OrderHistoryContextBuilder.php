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

use BackOfficeDefaultTwigBundle\Repository\OrderHistoryRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\Admin;
use Thelia\Model\Order;

/**
 * The history block of the order sheet: what happened to this order, newest first,
 * and the note the merchant is about to add to it.
 *
 * Everything it returns is empty while the admin lacks the orders permission, so the
 * order sheet simply shows no block instead of failing - the same shape the returns
 * block uses.
 */
final readonly class OrderHistoryContextBuilder
{
    private const ADMIN_ROLE = 'ADMIN';

    /**
     * Ten entries fit on the sheet without pushing the rest of the page out of reach,
     * and an order that has lived long enough to need more is exactly the one whose
     * recent entries matter.
     */
    public const PAGE_SIZE = 10;

    public function __construct(
        private OrderHistoryRepository $history,
        private OrderHistoryEntryPresenter $presenter,
        private OrderHistoryNotePolicy $notePolicy,
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order, int $page, string $locale): array
    {
        if (!$this->access->canView(AdminResources::ORDER)) {
            return [
                'history_enabled' => false,
                'history_entries' => [],
                'history_total' => 0,
                'history_page' => 1,
                'history_last_page' => 1,
                'history_can_add_note' => false,
                'history_note_max_length' => OrderHistoryNotePolicy::MAX_COMMENT_LENGTH,
            ];
        }

        $orderId = (int) $order->getId();
        $total = $this->history->countForOrder($orderId);
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        $mayWriteNotes = $this->securityContext->isGranted(
            [self::ADMIN_ROLE],
            [AdminResources::ORDER],
            [],
            [AccessManager::UPDATE],
        );

        // A settled order still accepts a new note - a refund is written down after the
        // fact - but the notes already on it are frozen, which is what the policy says.
        $editableByAdminId = $mayWriteNotes && !$this->notePolicy->isSettled($order)
            ? $this->currentAdminId()
            : null;

        return [
            'history_enabled' => true,
            'history_entries' => $this->presenter->presentAll(
                $this->history->findPageForOrder($orderId, $page, self::PAGE_SIZE),
                $locale,
                $editableByAdminId,
            ),
            'history_total' => $total,
            'history_page' => $page,
            'history_last_page' => $lastPage,
            'history_can_add_note' => $mayWriteNotes,
            'history_note_max_length' => OrderHistoryNotePolicy::MAX_COMMENT_LENGTH,
        ];
    }

    private function currentAdminId(): ?int
    {
        $adminUser = $this->securityContext->getAdminUser();

        return $adminUser instanceof Admin ? $adminUser->getId() : null;
    }
}
