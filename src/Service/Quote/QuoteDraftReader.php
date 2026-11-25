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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Quote\DTO\QuoteDraftInput;
use Thelia\Domain\Quote\DTO\QuoteDraftLine;

/**
 * Reads the form of the negotiation screen into what the quote facade writes. It only shapes
 * the input: whether a price, a quantity or a note is acceptable is for the facade to say, and
 * the catalogue price of a line is never read from the form, the facade sets it.
 */
final readonly class QuoteDraftReader
{
    public const MAXIMUM_LINES = 500;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws QuoteRefusedException when the form is not one the screen sends
     */
    public function read(Request $request): QuoteDraftInput
    {
        $form = $request->request->all();

        // A form that is not the one of the screen would otherwise read as a draft with no line.
        if ('1' !== self::text($form['edited'] ?? '')) {
            throw new QuoteRefusedException($this->translator->trans('The lines of the quote could not be read.'));
        }

        $lines = [];
        $submitted = $form['lines'] ?? [];

        if (!\is_array($submitted)) {
            throw new QuoteRefusedException($this->translator->trans('The lines of the quote could not be read.'));
        }

        if (\count($submitted) > self::MAXIMUM_LINES) {
            throw new QuoteRefusedException($this->translator->trans('A quote cannot hold more than %count% lines.', ['%count%' => self::MAXIMUM_LINES]));
        }

        foreach ($submitted as $line) {
            if (!\is_array($line) || !isset($line['pse']) || !\is_scalar($line['pse']) || (int) $line['pse'] <= 0) {
                throw new QuoteRefusedException($this->translator->trans('The lines of the quote could not be read.'));
            }

            $item = isset($line['item']) && \is_scalar($line['item']) ? (int) $line['item'] : 0;
            $lines[] = new QuoteDraftLine(
                $item > 0 ? $item : null,
                (int) $line['pse'],
                (float) self::number($line['quantity'] ?? ''),
                self::number($line['price'] ?? ''),
            );
        }

        $module = (int) self::text($form['delivery_module_id'] ?? '');
        $discount = self::number($form['discount'] ?? '');
        $postage = self::number($form['postage'] ?? '');

        return new QuoteDraftInput(
            $lines,
            '' === $discount ? '0' : $discount,
            '' === $postage ? '0' : $postage,
            $module > 0 ? $module : null,
            self::text($form['merchant_note'] ?? ''),
            $this->day(self::text($form['valid_until'] ?? '')),
        );
    }

    private function day(string $text): ?\DateTimeImmutable
    {
        if ('' === trim($text)) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($text));

        return false === $day ? throw new QuoteRefusedException($this->translator->trans('The last valid day is not a valid date.')) : $day;
    }

    /**
     * A number as typed: spaces dropped, the comma read as the decimal point.
     */
    private static function number(mixed $value): string
    {
        return str_replace(',', '.', preg_replace('/\s+/', '', self::text($value)) ?? '');
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
