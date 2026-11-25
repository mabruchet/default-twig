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

use BackOfficeDefaultTwigBundle\Service\Quote\QuoteDraftReader;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteNegotiationContext;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteRefusedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * How the form of the negotiation screen is read: shaped for the quote facade, which alone says
 * whether a figure is acceptable, and never trusted for what the core sets itself.
 */
final class QuoteDraftReaderTest extends TestCase
{
    public function testTheFormIsReadIntoTheDraftTheFacadeWrites(): void
    {
        $draft = $this->reader()->read($this->post([
            'lines' => [
                ['item' => '41', 'pse' => '7', 'quantity' => '12', 'price' => '90.50'],
                ['pse' => '8', 'quantity' => '2,5', 'price' => ' 1 250,75 '],
            ],
            'discount' => '30',
            'postage' => '15.5',
            'delivery_module_id' => '3',
            'valid_until' => '2030-02-01',
            'merchant_note' => 'Thank you',
        ]));

        self::assertCount(2, $draft->lines);
        self::assertSame([41, 7, 12.0, '90.50'], [$draft->lines[0]->quoteItemId, $draft->lines[0]->productSaleElementsId, $draft->lines[0]->quantity, $draft->lines[0]->price]);
        self::assertSame([null, 8, 2.5, '1250.75'], [$draft->lines[1]->quoteItemId, $draft->lines[1]->productSaleElementsId, $draft->lines[1]->quantity, $draft->lines[1]->price], 'A comma is a decimal point, spaces are dropped.');
        self::assertSame(['30', '15.5', 3, 'Thank you', '2030-02-01'], [$draft->discount, $draft->postage, $draft->deliveryModuleId, $draft->merchantNote, $draft->validUntil?->format('Y-m-d')]);
    }

    public function testAnEmptyFootReadsAsNothingToGiveAndNoCarrierAndNoLastDay(): void
    {
        $draft = $this->reader()->read($this->post(['lines' => [], 'discount' => '', 'postage' => ' ', 'delivery_module_id' => '', 'valid_until' => '']));

        self::assertSame([], $draft->lines);
        self::assertSame(['0', '0', null, null], [$draft->discount, $draft->postage, $draft->deliveryModuleId, $draft->validUntil]);
    }

    /**
     * @param array<string, mixed> $form
     */
    #[DataProvider('formsThatAreNotTheOneOfTheScreen')]
    public function testAFormThatIsNotTheOneOfTheScreenIsRefused(array $form, bool $edited = true): void
    {
        $this->expectException(QuoteRefusedException::class);

        $this->reader()->read(new Request([], $edited ? $form + ['edited' => '1'] : $form));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function formsThatAreNotTheOneOfTheScreen(): iterable
    {
        yield 'no marker of the screen' => [['lines' => [['pse' => '1', 'quantity' => '1', 'price' => '1']]], false];
        yield 'lines that are not a list' => [['lines' => 'x'], true];
        yield 'a line that is not an array' => [['lines' => ['x']], true];
        yield 'a line without a combination' => [['lines' => [['quantity' => '1', 'price' => '1']]], true];
        yield 'a combination that is zero' => [['lines' => [['pse' => '0', 'quantity' => '1', 'price' => '1']]], true];
        yield 'a last day that is no date' => [['lines' => [], 'valid_until' => '31/12/2030'], true];
        yield 'too many lines' => [['lines' => array_fill(0, QuoteDraftReader::MAXIMUM_LINES + 1, ['pse' => '1', 'quantity' => '1', 'price' => '1'])], true];
    }

    public function testTheCatalogPriceTheFormCarriesIsNotPartOfWhatIsRead(): void
    {
        $draft = $this->reader()->read($this->post(['lines' => [['pse' => '1', 'quantity' => '1', 'price' => '5', 'catalog_price' => '1', 'catalogPrice' => '1']]]));

        self::assertSame('5', $draft->lines[0]->price);
        self::assertFalse(property_exists($draft->lines[0], 'catalogPrice'), 'A line has no catalogue price of its own: the core sets it.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function storedAmounts(): iterable
    {
        yield 'a whole amount' => ['100', '100.00'];
        yield 'six zero decimals' => ['90.000000', '90.00'];
        yield 'one decimal' => ['0.500000', '0.50'];
        yield 'decimals kept' => ['87.123456', '87.123456'];
        yield 'trailing zeros of a long amount' => ['12.340000', '12.34'];
        yield 'zero' => ['0.000000', '0.00'];
    }

    #[DataProvider('storedAmounts')]
    public function testAStoredAmountIsAFieldValueWithTwoDecimalsAtLeast(string $stored, string $field): void
    {
        self::assertSame($field, QuoteNegotiationContext::amount($stored));
    }

    public function testAQuantityKeepsItsDecimalsAndNoMore(): void
    {
        self::assertSame('12', QuoteNegotiationContext::quantity(12.0));
        self::assertSame('2.5', QuoteNegotiationContext::quantity(2.5));
        self::assertSame('0.125', QuoteNegotiationContext::quantity(0.125));
    }

    /**
     * @param array<string, mixed> $form
     */
    private function post(array $form): Request
    {
        return new Request([], $form + ['edited' => '1']);
    }

    private function reader(): QuoteDraftReader
    {
        return new QuoteDraftReader(new IdentityTranslator());
    }
}
