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

namespace BackOfficeDefaultTwigBundle\Service\CatalogPriceRule;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Model\CatalogPriceRule;

/**
 * The parts of the edit screen that are not Symfony form fields: the lists the
 * pickers post next to the form.
 *
 *   criteria[category][]     the categories, brands, templates, feature values and
 *   criteria[brand][] ...    attribute values named by the scope
 *   products[]               the named products
 *   effect_value[<currency>] the amount off or fixed price, per currency
 *   rule_customers[]         the customers a reserved rule prices for
 *   rule_customer_groups[]   the customer groups a rule reserved for groups prices for
 */
final readonly class CatalogPriceRuleRequestReader
{
    public const CRITERIA_FIELD = 'criteria';

    public const PRODUCTS_FIELD = 'products';

    public const EFFECT_VALUES_FIELD = 'effect_value';

    public const CUSTOMERS_FIELD = 'rule_customers';

    public const CUSTOMER_GROUPS_FIELD = 'rule_customer_groups';

    public function __construct(private RequestStack $requestStack)
    {
    }

    /**
     * @return array<string, list<int>> criterion type => target ids, the named products included
     */
    public function criteriaFromRequest(Request $request): array
    {
        $criteria = [];

        foreach ((array) $request->request->all(self::CRITERIA_FIELD) as $type => $targetIds) {
            $ids = $this->ids($targetIds);

            if ([] !== $ids) {
                $criteria[(string) $type] = $ids;
            }
        }

        $products = $this->ids($request->request->all(self::PRODUCTS_FIELD));

        if ([] !== $products) {
            $criteria[CatalogPriceRule::CRITERION_PRODUCT] = $products;
        }

        return $criteria;
    }

    /**
     * @return array<int, float> currency id => value; blank inputs are left out
     */
    public function effectValuesFromRequest(Request $request): array
    {
        $values = [];

        foreach ((array) $request->request->all(self::EFFECT_VALUES_FIELD) as $currencyId => $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $values[(int) $currencyId] = (float) $value;
        }

        return $values;
    }

    /**
     * @return array<int, float>
     */
    public function effectValuesFromCurrentRequest(): array
    {
        $request = $this->requestStack->getMainRequest();

        return null === $request ? [] : $this->effectValuesFromRequest($request);
    }

    /**
     * @return list<int>
     */
    public function customerIdsFromRequest(Request $request): array
    {
        return $this->ids($request->request->all(self::CUSTOMERS_FIELD));
    }

    /**
     * @return list<int>
     */
    public function customerIdsFromCurrentRequest(): array
    {
        $request = $this->requestStack->getMainRequest();

        return null === $request ? [] : $this->customerIdsFromRequest($request);
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $raw): array
    {
        $list = \is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = array_map('intval', $list);

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
