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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\Tests\Support\BuildsQuotes;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Company\CompanyFacade;
use Thelia\Domain\Company\DTO\CompanyData;
use Thelia\Domain\Order\Exception\OrderStatusRefusedByQuoteException;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Domain\Quote\Service\QuoteNoteSanitizer;
use Thelia\Model\Admin;
use Thelia\Model\CartQuery;
use Thelia\Model\Company;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Map\QuoteTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\Product;
use Thelia\Model\Quote;
use Thelia\Model\QuoteItemQuery;
use Thelia\Model\QuoteQuery;
use Thelia\Model\QuoteRevision;
use Thelia\Model\QuoteRevisionAddress;
use Thelia\Model\QuoteRevisionQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The quote screens of the back office: the list with its filters, the sheet of a quote, the
 * actions that need no negotiation, the right each one asks for, and the setting of the shop.
 */
final class QuoteBackOfficeTest extends WebIntegrationTestCase
{
    use BuildsQuotes;

    private const LIST_URL = '/admin/quotes';

    private const PAST = '2020-01-15';

    private const ROW = '[data-testid="datatable-quotes-row"]';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------

    public function testTheListExplainsWhatAQuoteIsWhenThereIsNone(): void
    {
        $crawler = $this->client->request('GET', self::LIST_URL);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="quotes-empty"]'));
    }

    public function testTheListShowsTheStateTheBuyerSeesTheAmountTheCustomerAndTheCompany(): void
    {
        $company = $this->company('Acme List '.bin2hex(random_bytes(3)));
        $buyer = $this->customer('<b>Buyer</b>', 'Lister');
        $overdue = $this->aQuote($buyer, ['state' => 'quoted', 'validUntil' => self::PAST, 'total' => '1620.000000', 'company' => $company]);
        $open = $this->aQuote($buyer, ['state' => 'quoted', 'validUntil' => $this->inDays(10), 'total' => '500.000000', 'company' => $company]);

        $crawler = $this->client->request('GET', self::LIST_URL.'?company_id='.$company->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertCount(2, $crawler->filter(self::ROW));

        $overdueRow = $this->rowOf($crawler, (string) $overdue->getRef());
        self::assertStringContainsString('Expired', $overdueRow->text(), 'A quote waiting for the buyer past its last valid day reads expired.');
        self::assertSame('quoted', QuoteQuery::create()->findPk($overdue->getId())?->getState(), 'Nothing is written for it.');
        self::assertStringContainsString($this->money(1620.0), $overdueRow->text());
        self::assertStringContainsString('Acme List', $overdueRow->text());
        self::assertStringContainsString('Quoted', $this->rowOf($crawler, (string) $open->getRef())->text());

        self::assertStringNotContainsString('<b>Buyer</b>', $crawler->html(), 'The name of the customer is escaped.');
        self::assertStringContainsString('&lt;b&gt;Buyer&lt;/b&gt;', $crawler->html());
        self::assertSame('/admin/quotes/'.$overdue->getId(), $overdueRow->filter('a[data-testid="quote-ref-link"]')->attr('href'), 'The reference opens the sheet.');
    }

    public function testTheFiltersNarrowTheListAndTheSelectionIsKeptInTheForm(): void
    {
        $company = $this->company('Filter Co '.bin2hex(random_bytes(3)));
        $buyer = $this->customer('Filtered', 'Customer');
        $expired = $this->aQuote($buyer, ['state' => 'quoted', 'validUntil' => self::PAST, 'total' => '100.000000', 'company' => $company]);
        $countered = $this->aQuote($buyer, ['state' => 'countered', 'validUntil' => $this->inDays(20), 'total' => '900.000000', 'company' => $company]);

        $crawler = $this->client->request('GET', self::LIST_URL.'?company_id='.$company->getId().'&state=expired');
        self::assertCount(1, $crawler->filter(self::ROW));
        self::assertStringContainsString((string) $expired->getRef(), $crawler->filter('table')->text());
        self::assertSame('expired', $crawler->filter('#quote-filter-state option[selected]')->attr('value'), 'The state that was asked for stays selected.');
        self::assertSame((string) $company->getId(), $crawler->filter('#quote-filter-company option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', self::LIST_URL.'?company_id='.$company->getId().'&min_total=500');
        self::assertStringContainsString((string) $countered->getRef(), $crawler->filter('table')->text());
        self::assertStringNotContainsString((string) $expired->getRef(), $crawler->filter('table')->text());

        $crawler = $this->client->request('GET', self::LIST_URL.'?company_id='.$company->getId().'&q='.urlencode((string) $expired->getRef()));
        self::assertCount(1, $crawler->filter(self::ROW));

        $crawler = $this->client->request('GET', self::LIST_URL.'?company_id='.$company->getId().'&state=ordered');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter(self::ROW), 'A filter that matches nothing leaves the empty message, not an error.');
        self::assertStringContainsString('No quote matches.', $crawler->filter('table')->text());
    }

    public function testTheCompanyFilterIsNotOfferedToAnAdministratorWhoMayNotViewCompanies(): void
    {
        $this->aQuote($this->customer('Company', 'Filterless'), ['company' => $this->company('Filterless Co '.bin2hex(random_bytes(3)))]);

        $crawler = $this->client->request('GET', self::LIST_URL);
        self::assertCount(1, $crawler->filter('#quote-filter-company'));

        $this->injector->setAdmin($this->restricted([AdminResources::QUOTE => [AccessManager::VIEW]]));
        $crawler = $this->client->request('GET', self::LIST_URL);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('#quote-filter-company'));
    }

    // ------------------------------------------------------------------
    // The sheet
    // ------------------------------------------------------------------

    public function testTheSheetShowsTheHeaderTheLinesTheCounterProposalAndTheTotals(): void
    {
        $company = $this->company('Sheet Co '.bin2hex(random_bytes(3)));
        $buyer = $this->customer('Sheet', 'Buyer');
        $quote = $this->aQuote($buyer, ['state' => 'countered', 'validUntil' => $this->inDays(15), 'total' => '1300.000000', 'company' => $company, 'currentRevision' => 2, 'discount' => '30', 'postage' => '15', 'buyerComment' => "Delivery before Christmas\nPlease."]);
        $revision = $this->aRevisionOf($quote, 2);
        $first = $this->aLine($revision, 'Product A', 12.0, '100', '90', null, 1);
        $this->aLine($revision, 'Product B', 4.0, '50', '45', null, 2);
        $this->aCounterOffer($first, null, '85.000000');

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertStringContainsString((string) $quote->getRef(), $crawler->filter('h1')->text());
        self::assertSame('countered', $crawler->filter('[data-testid="quote-state"]')->attr('data-state'));
        self::assertStringContainsString('Sheet Co', $crawler->filter('[data-testid="quote-company"]')->text());
        self::assertStringContainsString('Sheet Buyer', $crawler->filter('[data-testid="quote-customer"]')->text());
        self::assertSame('2', trim($crawler->filter('[data-testid="quote-revision-number"]')->text()), 'The lines are the ones of the revision the quote stands at.');
        self::assertStringContainsString('Delivery before Christmas', $crawler->filter('[data-testid="quote-buyer-comment"]')->text());

        $lines = $crawler->filter('[data-testid^="quote-line-"][data-testid$="'.$first->getId().'"]');
        self::assertCount(1, $lines);
        self::assertStringContainsString($this->money(100.0), $lines->filter('[data-testid="quote-line-catalog-price"]')->text(), 'The catalogue price stands next to the proposed one.');
        self::assertStringContainsString($this->money(90.0), $lines->filter('[data-testid="quote-line-price"]')->text());
        self::assertStringContainsString($this->money(85.0), $lines->filter('[data-testid="quote-line-request"]')->text(), 'The counter-proposal stands next to the price of the shop.');

        $facade = $this->getService(QuoteFacade::class);
        \assert($facade instanceof QuoteFacade);
        $totals = $facade->totals($revision);
        self::assertStringContainsString($this->money((float) $totals->itemsUntaxed), $crawler->filter('[data-testid="quote-total-items"]')->text());
        self::assertStringContainsString($this->money((float) $totals->totalTaxed), $crawler->filter('[data-testid="quote-total-taxed"]')->text(), 'The totals are the ones of the quote facade.');
        self::assertStringNotContainsString($this->money(1300.0), $crawler->filter('[data-testid="quote-totals"]')->text(), 'Not the figure frozen on the revision, which the lines no longer add up to.');
        self::assertStringContainsString($this->money(1260.0), $crawler->filter('[data-testid="quote-total-items"]')->text(), '12 at 90 and 4 at 45.');
    }

    public function testTheTextsTheBuyerAndTheShopWroteAreEscaped(): void
    {
        $quote = $this->aQuote($this->customer('Escape', 'Buyer'), ['buyerComment' => '<script>alert("buyer")</script>', 'merchantNote' => '<img src=x onerror=alert(1)>'.\PHP_EOL.'second line']);
        $this->aLine($this->aRevisionOf($quote, 1), '<i>Italic</i> product', 1.0, '10', '10');

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());

        $comment = $crawler->filter('[data-testid="quote-buyer-comment"] .card-body');
        self::assertCount(0, $comment->filter('script'), 'The comment of the buyer carries no element.');
        self::assertSame('<script>alert("buyer")</script>', trim($comment->text()), 'It reads as the text it is.');

        $note = $crawler->filter('[data-testid="quote-merchant-note"] .card-body');
        self::assertCount(0, $note->filter('img'));
        self::assertStringContainsString('<img src=x onerror=alert(1)>', $note->text());
        self::assertCount(1, $note->filter('br'), 'The line break of the note is kept.');
        self::assertStringContainsString('second line', $note->text());

        $line = $crawler->filter('[data-testid="quote-lines"] tbody');
        self::assertCount(0, $line->filter('i'), 'A title of a line is text.');
        self::assertStringContainsString('<i>Italic</i> product', $line->text());
    }

    public function testTheSheetOfAnOrderedQuoteLinksTheOrder(): void
    {
        $buyer = $this->customer('Ordered', 'Buyer');
        $quote = $this->aQuote($buyer, ['state' => 'ordered']);
        $order = $this->factory->order($buyer);
        $order->setQuoteId($quote->getId())->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());

        $link = $crawler->filter('[data-testid="quote-order"] a');
        self::assertCount(1, $link);
        self::assertSame((string) $order->getRef(), trim($link->text()));
    }

    public function testASheetThatDoesNotExistSendsBackToTheList(): void
    {
        $this->client->request('GET', self::LIST_URL.'/999999999');

        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_URL));
    }

    public function testTheButtonsFollowTheStateOfTheQuote(): void
    {
        $buyer = $this->customer('Buttons', 'Buyer');
        $cases = [
            // state, valid until, negotiate, remind, extend
            'quoted' => ['quoted', $this->inDays(5), false, true, true],
            'overdue' => ['quoted', self::PAST, true, false, true],
            'requested' => ['requested', null, true, false, false],
            'countered' => ['countered', $this->inDays(5), true, false, false],
            'accepted' => ['accepted', $this->inDays(5), false, false, false],
            'rejected' => ['rejected', $this->inDays(5), false, false, false],
            'ordered' => ['ordered', $this->inDays(5), false, false, false],
        ];

        foreach ($cases as $name => [$state, $validUntil, $negotiate, $remind, $extend]) {
            $quote = $this->aQuote($buyer, ['state' => $state, 'validUntil' => $validUntil]);
            $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());

            self::assertCount($negotiate ? 1 : 0, $crawler->filter('[data-testid="quote-negotiate"]'), $name.': price the quote');
            self::assertCount($remind ? 1 : 0, $crawler->filter('[data-testid="quote-remind"]'), $name.': remind');
            self::assertCount($extend ? 1 : 0, $crawler->filter('[data-testid="quote-extend"]'), $name.': extend');
            self::assertCount(1, $crawler->filter('[data-testid="quote-duplicate"]'), $name.': duplicate');
        }
    }

    public function testWhileADraftIsBeingWrittenThePricingButtonIsTheLinkOfTheEditScreen(): void
    {
        $quote = $this->aQuote($this->customer('Link', 'Buyer'), ['state' => 'requested']);
        (new QuoteRevision())->setQuoteId($quote->getId())->setNumber(2)->setAuthor('admin')->setState('draft')->setDiscount('0')->setPostage('0')->save($this->getPropelConnection());
        $url = $this->getService(RouterInterface::class)->generate('admin.quote.edit', ['id' => $quote->getId()]);

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());
        self::assertSame($url, $crawler->filter('a[data-testid="quote-negotiate"]')->attr('href'), 'The link is the one the router builds.');
        self::assertCount(0, $crawler->filter('[data-testid="quote-negotiate-form"]'), 'A draft exists: nothing to start.');

        $crawler = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString((string) $quote->getRef(), $crawler->filter('h1')->text(), 'The screen opens on the sheet of the quote.');
    }

    /**
     * @return iterable<string, array{string, string|null, int, string|null}>
     */
    public static function quotesTheShopPrices(): iterable
    {
        yield 'a request' => ['requested', null, 1, null];
        yield 'a counter-proposal' => ['countered', '+5 days', 2, 'customer'];
        yield 'a quote past its last valid day' => ['quoted', '-20 days', 1, null];
    }

    #[DataProvider('quotesTheShopPrices')]
    public function testPricingAQuoteOpensTheNextDraftInOneGesture(string $state, ?string $validity, int $number, ?string $author): void
    {
        $buyer = $this->customer('Gesture', 'Buyer');
        $product = $this->product('Priced at once');
        $quote = $this->aQuote($buyer, ['state' => $state, 'validUntil' => null === $validity ? null : (new \DateTimeImmutable('today'))->modify($validity)->format('Y-m-d'), 'currentRevision' => $number] + (null === $author ? [] : ['author' => $author]));
        $this->aLine($this->aRevisionOf($quote, $number), 'Priced at once', 3.0, '100', '100', $this->saleElementId($product), 1);

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());
        $form = $crawler->filter('[data-testid="quote-negotiate-form"]');
        self::assertCount(1, $form, 'The button is a form: one click starts the draft.');
        self::assertSame(self::LIST_URL.'/'.$quote->getId().'/revisions/'.$number.'/resume', $form->attr('action'));
        self::assertNull(QuoteRevisionQuery::create()->filterByQuoteId($quote->getId())->filterByNumber($number + 1)->findOne(), 'Nothing is written before the click.');

        $this->client->submit($form->form());

        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_URL.'/'.$quote->getId().'/edit'), 'The shop lands on the draft.');
        $draft = QuoteRevisionQuery::create()->filterByQuoteId($quote->getId())->filterByNumber($number + 1)->findOne();
        self::assertSame('draft', $draft?->getState());
        self::assertCount(1, QuoteItemQuery::create()->filterByQuoteRevisionId($draft->getId())->find(), 'The draft starts from the lines of the revision the quote stands at.');

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="quote-negotiation-form"]'), 'The draft is open for writing.');
    }

    public function testNoGestureIsOfferedOnAQuoteTheShopCannotPrice(): void
    {
        $quote = $this->aQuote($this->customer('Locked', 'Buyer'), ['state' => 'quoted', 'validUntil' => $this->inDays(5)]);

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());

        self::assertCount(0, $crawler->filter('[data-testid="quote-negotiate"]'));
        self::assertCount(0, $crawler->filter('[data-testid="quote-negotiate-form"]'));
    }

    public function testTheListCallsTheTotalTheAmountRequestedWhenTheBuyerWroteTheLastRevision(): void
    {
        $buyer = $this->customer('Asked', 'Amount');
        $asked = $this->aQuote($buyer, ['state' => 'requested', 'total' => '480.000000']);
        $counter = $this->aQuote($buyer, ['state' => 'countered', 'validUntil' => $this->inDays(5), 'total' => '450.000000', 'currentRevision' => 2, 'author' => 'customer']);
        $offered = $this->aQuote($buyer, ['state' => 'quoted', 'validUntil' => $this->inDays(5), 'total' => '500.000000']);

        $crawler = $this->client->request('GET', self::LIST_URL.'?q='.rawurlencode('Amount'));

        $askedRow = $this->rowOf($crawler, (string) $asked->getRef());
        self::assertStringContainsString($this->money(480.0), $askedRow->text());
        self::assertCount(1, $askedRow->filter('[data-testid="quote-total-asked"]'));
        self::assertStringContainsString('Requested amount', $askedRow->filter('[data-testid="quote-total-asked"]')->text());
        self::assertCount(1, $this->rowOf($crawler, (string) $counter->getRef())->filter('[data-testid="quote-total-asked"]'), 'A counter-proposal is an amount the buyer asked for too.');
        self::assertCount(0, $this->rowOf($crawler, (string) $offered->getRef())->filter('[data-testid="quote-total-asked"]'), 'The amount the shop offered is not labelled.');
    }

    public function testASentRevisionIsReadFromWhatWasFrozenWhenItWasSent(): void
    {
        $buyer = $this->customer('Frozen', 'Sheet');
        $book = $this->factory->address($buyer, null, null, ['firstname' => 'Book', 'lastname' => 'Entry', 'address1' => '1 Address Book Road']);
        $quote = $this->aQuote($buyer, ['state' => 'quoted', 'validUntil' => $this->inDays(30)]);
        $quote->setInvoiceAddressId($book->getId())->setDeliveryAddressId($book->getId())->save($this->getPropelConnection());
        $revision = $this->aRevisionOf($quote, 1)->setValidUntil($this->inDays(12))->setReverseCharge(1);
        $revision->save($this->getPropelConnection());
        $this->aLine($revision, 'A line', 1.0, '10', '10');
        $this->copyAddress($revision, 'invoice', 'Frozen', 'Invoice', '10 Invoice Street', 'FR12345678901');
        $this->copyAddress($revision, 'delivery', 'Frozen', 'Delivery', '20 Delivery Street');

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());

        $invoice = $crawler->filter('[data-testid="quote-invoice-address"]')->text();
        self::assertStringContainsString('10 Invoice Street', $invoice, 'The invoice address is the copy.');
        self::assertStringContainsString('FR12345678901', $invoice);
        self::assertStringContainsString('20 Delivery Street', $crawler->filter('[data-testid="quote-delivery-address"]')->text());
        self::assertStringNotContainsString('1 Address Book Road', $crawler->filter('[data-testid="quote-addresses"]')->text(), 'The address book is not read once the copies exist.');
        self::assertSame($this->shortDate($this->inDays(12)), trim($crawler->filter('[data-testid="quote-valid-until"]')->text()), 'The last day is the one of the revision.');
        self::assertCount(1, $crawler->filter('[data-testid="quote-reverse-charge"]'));
    }

    public function testNoReverseChargeIsMentionedUnlessTheRevisionWasSentWithIt(): void
    {
        $quote = $this->aQuote($this->customer('Taxed', 'Sheet'), ['state' => 'quoted', 'validUntil' => $this->inDays(30)]);
        $this->aLine($this->aRevisionOf($quote, 1), 'A line', 1.0, '10', '10');

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());

        self::assertCount(0, $crawler->filter('[data-testid="quote-reverse-charge"]'));
        self::assertCount(1, $crawler->filter('[data-testid="quote-totals"]'));
    }

    public function testARevisionSentBeforeTheCopiesExistedReadsTheAddressesTheQuotePointsAt(): void
    {
        $buyer = $this->customer('Legacy', 'Sheet');
        $book = $this->factory->address($buyer, null, null, ['firstname' => 'Book', 'lastname' => 'Entry', 'address1' => '1 Address Book Road']);
        $quote = $this->aQuote($buyer, ['state' => 'quoted', 'validUntil' => $this->inDays(30)]);
        $quote->setInvoiceAddressId($book->getId())->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());

        self::assertStringContainsString('1 Address Book Road', $crawler->filter('[data-testid="quote-invoice-address"]')->text());
    }

    public function testADraftRevisionIsNeverMarkedAsReverseCharge(): void
    {
        $quote = $this->aQuote($this->customer('Draft', 'Sheet'), ['state' => 'draft', 'revisionState' => 'draft']);
        $revision = $this->aRevisionOf($quote, 1)->setReverseCharge(1);
        $revision->save($this->getPropelConnection());
        $this->aLine($revision, 'A line', 1.0, '10', '10');

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());

        self::assertCount(1, $crawler->filter('[data-testid="quote-totals"]'));
        self::assertCount(0, $crawler->filter('[data-testid="quote-reverse-charge"]'), 'The mention belongs to a revision that was sent.');
    }

    public function testAnEarlierRevisionShowsItsOwnLastDayAndItsOwnAddresses(): void
    {
        $buyer = $this->customer('Earlier', 'Sheet');
        $quote = $this->aQuote($buyer, ['state' => 'quoted', 'validUntil' => $this->inDays(40), 'currentRevision' => 2]);
        $first = $this->aRevisionOf($quote, 1)->setValidUntil($this->inDays(8));
        $first->save($this->getPropelConnection());
        $this->copyAddress($first, 'invoice', 'First', 'Revision', '1 First Revision Way');
        $second = $this->aRevisionOf($quote, 2)->setValidUntil($this->inDays(40));
        $second->save($this->getPropelConnection());
        $this->copyAddress($second, 'invoice', 'Second', 'Revision', '2 Second Revision Way');

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId().'/revisions/1');
        self::assertStringContainsString('1 First Revision Way', $crawler->filter('[data-testid="quote-invoice-address"]')->text());
        self::assertSame($this->shortDate($this->inDays(8)), trim($crawler->filter('[data-testid="quote-valid-until"]')->text()));

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());
        self::assertStringContainsString('2 Second Revision Way', $crawler->filter('[data-testid="quote-invoice-address"]')->text());
        self::assertSame($this->shortDate($this->inDays(40)), trim($crawler->filter('[data-testid="quote-valid-until"]')->text()));
    }

    public function testACancelledOrderThatAQuoteHasLetGoCannotBeBroughtBackAndTheShopIsToldInItsWords(): void
    {
        [$first, $second] = $this->twoOrdersOfOneQuote();
        $notPaid = OrderStatusQuery::getNotPaidStatus();
        \assert($notPaid instanceof OrderStatus);

        $this->client->request('POST', '/admin/order/update/'.$first->getId().'/status?status_id='.$notPaid->getId(), ['_token' => $this->sessionToken(), 'force' => '1']);
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('[data-testid="bo-flash-danger"]'));
        self::assertStringContainsString(OrderStatusRefusedByQuoteException::MESSAGE, $crawler->filter('[data-testid="bo-flash-danger"]')->text(), 'The refusal is shown in its own words, not as an internal error.');
        OrderTableMap::clearInstancePool();
        self::assertTrue((bool) OrderQuery::create()->findPk($first->getId())?->isCancelled(), 'The order stays cancelled.');
    }

    public function testTheBulkChangeOfStatusNamesTheOrdersAQuoteHoldsAndMovesTheOthers(): void
    {
        [$first] = $this->twoOrdersOfOneQuote();
        $notPaid = OrderStatusQuery::getNotPaidStatus();
        \assert($notPaid instanceof OrderStatus);

        $this->client->request('POST', '/admin/order/update/status', ['order_ids' => [$first->getId()], 'status_id' => $notPaid->getId(), '_token' => $this->sessionToken()]);
        $crawler = $this->client->followRedirect();

        $warning = $crawler->filter('[data-testid="bo-flash-warning"]')->text();
        self::assertStringContainsString((string) $first->getRef(), $warning);
        self::assertStringContainsString('has another order now', $warning);
        self::assertCount(0, $crawler->filter('[data-testid="bo-flash-danger"]'), 'It is not reported as a failure.');
    }

    /**
     * Two orders were placed from one quote: the first was cancelled and let the quote go, the
     * second holds it.
     *
     * @return array{Order, Order}
     */
    private function twoOrdersOfOneQuote(): array
    {
        $buyer = $this->customer('Two', 'Orders');
        $quote = $this->aQuote($buyer, ['state' => 'ordered']);
        $first = $this->factory->order($buyer, ['statusCode' => OrderStatus::CODE_CANCELED]);
        $cart = CartQuery::create()->findPk($first->getCartId());
        $cart?->setQuoteId($quote->getId())->save($this->getPropelConnection());
        $second = $this->factory->order($buyer);
        $second->setQuoteId($quote->getId())->save($this->getPropelConnection());

        return [$first, $second];
    }

    private function copyAddress(QuoteRevision $revision, string $kind, string $firstname, string $lastname, string $line, ?string $vatNumber = null): void
    {
        (new QuoteRevisionAddress())
            ->setQuoteRevisionId($revision->getId())
            ->setKind($kind)
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setAddress1($line)
            ->setZipcode('75001')
            ->setCity('Paris')
            ->setCountryTitle('France')
            ->setVatNumber($vatNumber)
            ->save($this->getPropelConnection());
    }

    private function shortDate(string $day): string
    {
        return (new \IntlDateFormatter('en_US', \IntlDateFormatter::SHORT, \IntlDateFormatter::NONE))->format(new \DateTimeImmutable($day)) ?: '';
    }

    // ------------------------------------------------------------------
    // The actions
    // ------------------------------------------------------------------

    public function testExtendingAnExpiredQuoteBringsItBackToQuotedUntilTheDayChosen(): void
    {
        $quote = $this->aQuote($this->customer('Extend', 'Buyer'), ['state' => 'quoted', 'validUntil' => self::PAST]);
        $day = $this->inDays(7);
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/extend', ['_token' => $token, 'valid_until' => $day]);

        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_URL.'/'.$quote->getId()));
        $saved = $this->reread($quote);
        self::assertSame('quoted', $saved->getState());
        self::assertSame($day, $saved->getValidUntil('Y-m-d'));
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-success"]'));
    }

    public function testExtendingToADayThatIsPastOrNotADateIsRefusedWithAMessage(): void
    {
        $quote = $this->aQuote($this->customer('Extend', 'Refused'), ['state' => 'quoted', 'validUntil' => $this->inDays(3)]);
        $token = $this->sessionToken();

        foreach (['2020-01-01' => 'already past', 'next week' => 'not a valid date', '' => 'not a valid date'] as $day => $message) {
            $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/extend', ['_token' => $token, 'valid_until' => (string) $day]);

            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $day);
            $flash = $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]');
            self::assertCount(1, $flash, 'A refusal reads as a message: '.$day);
            self::assertStringContainsString($message, $flash->text(), 'The message says what is wrong: '.$day);
        }

        self::assertSame($this->inDays(3), $this->reread($quote)->getValidUntil('Y-m-d'));
    }

    public function testRemindingStampsTheQuoteAndOnlyAQuoteWaitingForTheBuyerCanBeReminded(): void
    {
        $waiting = $this->aQuote($this->customer('Remind', 'Waiting'), ['state' => 'quoted', 'validUntil' => $this->inDays(5)]);
        $requested = $this->aQuote($this->customer('Remind', 'Requested'), ['state' => 'requested']);
        $overdue = $this->aQuote($this->customer('Remind', 'Overdue'), ['state' => 'quoted', 'validUntil' => self::PAST]);
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/'.$waiting->getId().'/remind', ['_token' => $token]);
        self::assertNotNull($this->reread($waiting)->getRemindedAt(), 'The reminder is on the quote.');
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-success"]'));

        foreach ([$requested, $overdue] as $refused) {
            $this->client->request('POST', self::LIST_URL.'/'.$refused->getId().'/remind', ['_token' => $token]);
            self::assertNull($this->reread($refused)->getRemindedAt());
            $crawler = $this->client->followRedirect();
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'A refusal is no server error.');
            self::assertStringContainsString('not in a state that allows this', $crawler->filter('[data-testid="bo-flash-danger"]')->text());
        }
    }

    public function testDuplicatingStartsANewDraftWithTheLinesOfTheRevisionTheQuoteStandsAt(): void
    {
        $buyer = $this->customer('Duplicate', 'Buyer');
        $product = $this->product('Duplicated product');
        $quote = $this->aQuote($buyer, ['state' => 'accepted', 'validUntil' => $this->inDays(4), 'currentRevision' => 2, 'merchantNote' => 'Thank you for your order', 'postage' => '15', 'total' => '1245.000000']);
        $revision = $this->aRevisionOf($quote, 2);
        $this->aLine($revision, 'Duplicated product', 12.0, '100', '87', $this->saleElementId($product), 1);
        $this->aLine($this->aRevisionOf($quote, 1), 'Old line that must not come along', 1.0, '1', '1', $this->saleElementId($product), 1);
        $before = $this->snapshot($quote);
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/duplicate', ['_token' => $token]);

        $copy = QuoteQuery::create()->filterByCustomerId($buyer->getId())->filterById($quote->getId(), \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne();
        self::assertNotNull($copy, 'A new quote exists for the same customer.');
        self::assertTrue($this->client->getResponse()->isRedirect('/admin/quotes/'.$copy->getId().'/edit'), 'The shop lands on the edit screen of the copy.');
        self::assertSame('draft', $copy->getState());
        self::assertNotSame($quote->getRef(), $copy->getRef());

        $lines = QuoteItemQuery::create()->useQuoteRevisionQuery()->filterByQuoteId($copy->getId())->endUse()->find();
        self::assertCount(1, $lines, 'Only the lines of the current revision are copied.');
        self::assertSame(12.0, (float) $lines[0]->getQuantity());
        self::assertSame('87.000000', $lines[0]->getPrice(), 'The price of the shop is kept.');
        self::assertSame($this->saleElementId($product), (int) $lines[0]->getProductSaleElementsId());

        $draft = QuoteRevisionQuery::create()->filterByQuoteId($copy->getId())->findOne();
        self::assertSame('15.000000', $draft?->getPostage());
        self::assertSame('Thank you for your order', $draft->getMerchantNote());
        self::assertSame('draft', $draft->getState());

        self::assertSame($before, $this->snapshot($quote), 'The quote it came from is left as it was.');
    }

    public function testDuplicatingLeavesOutTheLinesOfAProductThatIsGoneAndSaysSo(): void
    {
        $buyer = $this->customer('Duplicate', 'Gone');
        $product = $this->product('Still there');
        $quote = $this->aQuote($buyer, ['state' => 'rejected']);
        $revision = $this->aRevisionOf($quote, 1);
        $this->aLine($revision, 'Still there', 1.0, '10', '10', $this->saleElementId($product), 1);
        $this->aLine($revision, 'Gone', 1.0, '10', '10', null, 2);

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/duplicate', ['_token' => $this->sessionToken()]);
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('[data-testid="bo-flash-warning"]'));
        self::assertStringContainsString('PROD-2', $crawler->filter('[data-testid="bo-flash-warning"]')->text(), 'The warning names the line that was left out.');
        $copy = QuoteQuery::create()->filterByCustomerId($buyer->getId())->filterById($quote->getId(), \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne();
        self::assertSame(1, QuoteItemQuery::create()->useQuoteRevisionQuery()->filterByQuoteId($copy?->getId())->endUse()->count());
    }

    public function testDuplicatingKeepsTheCurrencyOfTheQuote(): void
    {
        $buyer = $this->customer('Duplicate', 'Dollars');
        $dollar = $this->factory->currency(['code' => 'ZQD', 'rate' => 1.1]);
        $quote = $this->aQuote($buyer, ['state' => 'rejected', 'currencyId' => (int) $dollar->getId()]);

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/duplicate', ['_token' => $this->sessionToken()]);

        $copy = QuoteQuery::create()->filterByCustomerId($buyer->getId())->filterById($quote->getId(), \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne();
        self::assertNotNull($copy);
        self::assertSame($dollar->getId(), $copy->getCurrencyId(), 'The prices are copied as they are: the copy stays in their currency.');
    }

    public function testADuplicationThatTheRulesRefuseIsShownAndTheShopStaysOnTheSheet(): void
    {
        $buyer = $this->customer('Duplicate', 'Refused');
        $product = $this->product('Too much to say');
        $quote = $this->aQuote($buyer, ['state' => 'rejected', 'merchantNote' => str_repeat('a', QuoteNoteSanitizer::MAXIMUM_LENGTH + 1)]);
        $this->aLine($this->aRevisionOf($quote, 1), 'Too much to say', 1.0, '10', '10', $this->saleElementId($product), 1);

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/duplicate', ['_token' => $this->sessionToken()]);

        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_URL.'/'.$quote->getId()), 'The shop stays on the sheet it came from.');
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'), 'The refusal is shown.');
    }

    public function testAQuoteIsCreatedForACustomerFromTheirSheetAndTheShopLandsOnTheEditScreen(): void
    {
        $customer = $this->customer('Create', 'Buyer');

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('[data-testid="customer-quote-form"]');
        self::assertCount(1, $form, 'The sheet of the customer offers the quote.');
        self::assertSame($this->getService(RouterInterface::class)->generate('admin.quote.create'), $form->attr('action'));

        $this->client->submit($form->form());

        $quote = QuoteQuery::create()->filterByCustomerId($customer->getId())->findOne();
        self::assertNotNull($quote);
        self::assertSame('draft', $quote->getState());
        self::assertTrue($this->client->getResponse()->isRedirect('/admin/quotes/'.$quote->getId().'/edit'));
    }

    public function testNoQuoteIsCreatedForAGuestOrForACustomerThatDoesNotExist(): void
    {
        $guest = $this->factory->guestCustomer($this->factory->customerTitle());
        $token = $this->sessionToken();

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$guest->getId());
        self::assertCount(0, $crawler->filter('[data-testid="customer-quote-form"]'), 'A guest has no account to write a quote for.');

        foreach ([(string) $guest->getId(), '999999999', ''] as $customerId) {
            $this->client->request('POST', self::LIST_URL.'/create', ['_token' => $token, 'customer_id' => $customerId]);
            self::assertSame(302, $this->client->getResponse()->getStatusCode());
            self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'), 'Refused with a message: '.$customerId);
        }

        self::assertSame(0, QuoteQuery::create()->filterByCustomerId($guest->getId())->count());
    }

    // ------------------------------------------------------------------
    // The rights and the token
    // ------------------------------------------------------------------

    public function testAnAdministratorWithoutTheRightOnQuotesIsRefusedEverywhere(): void
    {
        $quote = $this->aQuote($this->customer('Denied', 'Buyer'), ['state' => 'quoted', 'validUntil' => $this->inDays(5)]);
        $customer = $this->customer('Denied', 'Customer');
        $token = $this->sessionToken();

        $this->injector->setAdmin($this->restricted([AdminResources::CUSTOMER => [AccessManager::VIEW, AccessManager::UPDATE, AccessManager::CREATE], AdminResources::ORDER => [AccessManager::VIEW]]));

        foreach ($this->screensAndWrites($quote, $customer, $token) as [$method, $url, $payload]) {
            $this->client->request($method, $url, $payload);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $method.' '.$url);
        }

        self::assertNull($this->reread($quote)->getRemindedAt());
        self::assertSame(0, QuoteQuery::create()->filterByCustomerId($customer->getId())->count());

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        self::assertCount(0, $crawler->filter('[data-testid="customer-quote-form"]'), 'The button of the customer sheet is not offered either.');
        $crawler = $this->client->request('GET', '/admin/customers');
        self::assertCount(0, $crawler->filter('[data-testid="bo-nav-order-quotes"]'), 'Nor the menu entry.');
    }

    public function testTheRightToViewReadsTheScreensButWritesNothing(): void
    {
        $quote = $this->aQuote($this->customer('Viewer', 'Buyer'), ['state' => 'quoted', 'validUntil' => $this->inDays(5)]);
        $requested = $this->aQuote($this->customer('Viewer', 'Requester'), ['state' => 'requested']);
        $customer = $this->customer('Viewer', 'Customer');
        $token = $this->sessionToken();

        $this->injector->setAdmin($this->restricted([AdminResources::QUOTE => [AccessManager::VIEW], AdminResources::CUSTOMER => [AccessManager::VIEW]]));

        foreach ([self::LIST_URL, self::LIST_URL.'/'.$quote->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
        }

        foreach ([$quote, $requested] as $shown) {
            $crawler = $this->client->request('GET', self::LIST_URL.'/'.$shown->getId());
            foreach (['quote-negotiate', 'quote-remind', 'quote-extend', 'quote-duplicate'] as $button) {
                self::assertCount(0, $crawler->filter('[data-testid="'.$button.'"]'), 'The gestures the administrator may not make are not offered: '.$button.' on a quote '.$shown->getState());
            }
        }

        foreach ($this->screensAndWrites($quote, $customer, $token) as [$method, $url, $payload]) {
            if ('GET' === $method && str_ends_with($url, '/edit') === false) {
                continue;
            }
            $this->client->request($method, $url, $payload);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $method.' '.$url);
        }

        self::assertNull($this->reread($quote)->getRemindedAt());
        self::assertSame(0, QuoteQuery::create()->filterByCustomerId($customer->getId())->count());
    }

    public function testTheRightToUpdateExtendsAndRemindsButDoesNotCreateOrDuplicate(): void
    {
        $quote = $this->aQuote($this->customer('Updater', 'Buyer'), ['state' => 'quoted', 'validUntil' => $this->inDays(5)]);
        $customer = $this->customer('Updater', 'Customer');
        $token = $this->sessionToken();
        $this->injector->setAdmin($this->restricted([AdminResources::QUOTE => [AccessManager::VIEW, AccessManager::UPDATE]]));

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/remind', ['_token' => $token]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->reread($quote)->getRemindedAt());

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/extend', ['_token' => $token, 'valid_until' => $this->inDays(30)]);
        self::assertSame($this->inDays(30), $this->reread($quote)->getValidUntil('Y-m-d'));

        $this->client->request('GET', self::LIST_URL.'/'.$quote->getId().'/edit');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/duplicate', ['_token' => $token]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Duplicating writes a new quote: it asks for the right to create.');
        $this->client->request('POST', self::LIST_URL.'/create', ['_token' => $token, 'customer_id' => (string) $customer->getId()]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, QuoteQuery::create()->filterByCustomerId($customer->getId())->count());
    }

    public function testTheRightToCreateCreatesAndDuplicatesButDoesNotExtendOrRemind(): void
    {
        $buyer = $this->customer('Creator', 'Buyer');
        $quote = $this->aQuote($buyer, ['state' => 'quoted', 'validUntil' => $this->inDays(5)]);
        $token = $this->sessionToken();
        $this->injector->setAdmin($this->restricted([AdminResources::QUOTE => [AccessManager::VIEW, AccessManager::CREATE]]));

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/duplicate', ['_token' => $token]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, QuoteQuery::create()->filterByCustomerId($buyer->getId())->count());

        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/remind', ['_token' => $token]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', self::LIST_URL.'/'.$quote->getId().'/extend', ['_token' => $token, 'valid_until' => $this->inDays(30)]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', self::LIST_URL.'/'.$quote->getId().'/edit');
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The edit screen asks for the right to change quotes.');

        $saved = $this->reread($quote);
        self::assertNull($saved->getRemindedAt());
        self::assertSame($this->inDays(5), $saved->getValidUntil('Y-m-d'));
    }

    public function testEveryWriteIsRefusedWithoutTheTokenAndChangesNothing(): void
    {
        $buyer = $this->customer('Token', 'Buyer');
        $quote = $this->aQuote($buyer, ['state' => 'quoted', 'validUntil' => $this->inDays(5)]);
        $before = $this->snapshot($quote);

        foreach ($this->screensAndWrites($quote, $buyer, 'not-the-token') as [$method, $url, $payload]) {
            if ('POST' !== $method) {
                continue;
            }

            $this->client->request($method, $url, $payload);
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $url);
            self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-error"], [data-testid="bo-flash-danger"]'), 'The form that expired is sent back with a message: '.$url);
        }

        self::assertSame($before, $this->snapshot($quote));
        self::assertSame(1, QuoteQuery::create()->filterByCustomerId($buyer->getId())->count(), 'Neither a creation nor a duplication went through.');
    }

    public function testTheWritesAnswerToPostOnlyAndTheRoutesAreBuiltByTheRouter(): void
    {
        $router = $this->getService(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        foreach (['admin.quote.create', 'admin.quote.extend', 'admin.quote.remind', 'admin.quote.duplicate'] as $name) {
            $route = $router->getRouteCollection()->get($name);
            self::assertNotNull($route, $name);
            self::assertSame(['POST'], $route->getMethods(), $name.' only answers a POST.');
        }

        $quote = $this->aQuote($this->customer('Routes', 'Buyer'), ['state' => 'quoted', 'validUntil' => $this->inDays(5)]);
        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$quote->getId());

        foreach (['quote-remind-form' => 'admin.quote.remind', 'quote-extend-form' => 'admin.quote.extend', 'quote-duplicate-form' => 'admin.quote.duplicate'] as $testid => $name) {
            self::assertSame($router->generate($name, ['id' => $quote->getId()]), $crawler->filter('[data-testid="'.$testid.'"]')->attr('action'), $testid);
        }

        self::assertSame(['GET'], $router->getRouteCollection()->get('admin.quote.edit')?->getMethods());
        self::assertNull($router->getRouteCollection()->get('admin_quote_edit'), 'The screen is named after the convention: admin.<object>.<verb>.');
    }

    // ------------------------------------------------------------------
    // The menu and the setting
    // ------------------------------------------------------------------

    public function testTheMenuOffersTheQuotesUnderTheOrdersToAnAdministratorWhoMayViewThem(): void
    {
        $crawler = $this->client->request('GET', '/admin/customers');
        $entry = $crawler->filter('[data-testid="bo-nav-order"] [data-testid="bo-nav-order-quotes"]');
        self::assertCount(1, $entry, 'The entry sits in the orders section.');
        self::assertSame($this->getService(RouterInterface::class)->generate('admin.quote.list'), $entry->attr('href'));

        $this->injector->setAdmin($this->restricted([AdminResources::QUOTE => [AccessManager::VIEW], AdminResources::CUSTOMER => [AccessManager::VIEW]]));
        $crawler = $this->client->request('GET', '/admin/customers');
        self::assertCount(1, $crawler->filter('[data-testid="bo-nav-order-quotes"]'), 'Reading quotes without reading orders still gives the entry.');
        self::assertCount(0, $crawler->filter('[data-testid="bo-nav-order-count-total"]'), 'The orders it may not read are not listed.');

        $this->injector->setAdmin($this->restricted([AdminResources::ORDER => [AccessManager::VIEW], AdminResources::CUSTOMER => [AccessManager::VIEW]]));
        $crawler = $this->client->request('GET', '/admin/customers');
        self::assertCount(1, $crawler->filter('[data-testid="bo-nav-order-count-total"]'));
        self::assertCount(0, $crawler->filter('[data-testid="bo-nav-order-quotes"]'), 'Reading orders is no right on quotes.');
    }

    public function testTheModeAndTheValidityOfTheQuotesAreSetInTheStoreConfiguration(): void
    {
        ConfigQuery::write('quote_mode', 'disabled', false);
        ConfigQuery::write('quote_validity_days', '30', false);
        ConfigQuery::resetCache();

        $crawler = $this->client->request('GET', '/admin/configuration/store');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('disabled', $crawler->filter('[data-testid="config-store-quote-mode"] option[selected]')->attr('value'));
        self::assertSame(['disabled', 'alongside', 'only'], $crawler->filter('[data-testid="config-store-quote-mode"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('30', $crawler->filter('[data-testid="config-store-quote-validity-days"]')->attr('value'));

        $this->submitStore($crawler, 'only', '45');
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A 200 or a 400 means the form was rejected.');
        ConfigQuery::resetCache();
        self::assertSame('only', ConfigQuery::read('quote_mode'));
        self::assertSame('45', ConfigQuery::read('quote_validity_days'));

        $crawler = $this->client->request('GET', '/admin/configuration/store');
        self::assertSame('only', $crawler->filter('[data-testid="config-store-quote-mode"] option[selected]')->attr('value'));
        self::assertSame('45', $crawler->filter('[data-testid="config-store-quote-validity-days"]')->attr('value'), 'What was saved is what the screen reads back.');
    }

    public function testAModeThatIsNotOneAndAValidityOfZeroDaysAreRefusedAndNothingIsWritten(): void
    {
        ConfigQuery::write('quote_mode', 'alongside', false);
        ConfigQuery::write('quote_validity_days', '30', false);
        ConfigQuery::resetCache();
        $crawler = $this->client->request('GET', '/admin/configuration/store');

        $this->submitStore($crawler, 'everything', '30', false);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->submitStore($crawler, 'only', '0', false);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        ConfigQuery::resetCache();
        self::assertSame('alongside', ConfigQuery::read('quote_mode'));
        self::assertSame('30', ConfigQuery::read('quote_validity_days'));
    }

    // ------------------------------------------------------------------
    // Inside
    // ------------------------------------------------------------------

    /**
     * @return iterable<int, array{string, string, array<string, string>}>
     */
    private function screensAndWrites(Quote $quote, Customer $customer, string $token): iterable
    {
        $base = self::LIST_URL.'/'.$quote->getId();

        yield ['GET', self::LIST_URL, []];
        yield ['GET', $base, []];
        yield ['GET', $base.'/edit', []];
        yield ['POST', self::LIST_URL.'/create', ['_token' => $token, 'customer_id' => (string) $customer->getId()]];
        yield ['POST', $base.'/extend', ['_token' => $token, 'valid_until' => $this->inDays(30)]];
        yield ['POST', $base.'/remind', ['_token' => $token]];
        yield ['POST', $base.'/duplicate', ['_token' => $token]];
    }

    private function submitStore(Crawler $crawler, string $mode, string $days, bool $followUp = true): void
    {
        $form = $crawler->filter('[data-testid="config-store-save-stay"]')->form([
            'thelia_configuration_store[store_name]' => 'Test Store',
            'thelia_configuration_store[store_email]' => 'store@test.com',
            'thelia_configuration_store[store_notification_emails]' => 'store@test.com',
            'thelia_configuration_store[store_address1]' => '1 Main Street',
            'thelia_configuration_store[store_zipcode]' => '75001',
            'thelia_configuration_store[store_city]' => 'Paris',
        ]);
        $values = $form->getPhpValues();
        $values['thelia_configuration_store']['quote_mode'] = $mode;
        $values['thelia_configuration_store']['quote_validity_days'] = $days;

        $this->client->request('POST', $form->getUri(), $values);
    }

    /**
     * @return array<string, mixed> the columns of the quote and of its revisions
     */
    private function snapshot(Quote $quote): array
    {
        \Thelia\Model\Map\QuoteRevisionTableMap::clearInstancePool();
        \Thelia\Model\Map\QuoteItemTableMap::clearInstancePool();

        return [
            'quote' => $this->reread($quote)->toArray(),
            'revisions' => QuoteRevisionQuery::create()->filterByQuoteId($quote->getId())->orderByNumber()->find()->toArray(),
            'items' => QuoteItemQuery::create()->useQuoteRevisionQuery()->filterByQuoteId($quote->getId())->endUse()->orderById()->find()->toArray(),
        ];
    }

    private function reread(Quote $quote): Quote
    {
        QuoteTableMap::clearInstancePool();

        return QuoteQuery::create()->findPk($quote->getId()) ?? throw new \LogicException('The quote is gone.');
    }

    private function sessionToken(): string
    {
        $html = (string) $this->client->request('GET', self::LIST_URL)->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }

    private function rowOf(Crawler $crawler, string $ref): Crawler
    {
        $row = $crawler->filter(self::ROW)->reduce(static fn (Crawler $row): bool => str_contains($row->text(), $ref))->first();
        self::assertCount(1, $row, 'The row of '.$ref);

        return $row;
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function restricted(array $grants): Admin
    {
        $admin = $this->factory->restrictedAdmin($grants);
        $admin->eraseCredentials();

        return $admin;
    }

    private function inDays(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify('+'.$days.' days')->format('Y-m-d');
    }

    private function money(float $amount): string
    {
        $formatter = $this->getService(MoneyFormatter::class);
        \assert($formatter instanceof MoneyFormatter);

        return $formatter->format($amount, 'en_US');
    }

    private function customer(string $firstname, string $lastname): Customer
    {
        $customer = $this->factory->customer($this->factory->customerTitle(), ['firstname' => $firstname, 'lastname' => $lastname]);
        $customer->setEnable(1)->save($this->getPropelConnection());

        return $customer;
    }

    private function company(string $name): Company
    {
        $facade = $this->getService(CompanyFacade::class);
        \assert($facade instanceof CompanyFacade);

        return $facade->create(new CompanyData(name: $name));
    }

    private function product(string $title): Product
    {
        return $this->factory->product($this->factory->category(), $this->factory->taxRule(), Currency::getDefaultCurrency(), ['basePrice' => 100.0, 'baseQuantity' => 100, 'title' => $title]);
    }

    private function saleElementId(Product $product): int
    {
        return (int) $product->getProductSaleElementss()->getFirst()->getId();
    }
}
