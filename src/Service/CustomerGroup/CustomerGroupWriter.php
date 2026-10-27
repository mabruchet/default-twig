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
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Customer\Exception\DefaultCustomerGroupHasNoRegistrationLinkException;
use Thelia\Domain\Customer\Exception\DefaultCustomerGroupProtectedException;
use Thelia\Domain\Customer\Service\CustomerGroupLinks;
use Thelia\Domain\Customer\Service\CustomerGroupRegistration;
use Thelia\Model\CustomerGroup;
use Thelia\Model\Map\CustomerGroupTableMap;

/**
 * Writes the customer groups and the links to their payment modules and categories.
 * Every write goes through the models, which keep exactly one default group.
 */
final readonly class CustomerGroupWriter
{
    private const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';

    public function __construct(
        private CustomerGroupRepository $customerGroups,
        private CustomerGroupLinks $links,
        private CustomerGroupRegistration $registration,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<int> $paymentModuleIds
     * @param list<int> $categoryIds
     *
     * @throws CustomerGroupRefusedException when the code cannot be used or the default flag cannot be changed
     */
    public function create(string $code, string $locale, string $title, ?string $description, float $discount, bool $displayTaxedPrice, bool $isDefault, array $paymentModuleIds, array $categoryIds): CustomerGroup
    {
        $customerGroup = (new CustomerGroup())->setPosition($this->customerGroups->lastPosition() + 1);

        return $this->write($customerGroup, $code, $locale, $title, $description, $discount, $displayTaxedPrice, $isDefault, $paymentModuleIds, $categoryIds);
    }

    /**
     * @param list<int> $paymentModuleIds
     * @param list<int> $categoryIds
     *
     * @throws CustomerGroupRefusedException when the code cannot be used or the default flag cannot be changed
     */
    public function update(CustomerGroup $customerGroup, string $code, string $locale, string $title, ?string $description, float $discount, bool $displayTaxedPrice, bool $isDefault, array $paymentModuleIds, array $categoryIds): CustomerGroup
    {
        return $this->write($customerGroup, $code, $locale, $title, $description, $discount, $displayTaxedPrice, $isDefault, $paymentModuleIds, $categoryIds);
    }

    /**
     * The customers of the group move to the default group, which cannot be deleted.
     *
     * @throws CustomerGroupRefusedException when the group is the default one
     */
    public function delete(CustomerGroup $customerGroup): void
    {
        try {
            $customerGroup->delete();
        } catch (DefaultCustomerGroupProtectedException) {
            throw new CustomerGroupRefusedException($this->translator->trans('The default customer group cannot be deleted. Make another group the default first.'));
        }
    }

    /**
     * Opens the registration link of the group, or leaves it as it is when it is open.
     *
     * @throws CustomerGroupRefusedException when the group is the default one
     */
    public function openRegistration(CustomerGroup $customerGroup): void
    {
        $this->refusingTheDefaultGroup(fn () => $this->registration->open($customerGroup));
    }

    /**
     * Replaces the token of the group: the links already handed out stop working.
     *
     * @throws CustomerGroupRefusedException when the group is the default one
     */
    public function regenerateRegistration(CustomerGroup $customerGroup): void
    {
        $this->refusingTheDefaultGroup(fn () => $this->registration->regenerate($customerGroup));
    }

    public function closeRegistration(CustomerGroup $customerGroup): void
    {
        $this->registration->close($customerGroup);
    }

    /**
     * Lifts the restriction of one list: the list is emptied and the group sees everything
     * (categories) or can pay with every module (payment modules).
     */
    public function liftRestriction(CustomerGroup $customerGroup, bool $categories): void
    {
        $this->inTransaction(function (ConnectionInterface $connection) use ($customerGroup, $categories): void {
            $this->links->replace($customerGroup, $categories ? [] : null, $categories ? null : [], $connection);
        });
    }

    /**
     * Moves a group to a 1-based position and renumbers the others, so positions stay dense.
     */
    public function moveTo(CustomerGroup $customerGroup, int $position): void
    {
        $siblings = $this->customerGroups->findSiblingsOf($customerGroup);
        $position = max(1, min($position, \count($siblings) + 1));
        array_splice($siblings, $position - 1, 0, [$customerGroup]);

        $this->inTransaction(static function (ConnectionInterface $connection) use ($siblings): void {
            foreach ($siblings as $index => $sibling) {
                $sibling->setPosition($index + 1)->save($connection);
            }
        });
    }

    /**
     * @param list<int> $paymentModuleIds
     * @param list<int> $categoryIds
     */
    private function write(CustomerGroup $customerGroup, string $code, string $locale, string $title, ?string $description, float $discount, bool $displayTaxedPrice, bool $isDefault, array $paymentModuleIds, array $categoryIds): CustomerGroup
    {
        $code = trim($code);
        $exceptId = $customerGroup->isNew() ? null : (int) $customerGroup->getId();

        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new CustomerGroupRefusedException($this->translator->trans('The code is made of letters, digits, dots, dashes and underscores, 64 characters at most.'));
        }

        if ($this->customerGroups->isCodeTaken($code, $exceptId)) {
            throw new CustomerGroupRefusedException($this->translator->trans('Another customer group already uses the code %code%.', ['%code%' => $code]));
        }

        $description = trim((string) $description);

        try {
            $this->inTransaction(function (ConnectionInterface $connection) use ($customerGroup, $code, $locale, $title, $description, $discount, $displayTaxedPrice, $isDefault, $paymentModuleIds, $categoryIds): void {
                $customerGroup
                    ->setCode($code)
                    ->setDiscount(number_format($discount, 6, '.', ''))
                    ->setDisplayTaxedPrice($displayTaxedPrice ? 1 : 0)
                    ->setIsDefault($isDefault ? 1 : 0)
                    ->setLocale($locale)
                    ->setTitle(trim($title))
                    ->setDescription('' === $description ? null : $description)
                    ->save($connection);

                $groupId = (int) $customerGroup->getId();

                // The screen always posts both lists. A list that comes back as it is stored is
                // not a decision: leaving the flag alone keeps a group whose listed categories
                // were all deleted closed, instead of opening it by saving another field.
                $this->links->replace(
                    $customerGroup,
                    $this->unlessUnchanged($categoryIds, $this->customerGroups->categoryIdsOf($groupId)),
                    $this->unlessUnchanged($this->paymentModuleIdsToLink($groupId, $paymentModuleIds), $this->customerGroups->paymentModuleIdsOf($groupId)),
                    $connection,
                );
            });
        } catch (DefaultCustomerGroupProtectedException) {
            throw new CustomerGroupRefusedException($this->translator->trans('The default customer group cannot stop being the default. Make another group the default instead.'));
        }

        return $customerGroup;
    }

    /**
     * @param list<int> $posted
     * @param list<int> $stored
     *
     * @return list<int>|null null when the posted ids are the stored ones, in any order
     */
    private function unlessUnchanged(array $posted, array $stored): ?array
    {
        $posted = array_values(array_unique($posted));
        sort($posted);
        sort($stored);

        return $posted === $stored ? null : $posted;
    }

    /**
     * @param callable(): mixed $write
     */
    private function refusingTheDefaultGroup(callable $write): void
    {
        try {
            $write();
        } catch (DefaultCustomerGroupHasNoRegistrationLinkException) {
            throw new CustomerGroupRefusedException($this->translator->trans('The default customer group has no registration link: every account that registers without one joins it.'));
        }
    }

    /**
     * The form only offers the active payment modules: a link to an inactive one was not on
     * screen, so its absence from the post is no decision to drop it. Any posted id that is
     * no active payment module is not one.
     *
     * @param list<int> $postedIds
     *
     * @return list<int> the active modules posted, and the inactive ones the group is already linked to
     */
    private function paymentModuleIdsToLink(int $groupId, array $postedIds): array
    {
        $active = $this->customerGroups->activePaymentModuleIds();
        $inactiveLinked = array_diff($this->customerGroups->paymentModuleIdsOf($groupId), $active);

        return array_values(array_unique([...array_intersect($postedIds, $active), ...$inactiveLinked]));
    }

    /**
     * @param callable(ConnectionInterface): void $write
     */
    private function inTransaction(callable $write): void
    {
        $connection = Propel::getConnection(CustomerGroupTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $write($connection);
            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }
}
