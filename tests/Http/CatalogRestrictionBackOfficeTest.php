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

use BackOfficeDefaultTwigBundle\Tests\Support\SaleUnitRestrictedAdmin;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Brand;
use Thelia\Model\CatalogRestriction;
use Thelia\Model\CatalogRestrictionQuery;
use Thelia\Model\Category;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerGroup;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The assortment rules of the back office: the list and its filters, the creation in two
 * steps (a summary, then the confirmation), the deletion, the rights, and the count of the
 * products a rule leaves visible.
 */
final class CatalogRestrictionBackOfficeTest extends WebIntegrationTestCase
{
    use RecordsSqlQueries;

    private const BASE = '/admin/pricing/catalog-restrictions';

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

    public function testTheListShowsTheRulesWithWhoTheyBindAndWhatTheyAreAbout(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesalers');
        $category = $this->category('Tools');
        $customer = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Ada', 'lastname' => 'Lovelace', 'email' => 'ada@example.test']);
        $this->rule($group, null, $category, null, null, 'deny');
        $this->rule(null, $customer, null, $this->brand('Acme'), null, 'allow');

        $crawler = $this->client->request('GET', self::BASE);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $page = $crawler->filter('[data-testid="catalog-restrictions-page"]')->text();
        self::assertStringContainsString('Group: Wholesalers', $page);
        self::assertStringContainsString('Category: Tools', $page);
        self::assertStringContainsString('Customer: Ada Lovelace (ada@example.test)', $page);
        self::assertStringContainsString('Brand: Acme', $page);
        self::assertStringContainsString('Deny', $page);
        self::assertStringContainsString('Allow', $page);
    }

    public function testTheListIsFilteredByTheScope(): void
    {
        $wholesale = $this->factory->customerGroup(title: 'Wholesalers');
        $friends = $this->factory->customerGroup(title: 'Friends');
        $customer = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Grace', 'lastname' => 'Hopper', 'email' => 'grace@example.test']);
        $this->rule($wholesale, null, $this->category('Tools'), null, null, 'deny');
        $this->rule($friends, null, $this->category('Garden'), null, null, 'deny');
        $this->rule(null, $customer, $this->category('Kitchen'), null, null, 'deny');

        $byGroup = $this->client->request('GET', self::BASE.'?scope_type=group&group_id='.$friends->getId())->filter('[data-testid="catalog-restrictions-page"]')->text();
        self::assertStringContainsString('Garden', $byGroup);
        self::assertStringNotContainsString('Tools', $byGroup);
        self::assertStringNotContainsString('Kitchen', $byGroup);

        $byCustomer = $this->client->request('GET', self::BASE.'?scope_type=customer&customer_q=hopp')->filter('[data-testid="catalog-restrictions-page"]')->text();
        self::assertStringContainsString('Kitchen', $byCustomer);
        self::assertStringNotContainsString('Garden', $byCustomer);

        $tooShort = $this->client->request('GET', self::BASE.'?scope_type=customer&customer_q=zz')->filter('[data-testid="catalog-restrictions-page"]')->text();
        self::assertStringContainsString('Kitchen', $tooShort, 'A term under three characters filters nothing.');
    }

    public function testTheRulesOfACustomerAreFoundByTheReferenceOfTheCustomerAsOnEveryCustomerSearch(): void
    {
        $first = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Refone', 'lastname' => 'Alpha', 'email' => 'refone@example.test']);
        $second = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Reftwo', 'lastname' => 'Beta', 'email' => 'reftwo@example.test']);
        self::assertNotSame('', (string) $first->getRef());
        $this->rule(null, $first, $this->category('Kitchen'), null, null, 'deny');
        $this->rule(null, $second, $this->category('Garden'), null, null, 'deny');

        $byReference = $this->client->request('GET', self::BASE.'?scope_type=customer&customer_q='.urlencode((string) $first->getRef()))->filter('[data-testid="catalog-restrictions-page"]')->text();

        self::assertStringContainsString('Kitchen', $byReference);
        self::assertStringNotContainsString('Garden', $byReference, 'The reference of a customer finds their rules, as it finds them when a rule is written.');
    }

