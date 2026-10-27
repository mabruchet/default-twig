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

namespace BackOfficeDefaultTwigBundle\Service\CustomerGroup;

use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The customer groups a picker posts next to a form (`sale_customer_groups[]`,
 * `rule_customer_groups[]`): the ids of the groups that exist, once each.
 *
 * The form that refuses an audience without any group and the factory that sends the ids to
 * the core both read them here, so they cannot disagree: a post that names only groups
 * deleted since the page was drawn names no group at all.
 */
final readonly class PostedCustomerGroupIds
{
    public function __construct(
        private RequestStack $requestStack,
        private CustomerGroupRepository $customerGroups,
    ) {
    }

    /**
     * @return list<int>
     */
    public function fromRequest(Request $request, string $field): array
    {
        $raw = $request->request->all($field);
        $ids = array_map('intval', \is_array($raw) ? $raw : explode(',', (string) $raw));

        return $this->customerGroups->existingIds(array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0))));
    }

    /**
     * @return list<int>
     */
    public function fromCurrentRequest(string $field): array
    {
        $request = $this->requestStack->getMainRequest();

        return null === $request ? [] : $this->fromRequest($request, $field);
    }
}
