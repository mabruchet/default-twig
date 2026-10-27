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

use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use BackOfficeDefaultTwigBundle\Tests\Support\SaleUnitRestrictedAdmin;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Customer\CustomerGroupChangedEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerGroup;
use Thelia\Model\CustomerGroupI18nQuery;
use Thelia\Model\CustomerGroupQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The customer group on the customer sheet, in the creation form and on the customer list:
 * the group an account is filed under, the filter on it, and its column.
 */
final class CustomerGroupSheetBackOfficeTest extends WebIntegrationTestCase
{
    use RecordsSqlQueries;

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
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheSheetOffersTheGroupsAndSelectsTheOneOfTheCustomer(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesale');
        $customer = $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $group);

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());

        $select = $crawler->filter('select[name="thelia_customer_update[customer_group_id]"]');
        self::assertCount(1, $select);
        self::assertSame((string) $group->getId(), $select->filter('option[selected]')->attr('value'));
        self::assertContains('Wholesale', $select->filter('option')->each(static fn (Crawler $option): string => trim($option->text())));
    }

    public function testAnAccountWithAnAddressMovesToAnotherGroup(): void
    {
        $target = $this->factory->customerGroup(title: 'Wholesale');
        $customer = $this->factory->customer($this->factory->customerTitle());
        $this->factory->address($customer)->setIsDefault(1)->save($this->getPropelConnection());
        self::assertNotNull($customer->getDefaultAddress(), 'The fixture holds a default address, so the account event runs.');

        $changes = $this->listenToGroupChanges();
        $previous = $this->groupIdOf($customer);

        $this->saveSheet($customer, $target);

        self::assertSame((int) $target->getId(), $this->groupIdOf($customer));
        self::assertSame([[(int) $customer->getId(), $previous, (int) $target->getId()]], $changes->getArrayCopy(), 'The move is announced once.');
    }

    /**
     * CUSTOMER_UPDATEPROFILE, which saves an account without an address, ignores the group:
     * the controller moves the customer through the core service, which announces the move.
     */
    public function testAnAccountWithoutAnAddressMovesToAnotherGroupToo(): void
    {
        $target = $this->factory->customerGroup(title: 'Wholesale');
        $customer = $this->factory->customer($this->factory->customerTitle());
        self::assertNull($customer->getDefaultAddress());
        $changes = $this->listenToGroupChanges();
        $previous = $this->groupIdOf($customer);

        $this->saveSheet($customer, $target);

        self::assertSame((int) $target->getId(), $this->groupIdOf($customer));
        self::assertSame([[(int) $customer->getId(), $previous, (int) $target->getId()]], $changes->getArrayCopy(), 'The move is announced once, by the core.');
    }

    public function testSavingTheSheetInTheSameGroupAnnouncesNothing(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesale');
        $customer = $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $group);
        $changes = $this->listenToGroupChanges();

        $this->saveSheet($customer, $group);

        self::assertSame([], $changes->getArrayCopy());
    }

    public function testSavingTheSheetWithoutTouchingTheGroupKeepsIt(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesale');
        $customer = $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $group);

        $this->saveSheet($customer, null);

        self::assertSame((int) $group->getId(), $this->groupIdOf($customer));
    }

    public function testAGroupThatDoesNotExistIsRefused(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesale');
        $customer = $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $group);

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        $form = $crawler->filter('form[action*="/admin/customer/save"]')->form();
        $form->disableValidation();
        $form['thelia_customer_update[customer_group_id]'] = '999999';
        $this->client->submit($form);

        self::assertSame((int) $group->getId(), $this->groupIdOf($customer), 'An unknown group is not written.');
    }

    public function testACustomerCreatedWithoutGroupLandsInTheDefaultGroup(): void
    {
        $customer = $this->createThroughTheModal(null);

        self::assertSame((int) CustomerGroupQuery::findDefault()->getId(), $this->groupIdOf($customer));
    }

    public function testACustomerCreatedWithAGroupIsFiledUnderIt(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesale');

        $customer = $this->createThroughTheModal($group);

        self::assertSame((int) $group->getId(), $this->groupIdOf($customer));
    }

    public function testTheListFiltersOnTheGroupAndNamesItInAChip(): void
    {
        $wholesale = $this->factory->customerGroup(title: 'Wholesale');
        $member = $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Zelda']), $wholesale);
        $other = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Yvon']);

        $crawler = $this->client->request('GET', '/admin/customers?group_ids[]='.$wholesale->getId());

        $names = implode(' ', $crawler->filter('[data-testid="datatable-customers-row"]')->each(static fn (Crawler $row): string => $row->text()));
        self::assertStringContainsString((string) $member->getEmail(), $names);
        self::assertStringNotContainsString((string) $other->getEmail(), $names, 'A customer of another group is filtered out.');
        self::assertCount(1, $crawler->filter('[data-testid="customer-active-chip-group_ids"]'));
        self::assertStringContainsString('Wholesale', $crawler->filter('[data-testid="customer-active-chip-group_ids"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="customer-filter-group-'.$wholesale->getId().'"][checked]'));
    }

    public function testTheListShowsTheGroupOfEachCustomer(): void
    {
        $wholesale = $this->factory->customerGroup(title: 'Wholesale');
        $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $wholesale);

        $crawler = $this->client->request('GET', '/admin/customers?group_ids[]='.$wholesale->getId());

        self::assertSame('Wholesale', trim($crawler->filter('[data-testid="datatable-customers-row"] .bo-customer-group')->text()));
    }

    public function testTheListIsSortedOnTheTitleOfTheGroupItShows(): void
    {
        // The codes sort the other way round: the column shows the titles.
        $last = $this->factory->customerGroup('aa-last', title: 'Zulu');
        $first = $this->factory->customerGroup('zz-first', title: 'Alpha');
        $bare = $this->factory->customerGroup('mm-bare', title: 'Untranslated');
        CustomerGroupI18nQuery::create()->filterById($bare->getId())->delete($this->getPropelConnection());
        foreach ([$last, $first, $bare] as $group) {
            $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $group);
        }
        $filter = 'group_ids[]='.$last->getId().'&group_ids[]='.$first->getId().'&group_ids[]='.$bare->getId();

        $shown = function (string $direction) use ($filter): array {
            $crawler = $this->client->request('GET', '/admin/customers?'.$filter.'&order=group&direction='.$direction);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());

            return $crawler->filter('[data-testid="datatable-customers-row"] .bo-customer-group')->each(static fn (Crawler $cell): string => trim($cell->text()));
        };

        self::assertSame(['Alpha', 'mm-bare', 'Zulu'], $shown('asc'), 'The titles are in order, the code in the place of a missing title.');
        self::assertSame(['Zulu', 'mm-bare', 'Alpha'], $shown('desc'));
    }

    public function testTheListReadsTheGroupsInOneQuery(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesale');
        $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $group);

        // The first request of the kernel: the services remember what they read, as they do
        // for one request in production.
        $statements = $this->recordSqlQueries(function (): void {
            $this->client->request('GET', '/admin/customers');
        });

        $reads = array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/FROM\s+`?customer_group`?\b/i', $sql));
        self::assertCount(1, $reads, 'The filter, the creation form and the column share one read of the groups.');
    }

    public function testTheNumberOfQueriesOfTheListDoesNotGrowWithItsRows(): void
    {
        $groups = [$this->factory->customerGroup(title: 'A'), $this->factory->customerGroup(title: 'B'), $this->factory->customerGroup(title: 'C')];
        $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $groups[0]);
        $this->client->request('GET', '/admin/customers');

        $few = QueryCounter::count(function (): void {
            $this->client->request('GET', '/admin/customers');
        });

        for ($i = 0; $i < 20; ++$i) {
            $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $groups[$i % 3]);
        }
        $many = QueryCounter::count(function (): void {
            $this->client->request('GET', '/admin/customers');
        });

        self::assertSame($few, $many, 'The group column is read in one query, whatever the number of rows.');
    }

    public function testTheGroupFieldIsReadOnlyForAnAdministratorRestrictedToASaleUnit(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesale');
        $customer = $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle()), $group);
        $this->actAsRestrictedAdministrator();

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        $select = $crawler->filter('select[name="thelia_customer_update[customer_group_id]"]');
        self::assertCount(1, $select);
        self::assertSame('disabled', $select->attr('disabled'));
        self::assertSame((string) $group->getId(), $select->filter('option[selected]')->attr('value'), 'It still shows the group of the account.');

        $crawler = $this->client->request('GET', '/admin/customers');
        self::assertSame('disabled', $crawler->filter('select[name="thelia_customer_create[customer_group_id]"]')->attr('disabled'));
    }

    public function testAnAdministratorRestrictedToASaleUnitCannotMoveACustomerToAnotherGroup(): void
    {
        $group = $this->factory->customerGroup(title: 'Wholesale');
        $target = $this->factory->customerGroup(title: 'Retail');
        $customer = $this->factory->assignCustomerGroup($this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Before']), $group);
        $this->actAsRestrictedAdministrator();
        $changes = $this->listenToGroupChanges();

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        $values = $crawler->filter('form[action*="/admin/customer/save"]')->form()->getPhpValues();
        $values['thelia_customer_update']['firstname'] = 'After';
        $values['thelia_customer_update']['customer_group_id'] = (string) $target->getId();

        $this->client->request('POST', '/admin/customer/save', $values);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame((int) $group->getId(), $this->groupIdOf($customer));
        self::assertSame('Before', CustomerQuery::create()->findPk($customer->getId())?->getFirstname(), 'Nothing is written, not even the rest of the sheet.');
        self::assertSame([], $changes->getArrayCopy());

        // The sheet saved as it is shown keeps the group.
        $values['thelia_customer_update']['customer_group_id'] = (string) $group->getId();
        $this->client->request('POST', '/admin/customer/save', $values);
        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertSame('After', CustomerQuery::create()->findPk($customer->getId())?->getFirstname());
        self::assertSame((int) $group->getId(), $this->groupIdOf($customer));

        // The field the page disables is not posted at all.
        unset($values['thelia_customer_update']['customer_group_id']);
        $values['thelia_customer_update']['firstname'] = 'Again';
        $this->client->request('POST', '/admin/customer/save', $values);
        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertSame((int) $group->getId(), $this->groupIdOf($customer));
        self::assertSame([], $changes->getArrayCopy());
    }

    public function testAnAdministratorRestrictedToASaleUnitCreatesInTheDefaultGroupOnly(): void
    {
        $target = $this->factory->customerGroup(title: 'Wholesale');
        $this->factory->country();
        $this->actAsRestrictedAdministrator();

        $crawler = $this->client->request('GET', '/admin/customers');
        $values = $crawler->filter('form[action*="/admin/customer/create"]')->form()->getPhpValues();
        $email = 'nina-restricted-'.uniqid().'@test.com';
        $values['thelia_customer_create'] = [...$values['thelia_customer_create'], ...['firstname' => 'Nina', 'lastname' => 'Restricted', 'email' => $email, 'email_confirm' => $email, 'address1' => '1 Main Street', 'zipcode' => '75001', 'city' => 'Paris']];

        $this->client->request('POST', '/admin/customer/create', ['thelia_customer_create' => ['customer_group_id' => (string) $target->getId()] + $values['thelia_customer_create']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull(CustomerQuery::create()->findOneByEmail($email));

        $this->client->request('POST', '/admin/customer/create', $values);
        $customer = CustomerQuery::create()->findOneByEmail($email);
        self::assertNotNull($customer, 'Without a group the account is created.');
        self::assertSame((int) CustomerGroupQuery::findDefault()->getId(), $this->groupIdOf($customer));
    }

    private function actAsRestrictedAdministrator(): void
    {
        $this->injector->setAdmin(SaleUnitRestrictedAdmin::create($this->factory, $this->getService(EventDispatcherInterface::class), $this->getPropelConnection(), [
            AdminResources::CUSTOMER => [AccessManager::VIEW, AccessManager::CREATE, AccessManager::UPDATE],
        ]));
    }

    /**
     * @return \ArrayObject<int, array{int, int, int}> customer id, previous group id and new group id of each announced move
     */
    private function listenToGroupChanges(): \ArrayObject
    {
        $changes = new \ArrayObject();
        $this->getService(EventDispatcherInterface::class)->addListener(
            TheliaEvents::CUSTOMER_GROUP_CHANGED,
            static function (CustomerGroupChangedEvent $event) use ($changes): void {
                $changes[] = [(int) $event->getCustomer()->getId(), $event->getPreviousCustomerGroupId(), $event->getCustomerGroupId()];
            },
        );

        return $changes;
    }

    private function saveSheet(Customer $customer, ?CustomerGroup $group): void
    {
        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        $form = $crawler->filter('form[action*="/admin/customer/save"]')->form();
        if (null !== $group) {
            $form['thelia_customer_update[customer_group_id]'] = (string) $group->getId();
        }
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirection(), 'The sheet is saved.');
    }

    private function createThroughTheModal(?CustomerGroup $group): Customer
    {
        $this->factory->country();
        $crawler = $this->client->request('GET', '/admin/customers');
        $form = $crawler->filter('form[action*="/admin/customer/create"]')->form();
        $email = 'nina-created-'.uniqid().'@test.com';
        $values = [
            'thelia_customer_create[firstname]' => 'Nina',
            'thelia_customer_create[lastname]' => 'Created',
            'thelia_customer_create[email]' => $email,
            'thelia_customer_create[address1]' => '1 Main Street',
            'thelia_customer_create[zipcode]' => '75001',
            'thelia_customer_create[city]' => 'Paris',
        ];
        if (null !== $group) {
            $values['thelia_customer_create[customer_group_id]'] = (string) $group->getId();
        }
        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }
        if (isset($form['thelia_customer_create[email_confirm]'])) {
            $form['thelia_customer_create[email_confirm]'] = $email;
        }
        $this->client->submit($form);

        $customer = CustomerQuery::create()->findOneByEmail($email);
        self::assertNotNull($customer, 'The customer is created.');

        return $customer;
    }

    private function groupIdOf(Customer $customer): int
    {
        return (int) CustomerQuery::create()->findPk($customer->getId())?->getCustomerGroupId();
    }
}
