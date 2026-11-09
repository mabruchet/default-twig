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

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Customer;
use Thelia\Model\Map\PriceListItemTableMap;
use Thelia\Model\Map\PriceListTableMap;
use Thelia\Model\PriceList;
use Thelia\Model\PriceListAssignmentQuery;
use Thelia\Model\PriceListItem;
use Thelia\Model\PriceListItemQuery;
use Thelia\Model\PriceListQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The price list screens of the back office: the list and its filters, the sheet of a list
 * (settings, lines, assignments), the writes, and what they refuse: no right, no token.
 */
final class PriceListBackOfficeTest extends WebIntegrationTestCase
{
    private const LIST_URL = '/admin/pricing/price-lists';

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

    public function testTheListShowsTheListsWithTheirNumberOfLinesAndFiltersThem(): void
    {
        $pro = $this->priceList('pro-l6a');
        $this->priceList('retail-l6a', ['exclusive' => 1, 'active' => 0]);
        [, $first] = $this->productWithAPse('L6A-1');
        [, $second] = $this->productWithAPse('L6A-2');
        $this->line($pro, $first, 1, '10');
        $this->line($pro, $second, 1, '12');

        $crawler = $this->client->request('GET', self::LIST_URL);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $rows = $crawler->filter('table tbody tr');
        $proRow = $rows->reduce(static fn ($row): bool => str_contains($row->text(), 'pro-l6a'))->first();
        self::assertCount(1, $proRow);
        self::assertMatchesRegularExpression('/\b2\b/', $proRow->text(), 'The number of lines is counted by the aggregation.');

        $crawler = $this->client->request('GET', self::LIST_URL.'?q=retail-l6a');
        self::assertStringContainsString('retail-l6a', $crawler->filter('table')->text());
        self::assertStringNotContainsString('pro-l6a', $crawler->filter('table')->text(), 'The code filter leaves the other lists out.');

        $crawler = $this->client->request('GET', self::LIST_URL.'?exclusive=1&active=0&q=l6a');
        self::assertStringContainsString('retail-l6a', $crawler->filter('table')->text());
        self::assertStringNotContainsString('pro-l6a', $crawler->filter('table')->text());

        $crawler = $this->client->request('GET', self::LIST_URL.'?active=1&q=l6a');
        self::assertStringContainsString('pro-l6a', $crawler->filter('table')->text());
        self::assertStringNotContainsString('retail-l6a', $crawler->filter('table')->text());
    }

    public function testTheCreationFormProposesTheDefaultCurrency(): void
    {
        $crawler = $this->client->request('GET', self::LIST_URL);
        $selected = $crawler->filter('select[name="thelia_price_list_creation[currency_id]"] option[selected]');

        self::assertSame(1, $selected->count(), 'One currency is selected.');
        self::assertSame((string) $this->defaultCurrency()->getId(), $selected->attr('value'));
    }

