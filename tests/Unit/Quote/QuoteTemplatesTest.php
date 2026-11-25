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
use Symfony\Component\Finder\Finder;

/**
 * The address of the administration can be changed, so a quote screen never writes a path of
 * its own: every link and every form action is built by the router.
 */
final class QuoteTemplatesTest extends TestCase
{
    public function testNoQuoteTemplateWritesAnAdministrationPathByHand(): void
    {
        $root = \dirname(__DIR__, 3);
        $templates = (new Finder())->files()->in($root.'/quote')->name('*.twig');

        $found = 0;
        $handWritten = [];
        foreach ($templates as $template) {
            ++$found;
            if (1 === preg_match('#(href|action|src|data-[a-z-]*url)\s*=\s*["\']/admin#i', $template->getContents())) {
                $handWritten[] = $template->getRelativePathname();
            }
        }

        self::assertGreaterThanOrEqual(3, $found, 'The templates of the quote screens are read.');
        self::assertSame([], $handWritten, 'These templates write a path of the administration by hand.');
    }

    public function testTheQuoteControllersNameTheirRoutesThroughTheRouter(): void
    {
        $root = \dirname(__DIR__, 3);
        $controllers = (new Finder())->files()->in($root.'/src/Controller/Quote')->name('*.php');

        $hardCoded = [];
        foreach ($controllers as $controller) {
            // A redirect or a link is made by $this->urls->generate(); a literal path after "new RedirectResponse(" is not.
            if (1 === preg_match('#RedirectResponse\(\s*[\'"]/#', $controller->getContents())) {
                $hardCoded[] = $controller->getRelativePathname();
            }
        }

        self::assertSame([], $hardCoded);
    }
}
