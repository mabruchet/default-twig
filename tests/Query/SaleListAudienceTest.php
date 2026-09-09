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

use BackOfficeDefaultTwigBundle\Repository\SaleRepository;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Model\Customer;
use Thelia\Model\CustomerTitleQuery;
use Thelia\Model\Sale;
use Thelia\Test\IntegrationTestCase;

/**
 * How many customers each sale of the list is reserved for.
 *
 * `sale_customer.customer_id` cascades on delete, so deleting the last customer a
 * sale was reserved for leaves the sale marked reserved with nobody in it: it
 * disappears from every front-office, and nothing on the back-office list said so.
 * The count that makes it visible has to ride on the list query — a sale list of a
 * few hundred rows cannot afford a read per row to say it.
 */
final class SaleListAudienceTest extends IntegrationTestCase
{
    private SaleRepository $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = new SaleRepository();
    }

    public function testTheWholePageIsCountedInASingleQuery(): void
    {
        $reserved = $this->reservedSaleFor(2);
        $emptied = $this->reservedSaleFor(0);
        $public = $this->createFixtureFactory()->sale();

        $counts = [];
        $queries = QueryCounter::count(function () use (&$counts): void {
            $counts = $this->targetedCountsBySale('id', 'asc');
        });

        self::assertSame(1, $queries, 'One query for the page, count included, whatever the number of rows.');
        self::assertSame(2, $counts[(int) $reserved->getId()]);
        self::assertSame(0, $counts[(int) $emptied->getId()]);
        self::assertSame(0, $counts[(int) $public->getId()]);
    }

    /**
     * Sorting on a translated column joins sale_i18n. The count is a correlated
     * sub-select on the sale row, so the join must not double it.
     */
    public function testTheCountSurvivesTheSortThatJoinsTheTranslations(): void
    {
        $sale = $this->reservedSaleFor(3);

        $counts = [];
        $queries = QueryCounter::count(function () use (&$counts): void {
            $counts = $this->targetedCountsBySale('title', 'asc');
        });

        self::assertSame(1, $queries);
        self::assertSame(3, $counts[(int) $sale->getId()]);
    }

    /**
     * The case the shop owner has no other way of noticing: purging customers empties
     * the audience of a reserved sale without changing anything on the sale itself.
     */
    public function testDeletingTheLastTargetedCustomerLeavesTheSaleReservedForNobody(): void
    {
        $sale = $this->createFixtureFactory()->sale(['audienceMode' => Sale::AUDIENCE_MODE_CUSTOMERS]);
        $customer = $this->customer();
        $this->createFixtureFactory()->saleCustomer($sale, $customer);

        self::assertSame(1, $this->targetedCountsBySale('id', 'asc')[(int) $sale->getId()]);

        $customer->delete($this->getPropelConnection());

        $sale->reload(true, $this->getPropelConnection());
        self::assertTrue($sale->isReserved(), 'the sale itself is untouched: it still says it is reserved');
        self::assertSame(
            0,
            $this->targetedCountsBySale('id', 'asc')[(int) $sale->getId()],
            'and it now targets nobody, which is what the list has to be able to show',
        );
    }

    /**
     * @return array<int, int>
     */
    private function targetedCountsBySale(string $field, string $direction): array
    {
        $counts = [];
        foreach ($this->sales->findAllSorted($field, $direction, 'en_US') as $sale) {
            $counts[(int) $sale->getId()] = (int) $sale->getVirtualColumn(SaleRepository::TARGETED_CUSTOMERS_COUNT);
        }

        return $counts;
    }

    private function reservedSaleFor(int $customerCount): Sale
    {
        $factory = $this->createFixtureFactory();
        $sale = $factory->sale(['audienceMode' => Sale::AUDIENCE_MODE_CUSTOMERS]);

        for ($i = 0; $i < $customerCount; ++$i) {
            $factory->saleCustomer($sale, $this->customer());
        }

        return $sale;
    }

    private function customer(): Customer
    {
        $title = CustomerTitleQuery::create()->findOne($this->getPropelConnection());
        self::assertNotNull($title, 'the seeded shop has customer titles');

        return $this->createFixtureFactory()->customer($title);
    }
}
