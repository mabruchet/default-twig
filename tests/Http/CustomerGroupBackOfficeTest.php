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

use BackOfficeDefaultTwigBundle\Service\CustomerGroup\CustomerGroupWriter;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use BackOfficeDefaultTwigBundle\Tests\Support\SaleUnitRestrictedAdmin;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Condition\Implementation\ForSomeCustomerGroups;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\CatalogPriceRule;
use Thelia\Model\CatalogPriceRuleCustomerGroupQuery;
use Thelia\Model\CatalogPriceRuleQuery;
use Thelia\Model\Category;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\CustomerGroup;
use Thelia\Model\CustomerGroupCategory;
use Thelia\Model\CustomerGroupCategoryQuery;
use Thelia\Model\CustomerGroupPaymentModule;
use Thelia\Model\CustomerGroupPaymentModuleQuery;
use Thelia\Model\CustomerGroupQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Sale;
use Thelia\Model\SaleCustomerGroupQuery;
use Thelia\Model\SaleQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The customer groups in the back office: their configuration screen, the coupon
 * condition on the group of the buyer, and the audience by group of the catalog price
 * rules and of the sales.
 */
final class CustomerGroupBackOfficeTest extends WebIntegrationTestCase
{
    use RecordsSqlQueries;

    private const LIST_URL = '/admin/configuration/customer-groups';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        // The client runs in this process: a model the test read stays pooled and would
        // answer for the one the screen has just written.
        Propel::disableInstancePooling();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        Propel::enableInstancePooling();

        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheListShowsTheGroupsAndTheDefaultOneCannotBeDeletedFromIt(): void
    {
        $pro = $this->group('pro-list', 'Professionals');

        $this->assertPageRenders(self::LIST_URL);
        $crawler = $this->client->getCrawler();

        self::assertCount(1, $crawler->filter('[data-testid="customer-groups-page"]'));
        self::assertStringContainsString('Professionals', $crawler->filter('[data-testid="customer-groups-page"]')->text());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-customer-group-id="'.$pro->getId().'"', $html, 'a group other than the default one offers the deletion');

        $default = CustomerGroupQuery::findDefault();
        self::assertStringNotContainsString('data-customer-group-id="'.$default->getId().'"', $html, 'the default group does not offer the deletion');
    }

