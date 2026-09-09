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

namespace BackOfficeDefaultTwigBundle\Service\Sale;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Reads the customers a reserved sale targets from the request.
 *
 * The picker has a variable cardinality, so it posts as a plain `sale_customers[]`
 * outside the Symfony form - the same contract as `products[]` on this screen. Both
 * the form (which refuses a reserved sale with an empty selection) and the event
 * factory (which sends the ids to the core) read it through here, so they can never
 * disagree on what was selected.
 */
final readonly class SaleCustomerIdsReader
{
    public const FIELD = 'sale_customers';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<int>
     */
    public function fromRequest(Request $request): array
    {
        $ids = array_map('intval', (array) $request->request->all(self::FIELD));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<int>
     */
    public function fromCurrentRequest(): array
    {
        $request = $this->requestStack->getMainRequest();

        return $request === null ? [] : $this->fromRequest($request);
    }
}
