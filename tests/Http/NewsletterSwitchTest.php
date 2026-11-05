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
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * The newsletter page carries the switch of the shop's sign-up: an administrator allowed
 * to update it sets the state with a token, the others only read it.
 */
final class NewsletterSwitchTest extends WebIntegrationTestCase
{
    private const LIST_URL = '/admin/newsletter';
    private const SWITCH_URL = '/admin/newsletter/switch';

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        ConfigQuery::write('newsletter_enabled', '1', false);
    }

    protected function tearDown(): void
    {
        ConfigQuery::write('newsletter_enabled', '1', false);
        $this->injector?->clear();

        parent::tearDown();
    }

    public function testAnAdminAllowedToUpdateSeesTheSwitch(): void
    {
        $this->loginAs($this->factory->admin());
        $this->client->request('GET', self::LIST_URL);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->html();
        self::assertStringContainsString('data-testid="newsletter-switch-form"', $html);
        self::assertStringContainsString('data-testid="newsletter-state"', $html);
        self::assertStringNotContainsString('data-testid="newsletter-disabled-notice"', $html);
    }

    public function testTurningTheSignUpOffWritesTheVariableAndShowsTheNotice(): void
    {
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::SWITCH_URL, ['_token' => $this->token(), 'newsletter_enabled' => '0']);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('0', ConfigQuery::read('newsletter_enabled'));
        self::assertFalse(ConfigQuery::isNewsletterEnabled());

        $this->client->request('GET', self::LIST_URL);
        self::assertStringContainsString('data-testid="newsletter-disabled-notice"', $this->html());
    }

    public function testTurningTheSignUpBackOnWritesTheVariableAndHidesTheNotice(): void
    {
        ConfigQuery::write('newsletter_enabled', '0', false);
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::SWITCH_URL, ['_token' => $this->token(), 'newsletter_enabled' => '1']);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('1', ConfigQuery::read('newsletter_enabled'));

        $this->client->request('GET', self::LIST_URL);
        self::assertStringNotContainsString('data-testid="newsletter-disabled-notice"', $this->html());
    }

    public function testAPostRepeatedTwiceKeepsTheStateAsked(): void
    {
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::SWITCH_URL, ['_token' => $this->token(), 'newsletter_enabled' => '0']);
        $this->client->request('POST', self::SWITCH_URL, ['_token' => $this->token(), 'newsletter_enabled' => '0']);

        self::assertSame('0', ConfigQuery::read('newsletter_enabled'));
    }

    public function testAPostWithoutTokenLeavesTheVariableUnchanged(): void
    {
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::SWITCH_URL, ['newsletter_enabled' => '0']);

        self::assertSame('1', ConfigQuery::read('newsletter_enabled'));
    }

    public function testAPostWithAWrongTokenLeavesTheVariableUnchanged(): void
    {
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::SWITCH_URL, ['_token' => 'not-a-token', 'newsletter_enabled' => '0']);

        self::assertSame('1', ConfigQuery::read('newsletter_enabled'));
    }

    public function testAnUnknownStateLeavesTheVariableUnchanged(): void
    {
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::SWITCH_URL, ['_token' => $this->token(), 'newsletter_enabled' => 'maybe']);

        self::assertSame('1', ConfigQuery::read('newsletter_enabled'));
    }

    public function testAnAdminWhoOnlyViewsSeesTheStateWithoutTheSwitch(): void
    {
        $this->loginAs($this->viewer());
        ConfigQuery::write('newsletter_enabled', '0', false);

        $this->client->request('GET', self::LIST_URL);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->html();
        self::assertStringNotContainsString('data-testid="newsletter-switch-form"', $html);
        self::assertStringContainsString('data-testid="newsletter-state"', $html);
        self::assertStringContainsString('data-testid="newsletter-disabled-notice"', $html);
    }

    public function testAPostFromAnAdminWhoOnlyViewsIsRefusedAndLeavesTheVariableUnchanged(): void
    {
        $this->loginAs($this->viewer());

        $this->client->request('POST', self::SWITCH_URL, ['_token' => $this->token(), 'newsletter_enabled' => '0']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('1', ConfigQuery::read('newsletter_enabled'));
    }

    private function viewer(): Admin
    {
        return $this->factory->restrictedAdmin(['admin.newsletter' => [AccessManager::VIEW]]);
    }

    private function token(): string
    {
        return $this->getService(TokenProvider::class)->assignToken();
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }
}
