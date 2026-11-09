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

namespace BackOfficeDefaultTwigBundle\Controller\Pricing;

use BackOfficeDefaultTwigBundle\Repository\PriceListRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormToken;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListImportReportPresenter;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListImportStorage;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListRefusedException;
use BackOfficeDefaultTwigBundle\Service\PriceList\PriceListWriteGate;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Pricing\PriceList\Export\PriceListLineExport;
use Thelia\Domain\Pricing\PriceList\Import\PriceListImportException;
use Thelia\Domain\Pricing\PriceList\Import\PriceListLineImport;
use Thelia\Model\PriceList;
use Twig\Environment;

/**
 * The CSV of the lines of a price list. Import in three steps, none of which writes before the
 * last: the deposit (the file is stored out of the web root under a random name), the preview
 * (what the import would create, update and refuse), the confirmation (a token again). The
 * file is deleted once the import is applied or abandoned. The export downloads the lines in
 * the same columns.
 */
#[Route('/admin/pricing/price-lists/{price_list_id}', name: 'admin.pricing.price_list.csv_', requirements: ['price_list_id' => '\d+'])]
final class PriceListCsvController
{
    public const EXPIRED_FILE_MESSAGE = 'The import file has expired or was replaced by another one. Upload it again.';

    public const UPLOAD_LABEL = 'Price list import';
    public const ABANDON_LABEL = 'Price list import abandon';

    private const RESOURCE = PriceListWriteGate::RESOURCE;
    private const IMPORT_TEMPLATE = '@BackOfficeDefaultTwig/pricing/price-list/import.html.twig';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly RestrictedAdminGuard $restricted,
        private readonly AdminFormToken $formToken,
        private readonly AdminFlash $flash,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly PriceListRepository $priceLists,
        private readonly PriceListImportStorage $storage,
        private readonly PriceListLineImport $import,
        private readonly PriceListLineExport $export,
        private readonly PriceListImportReportPresenter $reports,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/import/upload', name: 'upload', methods: ['POST'])]
    public function upload(int $price_list_id, Request $request): Response
    {
        if ($denied = $this->guardImport()) {
            return $denied;
        }

        $sheet = $this->urls->generate('admin.pricing.price_list.view', ['price_list_id' => $price_list_id, 'tab' => 'lines']);

        if (null === $this->priceLists->find($price_list_id) || !$this->formToken->isValid($request)) {
            return new RedirectResponse($sheet);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            $this->errorRenderer->refuse($this->translator->trans(self::UPLOAD_LABEL), $this->translator->trans('Choose a CSV file to import.'));

            return new RedirectResponse($sheet);
        }

        try {
            $token = $this->storage->store($file, $price_list_id);
        } catch (PriceListRefusedException $refusal) {
            $this->errorRenderer->refuse($this->translator->trans(self::UPLOAD_LABEL), $refusal->getMessage(), null, $refusal);

            return new RedirectResponse($sheet);
        }

        return new RedirectResponse($this->urls->generate('admin.pricing.price_list.csv_preview', ['price_list_id' => $price_list_id, 'file_token' => $token]));
    }

    #[Route('/import/{file_token}', name: 'preview', methods: ['GET'], requirements: ['file_token' => '[0-9a-f]{32}'])]
    public function preview(int $price_list_id, string $file_token, Request $request): Response
    {
        if ($denied = $this->guardImport()) {
            return $denied;
        }

        $priceList = $this->priceLists->find($price_list_id);
        $file = $this->storage->find($price_list_id, $file_token);
        $sheet = new RedirectResponse($this->urls->generate('admin.pricing.price_list.view', ['price_list_id' => $price_list_id, 'tab' => 'lines']));

        if (null === $priceList || null === $file) {
            $this->flashExpiredFile($request);

            return $sheet;
        }

        try {
            $report = $this->import->preview($priceList, $file);
        } catch (PriceListImportException $refusal) {
            $this->storage->discard($price_list_id, $file_token);
            $this->errorRenderer->refuse($this->translator->trans(self::UPLOAD_LABEL), $refusal->getMessage(), null, $refusal);

            return $sheet;
        }

        return $this->render($priceList, $this->reports->present($report), $file_token);
    }

