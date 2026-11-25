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
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteNegotiationContext;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteSheetContext;
use BackOfficeDefaultTwigBundle\Service\Quote\QuoteWriteGate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Twig\Environment;

/**
 * The screen where the shop works on a quote. It needs the right to change quotes, the
 * writes of the screen go through the quote facade behind their own routes, see
 * {@see QuoteNegotiationController}.
 */
final class QuoteEditController
{
    public const ROUTE = 'admin.quote.edit';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly QuoteRepository $quotes,
        private readonly QuoteSheetContext $sheet,
        private readonly QuoteNegotiationContext $negotiation,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/admin/quotes/{id}/edit', name: self::ROUTE, methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        if ($denied = $this->access->check(QuoteWriteGate::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $quote = $this->quotes->find($id);
        if (null === $quote) {
            return new RedirectResponse($this->urls->generate('admin.quote.list'));
        }

        $negotiation = $this->negotiation->forScreen($quote, $request->getLocale());
        $sheet = $this->sheet->sheet($quote, $request->getLocale(), null, null === $negotiation);
        $sheet['negotiation'] = $negotiation;
        $sheet['tab'] = QuoteSheetContext::tabOf($request);

        return new Response($this->twig->render('@BackOfficeDefaultTwig/quote/edit.html.twig', $sheet));
    }
}
