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

namespace BackOfficeDefaultTwigBundle\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * A translation key with no entry in a catalogue falls back to its English source with
 * no error anywhere, so a French back-office quietly shows English words. This suite
 * reads the keys out of the history block itself and states that the four catalogues
 * the block is shipped in answer for every one of them.
 *
 * The template keys are extracted rather than listed, so a `|trans` added to the block
 * without its translation turns this red on its own.
 */
final class OrderHistoryTranslationCatalogueTest extends TestCase
{
    private const TRANSLATED_LOCALES = ['fr_FR', 'es_ES', 'it_IT'];

    private const TEMPLATES = [
        'order/_history_card.html.twig',
        'order/_history_note_edit_modal.html.twig',
    ];

    /**
     * What the presenter and the controller translate in PHP: the summaries of the
     * event types, the author kinds, and the messages flashed after a post.
     */
    private const PHP_KEYS = [
        'A module',
        'A note cannot be empty.',
        'A note cannot exceed %limit% characters.',
        'Address updated',
        'An administrator',
        'Customer %reference%',
        'Delivery address updated',
        'E-mail sent',
        'E-mail sent (%reference%)',
        'Invoice address updated',
        'Invoice reference %reference% allocated',
        'Invoice reference allocated',
        'Module %code%',
        'Note',
        'Only the administrator who wrote a note may edit it.',
        'Order %reference% created',
        'Order created',
        'Return %reference% changed from %from% to %to%',
        'Return %reference% opened',
        'Return %reference% received',
        'Return %reference% set to %to%',
        'Return opened',
        'Return received',
        'Return status changed from %from% to %to%',
        'Return status set to %to%',
        'Status changed from %from% to %to%',
        'Status set to %to%',
        'System',
        'The customer',
        'The form has expired, please try again.',
        'The note has been added.',
        'The note has been updated.',
        'This note does not exist on this order.',
        'This order is settled: its notes can no longer be edited.',
        'Tracking reference cleared',
        'Tracking reference set to %reference%',
        'Transaction reference cleared',
        'Transaction reference set to %reference%',
    ];

    #[\PHPUnit\Framework\Attributes\DataProvider('translatedLocales')]
    public function testEveryKeyOfTheHistoryBlockIsTranslated(string $locale): void
    {
        $catalogue = $this->catalogue($locale);
        $missing = [];

        foreach ([...self::PHP_KEYS, ...$this->templateKeys()] as $key) {
            if (!isset($catalogue[$key])) {
                $missing[] = $key;
            }
        }

        self::assertSame(
            [],
            $missing,
            \sprintf('The %s catalogue answers for every key of the order history block.', $locale),
        );
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function translatedLocales(): \Generator
    {
        foreach (self::TRANSLATED_LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * @return list<string>
     */
    private function templateKeys(): array
    {
        $keys = [];

        foreach (self::TEMPLATES as $template) {
            $source = file_get_contents($this->themeDir().'/'.$template);
            self::assertIsString($source, \sprintf('"%s" must be readable.', $template));

            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\\|trans/", $source, $matches);

            foreach ($matches[1] as $key) {
                $keys[] = str_replace("\\'", "'", $key);
            }
        }

        self::assertNotEmpty($keys, 'The history templates must carry translated labels.');

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, string>
     */
    private function catalogue(string $locale): array
    {
        $path = $this->themeDir().'/translations/messages.'.$locale.'.php';
        self::assertFileExists($path);

        /** @var array<string, string> $catalogue */
        $catalogue = require $path;

        return $catalogue;
    }

    private function themeDir(): string
    {
        return \dirname(__DIR__, 2);
    }
}
