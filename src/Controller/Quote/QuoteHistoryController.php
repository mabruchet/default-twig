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
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteSheetContext;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteWriteGate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\QuoteRevision;
use Twig\Environment;

/**
 * An earlier revision of a quote, read as it was sent. Nothing on it can be changed, and a
 * revision still being written is not one to read: its place is the edit screen.
 */
final class QuoteHistoryController
{
    public const ROUTE = 'admin.quote.revision';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly QuoteRepository $quotes,
        private readonly QuoteSheetContext $sheet,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/admin/quotes/{id}/revisions/{number}', name: self::ROUTE, methods: ['GET'], requirements: ['id' => '\d+', 'number' => '\d+'])]
    public function revision(int $id, int $number, Request $request): Response
    {
        if ($denied = $this->access->check(QuoteWriteGate::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $quote = $this->quotes->find($id);
        if (null === $quote) {
            return new RedirectResponse($this->urls->generate('admin.quote.list'));
        }

        $revision = $this->quotes->revision($quote, $number);
        if (!$revision instanceof QuoteRevision || 'draft' === $revision->getState()) {
            return new RedirectResponse($this->urls->generate('admin.quote.view', ['id' => $id]));
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/quote/view.html.twig', $this->sheet->sheet($quote, $request->getLocale(), $revision) + ['tab' => QuoteSheetContext::tabOf($request)]));
    }
}
