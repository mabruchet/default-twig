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

namespace BackOfficeDefaultTwigBundle\Controller\Pricing;

use BackOfficeDefaultTwigBundle\Repository\PriceListAssignmentRepository;
use BackOfficeDefaultTwigBundle\Repository\PriceListRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListRefusedException;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListWriteGate;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListWriter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\PriceList;

/**
 * Assigns a price list to customer groups (the whole set at once) or to customers (one at a
 * time, found by a bounded search on the sheet), and removes an assignment. The sheet posts a
 * token with every write.
 */
#[Route('/admin/pricing/price-lists/{price_list_id}/assignments', name: 'admin.pricing.price_list.assignment_', requirements: ['price_list_id' => '\d+'], methods: ['POST'])]
final class PriceListAssignmentController
{
    public const GROUPS_LABEL = 'Price list assignment to groups';
    public const CUSTOMER_LABEL = 'Price list assignment to a customer';
    public const REMOVAL_LABEL = 'Price list assignment removal';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly PriceListRepository $priceLists,
        private readonly PriceListAssignmentRepository $assignments,
        private readonly PriceListWriter $writer,
        private readonly PriceListWriteGate $gate,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/groups', name: 'groups')]
    public function groups(int $price_list_id, Request $request): Response
    {
        $ids = array_values(array_unique(array_map('intval', (array) $request->request->all('group_ids'))));

        return $this->write($price_list_id, $request, self::GROUPS_LABEL, 'Assignments saved.', function (PriceList $priceList) use ($ids): string {
            $this->writer->syncGroups($priceList, $ids);

            return \sprintf('Customer groups of the price list %s set', $priceList->getCode());
        });
    }

    #[Route('/customers/add', name: 'customer_add')]
    public function addCustomer(int $price_list_id, Request $request): Response
    {
        // The customer is picked among the customers, as on the assortments: the right to see them is needed.
        if ($denied = $this->access->check(AdminResources::CUSTOMER, [], AccessManager::VIEW)) {
            return $denied;
        }

        $customerId = (int) $request->request->get('customer_id', 0);

        return $this->write($price_list_id, $request, self::CUSTOMER_LABEL, 'Customer added.', function (PriceList $priceList) use ($customerId): string {
            $this->writer->assignCustomer($priceList, $customerId);

            return \sprintf('Price list %s assigned to the customer %d', $priceList->getCode(), $customerId);
        });
    }

    #[Route('/{assignment_id}/delete', name: 'delete', requirements: ['assignment_id' => '\d+'])]
    public function delete(int $price_list_id, int $assignment_id, Request $request): Response
    {
        return $this->write($price_list_id, $request, self::REMOVAL_LABEL, 'Assignment removed.', function (PriceList $priceList) use ($assignment_id): string {
            $assignment = $this->assignments->findInList((int) $priceList->getId(), $assignment_id)
                ?? throw new PriceListRefusedException($this->translator->trans('This assignment does not exist.'));

            $this->writer->unassign($assignment);

            return \sprintf('Assignment %d of the price list %s removed', $assignment_id, $priceList->getCode());
        });
    }

    /**
     * @param callable(PriceList): string $write
     */
    private function write(int $priceListId, Request $request, string $label, string $success, callable $write): Response
    {
        $back = $this->urls->generate('admin.pricing.price_list.view', ['price_list_id' => $priceListId, 'tab' => 'assignments']);

        return $this->gate->run($request, AccessManager::UPDATE, $label, $back, $priceListId, $success, function () use ($priceListId, $write): string {
            $priceList = $this->priceLists->find($priceListId)
                ?? throw new PriceListRefusedException($this->translator->trans('This price list does not exist.'));

            return $write($priceList);
        });
    }
}
