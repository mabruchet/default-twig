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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\PriceListItemTableMap;
use Thelia\Model\PriceList;
use Thelia\Model\PriceListItem;
use Thelia\Model\PriceListItemQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The CSV of a price list in the back office: the deposit, the preview that writes nothing,
 * the confirmation, the report of a file with errors, the file that does not outlive the
 * import, and the export.
 */
final class PriceListCsvBackOfficeTest extends WebIntegrationTestCase
{
    private const LIST_URL = '/admin/pricing/price-lists';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private string $scratch;

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

        $this->scratch = sys_get_temp_dir().'/price-list-csv-'.bin2hex(random_bytes(6));
        mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        foreach (glob($this->scratch.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (isset($this->scratch) && is_dir($this->scratch)) {
            rmdir($this->scratch);
        }

        foreach (glob($this->storageDirectory().'/*.csv') ?: [] as $left) {
            unlink($left);
        }

        parent::tearDown();
    }

    public function testThePreviewCountsWhatTheImportWouldDoAndWritesNothing(): void
    {
        $list = $this->priceList('preview-l6n');
        $this->productWithAPse('L6N-1');
        [, $existing] = $this->productWithAPse('L6N-2');
        $this->line($list, $existing, 1, '50');
        $token = $this->sessionToken($list);

        $crawler = $this->upload($list, $token, "pse_ref;min_quantity;price\nL6N-1;1;10\nL6N-2;1;45\n");

        self::assertTrue($this->client->getResponse()->isRedirect(), 'The deposit sends to the preview.');
        $crawler = $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('1', trim($crawler->filter('[data-testid="price-list-import-created"]')->text()));
        self::assertSame('1', trim($crawler->filter('[data-testid="price-list-import-updated"]')->text()));
        self::assertSame('0', trim($crawler->filter('[data-testid="price-list-import-errors"]')->text()));

        PriceListItemTableMap::clearInstancePool();
        self::assertSame(1, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'The preview created nothing.');
        self::assertSame(50.0, (float) PriceListItemQuery::create()->findPk($this->itemId($list, $existing))?->getPrice(), 'The preview updated nothing.');
    }

    public function testTheConfirmationWritesTheLinesAndDeletesTheFile(): void
    {
        $list = $this->priceList('confirm-l6o');
        $this->productWithAPse('L6O-1');
        $this->productWithAPse('L6O-2');
        $token = $this->sessionToken($list);

        $this->upload($list, $token, "pse_ref,min_quantity,discount_percent\nL6O-1,1,10\nL6O-2,5,20\n");
        $previewUrl = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->followRedirect();
        self::assertCount(1, glob($this->storageDirectory().'/'.$list->getId().'-*.csv') ?: [], 'The file waits for the confirmation.');

        $confirmUrl = $previewUrl.'/confirm';
        $crawler = $this->client->request('POST', $confirmUrl, ['_token' => $token]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('2', trim($crawler->filter('[data-testid="price-list-import-created"]')->text()));
        PriceListItemTableMap::clearInstancePool();
        self::assertSame(2, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'The base was read again: the lines are there.');
        self::assertSame([], glob($this->storageDirectory().'/'.$list->getId().'-*.csv') ?: [], 'The file is deleted once the import is applied.');

        $this->client->request('GET', $previewUrl);
        self::assertTrue($this->client->getResponse()->isRedirect(), 'The preview of a deleted file is gone.');
    }

    public function testTheConfirmationNeedsTheToken(): void
    {
        $list = $this->priceList('notoken-l6p');
        $this->productWithAPse('L6P-1');
        $token = $this->sessionToken($list);

        $this->upload($list, $token, "pse_ref,price\nL6P-1,10\n");
        $previewUrl = (string) $this->client->getResponse()->headers->get('Location');

        $this->client->request('POST', $previewUrl.'/confirm', ['_token' => 'wrong']);
        $this->client->request('POST', $previewUrl.'/confirm?_token='.$token);

        self::assertSame(0, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'Nothing is written without the token.');
        self::assertCount(1, glob($this->storageDirectory().'/'.$list->getId().'-*.csv') ?: [], 'The file is still waiting.');
    }

    public function testTheDepositNeedsTheToken(): void
    {
        $list = $this->priceList('deposit-l6v');
        $this->sessionToken($list);

        $this->upload($list, 'not-the-token', "pse_ref,price\nX,1\n");
        $this->upload($list, '', "pse_ref,price\nX,1\n");

        self::assertSame([], glob($this->storageDirectory().'/*') ?: [], 'A deposit without the token stores nothing.');
    }

    public function testAFileWithErrorsGivesAReportWithTheReasonOfEachLine(): void
    {
        $list = $this->priceList('errors-l6q');
        $this->productWithAPse('L6Q-1');
        $token = $this->sessionToken($list);

        $this->upload($list, $token, "pse_ref;min_quantity;price;discount_percent\nL6Q-1;1;10;\nNOPE-L6Q;1;10;\nL6Q-1;2;5;10\nL6Q-1;0;7;\n");
        $crawler = $this->client->followRedirect();

        self::assertSame('1', trim($crawler->filter('[data-testid="price-list-import-created"]')->text()));
        self::assertSame('3', trim($crawler->filter('[data-testid="price-list-import-errors"]')->text()));
        self::assertStringContainsString('NOPE-L6Q', $crawler->filter('[data-testid="price-list-import-error-3"]')->text(), 'The unknown reference is named on its line.');
        self::assertStringContainsString('price and a discount', $crawler->filter('[data-testid="price-list-import-error-4"]')->text());
        self::assertStringContainsString('minimum quantity', $crawler->filter('[data-testid="price-list-import-error-5"]')->text());

        $this->client->request('POST', (string) $this->previewUrl().'/confirm', ['_token' => $token]);
        self::assertSame(1, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'Only the valid line is written.');
    }

    public function testAFileThatIsNotACsvIsRefusedAndNotKept(): void
    {
        $list = $this->priceList('badfile-l6r');
        $token = $this->sessionToken($list);

        $path = $this->scratch.'/lines.csv';
        file_put_contents($path, "name;value\nfoo;bar\n");
        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/import/upload', ['_token' => $token], ['file' => new UploadedFile($path, 'lines.csv', 'text/csv', null, true)]);
        $this->client->followRedirect();
        self::assertSame([], glob($this->storageDirectory().'/'.$list->getId().'-*.csv') ?: [], 'A file the importer refuses is deleted at once.');

        $image = $this->scratch.'/lines.png';
        file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/import/upload', ['_token' => $token], ['file' => new UploadedFile($image, 'lines.csv', 'text/csv', null, true)]);
        self::assertSame([], glob($this->storageDirectory().'/*.csv') ?: [], 'A file that is not text is not stored.');
    }

    public function testTheFileIsStoredOutOfTheWebRootUnderARandomNameAndAbandonDeletesIt(): void
    {
        $list = $this->priceList('abandon-l6s');
        $token = $this->sessionToken($list);

        $this->upload($list, $token, "pse_ref,price\nX,1\n");
        $stored = glob($this->storageDirectory().'/*') ?: [];

        self::assertCount(1, $stored, 'One file, under the random name: nothing is kept under the name the browser sent.');
        $projectDirectory = (string) static::getContainer()->getParameter('kernel.project_dir');
        self::assertStringStartsNotWith(realpath($projectDirectory.'/public').'/', (string) realpath($stored[0]), 'Outside the web root.');
        self::assertMatchesRegularExpression('/^'.$list->getId().'-[0-9a-f]{32}\.csv$/', basename($stored[0]), 'A random name, nothing of the name the browser sent.');

        $previewUrl = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->request('POST', $previewUrl.'/abandon', ['_token' => $token]);
        self::assertSame([], glob($this->storageDirectory().'/'.$list->getId().'-*.csv') ?: [], 'Abandoning deletes the file.');

        $this->upload($list, $token, "pse_ref,price\nX,1\n");
        $this->upload($list, $token, "pse_ref,price\nY,1\n");
        self::assertCount(1, glob($this->storageDirectory().'/'.$list->getId().'-*.csv') ?: [], 'A new deposit deletes the file the last one left.');
    }

    public function testTheExportDownloadsTheLinesInTheColumnsOfTheImport(): void
    {
        $list = $this->priceList('export-l6t');
        [, $first] = $this->productWithAPse('L6T-1');
        [, $second] = $this->productWithAPse('L6T-2');
        $this->line($list, $first, 1, '10.5');
        $this->line($list, $second, 10, null, '15');

        $this->client->request('GET', self::LIST_URL.'/'.$list->getId().'/export');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $lines = preg_split('/\r?\n/', trim((string) $this->client->getInternalResponse()->getContent()));
        self::assertSame('pse_ref;pse_id;min_quantity;price;discount_percent', $lines[0]);
        self::assertCount(3, $lines);
        $firstCells = str_getcsv($lines[1], ';', '"', '');
        $secondCells = str_getcsv($lines[2], ';', '"', '');
        self::assertSame(['L6T-1', (string) $first->getId(), '1'], \array_slice($firstCells, 0, 3));
        self::assertSame(10.5, (float) $firstCells[3]);
        self::assertSame('', $firstCells[4], 'A price line has no discount.');
        self::assertSame(['L6T-2', (string) $second->getId(), '10', ''], \array_slice($secondCells, 0, 4));
        self::assertSame(15.0, (float) $secondCells[4]);
    }

    public function testTheImportIsRefusedToAViewerAndTheExportIsNot(): void
    {
        $list = $this->priceList('viewer-l6u');
        $token = $this->sessionToken($list);

        $viewer = $this->factory->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW]]);
        $viewer->eraseCredentials();
        $this->injector->setAdmin($viewer);

