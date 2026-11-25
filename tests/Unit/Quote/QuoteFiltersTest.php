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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Quote;

use BackOfficeDefaultTwigBundle\Service\Quote\QuoteFilters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Domain\Quote\Enum\QuoteState;

final class QuoteFiltersTest extends TestCase
{
    public function testARequestWithoutFiltersIsEmptyAndSortsByTheNewest(): void
    {
        $filters = QuoteFilters::fromRequest(new Request());

        self::assertTrue($filters->isEmpty());
        self::assertSame('created_at', $filters->sort);
        self::assertSame('desc', $filters->direction);
        self::assertSame([], $filters->toQueryParams());
    }

    public function testEachFilterIsReadAndWrittenBackInTheUrl(): void
    {
        $query = [
            'state' => 'countered',
            'company_id' => '12',
            'q' => 'Acme',
            'min_total' => '100.5',
            'max_total' => '2000',
            'valid_from' => '2026-12-01',
            'valid_to' => '2026-12-31',
            'order' => 'total',
            'direction' => 'asc',
        ];

        $filters = QuoteFilters::fromRequest(new Request($query));

        self::assertFalse($filters->isEmpty());
        self::assertSame(QuoteState::Countered, $filters->state);
        self::assertSame(12, $filters->companyId);
        self::assertSame(100.5, $filters->minTotal);
        self::assertSame($query, array_map('strval', $filters->toQueryParams()));
    }

    public function testAnUnknownStateSortOrDirectionFallsBackOnTheDefault(): void
    {
        $filters = QuoteFilters::fromRequest(new Request(['state' => 'archived', 'order' => 'customer_id; drop', 'direction' => 'sideways', 'company_id' => 'abc']));

        self::assertNull($filters->state, 'Only a state the quote has is a filter.');
        self::assertNull($filters->companyId);
        self::assertSame('created_at', $filters->sort, 'A sort outside the allow-list never reaches the query.');
        self::assertSame('desc', $filters->direction);
    }

    public function testTheEndsOfARangeAreSwappedWhenTheyComeTheWrongWayRound(): void
    {
        $filters = QuoteFilters::fromRequest(new Request(['min_total' => '900', 'max_total' => '100', 'valid_from' => '2027-02-01', 'valid_to' => '2027-01-01']));

        self::assertSame(100.0, $filters->minTotal);
        self::assertSame(900.0, $filters->maxTotal);
        self::assertSame('2027-01-01', $filters->validFrom?->format('Y-m-d'));
        self::assertSame('2027-02-01', $filters->validTo?->format('Y-m-d'));
    }

    public function testAnAmountOrADateThatIsNotOneIsIgnored(): void
    {
        $filters = QuoteFilters::fromRequest(new Request(['min_total' => 'many', 'max_total' => '-5', 'valid_from' => '2026-13-45', 'valid_to' => 'tomorrow']));

        self::assertTrue($filters->isEmpty());
    }

    public function testAnAmountMayBeTypedWithACommaAsTheDecimalSeparator(): void
    {
        self::assertSame(12.5, QuoteFilters::fromRequest(new Request(['min_total' => '12,5']))->minTotal);
    }
}