    #[Route('/import/{file_token}/confirm', name: 'confirm', methods: ['POST'], requirements: ['file_token' => '[0-9a-f]{32}'])]
    public function confirm(int $price_list_id, string $file_token, Request $request): Response
    {
        if ($denied = $this->guardImport()) {
            return $denied;
        }

        $sheet = new RedirectResponse($this->urls->generate('admin.pricing.price_list.view', ['price_list_id' => $price_list_id, 'tab' => 'lines']));

        if (!$this->formToken->isValid($request)) {
            return new RedirectResponse($this->urls->generate('admin.pricing.price_list.csv_preview', ['price_list_id' => $price_list_id, 'file_token' => $file_token]));
        }

        $priceList = $this->priceLists->find($price_list_id);
        $file = $this->storage->find($price_list_id, $file_token);
        if (null === $priceList || null === $file) {
            $this->flashExpiredFile($request);

            return $sheet;
        }

        try {
            $report = $this->import->apply($priceList, $file);
        } catch (PriceListImportException $failure) {
            $this->errorRenderer->refuse($this->translator->trans(self::UPLOAD_LABEL), $failure->getMessage(), null, $failure);

            return $sheet;
        } finally {
            $this->storage->discard($price_list_id, $file_token);
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Lines of the price list %s imported: %d created, %d updated, %d refused', $priceList->getCode(), $report->created, $report->updated, $report->errorCount), $price_list_id);

        return $this->render($priceList, $this->reports->present($report), null);
    }

    #[Route('/import/{file_token}/abandon', name: 'abandon', methods: ['POST'], requirements: ['file_token' => '[0-9a-f]{32}'])]
    public function abandon(int $price_list_id, string $file_token, Request $request): Response
    {
        if ($denied = $this->guard(AccessManager::UPDATE)) {
            return $denied;
        }

        if ($this->formToken->isValid($request)) {
            $this->storage->discard($price_list_id, $file_token);
            $this->flash->add($request, 'success', $this->translator->trans('Import abandoned.'));
        }

        return new RedirectResponse($this->urls->generate('admin.pricing.price_list.view', ['price_list_id' => $price_list_id, 'tab' => 'lines']));
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(int $price_list_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $priceList = $this->priceLists->find($price_list_id);
        if (null === $priceList) {
            return new RedirectResponse($this->urls->generate('admin.pricing.price_list.list'));
        }

        $response = new StreamedResponse(function () use ($priceList): void {
            $output = fopen('php://output', 'w');
            if (false === $output) {
                return;
            }

            try {
                $this->export->write($priceList, $output);
            } finally {
                fclose($output);
            }
        });

        $filename = 'price-list-'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $priceList->getCode()).'.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * An import creates lines as well as it updates them: it is written by who may do both, as the
     * line screens ask CREATE for an added line and UPDATE for a changed one.
     */
    /**
     * A new deposit on the list purges the files of the others: whoever comes back with the file
     * of an earlier deposit is told, instead of being sent back to the sheet as if the import was done.
     */
    private function flashExpiredFile(Request $request): void
    {
        $this->flash->add($request, 'warning', $this->translator->trans(self::EXPIRED_FILE_MESSAGE));
    }

    private function guardImport(): ?Response
    {
        return $this->guard(AccessManager::UPDATE) ?? $this->guard(AccessManager::CREATE);
    }

    private function guard(string $access): ?Response
    {
        return $this->access->check(self::RESOURCE, [], $access) ?? $this->restricted->denyIfRestricted(self::RESOURCE, $access);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function render(PriceList $priceList, array $report, ?string $fileToken): Response
    {
        return new Response($this->twig->render(self::IMPORT_TEMPLATE, [
            'price_list' => $priceList,
            'report' => $report,
            'file_token' => $fileToken,
        ]));
    }
}
