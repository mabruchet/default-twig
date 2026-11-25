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

namespace BackOfficeDefaultTwigBundle\Service\Quote;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormToken;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Quote\Exception\QuoteException;

/**
 * Runs one write of the quote screens: the right on the resource first, then the token, then
 * the write itself; a refusal of the rules is shown to the administrator, a failure is logged
 * and shown as an internal error, and either way the administrator lands back on the screen.
 */
final readonly class QuoteWriteGate
{
    public const RESOURCE = AdminResources::QUOTE;

    public const GENERIC_FAILURE = 'The quote could not be saved. Please try again.';

    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormToken $token,
        private AdminFormErrorRenderer $errorRenderer,
        private AdminLogger $adminLogger,
        private AdminFlash $flash,
        private TranslatorInterface $translator,
        private QuoteRefusal $refusal,
    ) {
    }

    /**
     * @param callable(): (string|array{0: string, 1: string}) $write returns the line of the admin log, with the address to land on when it is not the screen the action came from
     */
    public function run(Request $request, string $access, string $actionLabel, string $redirectUrl, ?int $resourceId, string $successMessage, callable $write): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], $access)) {
            return $denied;
        }

        $redirect = new RedirectResponse($redirectUrl);

        if (!$this->token->isValid($request)) {
            return $redirect;
        }

        try {
            $written = $write();
        } catch (QuoteRefusedException $refusal) {
            $this->errorRenderer->refuse($this->translator->trans($actionLabel), $refusal->getMessage());

            return null === $refusal->redirectUrl ? $redirect : new RedirectResponse($refusal->redirectUrl);
        } catch (QuoteException $refusal) {
            $this->errorRenderer->refuse($this->translator->trans($actionLabel), $this->refusal->messageOf($refusal));

            return $redirect;
        } catch (\Throwable $failure) {
            $this->errorRenderer->refuse($this->translator->trans($actionLabel), $this->translator->trans(self::GENERIC_FAILURE), null, $failure);

            return $redirect;
        }

        [$logLine, $landing] = \is_array($written) ? $written : [$written, null];

        $this->adminLogger->log(self::RESOURCE, $access, $logLine, $resourceId);
        $this->flash->add($request, 'success', $this->translator->trans($successMessage));

        return null === $landing ? $redirect : new RedirectResponse($landing);
    }
}
