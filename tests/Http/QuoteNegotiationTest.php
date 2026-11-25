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

use BackOfficeDefaultTwigBundle\Controller\Quote\QuoteNegotiationController;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\Tests\Support\BuildsQuotes;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Quote\DTO\QuoteItemRequestInput;
use Thelia\Domain\Quote\DTO\QuoteRequestInput;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\Map\QuoteItemTableMap;
use Thelia\Model\Map\QuoteRevisionTableMap;
use Thelia\Model\Map\QuoteTableMap;
use Thelia\Model\Module;
use Thelia\Model\Product;
use Thelia\Model\Quote;
use Thelia\Model\QuoteItem;
use Thelia\Model\QuoteItemQuery;
use Thelia\Model\QuoteQuery;
use Thelia\Model\QuoteRevision;
use Thelia\Model\QuoteRevisionQuery;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleCountry;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The negotiation screen of a quote: the lines the shop prices, the foot of the quote, the draft
 * that is saved and sent, the revision that starts from an earlier one and the history of them
 * all. Every figure the screen shows comes from the quote facade, and every write goes through it.
 */
final class QuoteNegotiationTest extends WebIntegrationTestCase
{
    use BuildsQuotes;

    private const BASE = '/admin/quotes';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private ?TaxRule $vat = null;

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

        ConfigQuery::write('quote_mode', 'alongside', false);
        ConfigQuery::write('taxrule_id_delivery_module', (string) $this->vatRule()->getId(), false);
        ConfigQuery::resetCache();
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
    // The draft
    // ------------------------------------------------------------------

    public function testARevisionThatWasSentIsReadOnlyAndTheNextOneStartsFromItsLines(): void
    {
        $scenario = $this->requested();
        $quote = $scenario['quote'];

        $crawler = $this->client->request('GET', $this->editUrl($quote));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="quote-negotiation-form"]'), 'A revision that was sent is not a form.');
        self::assertCount(1, $crawler->filter('[data-testid="quote-new-revision"]'));
        self::assertCount(2, $crawler->filter('[data-testid="quote-lines"] tbody tr'), 'The lines of the revision are read.');

        $this->client->submit($crawler->filter('[data-testid="quote-new-revision-form"]')->form());

