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

namespace BackOfficeDefaultTwigBundle\Controller\Quote;

use BackOfficeDefaultTwigBundle\Repository\QuoteRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteDraftReader;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteGrantedDiscount;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteRefusal;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteRefusedException;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteSheetContext;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteWriteGate;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Quote\Exception\QuoteException;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Model\Admin;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\QuoteItem;
use Thelia\Tools\TokenProvider;

/**
 * The save of the draft the negotiation screen makes in the background while the shop types:
 * the same write as the save of the form, through the same facade, answered in JSON with the
 * totals the server works out, the discount the shop grants on the lines and the ids of the
 * lines as they are now stored. A refusal is an answer, never a page to land on.
 */
#[Route('/admin/quotes/{id}', name: 'admin.quote.', requirements: ['id' => '\d+'])]
final class QuoteAutosaveController
{
    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly TokenProvider $tokens,
        private readonly QuoteRepository $quotes,
        private readonly QuoteFacade $facade,
        private readonly QuoteDraftReader $reader,
        private readonly QuoteSheetContext $sheet,
        private readonly QuoteGrantedDiscount $granted,
        private readonly QuoteRefusal $refusal,
        private readonly AdminLogger $adminLogger,
        private readonly SecurityContext $securityContext,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/draft/autosave', name: 'autosave', methods: ['POST'])]
    public function autosave(int $id, Request $request): JsonResponse
    {
        if (null !== $this->access->check(QuoteWriteGate::RESOURCE, [], AccessManager::UPDATE)) {
            return $this->refused($this->translator->trans("Sorry, you're not allowed to perform this action"), Response::HTTP_FORBIDDEN);
        }

        try {
            $this->tokens->checkRequestToken($request);
        } catch (TokenAuthenticationException) {
            return $this->refused($this->translator->trans('The form has expired, please try again.'), Response::HTTP_FORBIDDEN);
        }

        $quote = $this->quotes->find($id);
        if (null === $quote) {
            return $this->refused($this->translator->trans('This quote does not exist.'), Response::HTTP_NOT_FOUND);
        }

        try {
            $revision = $this->facade->saveDraft($quote, $this->admin(), $this->reader->read($request));
            $lines = array_map(static fn (array $row): QuoteItem => $row['line'], $this->quotes->lines($revision));
            $locale = $request->getLocale();
            $currency = CurrencyQuery::create()->findPk($quote->getCurrencyId())?->getCode();
            $currencyCode = null === $currency || '' === $currency ? null : $currency;

            $answer = [
                'totals' => $this->sheet->formattedTotals($revision, $locale, $currencyCode),
                'granted' => $this->granted->of($lines, $locale, $currencyCode),
                'lines' => array_map(static fn (QuoteItem $line): array => [
                    'item' => (int) $line->getId(),
                    'pse' => (int) $line->getProductSaleElementsId(),
                ], $lines),
            ];
        } catch (QuoteRefusedException $refusal) {
            return $this->refused($refusal->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (QuoteException $refusal) {
            return $this->refused($this->refusal->messageOf($refusal), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $failure) {
            $this->logger->error(\sprintf('Error during %s: %s', QuoteNegotiationController::SAVE_LABEL, JobFailureMessage::forLog($failure)));

            return $this->refused($this->translator->trans(QuoteWriteGate::GENERIC_FAILURE), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->adminLogger->log(QuoteWriteGate::RESOURCE, AccessManager::UPDATE, \sprintf('Draft of quote %s saved in the background', (string) $quote->getRef()), $id);

        return new JsonResponse($answer);
    }

    private function refused(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    private function admin(): ?Admin
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? $admin : null;
    }
}
