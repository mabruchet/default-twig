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
use Thelia\Domain\Pricing\PriceVisibility\PriceDisplayMode;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * How the prices show: the setting of the shop on the store configuration screen, and the
 * override of one product on the combinations tab of its sheet.
 */
final class PriceDisplayModeBackOfficeTest extends WebIntegrationTestCase
{
    private const SAVE_URL = '/admin/product/price-display-mode/save';

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

        // The cache of the config table is static and outlives the transaction rollback.
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheShopSettingIsSavedFromTheStoreConfiguration(): void
    {
        $this->submitStore(PriceDisplayMode::LoggedIn->value);

        self::assertSame(PriceDisplayMode::LoggedIn->value, $this->storedValue());

        $this->submitStore(PriceDisplayMode::OnRequest->value);
        self::assertSame(PriceDisplayMode::OnRequest->value, $this->storedValue());
    }

    public function testTheStorePageOffersTheThreeModesAndTheCurrentOneIsSelected(): void
    {
        $this->submitStore(PriceDisplayMode::LoggedIn->value);

        $crawler = $this->client->request('GET', '/admin/configuration/store');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $options = $crawler->filter('[data-testid="config-store-price-display-mode"] option');
        self::assertCount(3, $options);
        self::assertSame(PriceDisplayMode::LoggedIn->value, $crawler->filter('[data-testid="config-store-price-display-mode"] option[selected]')->attr('value'));
    }

    public function testAnUnknownShopModeIsRefusedAndNothingIsWritten(): void
    {
        $this->submitStore(PriceDisplayMode::LoggedIn->value);
        $this->submitStore('everybody', false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(PriceDisplayMode::LoggedIn->value, $this->storedValue());
    }

    public function testTheShopSettingDefaultsToVisible(): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/store');

        self::assertSame(PriceDisplayMode::Visible->value, $crawler->filter('[data-testid="config-store-price-display-mode"] option[selected]')->attr('value'));
    }

    public function testTheOverrideOfAProductIsSavedAndNothingElseOfTheProductChanges(): void
    {
        $product = $this->product();
        $before = $this->columnsOf($product);

        $this->save($product, PriceDisplayMode::OnRequest->value);

        $after = $this->columnsOf($product);
        self::assertSame('on_request', $after['price_display_mode']);
        // A save stamps the update date and bumps the version of the versionable product: nothing else moves.
        $stamps = ['price_display_mode', 'updated_at', 'version', 'version_created_at', 'version_created_by'];
        self::assertSame(array_diff_key($before, array_flip($stamps)), array_diff_key($after, array_flip($stamps)), 'Every other column of the product is left as it was.');
        self::assertSame('Original title', ProductQuery::create()->findPk($product->getId(), $this->getPropelConnection())->setLocale('en_US')->getTitle(), 'The translations are untouched.');
    }

    public function testLikeTheShopPutsTheOverrideBackToNull(): void
    {
        $product = $this->product();
        $this->save($product, PriceDisplayMode::LoggedIn->value);
        self::assertSame('logged_in', $this->columnsOf($product)['price_display_mode']);

        $this->save($product, '');

        self::assertNull($this->columnsOf($product)['price_display_mode'], 'Like the shop is NULL, never an empty string.');
    }

    public function testAnUnknownModeOrAForgedTokenWritesNothing(): void
    {
        $product = $this->product();
        $this->save($product, PriceDisplayMode::LoggedIn->value);

        $this->save($product, 'everybody');
        self::assertSame('logged_in', $this->columnsOf($product)['price_display_mode'], 'A mode nobody knows is no way to put the product back like the shop.');

        $this->save($product, PriceDisplayMode::OnRequest->value, token: 'forged');
        self::assertSame('logged_in', $this->columnsOf($product)['price_display_mode']);
    }

    public function testTheResultOfASaveIsToldToTheAdministratorOnTheNextPage(): void
    {
        $product = $this->product();

        $this->save($product, PriceDisplayMode::OnRequest->value);
        $this->client->followRedirect();
        self::assertStringContainsString('The display of the prices has been saved.', (string) $this->client->getResponse()->getContent());

        $this->save($product, 'everybody');
        $this->client->followRedirect();
        self::assertStringContainsString('This display of the prices is not known.', (string) $this->client->getResponse()->getContent());
    }