        self::assertTrue($this->client->getResponse()->isRedirect($this->editUrl($quote)));
        $draft = $this->revisionOf($quote, 2);
        self::assertSame('draft', $draft->getState());
        self::assertSame(['10', '4'], array_map(static fn (QuoteItem $line): string => rtrim(rtrim(number_format((float) $line->getQuantity(), 4, '.', ''), '0'), '.'), $this->linesOf($draft)));
        self::assertSame(['100.000000', '50.000000'], array_map(static fn (QuoteItem $line): string => (string) $line->getPrice(), $this->linesOf($draft)), 'The buyer asked for the catalogue price: it is the first proposal.');
        self::assertSame(1, $this->reread($quote)->getCurrentRevision(), 'The quote still stands at the revision that was sent.');

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="quote-negotiation-form"]'));
        self::assertCount(2, $crawler->filter('[data-testid="quote-lines-table"] [data-testid="quote-line-price-input"]'));
        self::assertSame('2', trim($crawler->filter('[data-testid="quote-revision-number"]')->text()));
    }

    public function testSavingTheDraftWritesTheLinesTheFootAndTheCleanedNoteAndReadsThemBack(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $day = $this->inDays(15);

        $this->save($quote, $this->lines($scenario, 12.0, '90', 4.0, '45', 5.0), [
            'discount' => '30',
            'postage' => '15',
            'valid_until' => $day,
            'merchant_note' => '<p>Thank you</p><script>alert(1)</script>',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect($this->editUrl($quote)));
        $draft = $this->revisionOf($quote, 2);
        $lines = $this->linesOf($draft);
        self::assertCount(3, $lines);
        self::assertSame(['12', '4', '5'], array_map(static fn (QuoteItem $line): string => rtrim(rtrim(number_format((float) $line->getQuantity(), 4, '.', ''), '0'), '.'), $lines));
        self::assertSame(['90.000000', '45.000000', '20.000000'], array_map(static fn (QuoteItem $line): string => (string) $line->getPrice(), $lines));
        self::assertSame('30.000000', $draft->getDiscount());
        self::assertSame('15.000000', $draft->getPostage());
        self::assertSame($day, $draft->getValidUntil('Y-m-d'), 'The validity is written on the draft.');
        self::assertNull($this->reread($quote)->getValidUntil(), 'The quote keeps its own validity until the draft is sent.');
        self::assertSame('Thank you', $draft->getMerchantNote(), 'The note of the shop is text: the tags and the scripts are not kept.');

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="bo-flash-success"]'));
        self::assertSame(['90.00', '45.00', '20.00'], $crawler->filter('[data-testid="quote-lines-table"] [data-testid="quote-line-price-input"]')->each(static fn (Crawler $field): string => (string) $field->attr('value')));
        self::assertSame(['12', '4', '5'], $crawler->filter('[data-testid="quote-lines-table"] [data-testid="quote-line-quantity-input"]')->each(static fn (Crawler $field): string => (string) $field->attr('value')));
        self::assertSame('30.00', $crawler->filter('[data-testid="quote-discount-input"]')->attr('value'));
        self::assertSame('15.00', $crawler->filter('[data-testid="quote-postage-input"]')->attr('value'));
        self::assertSame($day, $crawler->filter('[data-testid="quote-valid-until-input"]')->attr('value'));
        self::assertSame('Thank you', trim($crawler->filter('[data-testid="quote-merchant-note-input"]')->text()));
    }

    public function testTheTotalsAreTheOnesOfTheFacadeAfterEachSaveNotTheOnesTheShopTyped(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];

        $this->save($quote, $this->lines($scenario, 12.0, '90', 4.0, '45', 5.0), ['discount' => '30', 'postage' => '15']);
        $crawler = $this->client->followRedirect();

        self::assertSame($this->money(1360.0), trim($crawler->filter('[data-testid="quote-total-items"]')->text()));
        self::assertSame($this->money(1620.0), trim($crawler->filter('[data-testid="quote-total-taxed"]')->text()), '(1 360 + 15) x 1.2 - 30.');
        self::assertSame($this->money(30.0), trim($crawler->filter('[data-testid="quote-total-discount"]')->text()));

        $this->save($quote, $this->lines($scenario, 12.0, '87', 4.0, '45', 5.0), ['discount' => '30', 'postage' => '15']);
        $crawler = $this->client->followRedirect();
        self::assertSame($this->money(1576.8), trim($crawler->filter('[data-testid="quote-total-taxed"]')->text()), '(1 324 + 15) x 1.2 - 30.');

        $facade = $this->getService(QuoteFacade::class);
        \assert($facade instanceof QuoteFacade);
        self::assertSame('1576.800000', $facade->totals($this->revisionOf($quote, 2))->totalTaxed);
    }

    public function testTheCatalogPriceOfALineTheShopAddsIsTheOneTheCoreSetsWhateverTheFormSays(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $lines = $this->lines($scenario, 10.0, '100', 4.0, '50', 5.0);
        $lines[2]['catalog_price'] = '1';
        $lines[2]['catalogPrice'] = '1';

        $this->save($quote, $lines, ['discount' => '0', 'postage' => '0']);

        $added = $this->lineOf($this->revisionOf($quote, 2), $scenario['c']->getRef());
        self::assertSame('20.000000', $added->getCatalogPrice(), 'The catalogue price is read from the catalogue, never from the browser.');
        self::assertSame('20.000000', $added->getPrice());
    }

    public function testALineLeftOutOfTheFormIsRemovedFromTheDraft(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $lines = $this->lines($scenario, 10.0, '100', 4.0, '50');
        unset($lines[1]);

        $this->save($quote, array_values($lines), ['discount' => '0', 'postage' => '0']);

        self::assertSame([(string) $scenario['a']->getRef()], array_map(static fn (QuoteItem $line): string => (string) $line->getProductRef(), $this->linesOf($this->revisionOf($quote, 2))));
        self::assertSame(2, QuoteItemQuery::create()->useQuoteRevisionQuery()->filterByQuoteId($quote->getId())->filterByNumber(1)->endUse()->count(), 'The lines of the revision that was sent are not touched.');
    }

    public function testTheDeliveryModuleIsOneOfTheActiveDeliveryModulesAndOnlyThose(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $carrier = $this->deliveryModule('QuoteTestCarrier');
        $lines = $this->lines($scenario, 10.0, '100', 4.0, '50');

        $crawler = $this->client->request('GET', $this->editUrl($quote));
        self::assertContains((string) $carrier->getId(), $crawler->filter('[data-testid="quote-delivery-module-input"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));

        $this->save($quote, $lines, ['delivery_module_id' => (string) $carrier->getId()]);
        self::assertSame($carrier->getId(), $this->revisionOf($quote, 2)->getDeliveryModuleId(), 'The carrier is written on the draft.');
        self::assertNull($this->reread($quote)->getDeliveryModuleId(), 'The quote keeps its own carrier until the draft is sent.');
        $crawler = $this->client->followRedirect();
        self::assertSame((string) $carrier->getId(), $crawler->filter('[data-testid="quote-delivery-module-input"] option[selected]')->attr('value'));

        $payment = $this->factory->paymentModule();
        $this->save($quote, $lines, ['delivery_module_id' => (string) $payment->getId()]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('not a delivery module', $crawler->filter('[data-testid="bo-flash-danger"]')->text());
        self::assertSame($carrier->getId(), $this->revisionOf($quote, 2)->getDeliveryModuleId(), 'A refused save changes nothing.');
    }

    public function testTheScreenReadsTheValidityAndTheCarrierOfTheDraftNotTheOnesOfTheQuote(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $quoteCarrier = $this->deliveryModule('QuoteSideCarrier');
        $draftCarrier = $this->deliveryModule('QuoteDraftCarrier');
        $quote = $this->reread($quote)->setValidUntil($this->inDays(40))->setDeliveryModuleId($quoteCarrier->getId());
        $quote->save($this->getPropelConnection());
        $this->revisionOf($quote, 2)->setValidUntil($this->inDays(9))->setDeliveryModuleId($draftCarrier->getId())->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', $this->editUrl($quote));

        self::assertSame($this->inDays(9), $crawler->filter('[data-testid="quote-valid-until-input"]')->attr('value'));
        self::assertSame((string) $draftCarrier->getId(), $crawler->filter('[data-testid="quote-delivery-module-input"] option[selected]')->attr('value'));
    }

    public function testARevisionTakenUpAgainBringsItsOwnValidityAndCarrierNotTheOnesOfTheQuote(): void
    {
        $scenario = $this->requested();
        $quote = $scenario['quote'];
        $revisionCarrier = $this->deliveryModule('QuoteRevisionCarrier');
        $quoteCarrier = $this->deliveryModule('QuoteSideCarrier');
        $this->revisionOf($quote, 1)->setValidUntil($this->inDays(20))->setDeliveryModuleId($revisionCarrier->getId())->save($this->getPropelConnection());
        $this->reread($quote)->setValidUntil($this->inDays(50))->setDeliveryModuleId($quoteCarrier->getId())->save($this->getPropelConnection());

        $this->client->request('POST', $this->url($quote, '/revisions/1/resume'), ['_token' => $this->sessionToken()]);

        $draft = $this->revisionOf($quote, 2);
        self::assertSame($this->inDays(20), $draft->getValidUntil('Y-m-d'));
        self::assertSame($revisionCarrier->getId(), $draft->getDeliveryModuleId());
    }

    public function testTheNoteOfTheShopIsTextOnTheScreenAndInTheField(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $this->revisionOf($quote, 2)->setMerchantNote('5 < 6 & more'.\PHP_EOL.'<b>kept as typed</b>')->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', $this->editUrl($quote));

        $field = $crawler->filter('[data-testid="quote-merchant-note-input"]');
        self::assertSame('5 < 6 & more'.\PHP_EOL.'<b>kept as typed</b>', trim($field->text(null, false)), 'The field holds the text as it was written.');
        self::assertStringContainsString('&lt;b&gt;kept as typed&lt;/b&gt;', $crawler->html(), 'The text is escaped, not read as markup.');
        self::assertStringNotContainsString('Basic formatting', $crawler->html(), 'The hint no longer promises formatting.');
    }

    public function testAnInvalidFigureIsRefusedWithAMessageAndNothingIsWritten(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $before = $this->snapshot($quote);

        $cases = [
            'a price that is not a number' => [$this->lines($scenario, 10.0, 'free', 4.0, '50'), [], 'price of a line must be a positive amount'],
            'a quantity of zero' => [$this->lines($scenario, 0.0, '100', 4.0, '50'), [], 'quantity of a line must be a positive number'],
            'a discount that is negative' => [$this->lines($scenario, 10.0, '100', 4.0, '50'), ['discount' => '-5'], 'discount must be a positive amount'],
            'a validity in the past' => [$this->lines($scenario, 10.0, '100', 4.0, '50'), ['valid_until' => '2020-01-01'], 'already past'],
            'a validity that is no date' => [$this->lines($scenario, 10.0, '100', 4.0, '50'), ['valid_until' => 'soon'], 'not a valid date'],
        ];

        foreach ($cases as $name => [$lines, $foot, $message]) {
            $this->save($quote, $lines, $foot);
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $name);
            $flash = $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]');
            self::assertCount(1, $flash, $name);
            self::assertStringContainsString($message, $flash->text(), $name);
        }

        self::assertSame($before, $this->snapshot($quote));
    }

    public function testAFormThatIsNotTheOneOfTheScreenIsRefusedAndDoesNotEmptyTheDraft(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $before = $this->snapshot($quote);
        $token = $this->sessionToken();

        $this->client->request('POST', $this->url($quote, '/draft'), ['_token' => $token]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'));

        $this->client->request('POST', $this->url($quote, '/send'), ['_token' => $token, 'lines' => 'not-a-list', 'edited' => '1']);
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'));

        $this->client->request('POST', $this->url($quote, '/draft'), ['_token' => $token, 'edited' => '1', 'lines' => [['quantity' => '1', 'price' => '1']]]);
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'), 'A line that names no combination is not a line.');

        self::assertSame($before, $this->snapshot($quote), 'The draft is as it was.');
    }

    // ------------------------------------------------------------------
    // The save in the background
    // ------------------------------------------------------------------

    public function testTheBackgroundSaveWritesTheDraftAndAnswersTheTotalsOfTheServerTheDiscountGrantedAndTheStoredLines(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $lines = $this->lines($scenario, 12.0, '90', 4.0, '45', 5.0);
        $lines[2]['catalog_price'] = '1';

        $answer = $this->autosave($quote, $lines, ['discount' => '30', 'postage' => '15']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame($this->money(1360.0), $answer['totals']['items_untaxed']);
        self::assertSame($this->money(15.0), $answer['totals']['postage_untaxed']);
        self::assertSame($this->money(30.0), $answer['totals']['discount']);
        self::assertSame($this->money(1620.0), $answer['totals']['total_taxed'], '(1 360 + 15) x 1.2 - 30.');
        self::assertSame($this->money(140.0), $answer['granted']['amount'], '12 x (100 - 90) + 4 x (50 - 45) + 5 x (20 - 20).');
        self::assertSame('9.33 %', $answer['granted']['percent'], '140 over 1 500 at the catalogue.');

        $draft = $this->revisionOf($quote, 2);
        $stored = $this->linesOf($draft);
        self::assertSame(['90.000000', '45.000000', '20.000000'], array_map(static fn (QuoteItem $line): string => (string) $line->getPrice(), $stored), 'The draft is read back from the base.');
        self::assertSame('30.000000', $draft->getDiscount());
        self::assertSame('15.000000', $draft->getPostage());
        self::assertSame('20.000000', $this->lineOf($draft, $scenario['c']->getRef())->getCatalogPrice(), 'The catalogue price is the one of the catalogue, not the one the request names.');
        self::assertSame(
            array_map(static fn (QuoteItem $line): array => ['item' => (int) $line->getId(), 'pse' => (int) $line->getProductSaleElementsId()], $stored),
            $answer['lines'],
            'The answer names the stored lines in the order of the form, to give the added line its id.',
        );

        $again = $this->autosave($quote, array_map(static fn (array $line, array $stored): array => ['item' => (string) $stored['item']] + $line, $lines, $answer['lines']), ['discount' => '30', 'postage' => '15']);
        self::assertSame($answer, $again, 'The lines with their ids are saved again as they are.');
        self::assertSame(array_map(static fn (QuoteItem $line): int => (int) $line->getId(), $stored), array_map(static fn (QuoteItem $line): int => (int) $line->getId(), $this->linesOf($this->revisionOf($quote, 2))), 'No line is created again.');
    }

    public function testTheBackgroundSaveFollowsEveryChangeOfALineAndTheFootOfTheQuote(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];

        $this->autosave($quote, $this->lines($scenario, 12.0, '90', 4.0, '45', 5.0), ['discount' => '30', 'postage' => '15']);
        $answer = $this->autosave($quote, $this->lines($scenario, 12.0, '87', 4.0, '45', 5.0), ['discount' => '30', 'postage' => '15']);
        self::assertSame($this->money(1576.8), $answer['totals']['total_taxed'], '(1 324 + 15) x 1.2 - 30.');

        $lines = $this->lines($scenario, 12.0, '87', 4.0, '45');
        $answer = $this->autosave($quote, $lines, ['discount' => '0', 'postage' => '0']);
        self::assertCount(2, $answer['lines'], 'A line that is not sent any more is removed.');
        self::assertCount(2, $this->linesOf($this->revisionOf($quote, 2)));
        self::assertSame($this->money(1224.0 * 1.2), $answer['totals']['total_taxed']);
    }

    public function testTheBackgroundSaveIsRefusedWithoutTheTokenAndChangesNothing(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $before = $this->snapshot($quote);
        $lines = $this->lines($scenario, 12.0, '1', 4.0, '1');

        foreach ([null, 'not-the-token'] as $token) {
            $form = $this->form($lines, [], $token ?? 'x');
            if (null === $token) {
                unset($form['_token']);
            }

            $this->client->request('POST', $this->url($quote, '/draft/autosave'), $form);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), (string) $token);
            self::assertArrayHasKey('error', $this->answer());
        }

        $this->client->request('POST', $this->url($quote, '/draft/autosave'), $this->form($lines, [], 'x'), [], ['HTTP_X_CSRF_TOKEN' => $this->sessionToken()]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The token of the body is the one that counts when there is one.');

        self::assertSame($before, $this->snapshot($quote));
    }

    public function testTheBackgroundSaveAsksForTheRightToUpdateNotOnlyToView(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $token = $this->sessionToken();
        $before = $this->snapshot($quote);
        $lines = $this->lines($scenario, 12.0, '1', 4.0, '1');

        foreach ([[AdminResources::QUOTE => [AccessManager::VIEW]], [AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE]]] as $grants) {
            $this->injector->setAdmin($this->restricted($grants));
            $this->client->request('POST', $this->url($quote, '/draft/autosave'), $this->form($lines, [], $token));
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), (string) json_encode($grants));
        }

        self::assertSame($before, $this->snapshot($quote));

        $this->injector->setAdmin($this->restricted([AdminResources::QUOTE => [AccessManager::UPDATE]]));
        $this->client->request('POST', $this->url($quote, '/draft/autosave'), $this->form($lines, [], $token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The right to update is enough.');
    }

    public function testTheBackgroundSaveOfARevisionThatWasSentIsRefusedWithAMessageAndWritesNothing(): void
    {
        $scenario = $this->sentSecondRevision();
        $quote = $scenario['quote'];
        $before = $this->snapshot($quote);

        $answer = $this->autosave($quote, $this->lines($scenario, 12.0, '1', 4.0, '1', 5.0), ['discount' => '0', 'postage' => '0'], 422);

        self::assertStringContainsString('not in a state that allows this', $answer['error']);
        self::assertArrayNotHasKey('totals', $answer);
        self::assertSame($before, $this->snapshot($quote), 'The revision that was sent is as it was sent.');
    }

    public function testTheBackgroundSaveRefusesAFigureThatIsNotValidWithAMessageAndWritesNothing(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $before = $this->snapshot($quote);

        $answer = $this->autosave($quote, $this->lines($scenario, 10.0, 'free', 4.0, '50'), [], 422);
        self::assertStringContainsString('price of a line must be a positive amount', $answer['error']);

        $this->client->request('POST', $this->url($quote, '/draft/autosave'), ['_token' => $this->sessionToken()]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'A form that is not the one of the screen does not empty the draft.');

        self::assertSame($before, $this->snapshot($quote));
    }

    public function testTheBackgroundSaveOfAQuoteThatDoesNotExistAnswersNotFound(): void
    {
        $this->client->request('POST', self::BASE.'/999999999/draft/autosave', ['_token' => $this->sessionToken()]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheScreenShowsTheDiscountGrantedOnTheLinesAsTheServerWorksItOut(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];

        $this->save($quote, $this->lines($scenario, 12.0, '90', 4.0, '45', 5.0), ['discount' => '0', 'postage' => '0']);
        $crawler = $this->client->followRedirect();

        self::assertSame($this->money(140.0).' (9.33 %)', preg_replace('/\s+/u', ' ', trim($crawler->filter('[data-testid="quote-total-granted"]')->text())));
        self::assertGreaterThanOrEqual(5, $crawler->filter('[data-testid="quote-totals"] [data-total]')->count(), 'Each figure the script updates is marked.');
    }

    // ------------------------------------------------------------------
    // The search
    // ------------------------------------------------------------------

    public function testTheSearchOfProductsAnswersTwentyRowsAtMostWithTheCatalogPriceAndNothingForAShortTerm(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $marker = 'Bulkcatalogue'.bin2hex(random_bytes(3));

        for ($index = 1; $index <= 23; ++$index) {
            $this->product('20', $marker.' '.$index);
        }

        $this->client->request('GET', $this->url($quote, '/products?q='.$marker));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $rows = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(QuoteNegotiationController::SEARCH_LIMIT, $rows, 'The search is not a way to read the catalogue.');
        self::assertSame(20, QuoteNegotiationController::SEARCH_LIMIT);
        self::assertSame('20.00', $rows[0]['price'], 'The price shown is the catalogue price of the buyer of the quote.');
        self::assertArrayHasKey('pse_id', $rows[0]);

        foreach (['', 'a', ' b '] as $short) {
            $this->client->request('GET', $this->url($quote, '/products?q='.urlencode($short)));
            self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true), 'A term of less than two characters finds nothing: "'.$short.'".');
        }

        $this->client->request('GET', $this->url($quote, '/products?q='.$scenario['c']->getRef()));
        $rows = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([(string) $scenario['c']->getRef()], array_values(array_unique(array_column($rows, 'product_ref'))), 'A product is found by its reference.');
    }

    public function testTheSearchOfAQuoteThatDoesNotExistAnswersNotFound(): void
    {
        $this->client->request('GET', self::BASE.'/999999999/products?q=abcd');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Sending and the revisions that follow
    // ------------------------------------------------------------------

    public function testSendingSavesTheDraftThenSendsItAndTheScreenTurnsReadOnly(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];

        $this->client->request('POST', $this->url($quote, '/send'), $this->form($this->lines($scenario, 12.0, '90', 4.0, '45', 5.0), ['discount' => '30', 'postage' => '15']));

        self::assertTrue($this->client->getResponse()->isRedirect(self::BASE.'/'.$quote->getId()), 'The shop lands on the sheet of the quote.');
        $saved = $this->reread($quote);
        self::assertSame('quoted', $saved->getState());
        self::assertSame(2, $saved->getCurrentRevision());
        $sent = $this->revisionOf($quote, 2);
        self::assertSame('sent', $sent->getState());
        self::assertSame('1620.000000', $sent->getTotalTaxed(), 'The figure of the example: (1 360 + 15) x 1.2 - 30.');
        self::assertCount(3, $this->linesOf($sent), 'What was on the screen is what was sent.');
        self::assertNotNull($saved->getValidUntil(), 'The quote has a last day.');
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-success"]'));

        $crawler = $this->client->request('GET', $this->editUrl($quote));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="quote-negotiation-form"]'), 'A quote waiting for the buyer is not written.');
        self::assertCount(0, $crawler->filter('[data-testid="quote-new-revision"]'));
        self::assertCount(1, $crawler->filter('[data-testid="quote-negotiation-locked"]'));
        self::assertSame($this->money(1620.0), trim($crawler->filter('[data-testid="quote-total-taxed"]')->text()));
    }

    public function testASendingThatTheRulesRefuseIsShownAndTheQuoteStaysARequest(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];

        // 100 off with taxes on a draft that comes to 20: the core refuses to send.
        $this->client->request('POST', $this->url($quote, '/send'), $this->form([$this->addedLine($scenario['c'], 1.0, '20')], ['discount' => '5000']));

        self::assertTrue($this->client->getResponse()->isRedirect($this->editUrl($quote)));
        self::assertStringContainsString('discount cannot be higher', $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]')->text());
        self::assertSame('requested', $this->reread($quote)->getState());
        self::assertSame('draft', $this->revisionOf($quote, 2)->getState());
    }

    public function testAForgedWriteOnARevisionThatWasSentIsRefusedWithAMessageAndChangesNothing(): void
    {
        $scenario = $this->sentSecondRevision();
        $quote = $scenario['quote'];
        $before = $this->snapshot($quote);
        $lines = $this->lines($scenario, 12.0, '1', 4.0, '1', 5.0);

        $this->save($quote, $lines, ['discount' => '0', 'postage' => '0']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A refusal is no server error.');
        self::assertStringContainsString('not in a state that allows this', $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]')->text());

        $this->client->request('POST', $this->url($quote, '/send'), $this->form($lines));
        self::assertStringContainsString('not in a state that allows this', $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]')->text());

        self::assertSame($before, $this->snapshot($quote), 'The revision that was sent is as it was sent.');
    }

    public function testSavingOnAQuoteTheBuyerCounteredNeverRewritesTheRevisionThatWasSent(): void
    {
        $scenario = $this->counteredSecondRevision();
        $quote = $scenario['quote'];
        $sent = $this->snapshotOfRevision($quote, 2);

        $this->save($quote, $this->lines($scenario, 99.0, '1', 4.0, '45', 5.0), ['discount' => '0', 'postage' => '0']);

        self::assertSame($sent, $this->snapshotOfRevision($quote, 2), 'The revision that was sent keeps its lines.');
        $third = $this->revisionOf($quote, 3);
        self::assertSame('draft', $third->getState(), 'The write went to the next revision.');
        self::assertSame('1.000000', $this->lineOf($third, $scenario['a']->getRef())->getPrice());
    }

    public function testTheNextRevisionShowsWhatTheBuyerAskedBesideWhatTheShopProposedAndTheHistoryHoldsThreeRevisions(): void
    {
        $scenario = $this->counteredSecondRevision();
        $quote = $scenario['quote'];

        $crawler = $this->client->request('GET', $this->editUrl($quote));
        self::assertCount(1, $crawler->filter('[data-testid="quote-new-revision"]'));
        $this->client->submit($crawler->filter('[data-testid="quote-new-revision-form"]')->form());
        $crawler = $this->client->followRedirect();

        $rowOfA = $this->rowOfProduct($crawler, 'Product A');
        self::assertSame($this->money(85.0), trim($rowOfA->filter('[data-testid="quote-line-asked-price"]')->text()), 'The price the buyer asked, beside the one of the shop.');
        self::assertSame('-', trim($rowOfA->filter('[data-testid="quote-line-asked-quantity"]')->text()), 'The quantity was left as it is.');
        self::assertSame('90.00', $rowOfA->filter('[data-testid="quote-line-price-input"]')->attr('value'));
        self::assertSame($this->money(100.0), trim($rowOfA->filter('[data-testid="quote-line-catalog-price"]')->text()));
        self::assertSame('-', trim($this->rowOfProduct($crawler, 'Product B')->filter('[data-testid="quote-line-asked-price"]')->text()));

        $this->client->request('POST', $this->url($quote, '/send'), $this->form($this->lines($scenario, 12.0, '87', 4.0, '45', 5.0), ['discount' => '30', 'postage' => '15']));
        $third = $this->revisionOf($quote, 3);
        self::assertSame('1576.800000', $third->getTotalTaxed());

        $crawler = $this->client->request('GET', $this->url($quote, '?tab=history'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $rows = $crawler->filter('[data-testid^="quote-history-row-"]');
        self::assertCount(3, $rows, 'The history of the example holds three revisions.');
        self::assertSame(['3', '2', '1'], $rows->each(static fn (Crawler $row): string => (string) preg_replace('/\D+/', '', $row->filter('[data-testid="quote-history-number"]')->text())));
        self::assertSame(['admin', 'admin', 'customer'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-author')));
        self::assertSame(
            [$this->money(1576.8), $this->money(1620.0), $this->money((float) $this->revisionOf($quote, 1)->getTotalTaxed())],
            $rows->each(static fn (Crawler $row): string => trim($row->filter('[data-testid="quote-history-total"]')->text())),
            'Each revision shows the total it was sent with.',
        );
    }

    // ------------------------------------------------------------------
    // The history
    // ------------------------------------------------------------------

    public function testTheHistoryTabNamesWhoWroteEachRevisionAndWhenAndLinksTheEarlierOnes(): void
    {
        $scenario = $this->counteredSecondRevision();
        $quote = $scenario['quote'];
        $router = $this->getService(RouterInterface::class);

        foreach ([$this->url($quote, '?tab=history'), $this->url($quote, '/edit?tab=history')] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
            $rows = $crawler->filter('[data-testid^="quote-history-row-"]');
            self::assertCount(2, $rows, $url);

            $second = $crawler->filter('[data-testid="quote-history-row-2"]');
            self::assertStringContainsString('Shop', $second->filter('[data-testid="quote-history-author"]')->text());
            self::assertStringContainsString('Sent', $second->filter('[data-testid="quote-history-state"]')->text());
            self::assertStringContainsString('Current', $second->filter('[data-testid="quote-history-number"]')->text());
            self::assertNotSame('-', trim($second->filter('[data-testid="quote-history-date"]')->text()));
            self::assertSame($router->generate('admin.quote.revision', ['id' => $quote->getId(), 'number' => 2]), $second->filter('[data-testid="quote-history-open"]')->attr('href'));

            $first = $crawler->filter('[data-testid="quote-history-row-1"]');
            self::assertStringContainsString('Buyer', $first->filter('[data-testid="quote-history-author"]')->text());
        }
    }

    public function testAnEarlierRevisionOpensReadOnlyAsItWasSent(): void
    {
        $scenario = $this->counteredSecondRevision();
        $quote = $scenario['quote'];

        $crawler = $this->client->request('GET', $this->url($quote, '/revisions/1'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="quote-earlier-revision"]'));
        self::assertSame('1', trim($crawler->filter('[data-testid="quote-revision-number"]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid="quote-negotiation-form"]'));
        $prices = $crawler->filter('[data-testid="quote-line-price"]')->each(static fn (Crawler $cell): string => trim($cell->text()));
        self::assertSame([$this->money(100.0), $this->money(50.0)], $prices, 'The lines of revision 1, not the ones of revision 2.');

        $crawler = $this->client->request('GET', $this->url($quote, '/revisions/2'));
        self::assertCount(0, $crawler->filter('[data-testid="quote-earlier-revision"]'), 'The revision the quote stands at is not an earlier one.');
        self::assertContains($this->money(90.0), $crawler->filter('[data-testid="quote-line-price"]')->each(static fn (Crawler $cell): string => trim($cell->text())));

        $this->client->request('GET', $this->url($quote, '/revisions/77'));
        self::assertTrue($this->client->getResponse()->isRedirect(self::BASE.'/'.$quote->getId()), 'A revision that does not exist sends back to the sheet.');
    }

    public function testAnEarlierRevisionIsTakenUpAsTheBaseOfANewDraft(): void
    {
        $scenario = $this->counteredSecondRevision();
        $quote = $scenario['quote'];
        $before = $this->snapshotOfRevision($quote, 2);

        $crawler = $this->client->request('GET', $this->url($quote, '/revisions/1'));
        $this->client->submit($crawler->filter('[data-testid="quote-resume-form"]')->form());

        self::assertTrue($this->client->getResponse()->isRedirect($this->editUrl($quote)));
        $draft = $this->revisionOf($quote, 3);
        self::assertSame('draft', $draft->getState());
        self::assertSame(['100.000000', '50.000000'], array_map(static fn (QuoteItem $line): string => (string) $line->getPrice(), $this->linesOf($draft)), 'The draft starts from the lines of revision 1.');
        self::assertSame($before, $this->snapshotOfRevision($quote, 2), 'Revision 2 is as it was.');
        self::assertSame(2, $this->reread($quote)->getCurrentRevision());

        $crawler = $this->client->request('GET', $this->url($quote, '/revisions/1'));
        self::assertCount(0, $crawler->filter('[data-testid="quote-resume"]'), 'A draft is being written: no second one is offered.');
    }

    public function testAnEarlierRevisionNeverReplacesTheDraftBeingWritten(): void
    {
        $scenario = $this->counteredSecondRevision();
        $quote = $scenario['quote'];
        $this->save($quote, $this->lines($scenario, 12.0, '88', 4.0, '45'), ['discount' => '0', 'postage' => '0']);
        $before = $this->snapshotOfRevision($quote, 3);

        $this->client->request('POST', $this->url($quote, '/revisions/1/resume'), ['_token' => $this->sessionToken()]);

        self::assertTrue($this->client->getResponse()->isRedirect($this->editUrl($quote)));
        self::assertStringContainsString('already being written', $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]')->text());
        self::assertSame($before, $this->snapshotOfRevision($quote, 3), 'What the shop wrote is kept.');

        $this->client->request('POST', $this->url($quote, '/revisions/3/resume'), ['_token' => $this->sessionToken()]);
        self::assertStringContainsString('does not exist', $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]')->text(), 'A draft is not a revision that was sent: it is no base for another.');
        self::assertSame($before, $this->snapshotOfRevision($quote, 3));
    }

    public function testTakingUpARevisionLeavesOutTheLinesOfAProductThatIsGoneAndSaysSo(): void
    {
        $buyer = $this->customer('Gone', 'Lines');
        $product = $this->product('10', 'Still here');
        $quote = $this->aQuote($buyer, ['state' => 'countered', 'validUntil' => $this->inDays(5), 'total' => '10.000000']);
        $revision = $this->aRevisionOf($quote, 1);
        $this->aLine($revision, 'Still here', 1.0, '10', '10', $this->saleElementId($product), 1);
        $this->aLine($revision, 'Gone product', 1.0, '10', '10', null, 2);

        $this->client->request('POST', $this->url($quote, '/revisions/1/resume'), ['_token' => $this->sessionToken()]);
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('[data-testid="bo-flash-warning"]'));
        self::assertSame(1, QuoteItemQuery::create()->useQuoteRevisionQuery()->filterByQuoteId($quote->getId())->filterByNumber(2)->endUse()->count());
        $this->client->request('GET', $this->url($quote, '/revisions/1'));
        self::assertStringContainsString('Gone product', (string) $this->client->getResponse()->getContent(), 'The revision stays readable once the product is gone.');
    }

    public function testALineWhoseProductWasDeletedStaysReadableAndIsLeftOutWhenTheDraftIsSaved(): void
    {
        $buyer = $this->customer('Deleted', 'Product');
        $product = $this->product('10', 'Kept product');
        $quote = $this->aQuote($buyer, ['state' => 'draft', 'revisionState' => 'draft', 'total' => null]);
        $revision = $this->aRevisionOf($quote, 1);
        $kept = $this->aLine($revision, 'Kept product', 2.0, '10', '9', $this->saleElementId($product), 1);
        $this->aLine($revision, 'Deleted product title', 1.0, '10', '10', null, 2);

        $crawler = $this->client->request('GET', $this->editUrl($quote));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Deleted product title', $crawler->filter('[data-testid="quote-lines-table"]')->text(), 'The label of the line is the one written with it.');
        self::assertCount(1, $crawler->filter('[data-testid="quote-gone-lines"]'));

        $this->save($quote, [['item' => (string) $kept->getId(), 'pse' => (string) $this->saleElementId($product), 'quantity' => '2', 'price' => '9']], ['discount' => '0', 'postage' => '0']);

        self::assertSame(['Kept product'], array_map(static fn (QuoteItem $line): string => (string) $line->getTitle(), $this->linesOf($this->revisionOf($quote, 1))));
    }

    public function testTheFormTheScreenRendersIsTheFormTheShopPostsBackLineForLine(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $idsBefore = array_map(static fn (QuoteItem $line): int => (int) $line->getId(), $this->linesOf($this->revisionOf($quote, 2)));

        $crawler = $this->client->request('GET', $this->editUrl($quote));
        $form = $crawler->filter('[data-testid="quote-save"]')->form();
        $values = $form->getPhpValues();
        $values['lines'][0]['price'] = '95';
        $values['lines'][1]['quantity'] = '6';
        $values['discount'] = '12.5';
        $this->client->request('POST', $form->getUri(), $values);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $lines = $this->linesOf($this->revisionOf($quote, 2));
        self::assertSame($idsBefore, array_map(static fn (QuoteItem $line): int => (int) $line->getId(), $lines), 'The lines the form names are the lines that are written, not new ones.');
        self::assertSame(['95.000000', '50.000000'], array_map(static fn (QuoteItem $line): string => (string) $line->getPrice(), $lines));
        self::assertSame([10.0, 6.0], array_map(static fn (QuoteItem $line): float => (float) $line->getQuantity(), $lines));
        self::assertSame('12.500000', $this->revisionOf($quote, 2)->getDiscount());

        $crawler = $this->client->request('GET', $this->editUrl($quote));
        $send = $crawler->filter('[data-testid="quote-send"]');
        $values = $crawler->filter('[data-testid="quote-save"]')->form()->getPhpValues();
        $this->client->request('POST', (string) $send->attr('formaction'), $values);
        self::assertSame('quoted', $this->reread($quote)->getState(), 'The send button of the screen sends what the form holds.');
    }

    public function testADraftOnAQuoteTheRulesDoNotLetTheShopReviseIsNotAForm(): void
    {
        $quote = $this->aQuote($this->customer('Locked', 'Draft'), ['state' => 'accepted', 'validUntil' => $this->inDays(5), 'currentRevision' => 2, 'revisionState' => 'draft']);
        $this->aLine($this->aRevisionOf($quote, 2), 'Locked line', 1.0, '10', '10', null, 1);

        $crawler = $this->client->request('GET', $this->editUrl($quote));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="quote-negotiation-form"]'));
        self::assertCount(1, $crawler->filter('[data-testid="quote-negotiation-locked"]'));
    }

    public function testTheNoteOfTheShopIsEscapedInTheEditorWhateverIsStored(): void
    {
        $quote = $this->aQuote($this->customer('Note', 'Escaped'), ['state' => 'draft', 'revisionState' => 'draft', 'total' => null, 'merchantNote' => '</textarea><i id="injected-note">x</i>']);

        $crawler = $this->client->request('GET', $this->editUrl($quote));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('#injected-note'), 'A note cannot close the field it is shown in.');
        self::assertStringContainsString('</textarea><i id="injected-note">x</i>', $crawler->filter('[data-testid="quote-merchant-note-input"]')->text());
    }

    public function testTheRevisionBeingWrittenIsInTheHistoryWithItsTotalSoFarAndOpensTheEditor(): void
    {
        $scenario = $this->counteredSecondRevision();
        $quote = $scenario['quote'];
        $this->client->request('POST', $this->url($quote, '/revisions/2/resume'), ['_token' => $this->sessionToken()]);

        $crawler = $this->client->request('GET', $this->url($quote, '?tab=history'));
        $draft = $crawler->filter('[data-testid="quote-history-row-3"]');

        self::assertSame('draft', $draft->attr('data-revision-state'));
        self::assertStringContainsString('Draft', $draft->filter('[data-testid="quote-history-state"]')->text());
        self::assertSame($this->money(1620.0), trim($draft->filter('[data-testid="quote-history-total"]')->text()), 'The draft starts from the lines of revision 2: the total is the one they come to.');
        self::assertSame($this->editUrl($quote), $draft->filter('[data-testid="quote-history-open"]')->attr('href'), 'The revision being written opens in the editor.');
        self::assertCount(0, $crawler->filter('[data-testid="quote-history-resume"]'), 'A draft is being written: no other is offered.');

        $this->client->request('GET', $this->url($quote, '/revisions/3'));
        self::assertTrue($this->client->getResponse()->isRedirect(self::BASE.'/'.$quote->getId()), 'A draft is not read as a revision that was sent.');
    }

    public function testTheHistoryOffersEachSentRevisionAsTheBaseOfANewOneAndTheFormNamesThatRevision(): void
    {
        $scenario = $this->counteredSecondRevision();
        $quote = $scenario['quote'];
        $router = $this->getService(RouterInterface::class);

        $crawler = $this->client->request('GET', $this->url($quote, '?tab=history'));

        foreach ([1, 2] as $number) {
            $form = $crawler->filter('[data-testid="quote-history-row-'.$number.'"] [data-testid="quote-history-resume-form"]');
            self::assertCount(1, $form, 'Revision '.$number);
            self::assertSame($router->generate('admin.quote.resume', ['id' => $quote->getId(), 'number' => $number]), $form->attr('action'));
        }

        $this->client->submit($crawler->filter('[data-testid="quote-history-row-1"] [data-testid="quote-history-resume-form"]')->form());
        self::assertSame(['100.000000', '50.000000'], array_map(static fn (QuoteItem $line): string => (string) $line->getPrice(), $this->linesOf($this->revisionOf($quote, 3))), 'The row of revision 1 takes revision 1 up.');
    }

    // ------------------------------------------------------------------
    // The rights, the token and the routes
    // ------------------------------------------------------------------

    public function testTheRightToViewReadsTheHistoryAndAnEarlierRevisionButWritesNothingOnTheScreen(): void
    {
        $scenario = $this->counteredSecondRevision();
        $quote = $scenario['quote'];
        $token = $this->sessionToken();
        $before = $this->snapshot($quote);
        $this->injector->setAdmin($this->restricted([AdminResources::QUOTE => [AccessManager::VIEW]]));

        foreach ([$this->url($quote, '/revisions/1'), $this->url($quote, '?tab=history')] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
        }

        $crawler = $this->client->request('GET', $this->url($quote, '/revisions/1'));
        self::assertCount(0, $crawler->filter('[data-testid="quote-resume"]'), 'The gesture the administrator may not make is not offered.');
        $crawler = $this->client->request('GET', $this->url($quote, '?tab=history'));
        self::assertCount(0, $crawler->filter('[data-testid="quote-history-resume"]'));

        foreach ($this->writesAndScreens($quote, $token, $this->lines($scenario, 12.0, '1', 4.0, '1')) as [$method, $url, $payload]) {
            $this->client->request($method, $url, $payload);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $method.' '.$url);
        }

        self::assertSame($before, $this->snapshot($quote));
    }

    public function testTheRightToUpdateWritesTheDraftButDoesNotReadTheRevisionsOfTheHistory(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $token = $this->sessionToken();
        $this->injector->setAdmin($this->restricted([AdminResources::QUOTE => [AccessManager::UPDATE]]));

        $this->client->request('GET', $this->editUrl($quote));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', $this->url($quote, '/draft'), $this->form($this->lines($scenario, 11.0, '95', 4.0, '50'), [], $token));
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('95.000000', $this->lineOf($this->revisionOf($quote, 2), $scenario['a']->getRef())->getPrice());

        $this->client->request('GET', $this->url($quote, '/products?q='.$scenario['a']->getRef()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The search of products belongs to the screen that writes.');

        $this->client->request('GET', $this->url($quote, '/revisions/1'));
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Reading a revision asks for the right to view.');
    }

    public function testAnAdministratorWithoutTheRightOnQuotesIsRefusedOnEveryRouteOfTheScreen(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $token = $this->sessionToken();
        $before = $this->snapshot($quote);
        $this->injector->setAdmin($this->restricted([AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE]]));

        foreach ([...$this->writesAndScreens($quote, $token, $this->lines($scenario, 12.0, '1', 4.0, '1')), ['GET', $this->url($quote, '/revisions/1'), []]] as [$method, $url, $payload]) {
            $this->client->request($method, $url, $payload);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $method.' '.$url);
        }

        self::assertSame($before, $this->snapshot($quote));
    }

    public function testEveryWriteOfTheScreenIsRefusedWithoutTheTokenAndChangesNothing(): void
    {
        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $before = $this->snapshot($quote);

        foreach ($this->writesAndScreens($quote, 'not-the-token', $this->lines($scenario, 12.0, '1', 4.0, '1')) as [$method, $url, $payload]) {
            if ('POST' !== $method) {
                continue;
            }

            $this->client->request($method, $url, $payload);
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $url);
            self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-error"], [data-testid="bo-flash-danger"]'), 'The form that expired is sent back with a message: '.$url);
        }

        self::assertSame($before, $this->snapshot($quote));
    }

    public function testTheRoutesAreNamedByTheConventionAndTheWritesAnswerToPostOnly(): void
    {
        $router = $this->getService(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $routes = $router->getRouteCollection();

        self::assertNotNull($routes->get('admin.quote.edit'));
        self::assertNull($routes->get('admin_quote_edit'), 'The old name is gone.');
        self::assertSame(['GET'], $routes->get('admin.quote.edit')?->getMethods());

        foreach (['admin.quote.save', 'admin.quote.autosave', 'admin.quote.send', 'admin.quote.resume'] as $name) {
            self::assertSame(['POST'], $routes->get($name)?->getMethods(), $name);
        }
        foreach (['admin.quote.product_search', 'admin.quote.revision'] as $name) {
            self::assertSame(['GET'], $routes->get($name)?->getMethods(), $name);
        }

        $scenario = $this->requestedWithDraft();
        $quote = $scenario['quote'];
        $crawler = $this->client->request('GET', $this->editUrl($quote));
        $negotiation = $crawler->filter('[data-testid="quote-negotiation"]');

        self::assertSame($router->generate('admin.quote.save', ['id' => $quote->getId()]), $crawler->filter('[data-testid="quote-negotiation-form"]')->attr('action'));
        self::assertCount(1, $crawler->filter('[data-testid="quote-negotiation-form"] input[name="_token"]'), 'The form carries the anti-replay token.');
        self::assertSame('1', $crawler->filter('[data-testid="quote-negotiation-form"] input[name="edited"]')->attr('value'));
        self::assertSame($router->generate('admin.quote.send', ['id' => $quote->getId()]), $crawler->filter('[data-testid="quote-send"]')->attr('formaction'));
        self::assertSame($router->generate('admin.quote.product_search', ['id' => $quote->getId()]), $negotiation->attr('data-bo-quote-negotiation-search-url-value'));
        self::assertSame($router->generate('admin.quote.autosave', ['id' => $quote->getId()]), $negotiation->attr('data-bo-quote-negotiation-autosave-url-value'), 'The address of the background save is the router\'s, the script holds none.');
        self::assertSame($router->generate('admin.quote.edit', ['id' => $quote->getId(), 'tab' => 'lines']), $crawler->filter('[data-testid="quote-tab-lines"]')->attr('href'));
        self::assertSame($router->generate('admin.quote.edit', ['id' => $quote->getId(), 'tab' => 'history']), $crawler->filter('[data-testid="quote-tab-history"]')->attr('href'));
    }

    // ------------------------------------------------------------------
    // The cost of the screen
    // ------------------------------------------------------------------

    public function testTheScreenCostsTheSameQueriesForOneLineAndForTwenty(): void
    {
        $few = $this->draftOfLines(1);
        $many = $this->draftOfLines(20);

        $this->client->request('GET', $this->editUrl($few));
        $queriesFew = QueryCounter::count(fn (): Crawler => $this->client->request('GET', $this->editUrl($few)));
        $queriesMany = QueryCounter::count(fn (): Crawler => $this->client->request('GET', $this->editUrl($many)));

        self::assertSame(1, \count($this->linesOf($this->revisionOf($few, 1))));
        self::assertSame(20, \count($this->linesOf($this->revisionOf($many, 1))));
        self::assertSame($queriesFew, $queriesMany, 'The lines, what was asked on them and their combinations are read in a fixed number of queries.');

        $queriesFew = QueryCounter::count(fn (): Crawler => $this->client->request('GET', $this->url($few, '/revisions/1')));
        $queriesMany = QueryCounter::count(fn (): Crawler => $this->client->request('GET', $this->url($many, '/revisions/1')));
        self::assertSame($queriesFew, $queriesMany, 'An earlier revision is read the same way.');
    }

    // ------------------------------------------------------------------
    // Inside
    // ------------------------------------------------------------------

    /**
     * @return iterable<int, array{string, string, array<string, mixed>}>
     */
    private function writesAndScreens(Quote $quote, string $token, array $lines): iterable
    {
        yield ['GET', $this->editUrl($quote), []];
        yield ['GET', $this->url($quote, '/products?q=product'), []];
        yield ['POST', $this->url($quote, '/draft'), $this->form($lines, [], $token)];
        yield ['POST', $this->url($quote, '/send'), $this->form($lines, [], $token)];
        yield ['POST', $this->url($quote, '/revisions/1/resume'), ['_token' => $token]];
    }

    /**
     * A quote asked for by a buyer who wants 10 of A at 100 and 4 of B at 50, 20 % VAT; C is the
     * product the shop adds, at 20.
     *
     * @return array{buyer: Customer, quote: Quote, a: Product, b: Product, c: Product}
     */
    private function requested(): array
    {
        $buyer = $this->customer('Quote', 'Buyer');
        $a = $this->product('100', 'Product A');
        $b = $this->product('50', 'Product B');
        $c = $this->product('20', 'Product C');
        $cart = $this->factory->cart($buyer, ['currency' => Currency::getDefaultCurrency()]);
        $this->factory->cartItem($cart, $a, null, ['quantity' => 10.0, 'price' => '100', 'promoPrice' => '100']);
        $this->factory->cartItem($cart, $b, null, ['quantity' => 4.0, 'price' => '50', 'promoPrice' => '50']);

        $facade = $this->getService(QuoteFacade::class);
        \assert($facade instanceof QuoteFacade);

        return ['buyer' => $buyer, 'quote' => $facade->requestFromCart($cart, $buyer, new QuoteRequestInput()), 'a' => $a, 'b' => $b, 'c' => $c];
    }

    /**
     * @return array{buyer: Customer, quote: Quote, a: Product, b: Product, c: Product}
     */
    private function requestedWithDraft(): array
    {
        $scenario = $this->requested();
        $this->client->request('POST', $this->url($scenario['quote'], '/revisions/1/resume'), ['_token' => $this->sessionToken()]);
        self::assertSame('draft', $this->revisionOf($scenario['quote'], 2)->getState());

        return $scenario;
    }

    /**
     * The example of the shop: 12 of A at 90, 4 of B at 45, 5 of C at 20, 30 off, 15 of postage.
     *
     * @return array{buyer: Customer, quote: Quote, a: Product, b: Product, c: Product}
     */
    private function sentSecondRevision(): array
    {
        $scenario = $this->requestedWithDraft();
        $this->client->request('POST', $this->url($scenario['quote'], '/send'), $this->form($this->lines($scenario, 12.0, '90', 4.0, '45', 5.0), ['discount' => '30', 'postage' => '15']));
        self::assertSame('quoted', $this->reread($scenario['quote'])->getState());

        return $scenario;
    }

    /**
     * The same quote once the buyer asked 85 for A.
     *
     * @return array{buyer: Customer, quote: Quote, a: Product, b: Product, c: Product}
     */
    private function counteredSecondRevision(): array
    {
        $scenario = $this->sentSecondRevision();
        $quote = $scenario['quote'];
        $facade = $this->getService(QuoteFacade::class);
        \assert($facade instanceof QuoteFacade);
        $line = $this->lineOf($this->revisionOf($quote, 2), $scenario['a']->getRef());
        $facade->counterOffer($this->reread($quote), $scenario['buyer'], 2, [new QuoteItemRequestInput((int) $line->getId(), null, '85')]);
        self::assertSame('countered', $this->reread($quote)->getState());

        return $scenario;
    }

    /**
     * A draft of the shop of $count lines, written straight to the tables.
     */
    private function draftOfLines(int $count): Quote
    {
        $quote = $this->aQuote($this->customer('Lines', 'Count'.$count), ['state' => 'draft', 'revisionState' => 'draft', 'total' => null]);
        $revision = $this->aRevisionOf($quote, 1);

        for ($position = 1; $position <= $count; ++$position) {
            $this->aLine($revision, 'Line '.$position, 1.0, '10', '10', $this->saleElementId($this->product('10', 'Counted '.$count.' '.$position)), $position);
        }

        return $quote;
    }

    /**
     * The lines the screen posts for the example: A, B and, when a quantity is given, C.
     *
     * @param array{buyer: Customer, quote: Quote, a: Product, b: Product, c: Product} $scenario
     *
     * @return list<array<string, string>>
     */
    private function lines(array $scenario, float $quantityOfA, string $priceOfA, float $quantityOfB, string $priceOfB, ?float $quantityOfC = null): array
    {
        $draft = $this->draftOf($scenario['quote']);
        $lines = [
            ['item' => (string) $this->lineOf($draft, $scenario['a']->getRef())->getId(), 'pse' => (string) $this->saleElementId($scenario['a']), 'quantity' => (string) $quantityOfA, 'price' => $priceOfA],
            ['item' => (string) $this->lineOf($draft, $scenario['b']->getRef())->getId(), 'pse' => (string) $this->saleElementId($scenario['b']), 'quantity' => (string) $quantityOfB, 'price' => $priceOfB],
        ];

        if (null !== $quantityOfC) {
            $lines[] = ['pse' => (string) $this->saleElementId($scenario['c']), 'quantity' => (string) $quantityOfC, 'price' => '20'];
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private function addedLine(Product $product, float $quantity, string $price): array
    {
        return ['pse' => (string) $this->saleElementId($product), 'quantity' => (string) $quantity, 'price' => $price];
    }

    /**
     * @param list<array<string, string>> $lines
     * @param array<string, string>       $foot
     *
     * @return array<string, mixed>
     */
    private function form(array $lines, array $foot = [], ?string $token = null): array
    {
        return array_merge([
            '_token' => $token ?? $this->sessionToken(),
            'edited' => '1',
            'lines' => $lines,
            'discount' => '0',
            'postage' => '0',
            'delivery_module_id' => '',
            'valid_until' => '',
            'merchant_note' => '',
        ], $foot);
    }

    /**
     * @param list<array<string, string>> $lines
     * @param array<string, string>       $foot
     */
    private function save(Quote $quote, array $lines, array $foot = []): void
    {
        $this->client->request('POST', $this->url($quote, '/draft'), $this->form($lines, $foot));
    }

    /**
     * @param list<array<string, string>> $lines
     * @param array<string, string>       $foot
     *
     * @return array<string, mixed>
     */
    private function autosave(Quote $quote, array $lines, array $foot = [], int $status = 200): array
    {
        $this->client->request('POST', $this->url($quote, '/draft/autosave'), $this->form($lines, $foot), [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame($status, $this->client->getResponse()->getStatusCode());

        return $this->answer();
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function url(Quote $quote, string $suffix): string
    {
        return self::BASE.'/'.$quote->getId().$suffix;
    }

    private function editUrl(Quote $quote): string
    {
        return self::BASE.'/'.$quote->getId().'/edit';
    }

    /**
     * The revision the screen posts lines from: the draft, or the revision that was sent when there is none.
     */
    private function draftOf(Quote $quote): QuoteRevision
    {
        QuoteRevisionTableMap::clearInstancePool();

        return QuoteRevisionQuery::create()->filterByQuoteId($quote->getId())->filterByState('draft')->findOne()
            ?? $this->revisionOf($this->reread($quote), (int) $this->reread($quote)->getCurrentRevision());
    }

    private function revisionOf(Quote $quote, int $number): QuoteRevision
    {
        QuoteRevisionTableMap::clearInstancePool();

        return QuoteRevisionQuery::create()->filterByQuoteId($quote->getId())->filterByNumber($number)->findOne() ?? throw new \LogicException('No revision '.$number);
    }

    /**
     * @return list<QuoteItem>
     */
    private function linesOf(QuoteRevision $revision): array
    {
        QuoteItemTableMap::clearInstancePool();

        return [...QuoteItemQuery::create()->filterByQuoteRevisionId($revision->getId())->orderByPosition()->find()];
    }

    private function lineOf(QuoteRevision $revision, string $productRef): QuoteItem
    {
        foreach ($this->linesOf($revision) as $line) {
            if ($line->getProductRef() === $productRef) {
                return $line;
            }
        }

        throw new \LogicException('No line '.$productRef);
    }

    private function reread(Quote $quote): Quote
    {
        QuoteTableMap::clearInstancePool();

        return QuoteQuery::create()->findPk($quote->getId()) ?? throw new \LogicException('The quote is gone.');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Quote $quote): array
    {
        QuoteRevisionTableMap::clearInstancePool();
        QuoteItemTableMap::clearInstancePool();

        $saved = $this->reread($quote);

        return [
            'quote' => [$saved->getState(), $saved->getCurrentRevision(), $saved->getValidUntil('Y-m-d'), $saved->getDeliveryModuleId(), $saved->getRemindedAt('Y-m-d H:i:s')],
            'revisions' => array_map(fn (QuoteRevision $revision): array => $this->rowOfRevision($revision), [...QuoteRevisionQuery::create()->filterByQuoteId($quote->getId())->orderByNumber()->find()]),
            'items' => array_map(fn (QuoteItem $item): array => $this->rowOfItem($item), [...QuoteItemQuery::create()->useQuoteRevisionQuery()->filterByQuoteId($quote->getId())->endUse()->orderById()->find()]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOfRevision(Quote $quote, int $number): array
    {
        $revision = $this->revisionOf($quote, $number);

        return [
            'revision' => $this->rowOfRevision($revision),
            'items' => array_map(fn (QuoteItem $item): array => $this->rowOfItem($item), [...QuoteItemQuery::create()->filterByQuoteRevisionId($revision->getId())->orderById()->find()]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOfRevision(QuoteRevision $revision): array
    {
        return [$revision->getId(), $revision->getNumber(), $revision->getAuthor(), $revision->getState(), $revision->getMerchantNote(), $revision->getDiscount(), $revision->getPostage(), $revision->getTotalTaxed(), $revision->getTotalUntaxed()];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOfItem(QuoteItem $item): array
    {
        return [$item->getId(), $item->getQuoteRevisionId(), $item->getPosition(), $item->getProductSaleElementsId(), $item->getTitle(), $item->getQuantity(), $item->getCatalogPrice(), $item->getPrice()];
    }

    private function rowOfProduct(Crawler $crawler, string $title): Crawler
    {
        $row = $crawler->filter('[data-testid="quote-lines-table"] tbody tr')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), $title))->first();
        self::assertCount(1, $row, 'The row of '.$title);

        return $row;
    }

    private function sessionToken(): string
    {
        $html = (string) $this->client->request('GET', self::BASE)->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
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

    private function product(string $price, string $title): Product
    {
        return $this->factory->product($this->factory->category(), $this->vatRule(), Currency::getDefaultCurrency(), ['basePrice' => (float) $price, 'baseQuantity' => 100, 'title' => $title]);
    }

    private function saleElementId(Product $product): int
    {
        return (int) $product->getProductSaleElementss()->getFirst()->getId();
    }

    private function deliveryModule(string $code): Module
    {
        $module = (new Module())
            ->setCode($code)
            ->setFullNamespace('Fixture\\'.$code.'\\'.$code)
            ->setVersion('1.0.0')
            ->setType(BaseModule::DELIVERY_MODULE_TYPE)
            ->setCategory('delivery')
            ->setActivate(BaseModule::IS_ACTIVATED);
        $module->save($this->getPropelConnection());

        return $module;
    }

    /**
     * 20 % VAT in the default country of the shop, and nowhere else.
     */
    private function vatRule(): TaxRule
    {
        if (null === $this->vat) {
            $this->vat = $this->factory->taxRule(['isDefault' => false]);
            $tax = $this->factory->tax(['requirements' => ['percent' => '20.000000'], 'title' => 'VAT 20']);
            (new TaxRuleCountry())
                ->setTaxRuleId($this->vat->getId())
                ->setCountryId(Country::getDefaultCountry()->getId())
                ->setTaxId($tax->getId())
                ->setPosition(1)
                ->save($this->getPropelConnection());
        }

        return $this->vat;
    }
}
