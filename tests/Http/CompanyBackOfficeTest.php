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

use BackOfficeDefaultTwigBundle\Repository\CompanyRepository;
use BackOfficeDefaultTwigBundle\Tests\Support\SaleUnitRestrictedAdmin;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Company\CompanyFacade;
use Thelia\Domain\Company\DTO\CompanyData;
use Thelia\Model\Company;
use Thelia\Model\CompanyCustomerQuery;
use Thelia\Model\CompanyQuery;
use Thelia\Model\CompanyRoleQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Map\CompanyTableMap;
use Thelia\Model\Order;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The company screens of the back office: the list, the sheet of a company with its tabs, the
 * buyers and what refuses them, the roles, and the company on the customer and order screens.
 */
final class CompanyBackOfficeTest extends WebIntegrationTestCase
{
    private const LIST_URL = '/admin/companies';
    private const ROLES_URL = '/admin/configuration/company-roles';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private CompanyFacade $companies;

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

        $this->companies = $this->getService(CompanyFacade::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheListExplainsWhatACompanyIsWhenThereIsNone(): void
    {
        $crawler = $this->client->request('GET', self::LIST_URL);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="companies-empty"]'), 'An empty shop explains the screen instead of showing an empty table.');
    }

    public function testTheListCountsTheBuyersAndTheOrdersOfEachCompanyAndFiltersThem(): void
    {
        $acme = $this->company('Acme Lists', ['siret' => '11111111111111']);
        $globex = $this->company('Globex Lists', ['siret' => '22222222222222']);
        $this->suspend($globex);
        $this->buyer($acme, 'company_admin');
        $this->buyer($acme, 'buyer');
        $this->buyer($acme, 'buyer');
        $this->order($acme, $this->buyer($globex, 'company_admin'));
        $this->order($acme, $this->customer());

        $crawler = $this->client->request('GET', self::LIST_URL);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $row = $crawler->filter('table tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Acme Lists'))->first();
        self::assertCount(1, $row);
        $cells = array_map('trim', $row->filter('td')->each(static fn ($cell): string => $cell->text()));
        self::assertContains('3', $cells, 'The buyers are counted by an aggregation.');
        self::assertContains('2', $cells, 'The orders are counted by an aggregation.');

        $crawler = $this->client->request('GET', self::LIST_URL.'?q=Globex');
        self::assertStringContainsString('Globex Lists', $crawler->filter('table')->text());
        self::assertStringNotContainsString('Acme Lists', $crawler->filter('table')->text(), 'The name filter leaves the other companies out.');

        $crawler = $this->client->request('GET', self::LIST_URL.'?q=1111111111');
        self::assertStringContainsString('Acme Lists', $crawler->filter('table')->text(), 'The SIRET is searched.');
        self::assertStringNotContainsString('Globex Lists', $crawler->filter('table')->text());

