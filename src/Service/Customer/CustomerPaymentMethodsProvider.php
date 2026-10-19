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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\CustomerPaymentMethodQuery;

/**
 * The payment methods a customer asked the shop to keep, as the customer sheet lists them:
 * read only, and without the token, which never leaves the model. They are read in one
 * query, the title of the module included; a method whose module has no title in the
 * locale falls back to the module code. An administrator who may not see them gets none,
 * and the query is not run.
 */
final readonly class CustomerPaymentMethodsProvider
{
    public function __construct(
        private AdminAccessChecker $access,
    ) {
    }

    public function canView(): bool
    {
        // The table belongs to a core that ships stored payment methods: an older core has none.
        return class_exists(CustomerPaymentMethodQuery::class)
            && $this->access->canView(AdminResources::CUSTOMER_PAYMENT_METHOD);
    }

    /**
     * @return list<array{label: string, module: string, expires_at: ?string, is_expired: bool, is_default: bool}>
     */
    public function forCustomer(int $customerId, string $locale, \DateTimeImmutable $today): array
    {
        if (!$this->canView()) {
            return [];
        }

        // Only the columns the sheet shows are selected: the token is never loaded.
        $methods = CustomerPaymentMethodQuery::create()
            ->filterByCustomerId($customerId)
            ->usePaymentModuleQuery(null, Criteria::LEFT_JOIN)
                ->withColumn('Module.Code', 'module_code')
                ->useI18nQuery($locale, null, Criteria::LEFT_JOIN)
                    ->withColumn('ModuleI18n.Title', 'module_title')
                ->endUse()
            ->endUse()
            ->select(['Label', 'ExpiresAt', 'IsDefault', 'module_code', 'module_title'])
            ->orderByIsDefault(Criteria::DESC)
            ->orderById()
            ->find();

        $day = $today->format('Y-m-d');
        $lines = [];
        foreach ($methods as $method) {
            $expiresAt = \is_string($method['ExpiresAt']) && $method['ExpiresAt'] !== '' ? substr($method['ExpiresAt'], 0, 10) : null;
            $lines[] = [
                'label' => (string) $method['Label'],
                'module' => (string) ($method['module_title'] ?: $method['module_code']),
                'expires_at' => $expiresAt,
                // The last day is still valid: a method expires the day after.
                'is_expired' => $expiresAt !== null && $expiresAt < $day,
                'is_default' => (bool) $method['IsDefault'],
            ];
        }

        return $lines;
    }
}
