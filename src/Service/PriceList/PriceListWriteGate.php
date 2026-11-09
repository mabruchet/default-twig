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

namespace BackOfficeDefaultTwigBundle\Service\PriceList;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormToken;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\Resource\AdminResources;

/**
 * The road of every write of the price list screens: the right on the pricing resource,
 * the token read from the body (a form left open past its token is sent back with a message,
 * nothing written), the write, the admin log, and back to the screen with a refusal as a
 * message. A price list changes what customers pay: an administrator restricted to some sale
 * units does not write it.
 */
final readonly class PriceListWriteGate
{
    public const RESOURCE = AdminResources::PRICING;

    public const GENERIC_FAILURE = 'The price list could not be saved. Please try again.';

    public function __construct(
        private AdminAccessChecker $access,
        private RestrictedAdminGuard $restricted,
        private AdminFormToken $token,
        private AdminFormErrorRenderer $errorRenderer,
        private AdminLogger $adminLogger,
        private AdminFlash $flash,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param callable(): string $write returns the line of the admin log
     */
    public function run(Request $request, string $access, string $actionLabel, string $redirectUrl, ?int $resourceId, string $successMessage, callable $write): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], $access)) {
            return $denied;
        }

        if ($denied = $this->restricted->denyIfRestricted(self::RESOURCE, $access)) {
            return $denied;
        }

        $redirect = new RedirectResponse($redirectUrl);

        if (!$this->token->isValid($request)) {
            return $redirect;
        }

        try {
            $logLine = $write();
        } catch (PriceListRefusedException $refusal) {
            $this->errorRenderer->refuse($this->translator->trans($actionLabel), $refusal->getMessage(), null, $refusal);

            return $redirect;
        } catch (\Throwable $failure) {
            $this->errorRenderer->refuse($this->translator->trans($actionLabel), $this->translator->trans(self::GENERIC_FAILURE), null, $failure);

            return $redirect;
        }

        $this->adminLogger->log(self::RESOURCE, $access, $logLine, $resourceId);
        $this->flash->add($request, 'success', $this->translator->trans($successMessage));

        return $redirect;
    }
}