    public function testTheMerchantCreatesAGroupAndEditsItsTitleInTwoLanguages(): void
    {
        $this->client->request('POST', self::LIST_URL.'/create', ['thelia_customer_group_creation' => [
            'code' => 'wholesale-b1',
            'title' => 'Wholesale',
            'description' => '',
            'discount' => '12.5',
            'locale' => 'en_US',
            '_token' => $this->formToken(self::LIST_URL, 'thelia_customer_group_creation'),
        ]]);

        $group = CustomerGroupQuery::create()->findOneByCode('wholesale-b1');
        self::assertNotNull($group, 'The group is created.');
        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_URL.'/update/'.$group->getId()));
        self::assertEqualsWithDelta(12.5, (float) $group->getDiscount(), 0.000001);
        self::assertSame(0, $group->getDisplayTaxedPrice(), 'An unticked box shows the prices without taxes.');
        self::assertSame(0, $group->getIsDefault());
        self::assertSame('Wholesale', $group->setLocale('en_US')->getTitle());

        $editUrl = self::LIST_URL.'/update/'.$group->getId();
        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), ['thelia_customer_group_modification' => [
            'id' => (string) $group->getId(),
            'code' => 'wholesale-b1',
            'title' => 'Grossistes',
            'description' => 'Achats en gros',
            'discount' => '15',
            'display_taxed_price' => '1',
            'locale' => 'fr_FR',
            '_token' => $this->formToken($editUrl, 'thelia_customer_group_modification'),
        ]]);

        $group = CustomerGroupQuery::create()->findPk($group->getId());
        self::assertNotNull($group);
        self::assertSame('Grossistes', $group->setLocale('fr_FR')->getTitle());
        self::assertSame('Wholesale', $group->setLocale('en_US')->getTitle(), 'The other language keeps its title.');
        self::assertEqualsWithDelta(15.0, (float) $group->getDiscount(), 0.000001);
        self::assertSame(1, $group->getDisplayTaxedPrice());
    }

    public function testThePaymentModulesAndTheCategoriesOfAGroupPersist(): void
    {
        $group = $this->group('links-b1', 'Links');
        $module = $this->paymentModule('B1PayA');
        $other = $this->paymentModule('B1PayB');
        $category = $this->factory->category();
        $otherCategory = $this->factory->category();
        $editUrl = self::LIST_URL.'/update/'.$group->getId();

        $payload = static fn (array $moduleIds, array $categoryIds, string $token): array => ['thelia_customer_group_modification' => [
            'id' => (string) $group->getId(),
            'code' => 'links-b1',
            'title' => 'Links',
            'discount' => '0',
            'display_taxed_price' => '1',
            'locale' => 'en_US',
            'payment_module_ids' => array_map('strval', $moduleIds),
            'category_ids' => array_map('strval', $categoryIds),
            '_token' => $token,
        ]];

        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), $payload([$module->getId(), $other->getId()], [$category->getId(), $otherCategory->getId()], $this->formToken($editUrl, 'thelia_customer_group_modification')));
        self::assertEqualsCanonicalizing([$module->getId(), $other->getId()], $this->linkedModuleIds($group));
        self::assertEqualsCanonicalizing([$category->getId(), $otherCategory->getId()], $this->linkedCategoryIds($group));

        // The edit screen holds them selected.
        $crawler = $this->client->request('GET', $editUrl);
        self::assertSame(2, $crawler->filter('[data-testid="customer-group-payment-modules"] option[selected]')->count());
        self::assertSame(2, $crawler->filter('[data-testid="customer-group-categories"] option[selected]')->count());

        // One of each is dropped, none added: the links follow what is posted.
        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), $payload([$module->getId()], [$category->getId()], $this->formToken($editUrl, 'thelia_customer_group_modification')));
        self::assertSame([$module->getId()], $this->linkedModuleIds($group));
        self::assertSame([$category->getId()], $this->linkedCategoryIds($group));

        // A link to a module deactivated since is not on the form: saving the group keeps it.
        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), $payload([$module->getId(), $other->getId()], [$category->getId()], $this->formToken($editUrl, 'thelia_customer_group_modification')));
        $other->setActivate(BaseModule::IS_NOT_ACTIVATED)->save($this->getPropelConnection());
        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), $payload([$module->getId()], [$category->getId()], $this->formToken($editUrl, 'thelia_customer_group_modification')));
        self::assertEqualsCanonicalizing([$module->getId(), $other->getId()], $this->linkedModuleIds($group), 'the link to an inactive module survives the save');
        $other->setActivate(BaseModule::IS_ACTIVATED)->save($this->getPropelConnection());
        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), $payload([$module->getId()], [$category->getId()], $this->formToken($editUrl, 'thelia_customer_group_modification')));
        self::assertSame([$module->getId()], $this->linkedModuleIds($group));

        // A module that is not a payment module is not a choice: the form refuses it.
        $delivery = (new Module())->setCode('B1Delivery')->setFullNamespace('B1\\Delivery')->setVersion('1.0.0')->setType(BaseModule::DELIVERY_MODULE_TYPE)->setCategory('delivery')->setActivate(BaseModule::IS_ACTIVATED);
        $delivery->save($this->getPropelConnection());
        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), $payload([$delivery->getId()], [], $this->formToken($editUrl, 'thelia_customer_group_modification')));
        self::assertSame([$module->getId()], $this->linkedModuleIds($group), 'a delivery module is not linked');
    }

    public function testTheGroupsCanBeReorderedAndDeleted(): void
    {
        $first = $this->group('order-a-b1', 'A');
        $second = $this->group('order-b-b1', 'B');
        $token = $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/update-position?customer_group_id='.$second->getId().'&position=1', ['_token' => $token]);
        self::assertSame(1, (int) CustomerGroupQuery::create()->findPk($second->getId())?->getPosition());
        self::assertGreaterThan(1, (int) CustomerGroupQuery::create()->findPk($first->getId())?->getPosition(), 'the others follow');

        $this->client->request('POST', self::LIST_URL.'/delete', ['customer_group_id' => $first->getId(), '_token' => $token]);
        self::assertNull(CustomerGroupQuery::create()->findPk($first->getId()), 'a group other than the default one is deleted');
    }

    public function testTheDefaultGroupCannotBeDeletedAndTheRefusalIsShown(): void
    {
        $default = CustomerGroupQuery::findDefault();

        $this->client->request('POST', self::LIST_URL.'/delete', ['customer_group_id' => $default->getId(), '_token' => $this->sessionToken()]);

        self::assertNotNull(CustomerGroupQuery::create()->findPk($default->getId()), 'the default group stays');
        self::assertTrue($this->client->getResponse()->isRedirect(self::LIST_URL));
        $this->client->followRedirect();
        self::assertStringContainsString('The default customer group cannot be deleted', (string) $this->client->getResponse()->getContent());
    }

    public function testTheDefaultFlagMovesAndTheOnlyDefaultCannotBeReleased(): void
    {
        $default = CustomerGroupQuery::findDefault();
        $other = $this->group('flag-b1', 'Flag');
        $post = function (CustomerGroup $group, bool $isDefault): void {
            $url = self::LIST_URL.'/update/'.$group->getId();
            $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), ['thelia_customer_group_modification' => array_filter([
                'id' => (string) $group->getId(),
                'code' => $group->getCode(),
                'title' => 'Flag',
                'discount' => '0',
                'display_taxed_price' => '1',
                'is_default' => $isDefault ? '1' : null,
                'locale' => 'en_US',
                '_token' => $this->formToken($url, 'thelia_customer_group_modification'),
            ], static fn (?string $value): bool => null !== $value)]);
        };

        $post($other, true);
        self::assertSame(1, CustomerGroupQuery::create()->findPk($other->getId())?->getIsDefault());
        self::assertSame(0, CustomerGroupQuery::create()->findPk($default->getId())?->getIsDefault(), 'the previous default is released');

        // The only default group cannot stop being it.
        $post($other, false);
        self::assertSame(1, CustomerGroupQuery::create()->findPk($other->getId())?->getIsDefault());
        $this->client->followRedirect();
        self::assertStringContainsString('cannot stop being the default', (string) $this->client->getResponse()->getContent());
    }

    public function testARestrictedAdministratorIsRefusedOnEveryAction(): void
    {
        $group = $this->group('denied-b1', 'Denied');
        $token = $this->sessionToken();

        $viewer = $this->factory->restrictedAdmin([AdminResources::CUSTOMER_GROUP => [AccessManager::VIEW]]);
        $viewer->eraseCredentials();
        $this->injector->setAdmin($viewer);

        $this->client->request('GET', self::LIST_URL);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'the right to view opens the list');

        $this->client->request('POST', self::LIST_URL.'/create', ['thelia_customer_group_creation' => ['code' => 'no-b1', 'title' => 'No', 'locale' => 'en_US']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull(CustomerGroupQuery::create()->findOneByCode('no-b1'));

        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), ['thelia_customer_group_modification' => ['id' => (string) $group->getId(), 'code' => 'hacked', 'title' => 'x', 'locale' => 'en_US']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::LIST_URL.'/update-position?customer_group_id='.$group->getId().'&position=1', ['_token' => $token]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::LIST_URL.'/delete', ['customer_group_id' => $group->getId(), '_token' => $token]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('denied-b1', CustomerGroupQuery::create()->findPk($group->getId())?->getCode(), 'nothing was written');

        // Without any right on the resource, the direct URL is refused too.
        $nobody = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]);
        $nobody->eraseCredentials();
        $this->injector->setAdmin($nobody);
        $this->client->request('GET', self::LIST_URL);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', self::LIST_URL.'/update/'.$group->getId());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAdministratorRestrictedToASaleUnitCannotWriteTheGroups(): void
    {
        $group = $this->group('unit-b1', 'Unit');
        $token = $this->sessionToken();

        $this->injector->setAdmin(SaleUnitRestrictedAdmin::create($this->factory, $this->getService(EventDispatcherInterface::class), $this->getPropelConnection(), [
            AdminResources::CUSTOMER_GROUP => [AccessManager::VIEW, AccessManager::CREATE, AccessManager::UPDATE, AccessManager::DELETE],
        ]));

        $this->client->request('GET', self::LIST_URL);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'It may read the groups.');

        $this->client->request('POST', self::LIST_URL.'/create', ['thelia_customer_group_creation' => ['code' => 'unit-new-b1', 'title' => 'New', 'discount' => '0', 'locale' => 'en_US', '_token' => $token]]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull(CustomerGroupQuery::create()->findOneByCode('unit-new-b1'));

        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), ['thelia_customer_group_modification' => ['id' => (string) $group->getId(), 'code' => 'unit-hacked', 'title' => 'x', 'discount' => '90', 'locale' => 'en_US', '_token' => $token]]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::LIST_URL.'/update-position?customer_group_id='.$group->getId().'&position=1', ['_token' => $token]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::LIST_URL.'/delete', ['customer_group_id' => $group->getId(), '_token' => $token]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $stored = CustomerGroupQuery::create()->findPk($group->getId());
        self::assertSame('unit-b1', $stored?->getCode(), 'Nothing was written, nor deleted.');
        self::assertEqualsWithDelta(0.0, (float) $stored->getDiscount(), 0.000001);
    }

    public function testTheScreenOfTheGroupsOffersNoWriteToAnAdministratorRestrictedToASaleUnit(): void
    {
        $group = $this->group('unit-ui', 'Unit');
        $grants = [AdminResources::CUSTOMER_GROUP => [AccessManager::VIEW, AccessManager::CREATE, AccessManager::UPDATE, AccessManager::DELETE]];
        $writes = '[data-testid="customer-group-create-button"], #customer-group-create-modal, #customer-group-delete-modal, [data-controller~="bo-sortable"], a[href*="/customer-groups/update/"], [data-customer-group-id]';

        $crawler = $this->client->request('GET', self::LIST_URL);
        self::assertGreaterThan(0, $crawler->filter($writes)->count(), 'An unrestricted administrator is offered the writes.');
        $crawler = $this->client->request('GET', self::LIST_URL.'/update/'.$group->getId());
        self::assertCount(1, $crawler->filter('[data-testid="customer-group-edit-submit"]'));

        $this->injector->setAdmin(SaleUnitRestrictedAdmin::create($this->factory, $this->getService(EventDispatcherInterface::class), $this->getPropelConnection(), $grants));

        $crawler = $this->client->request('GET', self::LIST_URL);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('unit-ui', $crawler->text(), 'The groups are still listed.');
        self::assertCount(0, $crawler->filter($writes));

        $crawler = $this->client->request('GET', self::LIST_URL.'/update/'.$group->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="customer-group-edit-submit"]'));
    }

    public function testTheCategoryChoicesCarryTheirTitleAndAreIndentedUnderTheirParent(): void
    {
        $group = $this->group('tree-b1', 'Tree');
        $parent = $this->factory->category();
        $parent->setLocale('en_US')->setTitle('Shoes')->save($this->getPropelConnection());
        $child = $this->factory->category(['parent' => $parent->getId()]);
        $child->setLocale('en_US')->setTitle('Boots')->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', self::LIST_URL.'/update/'.$group->getId());

        $labels = $crawler->filter('[data-testid="customer-group-categories"] option')->each(static fn ($option): string => trim($option->text()));
        $shoes = array_search('Shoes', $labels, true);
        self::assertIsInt($shoes, 'The title of the category is its label.');
        self::assertSame('- Boots', $labels[$shoes + 1] ?? null, 'A child follows its parent, indented.');
    }

    public function testTheNumberOfQueriesOfTheListAndOfTheEditScreenDoesNotGrowWithTheCategories(): void
    {
        $group = $this->group('budget-b1', 'Budget');
        $this->factory->category();
        $editUrl = self::LIST_URL.'/update/'.$group->getId();
        $count = function (string $url): int {
            return QueryCounter::count(function () use ($url): void {
                $this->client->request('GET', $url);
                self::assertSame(200, $this->client->getResponse()->getStatusCode());
            });
        };

        $count(self::LIST_URL);
        $count($editUrl);
        $listFew = $count(self::LIST_URL);
        $editFew = $count($editUrl);

        for ($i = 0; $i < 8; ++$i) {
            $this->group('budget-more-'.$i.'-b1', 'More '.$i);
            $this->factory->category();
        }

        self::assertSame($listFew, $count(self::LIST_URL), 'The list reads its groups, and no category: the creation form has no links.');
        $listed = $this->recordSqlQueries(function (): void {
            $this->client->request('GET', self::LIST_URL);
        });
        self::assertSame([], array_filter($listed, static fn (string $sql): bool => 1 === preg_match('/FROM\s+`?category`?\b/i', $sql)), 'The creation form has no links: the list does not read the categories.');
        self::assertSame($editFew, $count($editUrl), 'The category titles are read by the query of the categories.');
    }

    public function testAGroupThatHasLinksIsDeletedAndItsLinksGoWithIt(): void
    {
        $group = $this->group('opener-b1', 'Opener');
        $category = $this->titledCategory('Reserved shoes');
        $module = $this->titledPaymentModule('B1PayOpen', 'Pay by wire');
        $this->open($group, $category, $module);
        self::assertSame([$category->getId()], $this->linkedCategoryIds($group));

        $this->client->request('POST', self::LIST_URL.'/delete', ['customer_group_id' => $group->getId(), '_token' => $this->sessionToken()]);

        self::assertNull(CustomerGroupQuery::create()->findPk($group->getId()), 'No other group has to open the category and the module: the group is deleted.');
        self::assertSame([], $this->linkedCategoryIds($group), 'The links of the group are gone.');
        self::assertSame([], $this->linkedModuleIds($group));
    }

    public function testTheLinksOfAGroupCanAllBeTakenOff(): void
    {
        $group = $this->group('unlink-b1', 'Unlink');
        $category = $this->titledCategory('Reserved hats');
        $module = $this->titledPaymentModule('B1PayUnlink', 'Pay by card');
        $this->open($group, $category, $module);

        $this->saveLinks($group, [$module->getId()], [$category->getId()]);
        self::assertSame([$category->getId()], $this->linkedCategoryIds($group), 'Links that are kept change nothing.');

        $this->saveLinks($group, [], []);
        self::assertSame([], $this->linkedCategoryIds($group), 'An empty list opens everything to the group: the links go.');
        self::assertSame([], $this->linkedModuleIds($group));
        self::assertSame([0, 0], $this->flagsOf($group), 'And the group is no longer restricted.');
    }

    public function testTheLinksTickedRestrictTheGroupAndTheFlagsFollowTheSave(): void
    {
        $group = $this->group('flags-b1', 'Flags');
        $category = $this->titledCategory('Reserved scarves');
        $module = $this->titledPaymentModule('B1PayFlags', 'Pay by transfer');
        self::assertSame([0, 0], $this->flagsOf($group));

        $this->saveLinks($group, [$module->getId()], [$category->getId()]);
        self::assertSame([1, 1], $this->flagsOf($group));

        $this->saveLinks($group, [$module->getId()], []);
        self::assertSame([0, 1], $this->flagsOf($group), 'Only the list left empty is lifted.');
    }

    public function testTheScreenWarnsWhenARestrictedGroupHasNothingLeftOnItsList(): void
    {
        $group = $this->group('emptied-b1', 'Emptied');
        $category = $this->titledCategory('Vanishing coats');
        $module = $this->titledPaymentModule('B1PayVanishing', 'Pay by coin');
        $this->open($group, $category, $module);
        $editUrl = self::LIST_URL.'/update/'.$group->getId();

        $crawler = $this->client->request('GET', $editUrl);
        self::assertSame(0, $crawler->filter('[data-testid="customer-group-categories-emptied"]')->count(), 'The lists are not empty: no warning.');
        self::assertSame(0, $crawler->filter('[data-testid="customer-group-payment-modules-emptied"]')->count());

        // The category and the module are deleted: the database drops the links and leaves the flags.
        $category->delete($this->getPropelConnection());
        ModuleQuery::create()->filterById($module->getId())->delete($this->getPropelConnection());

        $crawler = $this->client->request('GET', $editUrl);
        self::assertSame(1, $crawler->filter('[data-testid="customer-group-categories-emptied"]')->count());
        self::assertSame(1, $crawler->filter('[data-testid="customer-group-payment-modules-emptied"]')->count());
        self::assertStringContainsString('it sees no category', $crawler->filter('[data-testid="customer-group-categories-emptied"]')->text());
        self::assertStringContainsString('cannot pay with any payment module', $crawler->filter('[data-testid="customer-group-payment-modules-emptied"]')->text());
    }

    public function testTheScreenWarnsOfNothingForAGroupThatIsNotRestricted(): void
    {
        $group = $this->group('open-b1', 'Open');

        $crawler = $this->client->request('GET', self::LIST_URL.'/update/'.$group->getId());

        self::assertSame(0, $crawler->filter('[data-testid="customer-group-categories-emptied"]')->count(), 'No flag and no list: open to all, nothing to say.');
        self::assertSame(0, $crawler->filter('[data-testid="customer-group-payment-modules-emptied"]')->count());
    }

    public function testAModuleThatIsNotAPaymentModuleIsNeverLinked(): void
    {
        $group = $this->group('inject-b1', 'Inject');
        $payment = $this->paymentModule('B1PayInject');
        $delivery = (new Module())->setCode('B1DeliveryInject')->setFullNamespace('B1\\DeliveryInject')->setVersion('1.0.0')->setType(BaseModule::DELIVERY_MODULE_TYPE)->setCategory('delivery')->setActivate(BaseModule::IS_ACTIVATED);
        $delivery->save($this->getPropelConnection());

        // The form refuses the id of a delivery module posted next to a valid one...
        $this->saveLinks($group, [$payment->getId(), $delivery->getId()], []);
        self::assertSame([], $this->linkedModuleIds($group), 'Nothing is written.');

        // ... and the writer, whoever calls it, links the payment modules only.
        $writer = $this->getService(CustomerGroupWriter::class);
        $writer->update($group, 'inject-b1', 'en_US', 'Inject', null, 0.0, true, false, [$payment->getId(), $delivery->getId()], []);
        self::assertSame([$payment->getId()], $this->linkedModuleIds($group), 'A delivery module is not linked.');
    }

    public function testADiscountOutsideZeroToOneHundredIsRefused(): void
    {
        $group = $this->group('range-b1', 'Range');
        $editUrl = self::LIST_URL.'/update/'.$group->getId();

        foreach (['100.01', '-1'] as $discount) {
            $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), ['thelia_customer_group_modification' => [
                'id' => (string) $group->getId(),
                'code' => 'range-b1',
                'title' => 'Range',
                'discount' => $discount,
                'display_taxed_price' => '1',
                'locale' => 'en_US',
                '_token' => $this->formToken($editUrl, 'thelia_customer_group_modification'),
            ]]);
            self::assertEqualsWithDelta(0.0, (float) CustomerGroupQuery::create()->findPk($group->getId())?->getDiscount(), 0.000001, $discount.' % is not written.');
        }

        $this->client->request('POST', self::LIST_URL.'/create', ['thelia_customer_group_creation' => [
            'code' => 'range-new-b1',
            'title' => 'Range new',
            'discount' => '150',
            'locale' => 'en_US',
            '_token' => $this->formToken(self::LIST_URL, 'thelia_customer_group_creation'),
        ]]);
        self::assertNull(CustomerGroupQuery::create()->findOneByCode('range-new-b1'), 'A discount above 100 % creates no group.');

        foreach (['0', '100'] as $discount) {
            $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), ['thelia_customer_group_modification' => [
                'id' => (string) $group->getId(),
                'code' => 'range-b1',
                'title' => 'Range',
                'discount' => $discount,
                'display_taxed_price' => '1',
                'locale' => 'en_US',
                '_token' => $this->formToken($editUrl, 'thelia_customer_group_modification'),
            ]]);
            self::assertEqualsWithDelta((float) $discount, (float) CustomerGroupQuery::create()->findPk($group->getId())?->getDiscount(), 0.000001, 'The bounds are allowed.');
        }
    }

    public function testTheCsrfTokenIsChecked(): void
    {
        $group = $this->group('csrf-b1', 'Csrf');
        $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/create', ['thelia_customer_group_creation' => [
            'code' => 'csrf-new-b1',
            'title' => 'No token',
            'discount' => '0',
            'locale' => 'en_US',
            '_token' => 'forged',
        ]]);
        self::assertNull(CustomerGroupQuery::create()->findOneByCode('csrf-new-b1'), 'a form without its token creates nothing');

        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), ['thelia_customer_group_modification' => [
            'id' => (string) $group->getId(),
            'code' => 'csrf-changed',
            'title' => 'x',
            'discount' => '0',
            'locale' => 'en_US',
            '_token' => 'forged',
        ]]);
        self::assertSame('csrf-b1', CustomerGroupQuery::create()->findPk($group->getId())?->getCode());

        $this->client->request('POST', self::LIST_URL.'/delete', ['customer_group_id' => $group->getId(), '_token' => 'forged']);
        self::assertNotNull(CustomerGroupQuery::create()->findPk($group->getId()), 'a deletion with a forged token deletes nothing');

        $this->client->request('POST', self::LIST_URL.'/update-position?customer_group_id='.$group->getId().'&position=1', ['_token' => 'forged']);
        self::assertNotSame(1, (int) CustomerGroupQuery::create()->findPk($group->getId())?->getPosition(), 'a reorder with a forged token moves nothing');
    }

    public function testTheCouponEditorOffersTheCustomerGroupsConditionAndItIsSaved(): void
    {
        $group = $this->group('coupon-b1', 'Coupon group');

        $this->client->request('GET', '/admin/coupon/draw/read/conditionInputs/thelia.condition.for_some_customer_groups');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('coupon-customer-groups-select', $content);
        self::assertStringContainsString(ForSomeCustomerGroups::CUSTOMER_GROUPS_LIST.'[value][]', $content);
        self::assertStringContainsString('Coupon group', $content);
        self::assertStringNotContainsString('does not support this condition type', $content);

        $coupon = $this->factory->coupon();
        $token = $this->sessionToken('/admin/coupon');
        $this->client->request('POST', '/admin/coupon/'.$coupon->getId().'/condition/save', [
            'categoryCondition' => 'thelia.condition.for_some_customer_groups',
            ForSomeCustomerGroups::CUSTOMER_GROUPS_LIST => ['operator' => 'in', 'value' => [(string) $group->getId()]],
            '_token' => $token,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $saved = \Thelia\Model\CouponQuery::create()->findPk($coupon->getId());
        self::assertNotNull($saved);
        $conditions = json_decode((string) base64_decode((string) $saved->getSerializedConditions(), true), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('thelia.condition.for_some_customer_groups', $conditions[0]['conditionServiceId']);
        self::assertSame([ForSomeCustomerGroups::CUSTOMER_GROUPS_LIST => [$group->getId()]], $conditions[0]['values'], 'the group is stored as an integer id');

        // The saved condition is drawn back selected.
        $this->client->request('GET', '/admin/coupon/draw/update/conditionInputs/'.$coupon->getId().'/0');
        self::assertStringContainsString('value="'.$group->getId().'" selected', (string) $this->client->getResponse()->getContent());
    }

    public function testARuleReservedForGroupsIsSavedWithItsGroupsAndRefusedWithoutAny(): void
    {
        $group = $this->group('rule-b1', 'Rule group');
        $other = $this->group('rule-other-b1', 'Rule other group');
        $rule = $this->factory->catalogPriceRule(['title' => 'For groups']);
        $url = '/admin/catalog-price-rule/update/'.$rule->getId();

        $payload = fn (array $groupIds): array => [
            'thelia_catalog_price_rule' => [
                'id' => $rule->getId(),
                'locale' => 'en_US',
                'title' => 'For groups',
                'active' => '1',
                'priority' => '50',
                'effect_type' => (string) CatalogPriceRule::EFFECT_TYPE_PERCENTAGE,
                'percentage_value' => '20',
                'audience_mode' => (string) CatalogPriceRule::AUDIENCE_MODE_CUSTOMER_GROUPS,
                '_token' => $this->formToken($url, 'thelia_catalog_price_rule'),
            ],
            'criteria' => ['category' => [(string) $this->factory->category()->getId()]],
            'rule_customer_groups' => array_map('strval', $groupIds),
        ];

        $this->client->request('POST', '/admin/catalog-price-rule/save/'.$rule->getId(), $payload([$group->getId(), $other->getId()]));
        self::assertTrue($this->client->getResponse()->isRedirect($url));
        $saved = CatalogPriceRuleQuery::create()->findPk($rule->getId());
        self::assertSame(CatalogPriceRule::AUDIENCE_MODE_CUSTOMER_GROUPS, (int) $saved?->getAudienceMode());
        self::assertEqualsCanonicalizing([$group->getId(), $other->getId()], $this->ruleGroupIds($rule));

        // The screen shows them again.
        $crawler = $this->client->request('GET', $url);
        self::assertSame(2, $crawler->filter('[data-testid="catalog-price-rule-customer-groups-select"] option[selected]')->count());
        self::assertCount(1, $crawler->filter('[data-testid="catalog-price-rule-audience-'.CatalogPriceRule::AUDIENCE_MODE_CUSTOMER_GROUPS.'"]'));

        // No group: an error on the screen, the rule keeps its groups, no 500.
        $this->client->request('POST', '/admin/catalog-price-rule/save/'.$rule->getId(), $payload([]));
        self::assertTrue($this->client->getResponse()->isRedirect($url), 'the refusal goes back to the screen');
        self::assertEqualsCanonicalizing([$group->getId(), $other->getId()], $this->ruleGroupIds($rule));
        $this->client->followRedirect();
        self::assertStringContainsString('must name at least one group', (string) $this->client->getResponse()->getContent());

        // A group that no longer exists is as good as none: the form counts the groups that exist.
        $this->client->request('POST', '/admin/catalog-price-rule/save/'.$rule->getId(), $payload([99999999]));
        self::assertTrue($this->client->getResponse()->isRedirect($url), 'an unknown group is a refusal, not an error page');
        self::assertEqualsCanonicalizing([$group->getId(), $other->getId()], $this->ruleGroupIds($rule));
        $this->client->followRedirect();
        self::assertStringContainsString('must name at least one group', (string) $this->client->getResponse()->getContent());

        // Back to everyone: the groups go.
        $public = $payload([]);
        $public['thelia_catalog_price_rule']['audience_mode'] = (string) CatalogPriceRule::AUDIENCE_MODE_PUBLIC;
        $this->client->request('POST', '/admin/catalog-price-rule/save/'.$rule->getId(), $public);
        self::assertSame([], $this->ruleGroupIds($rule));
    }

    public function testASaleReservedForGroupsIsSavedWithItsGroupsAndRefusedWithoutAny(): void
    {
        $group = $this->group('sale-b1', 'Sale group');
        $sale = $this->factory->sale();
        $sale->setLocale('en_US')->setTitle('Group sale')->setSaleLabel('Group sale')->save($this->getPropelConnection());
        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? $this->factory->currency();
        $url = '/admin/sale/update/'.$sale->getId();

        $crawler = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $save = $crawler->filter('form[data-testid="sale-edit-form"]')->attr('action');
        self::assertNotNull($save);

        $payload = fn (array $groupIds, bool $hide): array => [
            'thelia_sale' => array_filter([
                'id' => (string) $sale->getId(),
                'locale' => 'en_US',
                'title' => 'Group sale',
                'label' => 'Group sale',
                'chapo' => '',
                'description' => '',
                'postscriptum' => '',
                'active' => '1',
                'price_offset_type' => (string) Sale::OFFSET_TYPE_PERCENTAGE,
                'audience_mode' => (string) Sale::AUDIENCE_MODE_CUSTOMER_GROUPS,
                'hide_products' => $hide ? '1' : null,
                'countdown_mode' => (string) Sale::COUNTDOWN_MODE_NONE,
                '_token' => $this->formToken($url, 'thelia_sale'),
            ], static fn (?string $value): bool => null !== $value),
            'price_offset' => [(string) $currency->getId() => '10'],
            'sale_customer_groups' => array_map('strval', $groupIds),
        ];

        $this->client->request('POST', $save, $payload([$group->getId()], true));
        self::assertTrue($this->client->getResponse()->isRedirect(), 'the save answers with a redirect');
        $saved = SaleQuery::create()->findPk($sale->getId());
        self::assertSame(Sale::AUDIENCE_MODE_CUSTOMER_GROUPS, (int) $saved?->getAudienceMode());
        self::assertTrue((bool) $saved?->getHideProducts(), 'hiding the products applies to a sale reserved for groups too');
        self::assertSame([$group->getId()], $this->saleGroupIds($sale));

        $crawler = $this->client->request('GET', $url);
        self::assertSame(1, $crawler->filter('[data-testid="sale-customer-groups-select"] option[selected]')->count());

        $this->client->request('POST', $save, $payload([], false));
        self::assertSame([$group->getId()], $this->saleGroupIds($sale), 'a refused save keeps the stored groups');
        $this->client->followRedirect();
        self::assertStringContainsString('must name at least one group', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', $save, $payload([99999999], false));
        self::assertTrue($this->client->getResponse()->isRedirect(), 'an unknown group is a refusal, not an error page');
        self::assertSame([$group->getId()], $this->saleGroupIds($sale));
        $this->client->followRedirect();
        self::assertStringContainsString('must name at least one group', (string) $this->client->getResponse()->getContent());
    }

    private function titledCategory(string $title): Category
    {
        $category = $this->factory->category();
        $category->setLocale('en_US')->setTitle($title)->save($this->getPropelConnection());

        return $category;
    }

    private function titledPaymentModule(string $code, string $title): Module
    {
        $module = $this->paymentModule($code);
        $module->setLocale('en_US')->setTitle($title)->save($this->getPropelConnection());

        return $module;
    }

    /**
     * Restricts the group to a category and a payment module: it is flagged restricted, as the
     * writer of the lists does, and the lists are what it is allowed.
     */
    private function open(CustomerGroup $group, Category $category, Module $module): void
    {
        (new CustomerGroupCategory())->setCustomerGroupId($group->getId())->setCategoryId($category->getId())->save($this->getPropelConnection());
        (new CustomerGroupPaymentModule())->setCustomerGroupId($group->getId())->setModuleId($module->getId())->save($this->getPropelConnection());
        $group->setRestrictsCategories(1)->setRestrictsPaymentModules(1)->save($this->getPropelConnection());
    }

    /**
     * @return array{int, int} restricts_categories, restricts_payment_modules, read from the database
     */
    private function flagsOf(CustomerGroup $group): array
    {
        $row = CustomerGroupQuery::create()->filterById($group->getId())->select(['RestrictsCategories', 'RestrictsPaymentModules'])->findOne();

        return [(int) $row['RestrictsCategories'], (int) $row['RestrictsPaymentModules']];
    }

    /**
     * @param list<int> $moduleIds
     * @param list<int> $categoryIds
     */
    private function saveLinks(CustomerGroup $group, array $moduleIds, array $categoryIds): void
    {
        $editUrl = self::LIST_URL.'/update/'.$group->getId();
        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), ['thelia_customer_group_modification' => [
            'id' => (string) $group->getId(),
            'code' => (string) $group->getCode(),
            'title' => 'Links',
            'discount' => '0',
            'display_taxed_price' => '1',
            'locale' => 'en_US',
            'payment_module_ids' => array_map('strval', $moduleIds),
            'category_ids' => array_map('strval', $categoryIds),
            '_token' => $this->formToken($editUrl, 'thelia_customer_group_modification'),
        ]]);
    }

    private function group(string $code, string $title, bool $openRegistration = false): CustomerGroup
    {
        return $this->factory->customerGroup($code, title: $title, openRegistration: $openRegistration);
    }

    private function paymentModule(string $code): Module
    {
        $module = ModuleQuery::create()->findOneByCode($code);
        if (null === $module) {
            $module = (new Module())
                ->setCode($code)
                ->setFullNamespace($code.'\\'.$code)
                ->setVersion('1.0.0')
                ->setType(BaseModule::PAYMENT_MODULE_TYPE)
                ->setCategory('payment')
                ->setActivate(BaseModule::IS_ACTIVATED);
            $module->save($this->getPropelConnection());
        }

        return $module;
    }

    /**
     * @return list<int>
     */
    private function linkedModuleIds(CustomerGroup $group): array
    {
        return array_map('intval', CustomerGroupPaymentModuleQuery::create()->filterByCustomerGroupId($group->getId())->select('ModuleId')->find()->getData());
    }

    /**
     * @return list<int>
     */
    private function linkedCategoryIds(CustomerGroup $group): array
    {
        return array_map('intval', CustomerGroupCategoryQuery::create()->filterByCustomerGroupId($group->getId())->select('CategoryId')->find()->getData());
    }

    /**
     * @return list<int>
     */
    private function ruleGroupIds(CatalogPriceRule $rule): array
    {
        return array_map('intval', CatalogPriceRuleCustomerGroupQuery::create()->filterByCatalogPriceRuleId($rule->getId())->select('CustomerGroupId')->find()->getData());
    }

    /**
     * @return list<int>
     */
    private function saleGroupIds(Sale $sale): array
    {
        return array_map('intval', SaleCustomerGroupQuery::create()->filterBySaleId($sale->getId())->select('CustomerGroupId')->find()->getData());
    }

    private function formToken(string $page, string $formName): string
    {
        $crawler = $this->client->request('GET', $page);
        $token = $crawler->filter(\sprintf('input[name="%s[_token]"]', $formName));
        self::assertGreaterThan(0, $token->count(), \sprintf('The page renders the %s form.', $formName));

        return (string) $token->attr('value');
    }

    public function testTheRegistrationLinkOfAGroupIsOpenedRegeneratedAndClosed(): void
    {
        $group = $this->group('link-m3', 'Link');
        $editUrl = self::LIST_URL.'/update/'.$group->getId();

        $crawler = $this->client->request('GET', $editUrl);
        self::assertCount(1, $crawler->filter('[data-testid="customer-group-registration-open"]'), 'A closed group offers to open its link.');
        self::assertCount(0, $crawler->filter('[data-testid="customer-group-registration-url"]'));

        $this->registrationAction('open', $group, $editUrl);
        $first = $this->storedToken($group);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $first);

        $crawler = $this->client->request('GET', $editUrl);
        $shown = (string) $crawler->filter('[data-testid="customer-group-registration-url"]')->attr('value');
        self::assertMatchesRegularExpression('#^https?://[^/]+/register/'.$first.'$#', $shown, 'The absolute URL of the sign-up form is shown, read only.');
        self::assertNotNull($crawler->filter('[data-testid="customer-group-registration-url"]')->attr('readonly'));
        self::assertCount(1, $crawler->filter('[data-testid="customer-group-registration-copy"]'));

        $this->registrationAction('open', $group, $editUrl);
        self::assertSame($first, $this->storedToken($group), 'Opening an open link changes nothing.');

        $this->registrationAction('regenerate', $group, $editUrl);
        $second = $this->storedToken($group);
        self::assertNotNull($second);
        self::assertNotSame($first, $second, 'The links already handed out stop working.');

        $this->registrationAction('close', $group, $editUrl);
        self::assertNull($this->storedToken($group));
    }

    public function testTheDefaultGroupHasNoRegistrationLink(): void
    {
        $default = CustomerGroupQuery::create()->findOneByIsDefault(1);
        self::assertNotNull($default);
        $editUrl = self::LIST_URL.'/update/'.$default->getId();

        $crawler = $this->client->request('GET', $editUrl);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="customer-group-registration"]'));

        $this->registrationAction('open', $default, $editUrl);
        self::assertNull($this->storedToken($default), 'A forged request does not open it either.');
    }

    public function testTheRegistrationActionsCheckTheCsrfToken(): void
    {
        $group = $this->group('link-csrf-m3', 'Csrf', true);
        $before = $this->storedToken($group);
        self::assertNotNull($before);
        $closed = $this->group('link-csrf-closed-m3', 'Closed');
        $this->sessionToken();

        foreach (['regenerate', 'close'] as $action) {
            $this->client->request('POST', self::LIST_URL.'/registration/'.$action, ['customer_group_id' => $group->getId(), '_token' => 'forged']);
            self::assertSame($before, $this->storedToken($group), $action.' with a forged token changes nothing');
        }

        $this->client->request('POST', self::LIST_URL.'/registration/open', ['customer_group_id' => $closed->getId(), '_token' => 'forged']);
        self::assertNull($this->storedToken($closed));
    }

    public function testAnAdministratorRestrictedToASaleUnitCannotTouchTheRegistrationLink(): void
    {
        $group = $this->group('link-unit-m3', 'Unit', true);
        $before = $this->storedToken($group);
        $token = $this->sessionToken();
        $emptied = $this->group('link-unit-flag-m3', 'Flag');
        $emptied->setRestrictsCategories(1)->save($this->getPropelConnection());

        $this->injector->setAdmin(SaleUnitRestrictedAdmin::create($this->factory, $this->getService(EventDispatcherInterface::class), $this->getPropelConnection(), [
            AdminResources::CUSTOMER_GROUP => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        foreach (['open', 'regenerate', 'close'] as $action) {
            $this->client->request('POST', self::LIST_URL.'/registration/'.$action, ['customer_group_id' => $group->getId(), '_token' => $token]);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $action);
        }
        $this->client->request('POST', self::LIST_URL.'/lift-restriction', ['customer_group_id' => $emptied->getId(), 'list' => 'categories', '_token' => $token]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        self::assertSame($before, $this->storedToken($group));
        self::assertSame([1, 0], $this->flagsOf($emptied));

        $crawler = $this->client->request('GET', self::LIST_URL.'/update/'.$group->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="customer-group-registration-regenerate"], [data-testid="customer-group-registration-close"], [data-testid="customer-group-registration-open"]'), 'No write is offered.');
    }

    public function testSavingAnotherFieldKeepsARestrictedGroupWhoseListWasEmptiedByDeletionClosed(): void
    {
        $group = $this->group('kept-m3', 'Kept');
        $category = $this->titledCategory('Vanishing capes');
        $module = $this->titledPaymentModule('M3PayKept', 'Pay by pigeon');
        $this->open($group, $category, $module);
        $category->delete($this->getPropelConnection());
        ModuleQuery::create()->filterById($module->getId())->delete($this->getPropelConnection());
        self::assertSame([1, 1], $this->flagsOf($group));

        $editUrl = self::LIST_URL.'/update/'.$group->getId();
        $this->client->request('POST', self::LIST_URL.'/save/'.$group->getId(), ['thelia_customer_group_modification' => [
            'id' => (string) $group->getId(),
            'code' => (string) $group->getCode(),
            'title' => 'Kept',
            'discount' => '12',
            'display_taxed_price' => '1',
            'locale' => 'en_US',
            '_token' => $this->formToken($editUrl, 'thelia_customer_group_modification'),
        ]]);

        $stored = CustomerGroupQuery::create()->findPk($group->getId());
        self::assertEqualsWithDelta(12.0, (float) $stored?->getDiscount(), 0.000001, 'The discount was saved.');
        self::assertSame([1, 1], $this->flagsOf($group), 'The lists were posted as they are stored: the restrictions stay.');
    }

    public function testTheButtonLiftsTheRestrictionOfTheListThatWasEmptied(): void
    {
        $group = $this->group('lift-m3', 'Lift');
        $category = $this->titledCategory('Vanishing hoods');
        $module = $this->titledPaymentModule('M3PayLift', 'Pay by smoke');
        $this->open($group, $category, $module);
        $category->delete($this->getPropelConnection());
        ModuleQuery::create()->filterById($module->getId())->delete($this->getPropelConnection());
        $editUrl = self::LIST_URL.'/update/'.$group->getId();

        $crawler = $this->client->request('GET', $editUrl);
        self::assertCount(1, $crawler->filter('[data-testid="customer-group-categories-lift"]'));
        self::assertCount(1, $crawler->filter('[data-testid="customer-group-payment-modules-lift"]'));

        $this->client->request('POST', self::LIST_URL.'/lift-restriction', ['customer_group_id' => $group->getId(), 'list' => 'categories', '_token' => $this->sessionToken($editUrl)]);
        self::assertSame([0, 1], $this->flagsOf($group), 'Only the list named is lifted.');

        $this->client->request('POST', self::LIST_URL.'/lift-restriction', ['customer_group_id' => $group->getId(), 'list' => 'payment_modules', '_token' => $this->sessionToken($editUrl)]);
        self::assertSame([0, 0], $this->flagsOf($group));

        $crawler = $this->client->request('GET', $editUrl);
        self::assertCount(0, $crawler->filter('[data-testid="customer-group-categories-emptied"], [data-testid="customer-group-payment-modules-emptied"]'));

        $this->client->request('POST', self::LIST_URL.'/lift-restriction', ['customer_group_id' => $group->getId(), 'list' => 'categories', '_token' => 'forged']);
        self::assertSame([0, 0], $this->flagsOf($group));
    }

    public function testTheLiftWithAForgedTokenLiftsNothing(): void
    {
        $group = $this->group('lift-csrf-m3', 'Lift');
        $group->setRestrictsCategories(1)->save($this->getPropelConnection());
        $this->sessionToken();

        $this->client->request('POST', self::LIST_URL.'/lift-restriction', ['customer_group_id' => $group->getId(), 'list' => 'categories', '_token' => 'forged']);

        self::assertSame([1, 0], $this->flagsOf($group));
    }

    public function testUntickingEveryBoxOfAListThatHasEntriesLiftsItOnPurpose(): void
    {
        $group = $this->group('untick-m3', 'Untick');
        $category = $this->titledCategory('Reserved gloves');
        $module = $this->titledPaymentModule('M3PayUntick', 'Pay by glove');
        $this->open($group, $category, $module);

        $this->saveLinks($group, [$module->getId()], []);

        self::assertSame([], $this->linkedCategoryIds($group));
        self::assertSame([0, 1], $this->flagsOf($group), 'The list that had entries and none ticked is a decision to open it.');
    }

    private function registrationAction(string $action, CustomerGroup $group, string $editUrl): void
    {
        $this->client->request('POST', self::LIST_URL.'/registration/'.$action, ['customer_group_id' => $group->getId(), '_token' => $this->sessionToken($editUrl)]);
    }

    private function storedToken(CustomerGroup $group): ?string
    {
        return CustomerGroupQuery::create()->filterById($group->getId())->select(['RegistrationToken'])->findOne();
    }

    private function sessionToken(string $page = self::LIST_URL): string
    {
        $html = (string) $this->client->request('GET', $page)->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }
}
