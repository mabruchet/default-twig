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

namespace BackOfficeDefaultTwigBundle\Service\Company;

use Symfony\Component\HttpFoundation\Request;
use Thelia\Domain\Company\DTO\CompanyData;

/**
 * What the company form and the buyer forms send, read from the request: a blank field is
 * no value, and the amounts are written the way an administrator types them.
 */
final readonly class CompanyInput
{
    public static function company(Request $request, ?int $billingAddressId): CompanyData
    {
        $post = $request->request;

        return new CompanyData(
            name: trim((string) $post->get('name', '')),
            siret: self::text($post->get('siret')),
            vatNumber: self::text($post->get('vat_number')),
            parentId: self::id($post->get('parent_id')),
            notificationEmail: self::text($post->get('notification_email')),
            internalNote: self::text($post->get('internal_note')),
            billingAddressId: $billingAddressId,
        );
    }

    public static function billingAddressId(Request $request): ?int
    {
        return self::id($request->request->get('billing_address_id'));
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null} the limit of one order, the limit of a period and the period
     */
    public static function limits(Request $request): array
    {
        $post = $request->request;

        return [
            self::amount($post->get('order_limit')),
            self::amount($post->get('period_limit')),
            self::text($post->get('period')),
        ];
    }

    public static function text(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    public static function id(mixed $value): ?int
    {
        $text = self::text($value);

        return null !== $text && ctype_digit($text) && (int) $text > 0 ? (int) $text : null;
    }

    private static function amount(mixed $value): ?string
    {
        $text = self::text($value);

        return null === $text ? null : str_replace([' ', ','], ['', '.'], $text);
    }
}