        $crawler = $this->client->request('GET', self::LIST_URL.'?active=0');
        self::assertStringContainsString('Globex Lists', $crawler->filter('table')->text());
        self::assertStringNotContainsString('Acme Lists', $crawler->filter('table')->text(), 'The state filter keeps the suspended companies only.');
    }

    public function testTheSheetShowsItsBuyersItsAddressesAndItsOrdersOnTheirTabs(): void
    {
        $company = $this->company('Acme Sheet');
        $admin = $this->buyer($company, 'company_admin');
        $buyer = $this->buyer($company, 'buyer');
        $address = $this->factory->address($admin, null, null, ['label' => 'Warehouse sheet']);
        $address->setCompanyId($company->getId())->save($this->getPropelConnection());
        $order = $this->order($company, $buyer);

        foreach (['infos', 'buyers', 'addresses', 'orders'] as $tab) {
            $crawler = $this->client->request('GET', self::LIST_URL.'/'.$company->getId().'?tab='.$tab);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $tab);
            self::assertCount(1, $crawler->filter('[data-testid="company-'.$tab.'"]'), $tab.' is the tab that opens.');
        }

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$company->getId().'?tab=buyers');
        self::assertCount(1, $crawler->filter('[data-testid="company-buyer-'.$admin->getId().'"]'));
        self::assertCount(1, $crawler->filter('[data-testid="company-buyer-'.$buyer->getId().'"]'));
        self::assertStringContainsString($buyer->getEmail(), $crawler->filter('[data-testid="company-buyers"]')->text());

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$company->getId().'?tab=addresses');
        self::assertCount(1, $crawler->filter('[data-testid="company-address-'.$address->getId().'"]'));

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$company->getId().'?tab=orders');
        $row = $crawler->filter('[data-testid="company-order-'.$order->getId().'"]');
        self::assertCount(1, $row);
        self::assertStringContainsString($buyer->getFirstname(), $row->text(), 'The buyer who placed the order is named.');
    }

    public function testACompanyIsCreatedAndItsDetailsAreSavedWithTheBillingAddress(): void
    {
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/create', ['_token' => $token, 'name' => 'Created Co', 'siret' => '12345678901234', 'vat_number' => 'FR123']);
        $company = CompanyQuery::create()->findOneByName('Created Co');
        self::assertNotNull($company, 'The company is in the database.');
        self::assertSame('12345678901234', $company->getSiret());
        self::assertSame(1, $company->getActive());
        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_URL.'/'.$company->getId()), 'The sheet of the new company opens.');

        $parent = $this->company('Parent Co');
        $admin = $this->buyer($company, 'company_admin');
        $address = $this->factory->address($admin);
        $address->setCompanyId($company->getId())->save($this->getPropelConnection());

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/save', [
            '_token' => $token,
            'name' => 'Renamed Co',
            'siret' => '30326504500003',
            'vat_number' => 'FR40303265045',
            'parent_id' => (string) $parent->getId(),
            'billing_address_id' => (string) $address->getId(),
            'notification_email' => 'buyers@created.test',
            'internal_note' => 'pays late',
        ]);

        CompanyTableMap::clearInstancePool();
        $saved = CompanyQuery::create()->findPk($company->getId());
        self::assertSame('Renamed Co', $saved?->getName());
        self::assertSame($parent->getId(), $saved->getParentId());
        self::assertSame($address->getId(), $saved->getBillingAddressId());
        self::assertSame('buyers@created.test', $saved->getNotificationEmail());
        self::assertSame('pays late', $saved->getInternalNote());
        \Thelia\Model\Map\AddressTableMap::clearInstancePool();
        self::assertSame('30326504500003', \Thelia\Model\AddressQuery::create()->findPk($address->getId())?->getSiret(), 'The details are copied onto the billing address.');
    }

    public function testIdentifiersTheRulesOfAnAddressRefuseAreShownAndNothingIsSaved(): void
    {
        $company = $this->company('Identifiers Co', ['siret' => '30326504500003']);
        $admin = $this->buyer($company, 'company_admin');
        $address = $this->factory->address($admin);
        $address->setCompanyId($company->getId())->save($this->getPropelConnection());
        $address->setCountryId(\Thelia\Model\CountryQuery::create()->filterByIsoalpha2('FR')->findOne()?->getId())->save($this->getPropelConnection());
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/save', [
            '_token' => $token,
            'name' => 'Identifiers Co',
            'siret' => '12345678900011',
            'vat_number' => 'FR12345678901',
            'billing_address_id' => (string) $address->getId(),
        ]);
        $this->client->followRedirect();

        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('The checksum of this SIRET number is invalid', $page);
        self::assertStringContainsString('The checksum of this VAT number is invalid', $page);

        CompanyTableMap::clearInstancePool();
        \Thelia\Model\Map\AddressTableMap::clearInstancePool();
        $stored = CompanyQuery::create()->findPk($company->getId());
        self::assertSame('30326504500003', $stored?->getSiret(), 'Nothing was saved.');
        self::assertNull($stored->getBillingAddressId());
        self::assertNull(\Thelia\Model\AddressQuery::create()->findPk($address->getId())?->getSiret());

        $this->client->request('POST', self::LIST_URL.'/create', ['_token' => $token, 'name' => 'Refused Co', 'siret' => 'AB#12']);
        $this->client->followRedirect();

        self::assertStringContainsString('A company registration number may only contain', (string) $this->client->getResponse()->getContent());
        self::assertNull(CompanyQuery::create()->findOneByName('Refused Co'));
    }

    public function testASheetRefusesAParentThatIsItselfOrDoesNotExistAndAnAddressOfAnotherCompany(): void
    {
        $company = $this->company('Refusing Co');
        $other = $this->company('Other Co');
        $stranger = $this->buyer($other, 'company_admin');
        $foreign = $this->factory->address($stranger);
        $foreign->setCompanyId($other->getId())->save($this->getPropelConnection());
        $token = $this->sessionToken();

        foreach ([['parent_id' => (string) $company->getId()], ['parent_id' => '99999'], ['billing_address_id' => (string) $foreign->getId()]] as $changes) {
            $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/save', $changes + ['_token' => $token, 'name' => 'Changed']);
        }

        CompanyTableMap::clearInstancePool();
        $stored = CompanyQuery::create()->findPk($company->getId());
        self::assertSame('Refusing Co', $stored?->getName(), 'Nothing was saved.');
        self::assertNull($stored->getParentId());
        self::assertNull($stored->getBillingAddressId());
    }

    public function testAnAccountIsAttachedByItsExactEmailAddressAndAFragmentFindsNothing(): void
    {
        $company = $this->company('Attach Co');
        $this->buyer($company, 'company_admin');
        $customer = $this->customer('exact.buyer@attach.test');
        $token = $this->sessionToken();
        $url = self::LIST_URL.'/'.$company->getId().'/buyers/attach';

        foreach (['exact.buyer', '@attach.test', 'exact.buyer@attach', $customer->getFirstname()] as $fragment) {
            $this->client->request('POST', $url, ['_token' => $token, 'lookup' => $fragment, 'role_code' => 'buyer']);
        }
        self::assertFalse(CompanyCustomerQuery::create()->filterByCustomerId($customer->getId())->exists(), 'A fragment of an address or of a name finds nobody.');

        $this->client->followRedirect();
        self::assertStringContainsString('No account has exactly this e-mail address or reference.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', $url, ['_token' => $token, 'lookup' => 'exact.buyer@attach.test', 'role_code' => 'buyer', 'order_limit' => '500', 'period_limit' => '2000', 'period' => 'month']);

        $link = CompanyCustomerQuery::create()->filterByCustomerId($customer->getId())->findOne();
        self::assertNotNull($link, 'The exact address attaches the account.');
        self::assertSame($company->getId(), $link->getCompanyId());
        self::assertSame(500.0, (float) $link->getOrderLimit());
        self::assertSame(2000.0, (float) $link->getPeriodLimit());
        self::assertSame('month', $link->getPeriod());
        self::assertSame('buyer', $link->getCompanyRole()->getCode());

        $second = $this->customer();
        $this->client->request('POST', $url, ['_token' => $token, 'lookup' => (string) $second->getRef(), 'role_code' => 'approver']);
        self::assertSame($company->getId(), CompanyCustomerQuery::create()->filterByCustomerId($second->getId())->findOne()?->getCompanyId(), 'The exact reference attaches the account too.');
    }

    public function testTheRefusalsOfTheCoreAreShownWhenAnAccountIsAttached(): void
    {
        $company = $this->company('Refusals Co');
        $other = $this->company('Elsewhere Co');
        $taken = $this->buyer($other, 'buyer');
        $guest = $this->factory->guestCustomer($this->factory->customerTitle());
        $token = $this->sessionToken();
        $url = self::LIST_URL.'/'.$company->getId().'/buyers/attach';

        $this->client->request('POST', $url, ['_token' => $token, 'lookup' => $taken->getEmail(), 'role_code' => 'buyer']);
        $this->client->followRedirect();
        self::assertStringContainsString('This customer already belongs to a company.', (string) $this->client->getResponse()->getContent());
        self::assertSame($other->getId(), CompanyCustomerQuery::create()->filterByCustomerId($taken->getId())->findOne()?->getCompanyId(), 'The buyer of another company stays where they are.');

        $this->client->request('POST', $url, ['_token' => $token, 'lookup' => $guest->getEmail(), 'role_code' => 'buyer']);
        $this->client->followRedirect();
        self::assertStringContainsString('A guest account cannot join a company', (string) $this->client->getResponse()->getContent());
        self::assertFalse(CompanyCustomerQuery::create()->filterByCustomerId($guest->getId())->exists());
    }

    public function testABuyerIsCreatedAsAnAccountThenAttachedAndAnExistingAddressIsNotCreatedTwice(): void
    {
        $company = $this->company('Create Buyer Co');
        $this->buyer($company, 'company_admin');
        $token = $this->sessionToken();
        $url = self::LIST_URL.'/'.$company->getId().'/buyers/create';

        $this->client->request('POST', $url, ['_token' => $token, 'firstname' => 'Nina', 'lastname' => 'Newbuyer', 'email' => 'nina.new@createbuyer.test', 'role_code' => 'approver', 'order_limit' => '250']);

        $customer = CustomerQuery::create()->findOneByEmail('nina.new@createbuyer.test');
        self::assertNotNull($customer, 'The account is created.');
        $link = CompanyCustomerQuery::create()->filterByCustomerId($customer->getId())->findOne();
        self::assertSame($company->getId(), $link?->getCompanyId(), 'The account is attached.');
        self::assertSame('approver', $link->getCompanyRole()->getCode());
        self::assertSame(250.0, (float) $link->getOrderLimit());

        $this->client->request('POST', $url, ['_token' => $token, 'firstname' => 'Nina', 'lastname' => 'Again', 'email' => 'nina.new@createbuyer.test', 'role_code' => 'buyer']);
        self::assertSame(1, CustomerQuery::create()->filterByEmail('nina.new@createbuyer.test')->count(), 'An address an account already uses is not created a second time.');

        $this->client->request('POST', $url, ['_token' => $token, 'firstname' => 'Bad', 'lastname' => 'Limit', 'email' => 'bad.limit@createbuyer.test', 'role_code' => 'buyer', 'order_limit' => 'abc']);
        self::assertFalse(CustomerQuery::create()->filterByEmail('bad.limit@createbuyer.test')->exists(), 'A refusal of the attachment leaves no account behind.');
    }

    public function testTheRoleAndTheLimitsOfABuyerChangeAndTheLastAdministratorKeepsTheirRole(): void
    {
        $company = $this->company('Change Co');
        $admin = $this->buyer($company, 'company_admin');
        $buyer = $this->buyer($company, 'buyer');
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/buyers/'.$buyer->getId().'/update', ['_token' => $token, 'role_code' => 'approver', 'order_limit' => '100,5', 'period_limit' => '900', 'period' => 'week']);
        $link = CompanyCustomerQuery::create()->filterByCustomerId($buyer->getId())->findOne();
        self::assertSame('approver', $link?->getCompanyRole()->getCode());
        self::assertSame(100.5, (float) $link->getOrderLimit());
        self::assertSame('week', $link->getPeriod());

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/buyers/'.$admin->getId().'/update', ['_token' => $token, 'role_code' => 'buyer']);
        $this->client->followRedirect();
        self::assertStringContainsString('left without an administrator', (string) $this->client->getResponse()->getContent());
        self::assertSame('company_admin', CompanyCustomerQuery::create()->filterByCustomerId($admin->getId())->findOne()?->getCompanyRole()->getCode(), 'The last administrator keeps the role.');

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/buyers/'.$buyer->getId().'/update', ['_token' => $token, 'role_code' => 'buyer', 'period_limit' => '900']);
        self::assertSame('approver', CompanyCustomerQuery::create()->filterByCustomerId($buyer->getId())->findOne()?->getCompanyRole()->getCode(), 'A limit over a period with no period is refused before anything changes.');
    }

    public function testTheLastAdministratorCannotBeDetachedAndAnotherBuyerCan(): void
    {
        $company = $this->company('Detach Co');
        $admin = $this->buyer($company, 'company_admin');
        $buyer = $this->buyer($company, 'buyer');
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/buyers/'.$admin->getId().'/detach', ['_token' => $token]);
        self::assertTrue(CompanyCustomerQuery::create()->filterByCustomerId($admin->getId())->exists(), 'The last administrator stays attached.');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('left without an administrator', $crawler->filter('body')->text(), 'The refusal of the core is shown.');

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/buyers/'.$buyer->getId().'/detach', ['_token' => $token]);
        self::assertFalse(CompanyCustomerQuery::create()->filterByCustomerId($buyer->getId())->exists(), 'A buyer who is not the last administrator is detached.');

        $other = $this->company('Other Detach Co');
        $stranger = $this->buyer($other, 'buyer');
        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/buyers/'.$stranger->getId().'/detach', ['_token' => $token]);
        self::assertTrue(CompanyCustomerQuery::create()->filterByCustomerId($stranger->getId())->exists(), 'A buyer is detached from the company the address names only.');
    }

    public function testACompanyWithOrdersIsNotDeletedAndIsSuspendedInstead(): void
    {
        $company = $this->company('History Co');
        $buyer = $this->buyer($company, 'company_admin');
        $this->order($company, $buyer);
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/delete', ['_token' => $token, 'company_id' => (string) $company->getId()]);
        self::assertNotNull(CompanyQuery::create()->findPk($company->getId()), 'The company with a history is kept.');
        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_URL.'/'.$company->getId()), 'The sheet opens, where the company is suspended.');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('has orders and cannot be deleted', $crawler->filter('body')->text());
        self::assertCount(1, $crawler->filter('[data-testid="company-suspend"]'), 'The suspension is offered.');

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/suspend', ['_token' => $token]);
        CompanyTableMap::clearInstancePool();
        self::assertSame(0, CompanyQuery::create()->findPk($company->getId())?->getActive());

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/reactivate', ['_token' => $token]);
        CompanyTableMap::clearInstancePool();
        self::assertSame(1, CompanyQuery::create()->findPk($company->getId())?->getActive());

        $empty = $this->company('Empty Co');
        $this->client->request('POST', self::LIST_URL.'/delete', ['_token' => $token, 'company_id' => (string) $empty->getId()]);
        self::assertFalse(CompanyQuery::create()->filterById($empty->getId())->exists(), 'A company with no order is deleted.');
    }

    public function testTheCustomerListFiltersOnTheCompanyAndShowsItsColumn(): void
    {
        $acme = $this->company('Acme Filter');
        $globex = $this->company('Globex Filter');
        $inAcme = $this->buyer($acme, 'company_admin', 'Alicia', 'Inacme');
        $inGlobex = $this->buyer($globex, 'company_admin', 'Gerard', 'Inglobex');
        $alone = $this->customer(null, 'Solo', 'Nocompany');

        $crawler = $this->client->request('GET', '/admin/customers?company_id='.$acme->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $table = $crawler->filter('table')->text();
        self::assertStringContainsString($inAcme->getEmail(), $table);
        self::assertStringNotContainsString($inGlobex->getEmail(), $table, 'The filter keeps the buyers of the company only.');
        self::assertStringNotContainsString($alone->getEmail(), $table);
        self::assertStringContainsString('Acme Filter', $crawler->filter('[data-testid="customer-active-chips"]')->text(), 'The active filter is named.');

        $crawler = $this->client->request('GET', '/admin/customers?in_company=without&q=@');
        $table = $crawler->filter('table')->text();
        self::assertStringContainsString($alone->getEmail(), $table);
        self::assertStringNotContainsString($inAcme->getEmail(), $table, 'The filter on no company leaves the buyers out.');

        $crawler = $this->client->request('GET', '/admin/customers?in_company=with');
        self::assertStringContainsString($inGlobex->getEmail(), $crawler->filter('table')->text());
        self::assertStringNotContainsString($alone->getEmail(), $crawler->filter('table')->text());

        $crawler = $this->client->request('GET', '/admin/customers?q='.urlencode($inAcme->getEmail()));
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="bo-customer-company-link"]')->count(), 'The company column links to the company.');
        self::assertStringContainsString('Acme Filter', $crawler->filter('[data-testid="bo-customer-company-link"]')->first()->text());
    }

    public function testAnyCompanyIsFoundByItsNameInTheFilterEvenBeyondTheFirstTwoHundred(): void
    {
        for ($i = 0; $i < CompanyRepository::CHOICE_LIMIT + 5; ++$i) {
            $this->company(\sprintf('Bulk %03d', $i));
        }
        $last = $this->company('Zzz Beyond The Limit', ['siret' => '99999999999999']);
        $buyer = $this->buyer($last, 'company_admin', 'Zora', 'Faraway');

        $this->client->request('GET', self::LIST_URL.'/search?q='.urlencode('beyond the'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $found = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([['id' => $last->getId(), 'title' => 'Zzz Beyond The Limit', 'ref' => '99999999999999']], $found, 'A company ranked after the 200 first by name is found.');

        $this->client->request('GET', self::LIST_URL.'/search?q=99999999999999');
        $found = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([$last->getId()], array_column($found, 'id'), 'The SIRET is searched too.');

        $this->client->request('GET', self::LIST_URL.'/search?q=Bulk');
        $found = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(CompanyRepository::SEARCH_LIMIT, $found, 'The answer is bounded.');
        self::assertSame('Bulk 000', $found[0]['title'], 'The answer is sorted by name.');

        $this->client->request('GET', self::LIST_URL.'/search?q='.urlencode('100%'));
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR), 'A percent sign is no wildcard.');

        $crawler = $this->client->request('GET', '/admin/customers?company_id='.$last->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString($buyer->getEmail(), $crawler->filter('table')->text());
        self::assertSame('Zzz Beyond The Limit', $crawler->filter('[data-testid="customer-filter-company-input"]')->attr('value'), 'The filter names the chosen company.');
        self::assertSame((string) $last->getId(), $crawler->filter('[data-testid="customer-filter-company-value"]')->attr('value'));
        self::assertSame((string) $last->getId(), $crawler->filter('[data-testid="customer-filter-company-id"] option[selected]')->attr('value'), 'Without JavaScript, the select still holds the chosen company.');
    }

    public function testTheCustomerListGivesNoClueAboutTheCompaniesToAnAdministratorWhoMayNotViewThem(): void
    {
        $acme = $this->company('Hidden Filter');
        $inside = $this->buyer($acme, 'company_admin', 'Hidden', 'Insider');
        $outside = $this->customer(null, 'Plain', 'Outsider');

        $viewer = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]);
        $viewer->eraseCredentials();
        $this->injector->setAdmin($viewer);

        $crawler = $this->client->request('GET', '/admin/customers?company_id='.$acme->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $table = $crawler->filter('table')->text();
        self::assertStringContainsString($outside->getEmail(), $table, 'The filter is ignored: every customer is listed.');
        self::assertStringContainsString($inside->getEmail(), $table);
        self::assertCount(0, $crawler->filter('[data-testid="customer-filter-company"]'), 'The filter is not offered.');
        self::assertCount(0, $crawler->filter('[data-testid="bo-customer-company-link"]'), 'The column is not shown.');
        self::assertStringNotContainsString('Company', $crawler->filter('table thead')->text(), 'The column has no header either.');

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$inside->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="customer-company-section"]'), 'The sheet of a customer does not name the company either.');
    }

    public function testAnAdministratorRestrictedToSaleUnitsDoesNotSeeTheOrdersOfTheCompany(): void
    {
        $company = $this->company('Units Co');
        $buyer = $this->buyer($company, 'company_admin');
        $order = $this->order($company, $buyer);

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$company->getId().'?tab=orders');
        self::assertCount(1, $crawler->filter('[data-testid="company-order-'.$order->getId().'"]'), 'An unrestricted administrator sees the order.');

        $restricted = SaleUnitRestrictedAdmin::create($this->factory, $this->getService(EventDispatcherInterface::class), $this->getPropelConnection(), [AdminResources::COMPANY => [AccessManager::VIEW], AdminResources::ORDER => [AccessManager::VIEW]]);
        $this->injector->setAdmin($restricted);

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$company->getId().'?tab=orders');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="company-order-'.$order->getId().'"]'), 'An administrator restricted to some sale units does not read the other orders.');
        self::assertStringContainsString('0 order', $crawler->filter('[data-testid="company-orders-total"]')->text(), 'Nor does the total count them.');

        $crawler = $this->client->request('GET', self::LIST_URL);
        $row = $crawler->filter('table tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Units Co'))->first();
        $cells = array_map('trim', $row->filter('td')->each(static fn ($cell): string => $cell->text()));
        self::assertNotContains('1', \array_slice($cells, 4, 1), 'The orders of the list are counted within the sale units of the administrator.');
    }

    public function testTheCustomerSheetShowsTheCompanyTheRoleAndTheLimits(): void
    {
        $company = $this->company('Sheet Co');
        $customer = $this->buyer($company, 'approver', null, null, '500', '2000', 'month');
        $alone = $this->customer();

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="customer-company-link"]'));
        self::assertStringContainsString('Sheet Co', $crawler->filter('[data-testid="customer-company-link"]')->text());
        $section = $crawler->filter('[data-testid="customer-company-section"]')->text();
        self::assertStringContainsString('Approver', $section, 'The role is named.');
        self::assertMatchesRegularExpression('/500/', $section);
        self::assertMatchesRegularExpression('/2[\s\x{202f}\x{a0},.]?000/u', $section);

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$alone->getId());
        self::assertCount(1, $crawler->filter('[data-testid="customer-company-none"]'), 'A customer with no company says so.');
    }

    public function testTheOrderSheetShowsTheCompanyAndTheBuyerWhoPlacedTheOrder(): void
    {
        $company = $this->company('Order Co');
        $buyer = $this->buyer($company, 'buyer', 'Placed', 'Bybuyer');
        $withCompany = $this->order($company, $buyer);
        $without = $this->order(null, $this->customer());

        $crawler = $this->client->request('GET', '/admin/order/update/'.$withCompany->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="order-company-card"]'));
        self::assertStringContainsString('Order Co', $crawler->filter('[data-testid="order-company-link"]')->text());
        self::assertStringContainsString('Placed Bybuyer', $crawler->filter('[data-testid="order-company-placed-by"]')->text());

        $crawler = $this->client->request('GET', '/admin/order/update/'.$without->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="order-company-card"]'), 'An order of a customer alone has no company card.');

        $viewer = $this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]);
        $viewer->eraseCredentials();
        $this->injector->setAdmin($viewer);
        $crawler = $this->client->request('GET', '/admin/order/update/'.$withCompany->getId());
        self::assertCount(0, $crawler->filter('[data-testid="order-company-card"]'), 'The right on the orders does not open the company.');
    }

    public function testASharedRoleIsCreatedEditedAndDeletedAndTheInstalledOnesAreReadOnly(): void
    {
        $token = $this->sessionToken();

        $crawler = $this->client->request('GET', self::ROLES_URL);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        foreach (['company_admin', 'buyer', 'approver'] as $code) {
            self::assertCount(1, $crawler->filter('[data-testid="company-role-row-'.$code.'"]'));
            self::assertCount(0, $crawler->filter('[data-testid="company-role-delete-'.$code.'"]'), 'An installed role cannot be deleted.');
        }

        $crawler = $this->client->request('GET', self::ROLES_URL.'/'.$this->sharedRole('buyer')->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="company-role-submit"]'), 'An installed role is shown, with nothing to save.');
        self::assertCount(1, $crawler->filter('[data-testid="company-role-shipped-note"]'));

        $this->client->request('POST', self::ROLES_URL.'/save', ['_token' => $token, 'code' => 'viewer-role', 'title' => 'Viewer', 'permissions' => ['order.view_own', 'order.view_company']]);
        $role = CompanyRoleQuery::create()->filterByCode('viewer-role')->filterByCompanyId(null, Criteria::ISNULL)->findOne();
        self::assertNotNull($role, 'The shared role is created.');
        self::assertSame(['order.view_own', 'order.view_company'], $role->getPermissionList());
        self::assertSame('Viewer', \Thelia\Model\CompanyRoleI18nQuery::create()->filterById($role->getId())->filterByLocale('en_US')->findOne()?->getTitle(), 'The title is stored in the language of edition.');
        self::assertTrue($this->client->getResponse()->isRedirect(self::ROLES_URL.'/'.$role->getId()));

        $crawler = $this->client->request('GET', self::ROLES_URL.'/'.$role->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('input[name="permissions[]"][value="order.view_company"][checked]'), 'The matrix shows what the role grants.');
        self::assertCount(0, $crawler->filter('input[name="permissions[]"][value="order.place"][checked]'));

        $this->client->request('POST', self::ROLES_URL.'/save', ['_token' => $token, 'role_id' => (string) $role->getId(), 'title' => 'Viewer bis', 'permissions' => ['order.place']]);
        $role = CompanyRoleQuery::create()->findPk($role->getId());
        self::assertSame(['order.place'], $role?->getPermissionList());

        $shipped = $this->sharedRole('buyer');
        $this->client->request('POST', self::ROLES_URL.'/save', ['_token' => $token, 'role_id' => (string) $shipped->getId(), 'permissions' => ['order.place', 'company.edit']]);
        self::assertSame(['order.place', 'order.view_own', 'quote.request'], CompanyRoleQuery::create()->findPk($shipped->getId())?->getPermissionList(), 'An installed role is not changed.');
        $this->client->request('POST', self::ROLES_URL.'/delete', ['_token' => $token, 'role_id' => (string) $shipped->getId()]);
        self::assertNotNull(CompanyRoleQuery::create()->findPk($shipped->getId()), 'An installed role is not deleted.');

        $this->client->request('POST', self::ROLES_URL.'/save', ['_token' => $token, 'code' => 'bad-permission', 'permissions' => ['not.a.permission']]);
        self::assertFalse(CompanyRoleQuery::create()->filterByCode('bad-permission')->exists(), 'A permission the shop does not know is refused.');

        $this->client->request('POST', self::ROLES_URL.'/save', ['_token' => $token, 'code' => 'buyer', 'permissions' => ['order.place']]);
        self::assertSame(1, CompanyRoleQuery::create()->filterByCode('buyer')->count(), 'A code in use is refused.');

        $holder = $this->company('Role Holder Co');
        $this->buyer($holder, 'viewer-role');
        $this->client->request('POST', self::ROLES_URL.'/delete', ['_token' => $token, 'role_id' => (string) $role->getId()]);
        self::assertNotNull(CompanyRoleQuery::create()->findPk($role->getId()), 'A role buyers hold is not deleted.');
        $this->client->followRedirect();
        self::assertStringContainsString('Buyers still hold this role', (string) $this->client->getResponse()->getContent(), 'The refusal is worded for the administrator.');

        CompanyCustomerQuery::create()->filterByCompanyId($holder->getId())->delete($this->getPropelConnection());
        $this->client->request('POST', self::ROLES_URL.'/delete', ['_token' => $token, 'role_id' => (string) $role->getId()]);
        self::assertNull(CompanyRoleQuery::create()->findPk($role->getId()), 'A role nobody holds is deleted.');
    }

    public function testARoleOfOneCompanyIsMadeOnItsSheetAndKeepsClearOfTheSharedCodes(): void
    {
        $company = $this->company('Own Role Co');
        $other = $this->company('Other Role Co');
        $token = $this->sessionToken();
        $url = self::LIST_URL.'/'.$company->getId().'/roles/save';

        $this->client->request('POST', $url, ['_token' => $token, 'code' => 'warehouse', 'title' => 'Warehouse', 'permissions' => ['order.place', 'address.manage_shared']]);
        $role = CompanyRoleQuery::create()->filterByCode('warehouse')->findOne();
        self::assertSame($company->getId(), $role?->getCompanyId(), 'The role belongs to the company.');
        self::assertSame(['order.place', 'address.manage_shared'], $role->getPermissionList());

        $admin = $this->buyer($company, 'company_admin');
        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$company->getId().'?tab=buyers');
        self::assertCount(1, $crawler->filter('[data-testid="company-role-'.$role->getId().'"]'));
        self::assertStringContainsString('Warehouse', $crawler->filter('[data-testid="company-buyer-role-'.$admin->getId().'"]')->html(), 'The role can be given to a buyer.');

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$other->getId().'?tab=buyers');
        self::assertCount(0, $crawler->filter('[data-testid="company-role-'.$role->getId().'"]'), 'Another company does not see the role.');

        $this->client->request('POST', self::LIST_URL.'/'.$other->getId().'/roles/save', ['_token' => $token, 'role_id' => (string) $role->getId(), 'title' => 'Hijacked', 'permissions' => ['company.edit']]);
        self::assertSame(['order.place', 'address.manage_shared'], CompanyRoleQuery::create()->findPk($role->getId())?->getPermissionList(), 'A role is edited from the sheet of its own company only.');

        $this->client->request('POST', self::ROLES_URL.'/save', ['_token' => $token, 'role_id' => (string) $role->getId(), 'permissions' => ['company.edit']]);
        self::assertSame(['order.place', 'address.manage_shared'], CompanyRoleQuery::create()->findPk($role->getId())?->getPermissionList(), 'The screen of the shared roles does not edit the role of a company.');
        $this->client->request('POST', self::ROLES_URL.'/delete', ['_token' => $token, 'role_id' => (string) $role->getId()]);
        self::assertNotNull(CompanyRoleQuery::create()->findPk($role->getId()), 'Nor does it delete it.');

        $this->client->request('POST', $url, ['_token' => $token, 'code' => 'approver', 'permissions' => ['order.place']]);
        self::assertSame(0, CompanyRoleQuery::create()->filterByCode('approver')->filterByCompanyId($company->getId())->count(), 'A company role cannot take the code of a shared role.');

        $this->client->request('POST', $url, ['_token' => $token, 'role_id' => (string) $role->getId(), 'title' => 'Warehouse bis', 'permissions' => ['order.place']]);
        self::assertSame(['order.place'], CompanyRoleQuery::create()->findPk($role->getId())?->getPermissionList());

        $this->client->request('POST', self::LIST_URL.'/'.$company->getId().'/roles/'.$role->getId().'/delete', ['_token' => $token]);
        self::assertNull(CompanyRoleQuery::create()->findPk($role->getId()), 'The role is deleted from the sheet.');

        $this->client->request('POST', self::ROLES_URL.'/save', ['_token' => $token, 'code' => 'warehouse', 'permissions' => ['order.place']]);
        $role = CompanyRoleQuery::create()->filterByCode('warehouse')->findOne();
        $this->roleOf('hands', $company);
        $this->client->request('POST', self::ROLES_URL.'/save', ['_token' => $token, 'code' => 'hands', 'permissions' => ['order.place']]);
        self::assertFalse(CompanyRoleQuery::create()->filterByCode('hands')->filterByCompanyId(null, Criteria::ISNULL)->exists(), 'A shared role cannot take a code a company already uses.');
    }

    public function testAnAdministratorWithoutTheRightIsRefusedOnEveryScreenAndWrite(): void
    {
        $company = $this->company('Denied Co');
        $buyer = $this->buyer($company, 'company_admin');
        $role = $this->sharedRole('buyer');
        $token = $this->sessionToken();
        $id = $company->getId();

        $nobody = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW, AccessManager::UPDATE, AccessManager::CREATE], AdminResources::ORDER => [AccessManager::VIEW]]);
        $nobody->eraseCredentials();
        $this->injector->setAdmin($nobody);

        foreach ($this->screensAndWrites($id, $buyer->getId(), $role->getId(), $token) as [$method, $url, $payload]) {
            $this->client->request($method, $url, $payload);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $method.' '.$url);
        }

        self::assertSame('Denied Co', CompanyQuery::create()->findPk($id)?->getName());
        self::assertTrue(CompanyCustomerQuery::create()->filterByCustomerId($buyer->getId())->exists());
        self::assertNotNull(CompanyRoleQuery::create()->findPk($role->getId()));

        $crawler = $this->client->request('GET', '/admin/customers');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="bo-nav-customer"]'), 'The page of the customers has its menu.');
        self::assertCount(0, $crawler->filter('[data-testid="bo-nav-customer-companies"]'), 'The menu does not offer what the administrator may not open.');
    }

    public function testTheRightToViewReadsTheScreensButWritesNothing(): void
    {
        $company = $this->company('Viewer Co');
        $buyer = $this->buyer($company, 'company_admin');
        $role = $this->sharedRole('buyer');
        $token = $this->sessionToken();
        $id = $company->getId();

        $viewer = $this->factory->restrictedAdmin([AdminResources::COMPANY => [AccessManager::VIEW], AdminResources::CUSTOMER => [AccessManager::VIEW, AccessManager::CREATE]]);
        $viewer->eraseCredentials();
        $this->injector->setAdmin($viewer);

        foreach ([self::LIST_URL, self::LIST_URL.'/'.$id, self::LIST_URL.'/'.$id.'?tab=buyers', self::LIST_URL.'/'.$id.'?tab=addresses', self::LIST_URL.'/'.$id.'?tab=orders', self::ROLES_URL, self::ROLES_URL.'/'.$role->getId()] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
        }

        $crawler = $this->client->request('GET', self::LIST_URL);
        self::assertCount(1, $crawler->filter('[data-testid="bo-nav-customer-companies"]'), 'The menu offers the companies with the right to view them.');

        $crawler = $this->client->request('GET', self::LIST_URL.'/'.$id.'?tab=buyers');
        self::assertCount(0, $crawler->filter('[data-testid="company-buyer-attach-form"]'), 'The gestures the administrator may not make are not offered.');
        self::assertCount(0, $crawler->filter('[data-testid="company-buyer-create-form"]'));
        self::assertCount(0, $crawler->filter('[data-testid="company-buyer-detach-'.$buyer->getId().'"]'));

        foreach ($this->screensAndWrites($id, $buyer->getId(), $role->getId(), $token) as [$method, $url, $payload]) {
            if ('POST' !== $method) {
                continue;
            }
            $this->client->request($method, $url, $payload);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $method.' '.$url);
        }

        self::assertSame('Viewer Co', CompanyQuery::create()->findPk($id)?->getName());
        self::assertTrue(CompanyCustomerQuery::create()->filterByCustomerId($buyer->getId())->exists());
    }

    public function testEveryWriteIsRefusedWithoutTheToken(): void
    {
        $company = $this->company('Token Co');
        $admin = $this->buyer($company, 'company_admin');
        $buyer = $this->buyer($company, 'buyer');
        $newcomer = $this->customer('token.newcomer@token.test');
        $role = $this->roleOf('token-role', $company);
        $shared = $this->roleOf('token-shared', null);
        $id = $company->getId();
        $base = self::LIST_URL.'/'.$id;

        $writes = [
            self::LIST_URL.'/create' => ['name' => 'Forged Co'],
            $base.'/save' => ['name' => 'Forged'],
            $base.'/suspend' => [],
            $base.'/reactivate' => [],
            self::LIST_URL.'/delete' => ['company_id' => (string) $id],
            $base.'/buyers/attach' => ['lookup' => $newcomer->getEmail(), 'role_code' => 'buyer'],
            $base.'/buyers/create' => ['firstname' => 'Forged', 'lastname' => 'Buyer', 'email' => 'forged@token.test', 'role_code' => 'buyer'],
            $base.'/buyers/'.$buyer->getId().'/update' => ['role_code' => 'approver'],
            $base.'/buyers/'.$buyer->getId().'/detach' => [],
            $base.'/roles/save' => ['code' => 'forged', 'permissions' => ['order.place']],
            $base.'/roles/'.$role->getId().'/delete' => [],
            self::ROLES_URL.'/save' => ['code' => 'forged-shared', 'permissions' => ['order.place']],
            self::ROLES_URL.'/delete' => ['role_id' => (string) $shared->getId()],
        ];

        foreach ($writes as $url => $payload) {
            $this->client->request('POST', $url, $payload + ['_token' => 'not-the-token']);
        }

        $token = $this->sessionToken();
        foreach ($writes as $url => $payload) {
            $this->client->request('POST', $url.'?_token='.$token, $payload);
        }

        foreach ($writes as $url => $payload) {
            $this->client->request('POST', $url, $payload);
        }

        CompanyTableMap::clearInstancePool();
        $stored = CompanyQuery::create()->findPk($id);
        self::assertSame('Token Co', $stored?->getName(), 'The company was not changed.');
        self::assertSame(1, $stored->getActive(), 'The company was not suspended.');
        self::assertFalse(CompanyQuery::create()->filterByName('Forged Co')->exists(), 'No company was created.');
        self::assertSame('buyer', CompanyCustomerQuery::create()->filterByCustomerId($buyer->getId())->findOne()?->getCompanyRole()->getCode(), 'The buyer kept the role and stayed attached.');
        self::assertTrue(CompanyCustomerQuery::create()->filterByCustomerId($admin->getId())->exists());
        self::assertFalse(CompanyCustomerQuery::create()->filterByCustomerId($newcomer->getId())->exists(), 'Nobody was attached.');
        self::assertFalse(CustomerQuery::create()->filterByEmail('forged@token.test')->exists(), 'No account was created.');
        self::assertFalse(CompanyRoleQuery::create()->filterByCode('forged')->exists());
        self::assertFalse(CompanyRoleQuery::create()->filterByCode('forged-shared')->exists());
        self::assertNotNull(CompanyRoleQuery::create()->findPk($role->getId()), 'No role was deleted.');
        self::assertNotNull(CompanyRoleQuery::create()->findPk($shared->getId()));
    }

    public function testAWriteAnsweredToAGetIsNotAServerError(): void
    {
        $company = $this->company('Get Co');
        $buyer = $this->buyer($company, 'company_admin');
        $role = $this->sharedRole('buyer');

        foreach ($this->screensAndWrites($company->getId(), $buyer->getId(), $role->getId(), 'x') as [$method, $url]) {
            if ('POST' !== $method) {
                continue;
            }
            $this->client->request('GET', $url);
            self::assertLessThan(500, $this->client->getResponse()->getStatusCode(), 'GET '.$url);
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function screensAndWrites(int $companyId, int $customerId, int $roleId, string $token): array
    {
        $base = self::LIST_URL.'/'.$companyId;

        return [
            ['GET', self::LIST_URL, []],
            ['GET', self::LIST_URL.'/search?q=a', []],
            ['GET', $base, []],
            ['GET', $base.'?tab=buyers', []],
            ['GET', $base.'?tab=addresses', []],
            ['GET', $base.'?tab=orders', []],
            ['GET', self::ROLES_URL, []],
            ['GET', self::ROLES_URL.'/'.$roleId, []],
            ['GET', self::ROLES_URL.'/new', []],
            ['POST', self::LIST_URL.'/create', ['_token' => $token, 'name' => 'Denied']],
            ['POST', $base.'/save', ['_token' => $token, 'name' => 'Denied']],
            ['POST', $base.'/suspend', ['_token' => $token]],
            ['POST', $base.'/reactivate', ['_token' => $token]],
            ['POST', self::LIST_URL.'/delete', ['_token' => $token, 'company_id' => (string) $companyId]],
            ['POST', $base.'/buyers/attach', ['_token' => $token, 'lookup' => 'nobody@denied.test', 'role_code' => 'buyer']],
            ['POST', $base.'/buyers/create', ['_token' => $token, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'denied@denied.test', 'role_code' => 'buyer']],
            ['POST', $base.'/buyers/'.$customerId.'/update', ['_token' => $token, 'role_code' => 'buyer']],
            ['POST', $base.'/buyers/'.$customerId.'/detach', ['_token' => $token]],
            ['POST', $base.'/roles/save', ['_token' => $token, 'code' => 'denied', 'permissions' => ['order.place']]],
            ['POST', $base.'/roles/'.$roleId.'/delete', ['_token' => $token]],
            ['POST', self::ROLES_URL.'/save', ['_token' => $token, 'code' => 'denied', 'permissions' => ['order.place']]],
            ['POST', self::ROLES_URL.'/delete', ['_token' => $token, 'role_id' => (string) $roleId]],
        ];
    }

    private function sessionToken(): string
    {
        $html = (string) $this->client->request('GET', self::LIST_URL)->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }

    /**
     * @param array{siret?: string} $details
     */
    private function company(string $name, array $details = []): Company
    {
        return $this->companies->create(new CompanyData(name: $name, siret: $details['siret'] ?? null));
    }

    private function suspend(Company $company): void
    {
        $this->companies->suspend($company);
    }

    private function customer(?string $email = null, string $firstname = 'John', string $lastname = 'Doe'): Customer
    {
        $customer = $this->factory->customer($this->factory->customerTitle(), ['firstname' => $firstname, 'lastname' => $lastname] + (null === $email ? [] : ['email' => $email]));
        $customer->setEnable(1)->save($this->getPropelConnection());

        return $customer;
    }

    private function buyer(Company $company, string $roleCode, ?string $firstname = null, ?string $lastname = null, ?string $orderLimit = null, ?string $periodLimit = null, ?string $period = null): Customer
    {
        $customer = $this->customer(null, $firstname ?? 'Buyer', $lastname ?? 'Person');
        $this->companies->attach($company, $customer, $roleCode, $orderLimit, $periodLimit, $period);

        return $customer;
    }

    private function roleOf(string $code, ?Company $company): \Thelia\Model\CompanyRole
    {
        $role = new \Thelia\Model\CompanyRole();
        $role->setCode($code)->setCompanyId($company?->getId())->setPermissions('["order.place"]');
        $role->setLocale('en_US')->setTitle(ucfirst($code));
        $role->save($this->getPropelConnection());

        return $role;
    }

    private function sharedRole(string $code): \Thelia\Model\CompanyRole
    {
        return CompanyRoleQuery::create()->filterByCode($code)->filterByCompanyId(null, Criteria::ISNULL)->findOne() ?? throw new \LogicException($code);
    }

    private function order(?Company $company, Customer $placedBy): Order
    {
        $order = $this->factory->order($placedBy);
        $order->setCompanyId($company?->getId())->setPlacedByCustomerId($placedBy->getId())->save($this->getPropelConnection());

        return $order;
    }
}
