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

use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Catalog\Stock\StockSettings;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The stock card of the store configuration: reservation switch, scope,
 * duration and the shop-wide low stock threshold.
 */
final class StockConfigurationTest extends WebIntegrationTestCase
{
    private const PREFIX = 'thelia_configuration_store';

    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        if (!class_exists(StockSettings::class)) {
            self::markTestSkipped('The core of this checkout predates the stock settings.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        // The cache of the config table is static and outlives the transaction rollback.
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheStockSettingsAreSavedFromTheStoreConfiguration(): void
    {
        $this->submit(['enabled' => true, 'scope' => 'cart', 'duration' => '30', 'threshold' => '20']);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A 400 means the form was rejected.');
        self::assertSame('1', $this->storedValue(StockSettings::RESERVATION_ENABLED));
        self::assertSame('cart', $this->storedValue(StockSettings::RESERVATION_SCOPE));
        self::assertSame('30', $this->storedValue(StockSettings::RESERVATION_DURATION));
        self::assertSame('20', $this->storedValue(StockSettings::LOW_STOCK_THRESHOLD));

        ConfigQuery::resetCache();
        $settings = new StockSettings();
        self::assertTrue($settings->reservationEnabled());
        self::assertSame(30, $settings->reservationDurationMinutes());
        self::assertSame(20.0, $settings->lowStockThreshold());
    }

    public function testAnUncheckedSwitchIsStoredAsZeroAndReadBackAsOff(): void
    {
        $this->submit(['enabled' => true, 'scope' => 'checkout', 'duration' => '15', 'threshold' => '5']);
        $this->submit(['enabled' => false, 'scope' => 'checkout', 'duration' => '15', 'threshold' => '5']);

        self::assertSame('0', $this->storedValue(StockSettings::RESERVATION_ENABLED), 'An unchecked box writes 0, not an empty string.');

        $crawler = $this->client->request('GET', '/admin/configuration/store');
        $checkbox = $crawler->filter('[data-testid="config-store-stock-reservation-enabled"]');
        self::assertCount(1, $checkbox);
        self::assertNull($checkbox->attr('checked'), 'The switch is shown off after a save at 0.');
    }

    public function testAThresholdOfZeroIsKeptAndShownBack(): void
    {
        $this->submit(['enabled' => false, 'scope' => 'checkout', 'duration' => '15', 'threshold' => '0']);

        self::assertSame('0', $this->storedValue(StockSettings::LOW_STOCK_THRESHOLD));
        ConfigQuery::resetCache();
        self::assertSame(0.0, (new StockSettings())->lowStockThreshold(), 'Zero is a value, not the default of 5.');

        $crawler = $this->client->request('GET', '/admin/configuration/store');
        self::assertSame('0', $crawler->filter('[data-testid="config-store-low-stock-threshold"]')->attr('value'));
    }

    public function testADurationBelowOneMinuteIsRefusedAndNothingIsWritten(): void
    {
        $this->submit(['enabled' => true, 'scope' => 'cart', 'duration' => '30', 'threshold' => '20']);
        $this->submit(['enabled' => false, 'scope' => 'checkout', 'duration' => '0', 'threshold' => '9'], false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('30', $this->storedValue(StockSettings::RESERVATION_DURATION));
        self::assertSame('20', $this->storedValue(StockSettings::LOW_STOCK_THRESHOLD));
        self::assertSame('1', $this->storedValue(StockSettings::RESERVATION_ENABLED));
    }

    public function testANegativeThresholdIsRefused(): void
    {
        $this->submit(['enabled' => true, 'scope' => 'cart', 'duration' => '30', 'threshold' => '20']);
        $this->submit(['enabled' => true, 'scope' => 'cart', 'duration' => '30', 'threshold' => '-1'], false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('20', $this->storedValue(StockSettings::LOW_STOCK_THRESHOLD));
    }

    public function testAnUnknownScopeIsRefused(): void
    {
        $this->submit(['enabled' => true, 'scope' => 'cart', 'duration' => '30', 'threshold' => '20']);
        $this->submit(['enabled' => true, 'scope' => 'forever', 'duration' => '30', 'threshold' => '20'], false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('cart', $this->storedValue(StockSettings::RESERVATION_SCOPE));
    }

    public function testThePageOffersTheTwoScopesAndSelectsTheStoredOne(): void
    {
        $this->submit(['enabled' => true, 'scope' => 'cart', 'duration' => '30', 'threshold' => '20']);

        $crawler = $this->client->request('GET', '/admin/configuration/store');

        $options = $crawler->filter('[data-testid="config-store-stock-reservation-scope"] option');
        self::assertCount(2, $options);
        self::assertSame('cart', $crawler->filter('[data-testid="config-store-stock-reservation-scope"] option[selected]')->attr('value'));
        self::assertSame('30', $crawler->filter('[data-testid="config-store-stock-reservation-duration"]')->attr('value'));
        self::assertSame('20', $crawler->filter('[data-testid="config-store-low-stock-threshold"]')->attr('value'));
    }

    /**
     * @param array{enabled: bool, scope: string, duration: string, threshold: string} $stock
     */
    private function submit(array $stock, bool $expectRedirect = true): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/store');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $form = $crawler->filter('[data-testid="config-store-save-stay"]')->form([
            self::PREFIX.'[store_name]' => 'Test Store',
            self::PREFIX.'[store_email]' => 'store@test.com',
            self::PREFIX.'[store_notification_emails]' => 'store@test.com',
            self::PREFIX.'[store_address1]' => '1 Main Street',
            self::PREFIX.'[store_zipcode]' => '75001',
            self::PREFIX.'[store_city]' => 'Paris',
            self::PREFIX.'[stock_reservation_duration]' => $stock['duration'],
            self::PREFIX.'[low_stock_threshold]' => $stock['threshold'],
        ]);

        // A value outside the offered choices is rejected by the browser-kit form itself.
        $form->disableValidation();
        $form->setValues([self::PREFIX.'[stock_reservation_scope]' => $stock['scope']]);

        $enabled = $form[self::PREFIX.'[stock_reservation_enabled]'];
        \assert($enabled instanceof ChoiceFormField);
        $stock['enabled'] ? $enabled->tick() : $enabled->untick();

        $this->client->submit($form);

        if ($expectRedirect) {
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A 400 means the form was rejected.');
        }
    }

    private function storedValue(string $name): ?string
    {
        ConfigQuery::resetCache();

        return ConfigQuery::create()->findOneByName($name)?->getValue();
    }
}