    public function testAListIsCreatedAndItsSettingsAreSaved(): void
    {
        $currency = $this->defaultCurrency();
        $token = $this->formToken(self::LIST_URL, 'thelia_price_list_creation');

        $this->client->request('POST', self::LIST_URL.'/create', ['thelia_price_list_creation' => [
            'code' => 'new-l6b',
            'title' => 'New list',
            'currency_id' => (string) $currency->getId(),
            'priority' => '30',
            'exclusive' => '1',
            'active' => '1',
            'locale' => 'en_US',
            '_token' => $token,
        ]]);

        $list = PriceListQuery::create()->findOneByCode('new-l6b');
        self::assertNotNull($list, 'The list is in the database.');
        self::assertSame(30, $list->getPriority());
        self::assertSame(1, $list->getExclusive());
        self::assertSame($currency->getId(), $list->getCurrencyId());
        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_URL.'/'.$list->getId()));

        $viewUrl = self::LIST_URL.'/'.$list->getId().'?tab=settings';
        $saveToken = $this->formToken($viewUrl, 'thelia_price_list_modification');
        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/save', ['thelia_price_list_modification' => [
            'id' => (string) $list->getId(),
            'code' => 'new-l6b',
            'title' => 'Renamed',
            'currency_id' => (string) $currency->getId(),
            'priority' => '5',
            'active' => '1',
            'starts_at' => '2030-01-01T00:00',
            'ends_at' => '2030-02-01T00:00',
            'locale' => 'en_US',
            '_token' => $saveToken,
        ]]);

        PriceListTableMap::clearInstancePool();
        $saved = PriceListQuery::create()->findPk($list->getId());
        self::assertSame(5, $saved?->getPriority());
        self::assertSame(0, $saved->getExclusive(), 'An unchecked switch turns the flag off.');
        self::assertSame('2030-01-01', $saved->getStartsAt('Y-m-d'));
        self::assertSame('2030-02-01', $saved->getEndsAt('Y-m-d'));
        self::assertSame('Renamed', $saved->setLocale('en_US')->getTitle());
    }

    public function testASavedListRefusesADuplicateCodeAndAnInvertedPeriod(): void
    {
        $this->priceList('taken-l6c');
        $list = $this->priceList('other-l6c');
        $currency = $this->defaultCurrency();
        $token = $this->formToken(self::LIST_URL.'/'.$list->getId().'?tab=settings', 'thelia_price_list_modification');

        $base = ['id' => (string) $list->getId(), 'code' => 'taken-l6c', 'currency_id' => (string) $currency->getId(), 'priority' => '1', 'locale' => 'en_US', '_token' => $token];
        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/save', ['thelia_price_list_modification' => $base]);
        self::assertSame('other-l6c', PriceListQuery::create()->findPk($list->getId())?->getCode(), 'A code used by another list is refused.');

        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/save', ['thelia_price_list_modification' => [
            'code' => 'other-l6c', 'starts_at' => '2030-02-01T00:00', 'ends_at' => '2030-01-01T00:00',
        ] + $base]);
        PriceListTableMap::clearInstancePool();
        self::assertNull(PriceListQuery::create()->findPk($list->getId())?->getStartsAt(), 'A list that ends before it starts is refused.');
    }

    public function testAListIsDeletedWithItsLines(): void
    {
        $list = $this->priceList('gone-l6d');
        [, $pse] = $this->productWithAPse('L6D-1');
        $this->line($list, $pse, 1, '10');
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/delete', ['price_list_id' => $list->getId(), '_token' => $token]);

        self::assertNull(PriceListQuery::create()->findPk($list->getId()));
        self::assertSame(0, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'The lines go with the list.');
    }

    public function testTheSheetShowsTheLinesPaginatedAndSearchedInTheDatabase(): void
    {
        $list = $this->priceList('sheet-l6e');
        for ($i = 1; $i <= 27; ++$i) {
            [, $pse] = $this->productWithAPse(\sprintf('L6E-%02d', $i));
            $this->line($list, $pse, 1, (string) $i);
        }

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$list->getId().'?tab=lines');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(25, $crawler->filter('[data-testid="price-list-lines-table"] tbody tr'), 'One page of lines.');
        self::assertStringContainsString('27', $crawler->filter('[data-testid="price-list-lines-total"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="price-list-lines-pagination"]'));

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$list->getId().'?tab=lines&page=2');
        self::assertCount(2, $crawler->filter('[data-testid="price-list-lines-table"] tbody tr'), 'The second page holds the rest.');

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$list->getId().'?tab=lines&q=L6E-07');
        self::assertCount(1, $crawler->filter('[data-testid="price-list-lines-table"] tbody tr'), 'The search by reference is done in the database.');
        self::assertStringContainsString('L6E-07', $crawler->filter('[data-testid="price-list-lines-table"]')->text());
    }

    public function testALineIsAddedUpdatedAndDeleted(): void
    {
        $list = $this->priceList('lines-l6f');
        [, $pse] = $this->productWithAPse('L6F-1');
        $token = $this->sessionToken();
        $base = self::LIST_URL.'/'.$list->getId().'/lines';

        $this->client->request('POST', $base.'/add', ['_token' => $token, 'pse_ref' => 'L6F-1', 'min_quantity' => '10', 'price' => '9,5']);
        $item = PriceListItemQuery::create()->filterByPriceListId($list->getId())->findOne();
        self::assertNotNull($item, 'The line is in the database.');
        self::assertSame($pse->getId(), $item->getProductSaleElementsId());
        self::assertSame(10, $item->getMinQuantity());
        self::assertSame(9.5, (float) $item->getPrice());
        self::assertNull($item->getDiscountPercent());

        $this->client->request('POST', $base.'/'.$item->getId().'/update', ['_token' => $token, 'min_quantity' => '10', 'price' => '', 'discount_percent' => '15']);
        PriceListItemTableMap::clearInstancePool();
        $updated = PriceListItemQuery::create()->findPk($item->getId());
        self::assertNull($updated?->getPrice(), 'A discount replaces the price.');
        self::assertSame(15.0, (float) $updated->getDiscountPercent());

        $this->client->request('POST', $base.'/'.$item->getId().'/delete', ['_token' => $token]);
        self::assertSame(0, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function refusedLines(): iterable
    {
        yield 'both a price and a discount' => [['pse_ref' => 'L6G-1', 'price' => '5', 'discount_percent' => '10']];
        yield 'neither' => [['pse_ref' => 'L6G-1']];
        yield 'a negative price' => [['pse_ref' => 'L6G-1', 'price' => '-1']];
        yield 'a discount above 100' => [['pse_ref' => 'L6G-1', 'discount_percent' => '101']];
        yield 'a null discount' => [['pse_ref' => 'L6G-1', 'discount_percent' => '0']];
        yield 'a quantity of 0' => [['pse_ref' => 'L6G-1', 'price' => '5', 'min_quantity' => '0']];
        yield 'an unknown reference' => [['pse_ref' => 'nope-l6g', 'price' => '5']];
        yield 'a price that is not a number' => [['pse_ref' => 'L6G-1', 'price' => 'abc']];
    }

    /**
     * @param array<string, string> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedLines')]
    public function testALineThatBreaksARuleOfTheApiIsNotWritten(array $payload): void
    {
        $list = $this->priceList('refused-l6g');
        $this->productWithAPse('L6G-1');
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/lines/add', ['_token' => $token] + $payload);

        self::assertSame(0, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count());
    }

    public function testASecondLineForTheSameCombinationAndQuantityIsRefused(): void
    {
        $list = $this->priceList('twice-l6h');
        [, $pse] = $this->productWithAPse('L6H-1');
        $this->line($list, $pse, 5, '10');
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/lines/add', ['_token' => $token, 'pse_ref' => 'L6H-1', 'min_quantity' => '5', 'price' => '9']);
        self::assertSame(1, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count());
        $this->client->followRedirect();
        self::assertStringContainsString('already has a line for this sale element', (string) $this->client->getResponse()->getContent(), 'The refusal says why, instead of the failure of the unique index.');

        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/lines/add', ['_token' => $token, 'pse_ref' => 'L6H-1', 'min_quantity' => '6', 'price' => '9']);
        self::assertSame(2, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'Another quantity is another line.');
    }

    public function testAListIsAssignedToGroupsAndToACustomer(): void
    {
        $list = $this->priceList('assign-l6i');
        $groupA = $this->factory->customerGroup();
        $groupB = $this->factory->customerGroup();
        $customer = $this->customer('Zelda', 'Assignable');
        $token = $this->sessionToken();
        $base = self::LIST_URL.'/'.$list->getId().'/assignments';

        $this->client->request('POST', $base.'/groups', ['_token' => $token, 'group_ids' => [(string) $groupA->getId(), (string) $groupB->getId()]]);
        $assigned = PriceListAssignmentQuery::create()->filterByPriceListId($list->getId())->find();
        self::assertSame([$groupA->getId(), $groupB->getId()], array_map(static fn ($assignment): int => $assignment->getCustomerGroupId(), iterator_to_array($assigned, false)));

        $this->client->request('POST', $base.'/groups', ['_token' => $token, 'group_ids' => [(string) $groupB->getId()]]);
        self::assertSame(1, PriceListAssignmentQuery::create()->filterByPriceListId($list->getId())->filterByCustomerGroupId(null, \Propel\Runtime\ActiveQuery\Criteria::ISNOTNULL)->count(), 'The set of groups is replaced, not added to.');

        $this->client->request('POST', $base.'/customers/add', ['_token' => $token, 'customer_id' => $customer->getId()]);
        self::assertSame(1, PriceListAssignmentQuery::create()->filterByPriceListId($list->getId())->filterByCustomerId($customer->getId())->count());

        $this->client->request('POST', $base.'/customers/add', ['_token' => $token, 'customer_id' => $customer->getId()]);
        self::assertSame(1, PriceListAssignmentQuery::create()->filterByPriceListId($list->getId())->filterByCustomerId($customer->getId())->count(), 'A customer is assigned once.');
        $this->client->followRedirect();
        self::assertStringContainsString('already assigned to this customer', (string) $this->client->getResponse()->getContent(), 'The refusal says why, instead of the failure of the unique index.');

        $assignment = PriceListAssignmentQuery::create()->filterByCustomerId($customer->getId())->findOne();
        $this->client->request('POST', $base.'/'.$assignment?->getId().'/delete', ['_token' => $token]);
        self::assertSame(0, PriceListAssignmentQuery::create()->filterByPriceListId($list->getId())->filterByCustomerId($customer->getId())->count());
        self::assertSame(1, PriceListAssignmentQuery::create()->filterByPriceListId($list->getId())->count(), 'The assignment to the group stays.');
    }

    public function testRemovingAGroupFromAListFiresTheDeleteEventTheCachesPurgesListenTo(): void
    {
        $list = $this->priceList('purge-rt2');
        $group = $this->factory->customerGroup();
        $kept = $this->factory->customerGroup();
        $token = $this->sessionToken();
        $base = self::LIST_URL.'/'.$list->getId().'/assignments';
        $this->client->request('POST', $base.'/groups', ['_token' => $token, 'group_ids' => [(string) $group->getId(), (string) $kept->getId()]]);

        $removed = [];
        $this->getService(EventDispatcherInterface::class)->addListener(
            \Thelia\Model\Event\PriceListAssignmentEvent::POST_DELETE,
            static function (\Thelia\Model\Event\PriceListAssignmentEvent $event) use (&$removed): void {
                $removed[] = (int) $event->getModel()->getCustomerGroupId();
            },
        );

        $this->client->request('POST', $base.'/groups', ['_token' => $token, 'group_ids' => [(string) $kept->getId()]]);

        self::assertSame([$group->getId()], $removed, 'The removal of the group is announced, and only that one.');
        self::assertSame(1, PriceListAssignmentQuery::create()->filterByPriceListId($list->getId())->count());
    }

    public function testTheCustomersAssignedToAListArePagedSoTheOldestCanBeRemoved(): void
    {
        $list = $this->priceList('paged-rt7');
        $customerIds = [];
        for ($i = 1; $i <= 27; ++$i) {
            $customer = $this->customer('Paged', \sprintf('Assignee%02d', $i));
            (new \Thelia\Model\PriceListAssignment())->setPriceListId($list->getId())->setCustomerId($customer->getId())->save($this->getPropelConnection());
            $customerIds[] = (int) $customer->getId();
        }

        $url = self::LIST_URL.'/'.$list->getId().'?tab=assignments';
        $crawler = $this->client->request('GET', $url);
        self::assertCount(25, $crawler->filter('[data-testid^="price-list-customer-remove-"]'), 'A page lists twenty-five assignments.');
        self::assertCount(0, $crawler->filter(\sprintf('[data-testid="price-list-customer-remove-%d"]', $customerIds[0])), 'The oldest assignment is not on the first page.');
        self::assertCount(1, $crawler->filter('[data-testid="price-list-customers-pagination"]'));

        $crawler = $this->client->request('GET', $url.'&assigned_page=2');
        self::assertCount(2, $crawler->filter('[data-testid^="price-list-customer-remove-"]'));
        self::assertCount(1, $crawler->filter(\sprintf('[data-testid="price-list-customer-remove-%d"]', $customerIds[0])), 'The oldest assignment can be reached and removed.');
    }

    public function testTheCustomerSearchOfTheAssignmentsFindsACustomerByReferenceAndLeavesTheAssignedOnesOut(): void
    {
        $list = $this->priceList('byref-rt8');
        $wanted = $this->customer('Refwanted', 'Gamma');
        $assigned = $this->customer('Refassigned', 'Delta');
        (new \Thelia\Model\PriceListAssignment())->setPriceListId($list->getId())->setCustomerId($assigned->getId())->save($this->getPropelConnection());
        $url = self::LIST_URL.'/'.$list->getId().'?tab=assignments&customer_search=';

        $crawler = $this->client->request('GET', $url.urlencode((string) $wanted->getRef()));
        self::assertCount(1, $crawler->filter(\sprintf('[data-testid="price-list-customer-result-%d"]', $wanted->getId())), 'A customer is found by reference.');

        $crawler = $this->client->request('GET', $url.urlencode((string) $assigned->getRef()));
        self::assertCount(0, $crawler->filter(\sprintf('[data-testid="price-list-customer-result-%d"]', $assigned->getId())), 'A customer already assigned is not proposed again.');
    }

    public function testTheCustomerSearchIsBounded(): void
    {
        $list = $this->priceList('search-l6j');
        for ($i = 1; $i <= 23; ++$i) {
            $this->customer('Bounded', \sprintf('Searchable%02d', $i));
        }

        $url = self::LIST_URL.'/'.$list->getId().'?tab=assignments&customer_search=';

        $crawler = $this->client->request('GET', $url.'Se');
        self::assertCount(0, $crawler->filter('[data-testid^="price-list-customer-result-"]'), 'Two characters search nothing.');
        self::assertCount(1, $crawler->filter('[data-testid="price-list-customer-search-short"]'));

        $crawler = $this->client->request('GET', $url.'Searchable');
        self::assertCount(20, $crawler->filter('[data-testid^="price-list-customer-result-"]'), 'Twenty results at most, never the directory.');
    }

    public function testAnAdministratorWhoMayNotViewTheCustomersDoesNotReadThemOnTheAssignments(): void
    {
        $list = $this->priceList('directory-rt1');
        $assigned = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Ada', 'lastname' => 'Assignedrt', 'email' => 'ada.assigned.rt1@leak.test']);
        $other = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Bob', 'lastname' => 'Searchedrt', 'email' => 'bob.searched.rt1@leak.test']);
        (new \Thelia\Model\PriceListAssignment())->setPriceListId($list->getId())->setCustomerId($assigned->getId())->save($this->getPropelConnection());
        $url = self::LIST_URL.'/'.$list->getId().'?tab=assignments&customer_search=earchedr';

        // Control: who may view the customers reads them, listed and found.
        $both = $this->factory->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW, AccessManager::UPDATE], AdminResources::CUSTOMER => [AccessManager::VIEW]]);
        $both->eraseCredentials();
        $this->injector->setAdmin($both);
        $this->client->request('GET', $url);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('ada.assigned.rt1@leak.test', $content);
        self::assertStringContainsString('bob.searched.rt1@leak.test', $content);

        $pricingOnly = $this->factory->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $pricingOnly->eraseCredentials();
        $this->injector->setAdmin($pricingOnly);
        $crawler = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $content = (string) $this->client->getResponse()->getContent();
        foreach (['leak.test', 'Assignedrt', 'Searchedrt'] as $identity) {
            self::assertStringNotContainsString($identity, $content, 'Neither the assigned nor the searched customers are named to an admin who may not view the customers: '.$identity);
        }
        self::assertCount(0, $crawler->filter('[data-testid^="price-list-customer-result-"]'));
        self::assertCount(1, $crawler->filter(\sprintf('[data-testid="price-list-customer-remove-%d"]', $assigned->getId())), 'The assignment can still be removed.');

        $token = $this->sessionToken($url);
        $base = self::LIST_URL.'/'.$list->getId().'/assignments';
        $this->client->request('POST', $base.'/customers/add', ['_token' => $token, 'customer_id' => (string) $other->getId()]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, PriceListAssignmentQuery::create()->filterByPriceListId($list->getId())->filterByCustomerId($other->getId())->count(), 'A customer is not assigned by who may not see the customers.');
    }

    public function testAnAdministratorWithoutTheRightIsRefusedOnEveryScreen(): void
    {
        $list = $this->priceList('denied-l6k');
        $token = $this->sessionToken();

        $nobody = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]);
        $nobody->eraseCredentials();
        $this->injector->setAdmin($nobody);

        foreach ([
            ['GET', self::LIST_URL, []],
            ['GET', self::LIST_URL.'/'.$list->getId(), []],
            ['GET', self::LIST_URL.'/'.$list->getId().'/export', []],
            ['POST', self::LIST_URL.'/'.$list->getId().'/lines/add', ['_token' => $token, 'pse_ref' => 'x', 'price' => '1']],
            ['POST', self::LIST_URL.'/'.$list->getId().'/assignments/groups', ['_token' => $token]],
            ['POST', self::LIST_URL.'/delete', ['price_list_id' => $list->getId(), '_token' => $token]],
        ] as [$method, $url, $payload]) {
            $this->client->request($method, $url, $payload);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $method.' '.$url);
        }

        self::assertNotNull(PriceListQuery::create()->findPk($list->getId()), 'Nothing was deleted.');
    }

    public function testTheRightToViewReadsButWritesNothing(): void
    {
        $list = $this->priceList('viewer-l6l');
        [, $pse] = $this->productWithAPse('L6L-1');
        $item = $this->line($list, $pse, 1, '10');
        $token = $this->sessionToken();

        $viewer = $this->factory->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW]]);
        $viewer->eraseCredentials();
        $this->injector->setAdmin($viewer);

        foreach (['', '?tab=settings', '?tab=lines', '?tab=assignments'] as $suffix) {
            $this->client->request('GET', self::LIST_URL.'/'.$list->getId().$suffix);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The right to view opens the sheet'.$suffix);
        }

        $writes = [
            self::LIST_URL.'/'.$list->getId().'/lines/add' => ['pse_ref' => 'L6L-1', 'min_quantity' => '2', 'price' => '1'],
            self::LIST_URL.'/'.$list->getId().'/lines/'.$item->getId().'/update' => ['min_quantity' => '1', 'price' => '99'],
            self::LIST_URL.'/'.$list->getId().'/lines/'.$item->getId().'/delete' => [],
            self::LIST_URL.'/delete' => ['price_list_id' => $list->getId()],
        ];
        foreach ($writes as $url => $payload) {
            $this->client->request('POST', $url, ['_token' => $token] + $payload);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }

        PriceListItemTableMap::clearInstancePool();
        self::assertSame(1, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count());
        self::assertSame(10.0, (float) PriceListItemQuery::create()->findPk($item->getId())?->getPrice());
        self::assertNotNull(PriceListQuery::create()->findPk($list->getId()));
    }

    public function testTheLinesScreenOffersOnlyTheGesturesTheAdministratorMayMake(): void
    {
        $list = $this->priceList('gestures-rt6');
        [, $pse] = $this->productWithAPse('RT6-1');
        $item = $this->line($list, $pse, 1, '10');
        $url = self::LIST_URL.'/'.$list->getId().'?tab=lines';
        $save = \sprintf('[data-testid="price-list-line-save-%d"]', $item->getId());
        $delete = \sprintf('[data-testid="price-list-line-delete-%d"]', $item->getId());

        $updateOnly = $this->factory->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $updateOnly->eraseCredentials();
        $this->injector->setAdmin($updateOnly);
        $crawler = $this->client->request('GET', $url);
        self::assertCount(1, $crawler->filter($save), 'A line is saved with the right to update.');
        self::assertCount(0, $crawler->filter($delete), 'No delete button without the right to delete.');
        self::assertCount(0, $crawler->filter('[data-testid="price-list-line-add"]'), 'No add form without the right to create.');
        self::assertCount(0, $crawler->filter('[data-testid="price-list-import"]'), 'No import form without the right to create.');

        $all = $this->factory->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW, AccessManager::UPDATE, AccessManager::CREATE, AccessManager::DELETE]]);
        $all->eraseCredentials();
        $this->injector->setAdmin($all);
        $crawler = $this->client->request('GET', $url);
        self::assertCount(1, $crawler->filter($save));
        self::assertCount(1, $crawler->filter($delete));
        self::assertCount(1, $crawler->filter('[data-testid="price-list-line-add"]'));
        self::assertCount(1, $crawler->filter('[data-testid="price-list-import"]'));
    }

    public function testEveryWriteIsRefusedWithoutTheToken(): void
    {
        $list = $this->priceList('token-l6m');
        [, $pse] = $this->productWithAPse('L6M-1');
        $item = $this->line($list, $pse, 1, '10');
        $group = $this->factory->customerGroup();
        $customer = $this->customer('No', 'Token');
        $base = self::LIST_URL.'/'.$list->getId();

        $writes = [
            self::LIST_URL.'/delete' => ['price_list_id' => $list->getId()],
            $base.'/lines/add' => ['pse_ref' => 'L6M-1', 'min_quantity' => '2', 'price' => '1'],
            $base.'/lines/'.$item->getId().'/update' => ['min_quantity' => '1', 'price' => '99'],
            $base.'/lines/'.$item->getId().'/delete' => [],
            $base.'/assignments/groups' => ['group_ids' => [(string) $group->getId()]],
            $base.'/assignments/customers/add' => ['customer_id' => (string) $customer->getId()],
            $base.'/import/upload' => [],
        ];

        foreach ($writes as $url => $payload) {
            $this->client->request('POST', $url, $payload + ['_token' => 'not-the-token']);
        }

        // The same writes with the token in the query string only.
        $token = $this->sessionToken();
        foreach ($writes as $url => $payload) {
            $this->client->request('POST', $url.'?_token='.$token, $payload);
        }

        PriceListItemTableMap::clearInstancePool();
        self::assertNotNull(PriceListQuery::create()->findPk($list->getId()), 'The list is still there.');
        self::assertSame(1, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'No line was added or deleted.');
        self::assertSame(10.0, (float) PriceListItemQuery::create()->findPk($item->getId())?->getPrice(), 'The line was not changed.');
        self::assertSame(0, PriceListAssignmentQuery::create()->filterByPriceListId($list->getId())->count(), 'Nothing was assigned.');
    }

    private function formToken(string $page, string $formName): string
    {
        $crawler = $this->client->request('GET', $page);
        $token = $crawler->filter(\sprintf('input[name="%s[_token]"]', $formName));
        self::assertGreaterThan(0, $token->count(), \sprintf('The page renders the %s form.', $formName));

        return (string) $token->attr('value');
    }

    private function sessionToken(string $page = self::LIST_URL): string
    {
        $html = (string) $this->client->request('GET', $page)->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }

    private function defaultCurrency(): Currency
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        self::assertNotNull($currency);

        return $currency;
    }

    /**
     * @param array<string, int> $overrides
     */
    private function priceList(string $code, array $overrides = []): PriceList
    {
        $list = (new PriceList())
            ->setCode($code)
            ->setCurrencyId($this->defaultCurrency()->getId())
            ->setPriority($overrides['priority'] ?? 100)
            ->setExclusive($overrides['exclusive'] ?? 0)
            ->setActive($overrides['active'] ?? 1);
        $list->save($this->getPropelConnection());

        return $list;
    }

    private function line(PriceList $list, ProductSaleElements $pse, int $minimumQuantity, string $price): PriceListItem
    {
        $item = (new PriceListItem())
            ->setPriceListId($list->getId())
            ->setProductSaleElementsId($pse->getId())
            ->setMinQuantity($minimumQuantity)
            ->setPrice($price);
        $item->save($this->getPropelConnection());

        return $item;
    }

    /**
     * @return array{0: \Thelia\Model\Product, 1: ProductSaleElements}
     */
    private function productWithAPse(string $reference): array
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->defaultCurrency(), ['ref' => 'PROD-'.$reference]);
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($pse);
        $pse->setRef($reference)->save($this->getPropelConnection());

        return [$product, $pse];
    }

    private function customer(string $firstname, string $lastname): Customer
    {
        return $this->factory->customer($this->factory->customerTitle(), ['firstname' => $firstname, 'lastname' => $lastname]);
    }
}
