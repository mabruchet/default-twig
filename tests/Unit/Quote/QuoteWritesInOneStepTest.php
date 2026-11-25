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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Quote;

use PHPUnit\Framework\TestCase;

/**
 * Duplicating a quote, and saving then sending a draft, are one transaction each in the quote
 * facade. A test that runs inside the transaction of the test cannot see a rollback, so the
 * actions are read here: they call the one method of the facade that does the whole write, and
 * none of the methods that would split it in two transactions.
 */
final class QuoteWritesInOneStepTest extends TestCase
{
    public function testDuplicatingGoesThroughTheFacadeMethodThatWritesTheWholeCopy(): void
    {
        $action = $this->action('Controller/Quote/QuoteController.php', 'duplicate');

        self::assertStringContainsString('->facade->duplicate(', $action);
        self::assertStringNotContainsString('->createForCustomer(', $action, 'A quote created first and filled after is left empty when the second write fails.');
        self::assertStringNotContainsString('->saveDraft(', $action);
    }

    public function testSendingGoesThroughTheFacadeMethodThatSavesAndSendsTogether(): void
    {
        $action = $this->action('Controller/Quote/QuoteNegotiationController.php', 'send');

        self::assertStringContainsString('->facade->saveAndSend(', $action);
        self::assertStringNotContainsString('->saveDraft(', $action, 'A draft saved on its own can change between the save and the send.');
        self::assertStringNotContainsString('->facade->send(', $action);
    }

    private function action(string $file, string $method): string
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3).'/src/'.$file);
        self::assertSame(1, preg_match('/public function '.$method.'\(.*?(?=\n    #\[Route|\n    \/\*\*|\n    private function)/s', $source, $found), 'The action '.$method.' is read.');

        return $found[0];
    }
}
