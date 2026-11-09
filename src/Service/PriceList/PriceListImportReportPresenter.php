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

namespace BackOfficeDefaultTwigBundle\Service\PriceList;

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Pricing\PriceList\Import\PriceListImportLineError;
use Thelia\Domain\Pricing\PriceList\Import\PriceListImportReport;

/**
 * What an import report says, in the words of the merchant: the counters, and one sentence
 * per line in error, the first SHOWN_ERRORS of them.
 */
final readonly class PriceListImportReportPresenter
{
    public const SHOWN_ERRORS = 200;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @return array{applied: bool, created: int, updated: int, errorCount: int, errors: list<array{line: int, reason: string}>, hidden: int}
     */
    public function present(PriceListImportReport $report): array
    {
        $errors = [];
        foreach (\array_slice($report->errors, 0, self::SHOWN_ERRORS) as $error) {
            $errors[] = ['line' => $error->line, 'reason' => $this->reason($error)];
        }

        return [
            'applied' => $report->applied,
            'created' => $report->created,
            'updated' => $report->updated,
            'errorCount' => $report->errorCount,
            'errors' => $errors,
            'hidden' => $report->errorCount - \count($errors),
        ];
    }

    private function reason(PriceListImportLineError $error): string
    {
        $detail = (string) $error->detail;

        return match ($error->reason) {
            PriceListImportLineError::WRONG_COLUMN_COUNT => $this->translator->trans('The line does not have as many cells as the header.'),
            PriceListImportLineError::INVALID_ENCODING => $this->translator->trans('The line is not UTF-8 text.'),
            PriceListImportLineError::NO_SALE_ELEMENT => $this->translator->trans('The line names no sale element.'),
            PriceListImportLineError::UNKNOWN_REFERENCE => $this->translator->trans('No sale element has this reference: %detail%', ['%detail%' => $detail]),
            PriceListImportLineError::AMBIGUOUS_REFERENCE => $this->translator->trans('Several sale elements have this reference: %detail%', ['%detail%' => $detail]),
            PriceListImportLineError::UNKNOWN_SALE_ELEMENT_ID => $this->translator->trans('No sale element has this id: %detail%', ['%detail%' => $detail]),
            PriceListImportLineError::REFERENCE_AND_ID_DISAGREE => $this->translator->trans('The reference and the id name two different sale elements.'),
            PriceListImportLineError::INVALID_MINIMUM_QUANTITY => $this->translator->trans('The minimum quantity is not a whole number, 1 or more: %detail%', ['%detail%' => $detail]),
            PriceListImportLineError::TWO_AMOUNTS => $this->translator->trans('The line has a price and a discount percentage: it needs one of them.'),
            PriceListImportLineError::NO_AMOUNT => $this->translator->trans('The line has neither a price nor a discount percentage.'),
            PriceListImportLineError::INVALID_PRICE => $this->translator->trans('The price is not valid: %detail%', ['%detail%' => $detail]),
            PriceListImportLineError::INVALID_DISCOUNT_PERCENT => $this->translator->trans('The discount percentage is not valid (more than 0, 100 at most): %detail%', ['%detail%' => $detail]),
            PriceListImportLineError::DUPLICATE_IN_FILE => $this->translator->trans('The same sale element and minimum quantity already appear on line %detail%.', ['%detail%' => $detail]),
            default => $this->translator->trans('The line is refused.'),
        };
    }
}
