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

namespace BackOfficeDefaultTwigBundle\Controller\Order;

use BackOfficeDefaultTwigBundle\Repository\OrderHistoryRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Order\OrderHistoryNotePolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Order\Service\OrderHistoryRecorder;
use Thelia\Model\Admin;
use Thelia\Model\Order;
use Thelia\Model\OrderHistory;
use Thelia\Tools\TokenProvider;

/**
 * The two gestures the merchant makes on the history of an order: writing a note, and
 * correcting the note he wrote himself.
 *
 * Both reload the order sheet, like every other post of this back-office - there is no
 * ajax action anywhere in this theme, and a note is not the place to introduce one.
 */
final class OrderHistoryController
{
    private const RESOURCE = AdminResources::ORDER;
    private const LIST_ROUTE = 'admin.order.list';
    private const DETAIL_ROUTE = 'admin.order.update.view';
    private const DETAIL_ANCHOR = '#order-history';
    private const DOMAIN = 'messages';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly TokenProvider $tokens,
        private readonly UrlGeneratorInterface $urls,
        // The back-office catalogue lives on the Symfony translator; the aliased
        // interface would answer from Thelia's own, which never loaded it.
        #[Autowire(service: 'translator')]
        private readonly TranslatorInterface $translator,
        private readonly SecurityContext $securityContext,
        private readonly OrderRepository $orders,
        private readonly OrderHistoryRepository $history,
        private readonly OrderHistoryNotePolicy $notePolicy,
        private readonly OrderHistoryRecorder $recorder,
    ) {
    }

    #[Route('/admin/order/{order_id}/history/note', name: 'admin.order.history.note.add', methods: ['POST'], requirements: ['order_id' => '\d+'])]
    public function addNote(Request $request, int $order_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $order = $this->orders->findById($order_id);
        if (null === $order) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        if (!$this->tokenIsValid($request)) {
            return $this->back($request, $order_id, 'danger', 'The form has expired, please try again.');
        }

        $comment = $this->readComment($request);
        if (null === $comment) {
            return $this->back($request, $order_id, 'danger', $this->commentError($request));
        }

        $this->recorder->recordNote($order_id, $comment, $this->readVisibility($request));

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf('Note added to the history of order %s', (string) $order->getRef()),
            $order_id,
        );

        return $this->back($request, $order_id, 'success', 'The note has been added.');
    }

    #[Route('/admin/order/{order_id}/history/note/{history_id}', name: 'admin.order.history.note.edit', methods: ['POST'], requirements: ['order_id' => '\d+', 'history_id' => '\d+'])]
    public function editNote(Request $request, int $order_id, int $history_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $order = $this->orders->findById($order_id);
        if (null === $order) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        if (!$this->tokenIsValid($request)) {
            return $this->back($request, $order_id, 'danger', 'The form has expired, please try again.');
        }

        $entry = $this->history->findNoteOfOrder($order_id, $history_id);
        if (null === $entry) {
            return $this->back($request, $order_id, 'danger', 'This note does not exist on this order.');
        }

        if (null !== $refusal = $this->refuseEdition($entry, $order)) {
            $this->adminLogger->log(
                self::RESOURCE,
                AccessManager::UPDATE,
                \sprintf('Refused edition of history note %d of order %s', $history_id, (string) $order->getRef()),
                $order_id,
            );

            return $this->back($request, $order_id, 'danger', $refusal);
        }

        $comment = $this->readComment($request);
        if (null === $comment) {
            return $this->back($request, $order_id, 'danger', $this->commentError($request));
        }

        // A correction replaces what was written: it is the same entry, at the same
        // place in the timeline, and adding a second one would read as a second note.
        $entry
            ->setComment($comment)
            ->setVisibleToCustomer($this->readVisibility($request) ? 1 : 0)
            ->save();

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf('History note %d of order %s edited', $history_id, (string) $order->getRef()),
            $order_id,
        );

        return $this->back($request, $order_id, 'success', 'The note has been updated.');
    }

    /**
     * The reason the edition is refused, or null when it is allowed. Read on the server
     * whatever the page offered: the button is hidden on the same rule, but the rule is
     * the one that holds here.
     */
    private function refuseEdition(OrderHistory $entry, Order $order): ?string
    {
        if ($this->notePolicy->isSettled($order)) {
            return 'This order is settled: its notes can no longer be edited.';
        }

        if (!$this->notePolicy->mayEdit($entry, $order, $this->currentAdminId())) {
            return 'Only the administrator who wrote a note may edit it.';
        }

        return null;
    }

    private function currentAdminId(): ?int
    {
        $adminUser = $this->securityContext->getAdminUser();

        return $adminUser instanceof Admin ? $adminUser->getId() : null;
    }

    private function tokenIsValid(Request $request): bool
    {
        try {
            $this->tokens->checkToken((string) ($request->request->get('_token') ?? $request->query->get('_token') ?? ''));
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * The submitted note, or null when it is unusable.
     */
    private function readComment(Request $request): ?string
    {
        $comment = trim((string) $request->request->get('comment', ''));

        if ('' === $comment || mb_strlen($comment) > OrderHistoryNotePolicy::MAX_COMMENT_LENGTH) {
            return null;
        }

        return $comment;
    }

    private function commentError(Request $request): string
    {
        return '' === trim((string) $request->request->get('comment', ''))
            ? 'A note cannot be empty.'
            : 'A note cannot exceed %limit% characters.';
    }

    private function readVisibility(Request $request): bool
    {
        return $request->request->getBoolean('visible_to_customer');
    }

    private function back(Request $request, int $orderId, string $type, string $messageKey): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->translator->trans(
                $messageKey,
                ['%limit%' => OrderHistoryNotePolicy::MAX_COMMENT_LENGTH],
                self::DOMAIN,
                $request->getLocale(),
            ));
        }

        return new RedirectResponse(
            $this->urls->generate(self::DETAIL_ROUTE, ['order_id' => $orderId]).self::DETAIL_ANCHOR,
        );
    }
}
