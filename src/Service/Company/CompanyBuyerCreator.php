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

namespace BackOfficeDefaultTwigBundle\Service\Company;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateMinimalEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Company\CompanyFacade;
use Thelia\Domain\Company\Exception\UnknownCompanyRoleException;
use Thelia\Domain\Company\Service\CompanyLimits;
use Thelia\Domain\Company\Service\CompanyRoleResolver;
use Thelia\Model\Company;
use Thelia\Model\CustomerQuery;
use Thelia\Model\CustomerTitleQuery;
use Thelia\Tools\Password;

/**
 * Creates the account of a new buyer and attaches it to the company. Everything the attachment
 * would refuse is checked before the account is written, so a refusal leaves no account behind.
 */
final readonly class CompanyBuyerCreator
{
    public function __construct(
        private EventDispatcherInterface $events,
        private CompanyFacade $companies,
        private CompanyRoleResolver $roles,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws CompanyRefusedException
     */
    public function create(Company $company, string $firstname, string $lastname, string $email, string $roleCode, ?string $orderLimit, ?string $periodLimit, ?string $period): int
    {
        $firstname = trim($firstname);
        $lastname = trim($lastname);
        $email = trim($email);

        if ('' === $firstname || '' === $lastname || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new CompanyRefusedException($this->translator->trans('A buyer needs a first name, a last name and a valid e-mail address.'));
        }

        CompanyLimits::assertValid($orderLimit, $periodLimit, $period);

        if (null === $this->roles->resolve((int) $company->getId(), $roleCode)) {
            throw UnknownCompanyRoleException::forCompany($roleCode, (int) $company->getId());
        }

        if (CustomerQuery::create()->filterByEmail($email)->filterByIsGuest(0)->exists()) {
            throw new CompanyRefusedException($this->translator->trans('An account already uses this e-mail address: attach it with its e-mail address instead of creating it.'));
        }

        $title = CustomerTitleQuery::create()->filterByByDefault(1)->findOne() ?? CustomerTitleQuery::create()->findOne();
        if (null === $title) {
            throw new CompanyRefusedException($this->translator->trans('The shop has no customer title: create one before creating a buyer.'));
        }

        $event = (new CustomerCreateOrUpdateMinimalEvent())
            ->setTitle((int) $title->getId())
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setEmail($email)
            ->setPassword(Password::generateRandom())
            ->setEnabled(true);
        $this->events->dispatch($event, TheliaEvents::CREATE_CUSTOMER_MINIMAL);

        $customer = $event->getCustomer();
        if (null === $customer) {
            throw new \RuntimeException('The buyer account was not created.');
        }

        $this->companies->attach($company, $customer, $roleCode, $orderLimit, $periodLimit, $period);

        return (int) $customer->getId();
    }
}
