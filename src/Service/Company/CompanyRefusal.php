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

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Company\Exception\CompanyException;
use Thelia\Domain\Company\Exception\CompanyHasOrdersException;
use Thelia\Domain\Company\Exception\CustomerAlreadyInCompanyException;
use Thelia\Domain\Company\Exception\CustomerNotInCompanyException;
use Thelia\Domain\Company\Exception\GuestCannotJoinCompanyException;
use Thelia\Domain\Company\Exception\InvalidCompanyDataException;
use Thelia\Domain\Company\Exception\InvalidCompanyIdentifiersException;
use Thelia\Domain\Company\Exception\LastCompanyAdministratorException;
use Thelia\Domain\Company\Exception\UnknownCompanyException;
use Thelia\Domain\Company\Exception\UnknownCompanyRoleException;
use Thelia\Domain\Legal\CompanyIdentifierViolation;

/**
 * Words what the company rules of the core refuse, in the language of the administrator: the
 * messages of the core name an identifier and are written in English.
 */
final readonly class CompanyRefusal
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function messageOf(CompanyException $refusal): string
    {
        return match (true) {
            $refusal instanceof LastCompanyAdministratorException => $this->translator->trans('The company would be left without an administrator, or without anyone to take the addresses it shares: give the administrator role to another buyer first.'),
            $refusal instanceof GuestCannotJoinCompanyException => $this->translator->trans('A guest account cannot join a company: only an account with a password can.'),
            $refusal instanceof CustomerAlreadyInCompanyException => $this->translator->trans('This customer already belongs to a company.'),
            $refusal instanceof CustomerNotInCompanyException => $this->translator->trans('This customer does not belong to this company.'),
            $refusal instanceof CompanyHasOrdersException => $this->translator->trans('This company has orders and cannot be deleted. Suspend it instead.'),
            $refusal instanceof UnknownCompanyException => $this->translator->trans('This company does not exist.'),
            $refusal instanceof UnknownCompanyRoleException => $this->translator->trans('This role does not exist, or belongs to another company.'),
            $refusal instanceof InvalidCompanyIdentifiersException => implode(' ', array_map(
                fn (CompanyIdentifierViolation $violation): string => $this->translator->trans($violation->message, $violation->parameters),
                $refusal->violations(),
            )),
            $refusal instanceof InvalidCompanyDataException => $this->translator->trans($refusal->getMessage()),
            default => $this->translator->trans('The company could not be saved. Please try again.'),
        };
    }
}
