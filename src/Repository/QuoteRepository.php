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

namespace BackOfficeDefaultTwigBundle\Repository;

use BackOfficeDefaultTwigBundle\Service\Quote\QuoteFilters;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Clock\ClockInterface;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\Quote;
use Thelia\Model\QuoteItem;
use Thelia\Model\QuoteItemQuery;
use Thelia\Model\QuoteItemRequest;
use Thelia\Model\QuoteItemRequestQuery;
use Thelia\Model\QuoteQuery;
use Thelia\Model\QuoteRevision;
use Thelia\Model\QuoteRevisionAddress;
use Thelia\Model\QuoteRevisionAddressQuery;
use Thelia\Model\QuoteRevisionQuery;

/**
 * The reads of the quote screens. One page of the list is one query that carries the customer,
 * the company and the revision the quote stands at, and the lines of a revision with what the
 * buyer asked for are two queries, whatever the number of lines.
 */
final readonly class QuoteRepository
{
    public const PAGE_SIZE = 20;

    private const LIST_COLUMNS = [
        'Id', 'Ref', 'State', 'ValidUntil', 'CreatedAt', 'CurrentRevision', 'CustomerId', 'CompanyId',
        'CustomerFirstname', 'CustomerLastname', 'CustomerEmail', 'CustomerRef', 'CompanyName',
        'CurrencyCode', 'RevisionState', 'RevisionAuthor', 'RevisionTotalTaxed',
    ];

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function find(int $quoteId): ?Quote
    {
        return $quoteId > 0 ? QuoteQuery::create()->findPk($quoteId) : null;
    }

    /**
     * @return array{rows: list<array{id: int, ref: string, state: string, validUntil: \DateTimeImmutable|null, createdAt: \DateTimeImmutable|null, currentRevision: int, customerId: int, companyId: int|null, customerName: string, customerEmail: string, customerRef: string, companyName: string|null, currencyCode: string, totalTaxed: string|null, amountAskedByBuyer: bool}>, total: int, page: int, lastPage: int}
     */
    public function search(QuoteFilters $filters, int $page): array
    {
        $query = QuoteQuery::create()
            ->joinCustomer()
            ->joinCurrency()
            ->leftJoinCompany()
            ->leftJoinQuoteRevision()
            ->addJoinCondition('QuoteRevision', 'QuoteRevision.Number = Quote.CurrentRevision');

        $filters->applyTo($query, $this->today());

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);
        $direction = 'desc' === $filters->direction ? Criteria::DESC : Criteria::ASC;

        match ($filters->sort) {
            'ref' => $query->orderByRef($direction),
            'state' => $query->orderByState($direction),
            'valid_until' => $query->orderByValidUntil($direction),
            'total' => $query->orderBy('QuoteRevision.TotalTaxed', $direction),
            default => $query->orderByCreatedAt($direction),
        };
        $query->orderById($direction);

        $found = $query
            ->withColumn('Customer.Firstname', 'CustomerFirstname')
            ->withColumn('Customer.Lastname', 'CustomerLastname')
            ->withColumn('Customer.Email', 'CustomerEmail')
            ->withColumn('Customer.Ref', 'CustomerRef')
            ->withColumn('Company.Name', 'CompanyName')
            ->withColumn('Currency.Code', 'CurrencyCode')
            ->withColumn('QuoteRevision.State', 'RevisionState')
            ->withColumn('QuoteRevision.Author', 'RevisionAuthor')
            ->withColumn('QuoteRevision.TotalTaxed', 'RevisionTotalTaxed')
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->select(self::LIST_COLUMNS)
            ->find();

        $rows = [];
        foreach ($found as $row) {
            $name = trim($row['CustomerFirstname'].' '.$row['CustomerLastname']);
            $rows[] = [
                'id' => (int) $row['Id'],
                'ref' => (string) $row['Ref'],
                'state' => (string) $row['State'],
                'validUntil' => self::date($row['ValidUntil']),
                'createdAt' => self::date($row['CreatedAt']),
                'currentRevision' => (int) $row['CurrentRevision'],
                'customerId' => (int) $row['CustomerId'],
                'companyId' => null === $row['CompanyId'] ? null : (int) $row['CompanyId'],
                'customerName' => '' === $name ? (string) $row['CustomerEmail'] : $name,
                'customerEmail' => (string) $row['CustomerEmail'],
                'customerRef' => (string) $row['CustomerRef'],
                'companyName' => null === $row['CompanyName'] ? null : (string) $row['CompanyName'],
                'currencyCode' => (string) $row['CurrencyCode'],
                'totalTaxed' => 'sent' === $row['RevisionState'] && null !== $row['RevisionTotalTaxed'] ? (string) $row['RevisionTotalTaxed'] : null,
                'amountAskedByBuyer' => 'sent' === $row['RevisionState'] && 'customer' === $row['RevisionAuthor'],
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }

    /**
     * The revision the quote stands at: the last one the shop sent, or the first one of a quote
     * that was never sent.
     */
    public function currentRevision(Quote $quote): ?QuoteRevision
    {
        return QuoteRevisionQuery::create()
            ->filterByQuoteId((int) $quote->getId())
            ->filterByNumber((int) $quote->getCurrentRevision())
            ->findOne();
    }

    /**
     * Whether the shop is writing a revision that is not sent yet, on top of the one the quote stands at.
     */
    public function hasPendingDraft(Quote $quote): bool
    {
        return QuoteRevisionQuery::create()
            ->filterByQuoteId((int) $quote->getId())
            ->filterByState('draft')
            ->filterByNumber((int) $quote->getCurrentRevision(), Criteria::GREATER_THAN)
            ->exists();
    }

    /**
     * The revision the shop is writing, whatever its number, when there is one.
     */
    public function draftRevision(Quote $quote): ?QuoteRevision
    {
        return QuoteRevisionQuery::create()
            ->filterByQuoteId((int) $quote->getId())
            ->filterByState('draft')
            ->findOne();
    }

    public function revision(Quote $quote, int $number): ?QuoteRevision
    {
        return QuoteRevisionQuery::create()
            ->filterByQuoteId((int) $quote->getId())
            ->filterByNumber($number)
            ->findOne();
    }

    /**
     * Every revision of the quote, the latest first, in one query.
     *
     * @return list<QuoteRevision>
     */
    public function revisions(Quote $quote): array
    {
        return [...QuoteRevisionQuery::create()
            ->filterByQuoteId((int) $quote->getId())
            ->orderByNumber(Criteria::DESC)
            ->find()];
    }

    public function countRevisions(Quote $quote): int
    {
        return QuoteRevisionQuery::create()->filterByQuoteId((int) $quote->getId())->count();
    }

    /**
     * The lines of a revision, each with what the buyer asked for on it, in two queries.
     *
     * @return list<array{line: QuoteItem, request: QuoteItemRequest|null}>
     */
    public function lines(QuoteRevision $revision): array
    {
        $lines = [...QuoteItemQuery::create()
            ->filterByQuoteRevisionId((int) $revision->getId())
            ->orderByPosition()
            ->orderById()
            ->find()];

        if ([] === $lines) {
            return [];
        }

        $requests = [];
        $found = QuoteItemRequestQuery::create()
            ->filterByQuoteItemId(array_map(static fn (QuoteItem $line): int => (int) $line->getId(), $lines), Criteria::IN)
            ->find();

        foreach ($found as $request) {
            $requests[(int) $request->getQuoteItemId()] = $request;
        }

        return array_map(
            static fn (QuoteItem $line): array => ['line' => $line, 'request' => $requests[(int) $line->getId()] ?? null],
            $lines,
        );
    }

    /**
     * The addresses copied on a revision when it was sent, by kind (`invoice`, `delivery`), in one query.
     *
     * @return array<string, QuoteRevisionAddress>
     */
    public function revisionAddresses(QuoteRevision $revision): array
    {
        $addresses = [];
        foreach (QuoteRevisionAddressQuery::create()->filterByQuoteRevisionId((int) $revision->getId())->find() as $address) {
            $addresses[(string) $address->getKind()] = $address;
        }

        return $addresses;
    }

    /**
     * The order the quote became, if it did.
     */
    public function orderOf(Quote $quote): ?Order
    {
        return OrderQuery::create()->filterByQuoteId((int) $quote->getId())->findOne();
    }

    private function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return null === $value || '' === $value ? null : new \DateTimeImmutable((string) $value);
    }
}