        $this->client->request('GET', self::LIST_URL.'/'.$list->getId().'/export');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The right to view downloads the export.');

        $path = $this->scratch.'/lines.csv';
        file_put_contents($path, "pse_ref,price\nX,1\n");
        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/import/upload', ['_token' => $token], ['file' => new UploadedFile($path, 'lines.csv', 'text/csv', null, true)]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame([], glob($this->storageDirectory().'/*.csv') ?: [], 'Nothing was stored.');
    }

    public function testAnImportCreatesLinesSoItNeedsTheRightToCreateAsWellAsTheRightToUpdate(): void
    {
        $list = $this->priceList('rights-rt3');
        $this->productWithAPse('RT3-1');
        $token = $this->sessionToken($list);

        // Deposited by a full admin: the preview and the confirmation are what the weaker admin tries.
        $this->upload($list, $token, "pse_ref,min_quantity,price\nRT3-1,1,10\n");
        $previewUrl = $this->previewUrl();

        $updateOnly = $this->factory->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $updateOnly->eraseCredentials();
        $this->injector->setAdmin($updateOnly);

        $this->client->request('GET', $previewUrl);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The preview is read by who may import.');
        $this->client->request('POST', $previewUrl.'/confirm', ['_token' => $token]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        PriceListItemTableMap::clearInstancePool();
        self::assertSame(0, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'No line is created without the right to create.');

        $path = $this->scratch.'/second.csv';
        file_put_contents($path, "pse_ref,price\nRT3-1,1\n");
        $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/import/upload', ['_token' => $token], ['file' => new UploadedFile($path, 'lines.csv', 'text/csv', null, true)]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The deposit is refused too.');

        $both = $this->factory->restrictedAdmin([AdminResources::PRICING => [AccessManager::VIEW, AccessManager::UPDATE, AccessManager::CREATE]]);
        $both->eraseCredentials();
        $this->injector->setAdmin($both);
        $this->client->request('POST', $previewUrl.'/confirm', ['_token' => $token]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        PriceListItemTableMap::clearInstancePool();
        self::assertSame(1, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'Who may update and create imports.');
    }

    public function testAFileReplacedByAnotherDepositIsAnsweredWithAMessageNotASilentRedirect(): void
    {
        $list = $this->priceList('expired-rt4');
        $this->productWithAPse('RT4-1');
        $token = $this->sessionToken($list);

        $this->upload($list, $token, "pse_ref,price\nRT4-1,10\n");
        $firstPreview = $this->previewUrl();
        $this->upload($list, $token, "pse_ref,price\nRT4-1,12\n");

        $this->client->request('GET', $firstPreview);
        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->client->followRedirect();
        self::assertStringContainsString('has expired or was replaced', (string) $this->client->getResponse()->getContent(), 'The preview of a replaced file says so.');

        $this->client->request('POST', $firstPreview.'/confirm', ['_token' => $token]);
        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->client->followRedirect();
        self::assertStringContainsString('has expired or was replaced', (string) $this->client->getResponse()->getContent(), 'The confirmation of a replaced file says so.');
        PriceListItemTableMap::clearInstancePool();
        self::assertSame(0, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'The replaced file imported nothing.');
    }

    public function testAnImportOfTwoHundredLinesReportsEveryLineAndEscapesAHostileReference(): void
    {
        $list = $this->priceList('volume-rt5');
        [$product] = $this->productWithAPse('RT5-0');
        $rows = ['pse_ref,min_quantity,price'];
        for ($i = 1; $i <= 120; ++$i) {
            $reference = \sprintf('RT5-%03d', $i);
            $pse = $this->factory->productSaleElement($product, ['ref' => $reference]);
            $this->factory->productPrice($pse, Currency::getDefaultCurrency(), ['price' => '100', 'promoPrice' => '100']);
            $rows[] = $reference.',1,'.(10 + $i);
        }
        for ($i = 1; $i <= 79; ++$i) {
            $rows[] = \sprintf('NOPE-RT5-%03d,1,10', $i);
        }
        $rows[] = '<script>alert(1)</script>,1,10';
        self::assertCount(201, $rows, 'Header and two hundred lines.');
        $token = $this->sessionToken($list);

        $this->upload($list, $token, implode("\n", $rows)."\n");
        $crawler = $this->client->followRedirect();

        self::assertSame('120', trim($crawler->filter('[data-testid="price-list-import-created"]')->text()));
        self::assertSame('80', trim($crawler->filter('[data-testid="price-list-import-errors"]')->text()));
        self::assertCount(80, $crawler->filter('[data-testid^="price-list-import-error-"]:not([data-testid="price-list-import-error-table"])'), 'Every refused line is reported with its reason.');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $content, 'The reference of the file is escaped in the report.');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);

        $crawler = $this->client->request('POST', $this->previewUrl().'/confirm', ['_token' => $token]);
        self::assertSame('120', trim($crawler->filter('[data-testid="price-list-import-created"]')->text()));
        PriceListItemTableMap::clearInstancePool();
        self::assertSame(120, PriceListItemQuery::create()->filterByPriceListId($list->getId())->count(), 'The valid lines are written, the refused ones are not.');
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $this->client->getResponse()->getContent());
    }

    private function upload(PriceList $list, string $token, string $content): ?\Symfony\Component\DomCrawler\Crawler
    {
        $path = $this->scratch.'/'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $content);

        return $this->client->request('POST', self::LIST_URL.'/'.$list->getId().'/import/upload', ['_token' => $token], ['file' => new UploadedFile($path, 'lines.csv', 'text/csv', null, true)]);
    }

    private function previewUrl(): string
    {
        $stored = glob($this->storageDirectory().'/*.csv') ?: [];
        self::assertCount(1, $stored);
        [$listId, $rest] = explode('-', basename($stored[0], '.csv'), 2);

        return self::LIST_URL.'/'.$listId.'/import/'.$rest;
    }

    private function storageDirectory(): string
    {
        return (string) static::getContainer()->getParameter('kernel.project_dir').'/var/price-list-imports';
    }

    private function itemId(PriceList $list, ProductSaleElements $pse): int
    {
        return (int) PriceListItemQuery::create()->filterByPriceListId($list->getId())->filterByProductSaleElementsId($pse->getId())->findOne()?->getId();
    }

    private function sessionToken(PriceList $list): string
    {
        $html = (string) $this->client->request('GET', self::LIST_URL.'/'.$list->getId().'?tab=lines')->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }

    private function defaultCurrency(): Currency
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        self::assertNotNull($currency);

        return $currency;
    }

    private function priceList(string $code): PriceList
    {
        $list = (new PriceList())->setCode($code)->setCurrencyId($this->defaultCurrency()->getId())->setPriority(100)->setExclusive(0)->setActive(1);
        $list->save($this->getPropelConnection());

        return $list;
    }

    private function line(PriceList $list, ProductSaleElements $pse, int $minimumQuantity, ?string $price, ?string $discount = null): PriceListItem
    {
        $item = (new PriceListItem())
            ->setPriceListId($list->getId())
            ->setProductSaleElementsId($pse->getId())
            ->setMinQuantity($minimumQuantity)
            ->setPrice($price)
            ->setDiscountPercent($discount);
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
}
