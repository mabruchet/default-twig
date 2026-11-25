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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\QuoteRepository;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteFilters;
use BackOfficeDefaultTwigBundle\Tests\Support\BuildsQuotes;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Domain\Company\CompanyFacade;
use Thelia\Domain\Company\DTO\CompanyData;
use Thelia\Model\Company;
use Thelia\Model\Customer;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The list of quotes as the repository answers it: the state is the one a buyer sees (a quote
 * waiting for the buyer past its last valid day is expired), the amount is the one of the
 * revision the quote stands at, and one page costs the same queries whatever it holds.
 */
final class QuoteListTest extends IntegrationTestCase
{
    use BuildsQuotes;

    private QuoteRepository $quotes;

    private FixtureFactory $factory;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotes = new QuoteRepository(new MockClock('2026-12-01 09:00:00'));
        $this->factory = $this->createFixtureFactory();
        $this->company = $this->aCompany('Acme Quotes '.bin2hex(random_bytes(3)));
    }

    public function testAQuoteWaitingForTheBuyerPastItsLastValidDayIsExpired(): void
    {
        $customer = $this->customer();
        $overdue = $this->aQuote($customer, ['state' => 'quoted', 'validUntil' => '2026-11-30', 'company' => $this->company]);
        $today = $this->aQuote($customer, ['state' => 'quoted', 'validUntil' => '2026-12-01', 'company' => $this->company]);
        $open = $this->aQuote($customer, ['state' => 'quoted', 'validUntil' => null, 'company' => $this->company]);
        $written = $this->aQuote($customer, ['state' => 'expired', 'validUntil' => '2026-11-01', 'company' => $this->company]);
        $accepted = $this->aQuote($customer, ['state' => 'accepted', 'validUntil' => '2026-11-01', 'company' => $this->company]);

        $expired = $this->idsOf(['state' => 'expired']);
        self::assertEqualsCanonicalizing([$overdue->getId(), $written->getId()], $expired, 'The overdue quote reads expired, as the one the console wrote does; the accepted one past its day is not.');

        $quoted = $this->idsOf(['state' => 'quoted']);
        self::assertEqualsCanonicalizing([$today->getId(), $open->getId()], $quoted, 'The last valid day itself still counts, and a quote without a last day never expires.');

        self::assertSame([$accepted->getId()], $this->idsOf(['state' => 'accepted']));
    }

    public function testTheCompanyFilterKeepsTheQuotesOfThatCompanyOnly(): void
    {
        $customer = $this->customer();
        $other = $this->aCompany('Globex Quotes '.bin2hex(random_bytes(3)));
        $mine = $this->aQuote($customer, ['company' => $this->company]);
        $this->aQuote($customer, ['company' => $other]);
        $this->aQuote($customer, ['company' => null]);

        self::assertSame([$mine->getId()], $this->idsOf([]));
    }

    public function testTheSearchFindsTheReferenceAndTheCustomerAndTakesItsWildcardsLiterally(): void
    {
        $marker = 'Zq'.bin2hex(random_bytes(3));
        $alice = $this->customer($marker.'alice@example.test', 'Alice', $marker.'Martin');
        $bob = $this->customer($marker.'bob@example.test', 'Bob', 'Durand');
        $byRef = $this->aQuote($bob, ['ref' => 'QUO-'.$marker, 'company' => $this->company]);
        $byLastname = $this->aQuote($alice, ['company' => $this->company]);
        $byEmail = $this->aQuote($bob, ['company' => $this->company]);

        $found = $this->idsOf(['q' => $marker], false);
        self::assertEqualsCanonicalizing([$byRef->getId(), $byLastname->getId(), $byEmail->getId()], $found, 'The reference of the quote, then the name and the e-mail address of the customer.');

        self::assertSame([$byLastname->getId()], $this->idsOf(['q' => $marker.'Martin']));
        self::assertSame([], $this->idsOf(['q' => '%']), 'A percent sign is a character of the search, not a wildcard.');
    }

    public function testTheAmountIsTheOneOfTheRevisionTheQuoteStandsAt(): void
    {
        $customer = $this->customer();
        $small = $this->aQuote($customer, ['total' => '500.000000', 'company' => $this->company]);
        $large = $this->aQuote($customer, ['total' => '1620.000000', 'company' => $this->company, 'currentRevision' => 2]);
        $draft = $this->aQuote($customer, ['state' => 'draft', 'total' => '9999.000000', 'revisionState' => 'draft', 'company' => $this->company]);

        self::assertSame([$large->getId()], $this->idsOf(['min_total' => '1000']), 'The first revision of the large quote costs 1, its current one 1620.');
        self::assertSame([$small->getId()], $this->idsOf(['max_total' => '1000']), 'A draft that was never sent has no amount to filter on.');
        self::assertSame([], $this->idsOf(['min_total' => '501', 'max_total' => '1000']));
        self::assertEqualsCanonicalizing([$small->getId(), $large->getId()], $this->idsOf(['min_total' => '500', 'max_total' => '1620']));
        self::assertNotContains($draft->getId(), $this->idsOf(['min_total' => '0']));
    }

    public function testTheValidityRangeKeepsTheQuotesValidWithinIt(): void
    {
        $customer = $this->customer();
        $early = $this->aQuote($customer, ['validUntil' => '2026-12-10', 'company' => $this->company]);
        $late = $this->aQuote($customer, ['validUntil' => '2027-01-20', 'company' => $this->company]);
        $this->aQuote($customer, ['validUntil' => null, 'company' => $this->company]);

        self::assertSame([$early->getId()], $this->idsOf(['valid_to' => '2026-12-31']));
        self::assertSame([$late->getId()], $this->idsOf(['valid_from' => '2027-01-01']));
        self::assertEqualsCanonicalizing([$early->getId(), $late->getId()], $this->idsOf(['valid_from' => '2026-12-10', 'valid_to' => '2027-01-20']), 'Both ends are included.');
    }

    public function testTheFiltersAddUp(): void
    {
        $customer = $this->customer();
        $match = $this->aQuote($customer, ['state' => 'countered', 'total' => '800.000000', 'company' => $this->company]);
        $this->aQuote($customer, ['state' => 'countered', 'total' => '100.000000', 'company' => $this->company]);
        $this->aQuote($customer, ['state' => 'quoted', 'total' => '800.000000', 'company' => $this->company]);

        self::assertSame([$match->getId()], $this->idsOf(['state' => 'countered', 'min_total' => '500']));
    }

    public function testTheSortsFollowTheAmountTheReferenceAndTheValidity(): void
    {
        $customer = $this->customer();
        $a = $this->aQuote($customer, ['ref' => 'QUO-S-B', 'total' => '300.000000', 'validUntil' => '2026-12-20', 'company' => $this->company]);
        $b = $this->aQuote($customer, ['ref' => 'QUO-S-C', 'total' => '100.000000', 'validUntil' => '2026-12-05', 'company' => $this->company]);
        $c = $this->aQuote($customer, ['ref' => 'QUO-S-A', 'total' => '200.000000', 'validUntil' => '2026-12-10', 'company' => $this->company]);

        self::assertSame([$b->getId(), $c->getId(), $a->getId()], $this->idsOf(['order' => 'total', 'direction' => 'asc']));
        self::assertSame([$a->getId(), $c->getId(), $b->getId()], $this->idsOf(['order' => 'total', 'direction' => 'desc']));
        self::assertSame([$c->getId(), $a->getId(), $b->getId()], $this->idsOf(['order' => 'ref', 'direction' => 'asc']));
        self::assertSame([$b->getId(), $c->getId(), $a->getId()], $this->idsOf(['order' => 'valid_until', 'direction' => 'asc']));
    }

    public function testTheRowsCarryTheCustomerTheCompanyTheAmountAndTheCurrency(): void
    {
        $customer = $this->customer('row@example.test', 'Rowena', 'Rowson');
        $quote = $this->aQuote($customer, ['total' => '1620.000000', 'validUntil' => '2026-12-31', 'company' => $this->company]);

        $row = $this->search(['company_id' => (string) $this->company->getId()])['rows'][0];

        self::assertSame((int) $quote->getId(), $row['id']);
        self::assertSame('Rowena Rowson', $row['customerName']);
        self::assertSame('row@example.test', $row['customerEmail']);
        self::assertSame((string) $this->company->getName(), $row['companyName']);
        self::assertSame('1620.000000', $row['totalTaxed']);
        self::assertSame('2026-12-31', $row['validUntil']?->format('Y-m-d'));
        self::assertNotSame('', $row['currencyCode']);
    }

    public function testAPageIsPaginatedInTheDatabaseAndCostsTheSameQueriesWhateverItHolds(): void
    {
        $customer = $this->customer();
        for ($i = 0; $i < 25; ++$i) {
            $this->aQuote($customer, ['ref' => \sprintf('QUO-P-%02d', $i), 'total' => '10.000000', 'company' => $this->company]);
        }

        $filters = ['company_id' => (string) $this->company->getId(), 'order' => 'ref', 'direction' => 'asc'];
        $first = $this->search($filters);
        $second = $this->search($filters, 2);
        $beyond = $this->search($filters, 9);

        self::assertSame(25, $first['total']);
        self::assertCount(QuoteRepository::PAGE_SIZE, $first['rows']);
        self::assertCount(5, $second['rows']);
        self::assertSame(2, $beyond['page'], 'A page past the end falls back on the last one.');
        self::assertSame('QUO-P-20', $second['rows'][0]['ref']);

        $oneRow = $this->search($filters + ['q' => 'QUO-P-03']);
        self::assertCount(1, $oneRow['rows']);

        $queriesFull = QueryCounter::count(fn (): array => $this->search($filters));
        $queriesOne = QueryCounter::count(fn (): array => $this->search($filters + ['q' => 'QUO-P-03']));

        self::assertSame($queriesOne, $queriesFull, 'The customer, the company and the amount come with the page: no query per row.');
        self::assertLessThanOrEqual(3, $queriesFull, 'The count and the page.');
    }

    public function testTheLinesOfARevisionComeWithTheirRequestsInTwoQueries(): void
    {
        $customer = $this->customer();
        $quote = $this->aQuote($customer, ['company' => $this->company]);
        $revision = $this->aRevisionOf($quote, 1);
        $first = $this->aLine($revision, 'Line one', 10.0, '100', '90', null, 1);
        $second = $this->aLine($revision, 'Line two', 4.0, '50', '45', null, 2);
        $this->aLine($revision, 'Line three', 1.0, '20', '20', null, 3);
        $this->aCounterOffer($first, null, '85.000000');

        $lines = [];
        $queries = QueryCounter::count(function () use ($revision, &$lines): void {
            $lines = $this->quotes->lines($revision);
        });

        self::assertSame(2, $queries, 'The lines, then what the buyer asked for on all of them.');
        self::assertSame(['Line one', 'Line two', 'Line three'], array_map(static fn (array $line): string => (string) $line['line']->getTitle(), $lines));
        self::assertSame('85.000000', $lines[0]['request']?->getPrice());
        self::assertNull($lines[1]['request']);
        self::assertSame((int) $second->getId(), (int) $lines[1]['line']->getId());
    }

    public function testTheRevisionTheQuoteStandsAtIsTheLastSentOneNotThePendingDraft(): void
    {
        $customer = $this->customer();
        $quote = $this->aQuote($customer, ['currentRevision' => 2, 'state' => 'countered', 'company' => $this->company]);
        self::assertSame(2, $this->quotes->currentRevision($quote)?->getNumber());
        self::assertFalse($this->quotes->hasPendingDraft($quote));

        (new \Thelia\Model\QuoteRevision())->setQuoteId($quote->getId())->setNumber(3)->setAuthor('admin')->setState('draft')->setDiscount('0')->setPostage('0')->save($this->getPropelConnection());

        self::assertTrue($this->quotes->hasPendingDraft($quote));
        self::assertSame(2, $this->quotes->currentRevision($quote)?->getNumber());
        self::assertSame(3, $this->quotes->countRevisions($quote));
    }

    /**
     * @param array<string, string> $query the filters of the request
     *
     * @return list<int> the ids of the first page, for the company of the test unless the query names none
     */
    private function idsOf(array $query, bool $ofTheCompany = true): array
    {
        if ($ofTheCompany) {
            $query += ['company_id' => (string) $this->company->getId()];
        }

        return array_map(static fn (array $row): int => $row['id'], $this->search($query)['rows']);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, lastPage: int}
     */
    private function search(array $query, int $page = 1): array
    {
        return $this->quotes->search(QuoteFilters::fromRequest(new Request($query)), $page);
    }

    private function customer(?string $email = null, string $firstname = 'John', string $lastname = 'Doe'): Customer
    {
        return $this->factory->customer($this->factory->customerTitle(), ['firstname' => $firstname, 'lastname' => $lastname] + (null === $email ? [] : ['email' => $email]));
    }

    private function aCompany(string $name): Company
    {
        $facade = $this->getService(CompanyFacade::class);
        \assert($facade instanceof CompanyFacade);

        return $facade->create(new CompanyData(name: $name));
    }
}
