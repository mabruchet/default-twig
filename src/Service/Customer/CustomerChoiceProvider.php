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

use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Customer;

/**
 * Options for the shared customer picker (coupon conditions, reserved sales).
 *
 * A shop can hold hundreds of thousands of customers, so the list is deliberately
 * short: whoever is already selected - who must stay visible or the save would drop
 * them - plus the latest registrations. The picker's search box narrows that list
 * client-side.
 */
final readonly class CustomerChoiceProvider
{
    public const LATEST_LIMIT = 50;

    public function __construct(
        private CustomerRepository $customers,
        private AdminAccessChecker $access,
    ) {
    }

    /**
     * @param list<int> $selectedIds
     *
     * @return list<array{id: int, label: string}>
     */
    public function choices(array $selectedIds): array
    {
        $withEmail = $this->labelsIncludeEmail();
        $choices = [];
        $seen = [];

        foreach ([$this->customers->findByIds($selectedIds), $this->customers->findLatest(self::LATEST_LIMIT)] as $rows) {
            foreach ($rows as $customer) {
                $id = (int) $customer->getId();
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $choices[] = ['id' => $id, 'label' => $this->label($customer, $withEmail)];
            }
        }

        usort($choices, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $choices;
    }

    /**
     * Whether the options carry an e-mail address, so the screens can say what their
     * filter actually searches on.
     *
     * An e-mail address is personal data the customer resource guards. The coupon
     * screen only asks for the coupon right, so an admin who may manage coupons but
     * not view customers gets a picker that names them without spelling out how to
     * reach them.
     */
    public function labelsIncludeEmail(): bool
    {
        return $this->access->canView(AdminResources::CUSTOMER);
    }

    /**
     * The reference always appears because the picker's search box filters on the
     * option text: without it, searching for a reference finds nothing. The e-mail
     * address follows the same rule, for whoever is allowed to see it.
     */
    private function label(Customer $customer, bool $withEmail): string
    {
        $name = trim(\sprintf('%s %s', (string) $customer->getLastname(), (string) $customer->getFirstname()));
        $label = trim(\sprintf('%s (%s)', $name, (string) $customer->getRef()));

        return $withEmail ? trim($label.' '.(string) $customer->getEmail()) : $label;
    }
}
