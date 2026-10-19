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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Module;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The customer sheet lists, read only, the payment methods the customer asked the shop to
 * keep: never their token, and only for an administrator who may see them.
 */
final class CustomerPaymentMethodsSheetTest extends WebIntegrationTestCase
{
    private const LOCALE = 'en_US';
    private const TOKEN = 'tok_SECRET_do_not_render_9f3a';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        // TODO remove once thelia/core 3.3 is required.
        if (!class_exists(CustomerPaymentMethod::class)) {
            self::markTestSkipped('Needs the stored payment methods of Thelia 3.3.');
        }

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheMethodsAreListedWithTheirModuleTheirExpirationAndTheDefaultOne(): void
    {
        $customer = $this->customer();
        $module = $this->paymentModule('Card provider');
        $this->method($customer, $module, 'Visa ending 4242', '2031-05-31', false);
        $this->method($customer, $module, 'Mastercard ending 1111', null, true);

        $crawler = $this->sheet($customer, $this->factory->admin(['locale' => self::LOCALE]));

        $rows = $crawler->filter('[data-testid="customer-payment-method"]')
            ->each(static fn (Crawler $row): string => (string) preg_replace('/\s+/u', ' ', trim($row->text())));
        self::assertCount(2, $rows);
        self::assertSame('Mastercard ending 1111 Default Card provider -', $rows[0], 'The default method comes first.');
        self::assertSame('Visa ending 4242 Card provider May 31, 2031', $rows[1]);
        self::assertCount(1, $crawler->filter('[data-testid="customer-payment-method-default"]'));
        self::assertSame('2', trim($crawler->filter('[data-testid="customer-payment-methods-section-count"]')->text()));
    }

    public function testAMethodWhoseModuleHasNoTitleFallsBackToTheModuleCode(): void
    {
        $customer = $this->customer();
        $module = $this->paymentModule('');
        $this->method($customer, $module, 'Wallet', null, false);

        $crawler = $this->sheet($customer, $this->factory->admin(['locale' => self::LOCALE]));

        $row = $crawler->filter('[data-testid="customer-payment-method"]');
        self::assertCount(1, $row);
        self::assertStringContainsString($module->getCode(), $row->text());
    }

    public function testAMethodWhoseModuleHasNoTranslationInTheLocaleFallsBackToTheModuleCode(): void
    {
        $customer = $this->customer();
        // Titled in French only: the request is in English, the LEFT JOIN on the translation gives NULL.
        $module = $this->paymentModule('Fournisseur de cartes', 'fr_FR');
        $this->method($customer, $module, 'Wallet', null, false);

        $crawler = $this->sheet($customer, $this->factory->admin(['locale' => self::LOCALE]));

        $row = $crawler->filter('[data-testid="customer-payment-method"]');
        self::assertCount(1, $row);
        self::assertStringContainsString($module->getCode(), $row->text());
        self::assertStringNotContainsString('Fournisseur', $row->text());
    }

    public function testAnExpiredMethodIsFlagged(): void
    {
        $customer = $this->customer();
        $this->method($customer, $this->paymentModule('Card provider'), 'Old card', '2020-01-31', false);

        $crawler = $this->sheet($customer, $this->factory->admin(['locale' => self::LOCALE]));

        self::assertStringContainsString('Expired', $crawler->filter('[data-testid="customer-payment-method"]')->text());
    }

    public function testACustomerWithoutMethodIsToldSo(): void
    {
        $crawler = $this->sheet($this->customer(), $this->factory->admin(['locale' => self::LOCALE]));

        self::assertCount(1, $crawler->filter('[data-testid="customer-payment-methods-section"]'));
        self::assertCount(0, $crawler->filter('[data-testid="customer-payment-method"]'));
        self::assertCount(1, $crawler->filter('[data-testid="customer-payment-methods-empty"]'));
    }

    public function testTheTokenIsNeverInThePage(): void
    {
        $customer = $this->customer();
        $this->method($customer, $this->paymentModule('Card provider'), 'Visa ending 4242', null, true);

        $this->sheet($customer, $this->factory->admin(['locale' => self::LOCALE]));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Visa ending 4242', $html, 'The method is on the page.');
        self::assertStringNotContainsString(self::TOKEN, $html);
    }

    public function testTheMethodsOfAnotherCustomerAreNotListed(): void
    {
        $other = $this->customer();
        $this->method($other, $this->paymentModule('Card provider'), 'Not yours', null, false);

        $crawler = $this->sheet($this->customer(), $this->factory->admin(['locale' => self::LOCALE]));

        self::assertCount(0, $crawler->filter('[data-testid="customer-payment-method"]'));
        self::assertStringNotContainsString('Not yours', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAdministratorWhoMayNotSeeTheMethodsDoesNotGetTheSection(): void
    {
        $customer = $this->customer();
        $this->method($customer, $this->paymentModule('Card provider'), 'Visa ending 4242', null, false);
        $admin = $this->factory->restrictedAdmin(
            [AdminResources::CUSTOMER => [AccessManager::VIEW]],
            ['locale' => self::LOCALE],
        );

        $crawler = $this->sheet($customer, $admin);

        self::assertCount(0, $crawler->filter('[data-testid="customer-payment-methods-section"]'));
        self::assertStringNotContainsString('Visa ending 4242', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAdministratorWhoMaySeeTheMethodsGetsTheSectionWithoutOtherRights(): void
    {
        $customer = $this->customer();
        $this->method($customer, $this->paymentModule('Card provider'), 'Visa ending 4242', null, false);
        $admin = $this->factory->restrictedAdmin(
            [
                AdminResources::CUSTOMER => [AccessManager::VIEW],
                AdminResources::CUSTOMER_PAYMENT_METHOD => [AccessManager::VIEW],
            ],
            ['locale' => self::LOCALE],
        );

        $crawler = $this->sheet($customer, $admin);

        self::assertCount(1, $crawler->filter('[data-testid="customer-payment-method"]'));
    }

    private function sheet(Customer $customer, Admin $admin): Crawler
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    private function customer(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle());
    }

    private function paymentModule(string $title, string $locale = self::LOCALE): Module
    {
        $module = (new Module())
            ->setCode('StoredTest'.bin2hex(random_bytes(4)))
            ->setType(BaseModule::PAYMENT_MODULE_TYPE)
            ->setActivate(1)
            ->setPosition(99)
            ->setFullNamespace('Test\\StoredTest');
        $module->setLocale($locale);
        $module->setTitle($title);
        $module->save($this->getPropelConnection());

        return $module;
    }

    private function method(Customer $customer, Module $module, string $label, ?string $expiresAt, bool $default): CustomerPaymentMethod
    {
        $method = (new CustomerPaymentMethod())
            ->setCustomerId((int) $customer->getId())
            ->setPaymentModuleId((int) $module->getId())
            ->setToken(self::TOKEN)
            ->setLabel($label)
            ->setExpiresAt($expiresAt)
            ->setIsDefault($default);
        $method->save($this->getPropelConnection());

        return $method;
    }
}