    public function testTheSummaryOfTheDraftOffersTheConfirmationOnlyOnceTheRuleIsComplete(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesalers');
        $category = $this->category('Tools');

        $crawler = $this->client->request('GET', self::BASE.'/new');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="catalog-restriction-summary"]'));

        $crawler = $this->client->request('GET', self::BASE.'/new?'.http_build_query(['scope_type' => 'group', 'group_ids' => [$group->getId()], 'target_type' => 'category', 'category_id' => $category->getId(), 'mode' => 'allow']));
        self::assertCount(1, $crawler->filter('[data-testid="catalog-restriction-summary"]'));
        $summary = $crawler->filter('[data-testid="catalog-restriction-summary"]')->text();
        self::assertStringContainsString('Tools', $summary);
        self::assertStringContainsString('Wholesalers', $summary);
        self::assertStringContainsString('Allow', $summary);
        self::assertCount(1, $crawler->filter('[data-testid="catalog-restriction-create-form"] input[name="category_id"]'));
    }

    public function testTheConfirmationWritesOneRulePerGroupAndNeverTwiceTheSameRule(): void
    {
        $first = $this->factory->customerGroup();
        $second = $this->factory->customerGroup();
        $category = $this->category('Tools');
        $draft = ['scope_type' => 'group', 'group_ids' => [$first->getId(), $second->getId()], 'target_type' => 'category', 'category_id' => $category->getId(), 'mode' => 'deny'];

        $this->post('/create', $draft);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, CatalogRestrictionQuery::create()->filterByCategoryId($category->getId())->filterByMode('deny')->count($this->getPropelConnection()));
        $row = CatalogRestrictionQuery::create()->filterByCustomerGroupId($first->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($row);
        self::assertNull($row->getCustomerId());
        self::assertNull($row->getBrandId());
        self::assertNull($row->getProductId());

        $this->post('/create', $draft);
        self::assertSame(2, CatalogRestrictionQuery::create()->filterByCategoryId($category->getId())->count($this->getPropelConnection()), 'A rule already written is left alone.');
    }

    public function testARuleForACustomerAndForABrandAndForAProductIsWritten(): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $brand = $this->brand('Acme');
        $product = $this->product();

        $this->post('/create', ['scope_type' => 'customer', 'customer_id' => $customer->getId(), 'target_type' => 'brand', 'brand_id' => $brand->getId(), 'mode' => 'allow']);
        $this->post('/create', ['scope_type' => 'customer', 'customer_id' => $customer->getId(), 'target_type' => 'product', 'product_id' => $product->getId(), 'mode' => 'deny']);

        $rules = CatalogRestrictionQuery::create()->filterByCustomerId($customer->getId())->orderById()->find($this->getPropelConnection());
        self::assertCount(2, $rules);
        self::assertSame($brand->getId(), $rules[0]->getBrandId());
        self::assertSame('allow', $rules[0]->getMode());
        self::assertSame($product->getId(), $rules[1]->getProductId());
        self::assertSame('deny', $rules[1]->getMode());
        self::assertNull($rules[1]->getCustomerGroupId());
    }

    public function testAnIncompleteOrUnknownRuleWritesNothing(): void
    {
        $group = $this->factory->customerGroup();
        $before = CatalogRestrictionQuery::create()->count($this->getPropelConnection());

        $this->post('/create', ['scope_type' => 'group', 'group_ids' => [$group->getId()], 'target_type' => 'category', 'mode' => 'deny']);
        $this->post('/create', ['scope_type' => 'group', 'group_ids' => [], 'target_type' => 'category', 'category_id' => $this->category('Tools')->getId(), 'mode' => 'deny']);
        $this->post('/create', ['scope_type' => 'group', 'group_ids' => [$group->getId()], 'target_type' => 'category', 'category_id' => 999999, 'mode' => 'deny']);
        $this->post('/create', ['scope_type' => 'customer', 'customer_id' => 999999, 'target_type' => 'category', 'category_id' => $this->category('Garden')->getId(), 'mode' => 'deny']);

        self::assertSame($before, CatalogRestrictionQuery::create()->count($this->getPropelConnection()));
    }

    public function testAForgedTokenWritesAndDeletesNothing(): void
    {
        $group = $this->factory->customerGroup();
        $category = $this->category('Tools');
        $existing = $this->rule($group, null, $category, null, null, 'deny');

        $this->post('/create', ['scope_type' => 'group', 'group_ids' => [$group->getId()], 'target_type' => 'category', 'category_id' => $this->category('Garden')->getId(), 'mode' => 'allow'], 'forged');
        $this->post('/delete', ['restriction_id' => $existing->getId()], 'forged');

        self::assertSame(1, CatalogRestrictionQuery::create()->filterByCustomerGroupId($group->getId())->count($this->getPropelConnection()));
        self::assertNotNull(CatalogRestrictionQuery::create()->findPk($existing->getId(), $this->getPropelConnection()));
    }

    public function testADeletedRuleIsGone(): void
    {
        $group = $this->factory->customerGroup();
        $rule = $this->rule($group, null, $this->category('Tools'), null, null, 'deny');
        $kept = $this->rule($group, null, $this->category('Garden'), null, null, 'deny');

        $this->post('/delete', ['restriction_id' => $rule->getId()]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull(CatalogRestrictionQuery::create()->findPk($rule->getId(), $this->getPropelConnection()));
        self::assertNotNull(CatalogRestrictionQuery::create()->findPk($kept->getId(), $this->getPropelConnection()));
    }

    public function testTheResultOfADeletionAndOfAForgedTokenIsToldOnTheNextPage(): void
    {
        $group = $this->factory->customerGroup();
        $rule = $this->rule($group, null, $this->category('Tools'), null, null, 'deny');

        $this->post('/delete', ['restriction_id' => $rule->getId()], 'forged');
        $this->client->followRedirect();
        self::assertStringContainsString('Invalid security token', (string) $this->client->getResponse()->getContent());

        $this->post('/delete', ['restriction_id' => $rule->getId()]);
        $this->client->followRedirect();
        self::assertStringContainsString('The assortment rule has been deleted.', (string) $this->client->getResponse()->getContent());
    }

    public function testTheSearchOfACustomerAnswersTwentyAtMostAndNothingUnderThreeCharacters(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->factory->customer($this->factory->customerTitle(), ['lastname' => 'Searchable'.$i, 'email' => 'searchable'.$i.'@example.test']);
        }

        $crawler = $this->client->request('GET', self::BASE.'/new?scope_type=customer&customer_q=Searchable');
        self::assertCount(20, $crawler->filter('[data-testid="catalog-restriction-customer-results"] li'));

        $crawler = $this->client->request('GET', self::BASE.'/new?scope_type=customer&customer_q=Se');
        self::assertCount(0, $crawler->filter('[data-testid="catalog-restriction-customer-results"] li'));
    }

    public function testTheSearchOfAProductAnswersTwentyAtMostAndNothingUnderThreeCharacters(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->product('FINDME-'.$i);
        }

        $crawler = $this->client->request('GET', self::BASE.'/new?target_type=product&product_q=FINDME');
        self::assertCount(20, $crawler->filter('[data-testid="catalog-restriction-product-results"] li'));

        $crawler = $this->client->request('GET', self::BASE.'/new?target_type=product&product_q=FI');
        self::assertCount(0, $crawler->filter('[data-testid="catalog-restriction-product-results"] li'));
    }

    public function testTheCounterTellsHowManyProductsAGroupAndACustomerKeep(): void
    {
        $group = $this->factory->customerGroup();
        $open = $this->category('Open');
        $closed = $this->category('Closed');
        foreach (range(1, 3) as $i) {
            $this->product(null, $open);
        }
        foreach (range(1, 2) as $i) {
            $this->product(null, $closed);
        }
        $total = ProductQuery::create()->count($this->getPropelConnection());

        $this->rule($group, null, $open, null, null, 'allow');

        $crawler = $this->client->request('GET', self::BASE.'?view_group_id='.$group->getId());
        self::assertSame('3', trim($crawler->filter('[data-testid="catalog-restriction-view-visible"]')->text()), 'An allow list leaves the products of its category only.');
        self::assertSame((string) $total, trim($crawler->filter('[data-testid="catalog-restriction-view-total"]')->text()));

        $member = $this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $this->factory->customerGroup(), 'lastname' => 'Counted']);
        $this->rule(null, $member, $closed, null, null, 'deny');

        $crawler = $this->client->request('GET', self::BASE.'?view_customer_id='.$member->getId());
        self::assertSame((string) ($total - 2), trim($crawler->filter('[data-testid="catalog-restriction-view-visible"]')->text()), 'A deny rule takes its category off the count of the customer.');

        $crawler = $this->client->request('GET', self::BASE.'?view_group_id='.$this->factory->customerGroup()->getId());
        self::assertSame((string) $total, trim($crawler->filter('[data-testid="catalog-restriction-view-visible"]')->text()), 'A group with no rule keeps everything.');
    }

    public function testTheCounterIsAnAggregateAndNeverLoadsTheProducts(): void
    {
        $group = $this->factory->customerGroup();
        $this->rule($group, null, $this->category('Open'), null, null, 'allow');
        foreach (range(1, 6) as $ignored) {
            $this->product();
        }

        $statements = $this->recordSqlQueries(function () use ($group): void {
            $this->client->request('GET', self::BASE.'?view_group_id='.$group->getId());
        });

        $productReads = array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/FROM\s+`?product`?\s/i', $sql) && 1 === preg_match('/`?product`?\.`?(ref|visible|position)`?/i', $sql));
        self::assertSame([], array_values($productReads), 'No product row is read: the database counts.');
        self::assertNotSame([], array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/count\(/i', $sql)), 'The database does the counting.');
    }

    public function testTheScreensNeedTheRightsOfThePricing(): void
    {
        $group = $this->factory->customerGroup();
        $category = $this->category('Tools');
        $rule = $this->rule($group, null, $category, null, null, 'deny');
        $draft = ['scope_type' => 'group', 'group_ids' => [$group->getId()], 'target_type' => 'category', 'category_id' => $category->getId(), 'mode' => 'allow'];
        $token = $this->sessionToken();

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]));
        $this->client->request('GET', self::BASE);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Without the right to see the pricing the list is refused.');

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW]]));
        $crawler = $this->client->request('GET', self::BASE);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="catalog-restriction-create-button"]'), 'The creation is not offered without the right.');
        $this->client->request('GET', self::BASE.'/new');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->post('/create', $draft, $token, 403);
        $this->post('/delete', ['restriction_id' => $rule->getId()], $token, 403);
        self::assertNotNull(CatalogRestrictionQuery::create()->findPk($rule->getId(), $this->getPropelConnection()));
        self::assertSame(1, CatalogRestrictionQuery::create()->count($this->getPropelConnection()));

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW, AccessManager::CREATE]]));
        $this->post('/create', $draft, $token, 302);
        self::assertSame(2, CatalogRestrictionQuery::create()->count($this->getPropelConnection()));
        $this->post('/delete', ['restriction_id' => $rule->getId()], $token, 403);
    }

    public function testWithoutTheRightToSeeTheCustomersNoCustomerIsNamedNorSearchedNorPicked(): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Ada', 'lastname' => 'Lovelace', 'email' => 'ada@example.test']);
        $category = $this->category('Tools');
        $this->rule(null, $customer, $category, null, null, 'deny');
        $token = $this->sessionToken();

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW, AccessManager::CREATE]]));

        $crawler = $this->client->request('GET', self::BASE.'?view_customer_id='.$customer->getId().'&view_customer_q=Lovelace&customer_q=Lovelace&scope_type=customer');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $page = $crawler->filter('[data-testid="catalog-restrictions-page"]')->text();
        self::assertStringContainsString('Customer: #'.$customer->getId(), $page, 'The rule names the customer by number only.');
        self::assertStringNotContainsString('Lovelace', $page);
        self::assertStringNotContainsString('ada@example.test', $page);
        self::assertCount(0, $crawler->filter('[data-testid="catalog-restriction-view-customer"]'), 'No customer search on the counter.');
        self::assertCount(0, $crawler->filter('[data-testid="catalog-restriction-view-result"]'), 'A customer is not counted without the right to see the customers.');

        $crawler = $this->client->request('GET', self::BASE.'/new?scope_type=customer&customer_q=Lovelace');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="catalog-restriction-customer-search"]'));
        self::assertCount(0, $crawler->filter('[data-testid="catalog-restriction-customer-results"]'));
        self::assertStringNotContainsString('Lovelace', $crawler->text());

        $before = CatalogRestrictionQuery::create()->count($this->getPropelConnection());
        $this->post('/create', ['scope_type' => 'customer', 'customer_id' => $customer->getId(), 'target_type' => 'category', 'category_id' => $category->getId(), 'mode' => 'allow'], $token, 403);
        self::assertSame($before, CatalogRestrictionQuery::create()->count($this->getPropelConnection()));
    }

    public function testAnAdministratorRestrictedToASaleUnitOnlyReads(): void
    {
        $group = $this->factory->customerGroup();
        $category = $this->category('Tools');
        $rule = $this->rule($group, null, $category, null, null, 'deny');
        $token = $this->sessionToken();

        $this->injector->setAdmin(SaleUnitRestrictedAdmin::create($this->factory, $this->getService(EventDispatcherInterface::class), $this->getPropelConnection(), [
            AdminResources::PRICING => [AccessManager::VIEW, AccessManager::CREATE, AccessManager::DELETE],
        ]));

        $crawler = $this->client->request('GET', self::BASE);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="catalog-restriction-create-button"]'));
        $this->post('/create', ['scope_type' => 'group', 'group_ids' => [$group->getId()], 'target_type' => 'category', 'category_id' => $category->getId(), 'mode' => 'allow'], $token, 403);
        $this->post('/delete', ['restriction_id' => $rule->getId()], $token, 403);
        self::assertSame(1, CatalogRestrictionQuery::create()->count($this->getPropelConnection()));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(string $path, array $fields, ?string $token = null, int $expectedStatus = 302): void
    {
        $this->client->request('POST', self::BASE.$path, ['_token' => $token ?? $this->sessionToken()] + $fields);

        self::assertSame($expectedStatus, $this->client->getResponse()->getStatusCode());
    }

    private function rule(?CustomerGroup $group, ?Customer $customer, ?Category $category, ?Brand $brand, ?Product $product, string $mode): CatalogRestriction
    {
        $rule = (new CatalogRestriction())
            ->setCustomerGroupId($group?->getId())
            ->setCustomerId($customer?->getId())
            ->setCategoryId($category?->getId())
            ->setBrandId($brand?->getId())
            ->setProductId($product?->getId())
            ->setMode($mode);
        $rule->save($this->getPropelConnection());

        return $rule;
    }

    private function category(string $title): Category
    {
        $category = $this->factory->category();
        $category->setLocale('en_US')->setTitle($title)->save($this->getPropelConnection());

        return $category;
    }

    private function brand(string $title): Brand
    {
        return $this->factory->brand(['title' => $title]);
    }

    private function product(?string $ref = null, ?Category $category = null): Product
    {
        return $this->factory->product(
            $category ?? $this->factory->category(),
            $this->factory->taxRule(),
            $this->defaultCurrency(),
            null === $ref ? [] : ['ref' => $ref],
        );
    }

    private function defaultCurrency(): Currency
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        self::assertNotNull($currency);

        return $currency;
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function restrictedAdmin(array $grants): \Thelia\Model\Admin
    {
        $admin = $this->factory->restrictedAdmin($grants);
        $admin->eraseCredentials();

        return $admin;
    }

    private function sessionToken(): string
    {
        $html = (string) $this->client->request('GET', '/admin/configuration/sale-units')->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }
}