    public function testThePanelOfTheCombinationsTabShowsTheShopDefaultAndTheOverride(): void
    {
        $product = $this->product();
        ConfigQuery::write(PriceDisplayMode::CONFIG_KEY, 'logged_in', false);
        $this->save($product, PriceDisplayMode::OnRequest->value);

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertCount(1, $crawler->filter('[data-testid="price-display-mode-section"]'));
        self::assertStringContainsString('Identified customers only', $crawler->filter('[data-testid="price-display-mode-shop"]')->text());
        self::assertSame('on_request', $crawler->filter('[data-testid="price-display-mode-select"] option[selected]')->attr('value'));
        self::assertCount(4, $crawler->filter('[data-testid="price-display-mode-select"] option'));
    }

    public function testTheRightToUpdateTheProductIsNeededToSave(): void
    {
        $product = $this->product();
        $token = $this->sessionToken();

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]]));
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="price-display-mode-section"]'));
        self::assertCount(0, $crawler->filter('[data-testid="price-display-mode-submit"]'), 'The button is not offered without the right to update.');

        $this->save($product, PriceDisplayMode::OnRequest->value, token: $token, expectedStatus: 403);
        self::assertNull($this->columnsOf($product)['price_display_mode']);

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE]]));
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="price-display-mode-submit"]'));
        $this->save($product, PriceDisplayMode::OnRequest->value, token: $token);
        self::assertSame('on_request', $this->columnsOf($product)['price_display_mode']);
    }

    public function testAnAdministratorRestrictedToASaleUnitCannotChangeTheDisplay(): void
    {
        $product = $this->product();
        $token = $this->sessionToken();

        $this->injector->setAdmin(SaleUnitRestrictedAdmin::create($this->factory, $this->getService(EventDispatcherInterface::class), $this->getPropelConnection(), [
            AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        $this->save($product, PriceDisplayMode::OnRequest->value, token: $token, expectedStatus: 403);
        self::assertNull($this->columnsOf($product)['price_display_mode'], 'What holds for every sale unit is not written by a restricted administrator.');
    }

    private function product(): Product
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        self::assertNotNull($currency);

        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $currency, ['title' => 'Original title', 'basePrice' => 25.0, 'baseQuantity' => 7]);
        $product->setVisible(0)->save($this->getPropelConnection());

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnsOf(Product $product): array
    {
        $statement = $this->getPropelConnection()->prepare('SELECT * FROM product WHERE id = ?');
        $statement->execute([$product->getId()]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    private function save(Product $product, string $mode, ?string $token = null, int $expectedStatus = 302): void
    {
        $this->client->request('POST', self::SAVE_URL, [
            '_token' => $token ?? $this->sessionToken(),
            'product_id' => $product->getId(),
            'price_display_mode' => $mode,
        ]);

        self::assertSame($expectedStatus, $this->client->getResponse()->getStatusCode());
    }

    private function submitStore(string $mode, bool $expectRedirect = true): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/store');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $form = $crawler->filter('[data-testid="config-store-save-stay"]')->form([
            'thelia_configuration_store[store_name]' => 'Test Store',
            'thelia_configuration_store[store_email]' => 'store@test.com',
            'thelia_configuration_store[store_notification_emails]' => 'store@test.com',
            'thelia_configuration_store[store_address1]' => '1 Main Street',
            'thelia_configuration_store[store_zipcode]' => '75001',
            'thelia_configuration_store[store_city]' => 'Paris',
        ]);

        // A value outside the offered choices is rejected by the browser-kit form itself.
        $form->disableValidation();
        $form->setValues(['thelia_configuration_store[price_display_mode]' => $mode]);

        $this->client->submit($form);

        if ($expectRedirect) {
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A 400 means the form was rejected.');
        }
    }

    private function storedValue(): ?string
    {
        ConfigQuery::resetCache();

        return ConfigQuery::create()->findOneByName(PriceDisplayMode::CONFIG_KEY)?->getValue();
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
