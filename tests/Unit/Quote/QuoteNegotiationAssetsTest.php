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
 * No browser reads these tests, so the wiring between the template of the negotiation screen
 * and its Stimulus controller is read here: a controller that is not registered, an action the
 * controller does not have or a target it does not declare leaves the screen dead with no error.
 */
final class QuoteNegotiationAssetsTest extends TestCase
{
    private const IDENTIFIER = 'bo-quote-negotiation';

    public function testTheControllerIsImportedAndRegisteredUnderTheNameTheTemplateUses(): void
    {
        $bootstrap = (string) file_get_contents(self::root().'/assets/bootstrap.js');

        self::assertStringContainsString("from './controllers/bo-quote-negotiation_controller.js'", $bootstrap);
        self::assertMatchesRegularExpression("/app\\.register\\('".self::IDENTIFIER."', \\w+\\);/", $bootstrap);
        self::assertStringContainsString('data-controller="'.self::IDENTIFIER.'"', $this->template());
    }

    public function testTheScriptNeverWritesTheTextOfAFieldAsMarkup(): void
    {
        self::assertDoesNotMatchRegularExpression('/\b(innerHTML|outerHTML|insertAdjacentHTML|document\.write)\b/', $this->controller(), 'The note of the shop and the names of products are text: they are never injected as markup.');
    }

    public function testEveryActionTheTemplateCallsIsAMethodOfTheController(): void
    {
        preg_match_all('/'.self::IDENTIFIER.'#(\w+)/', $this->template(), $matches);
        $actions = array_unique($matches[1]);

        self::assertNotEmpty($actions);

        foreach ($actions as $action) {
            self::assertMatchesRegularExpression('/^\s{4}(async\s+)?'.$action.'\(/m', $this->controller(), 'The controller has no method '.$action.'.');
        }
    }

    public function testEveryTargetTheTemplateNamesIsDeclaredByTheController(): void
    {
        preg_match_all('/data-'.self::IDENTIFIER.'-target="(\w+)"/', $this->template(), $matches);
        $named = array_unique($matches[1]);

        preg_match('/static targets = \\[(.*?)\\];/s', $this->controller(), $declared);
        preg_match_all("/'(\\w+)'/", $declared[1] ?? '', $declaredNames);

        self::assertNotEmpty($named);
        self::assertSame([], array_values(array_diff($named, $declaredNames[1])), 'Targets the template names and the controller does not declare.');
    }

    public function testEveryValueTheTemplateGivesIsDeclaredByTheController(): void
    {
        preg_match_all('/data-'.self::IDENTIFIER.'-([a-z-]+)-value=/', $this->template(), $matches);

        self::assertNotEmpty($matches[1]);

        foreach ($matches[1] as $kebab) {
            $camel = lcfirst(str_replace('-', '', ucwords($kebab, '-')));
            self::assertMatchesRegularExpression('/\b'.$camel.'\s*:/', $this->controller(), 'The controller declares no value '.$camel.'.');
        }
    }

    public function testTheDraftIsSavedInTheBackgroundWhenAFieldIsLeftNotOnEveryKeystroke(): void
    {
        $template = $this->template();
        $controller = $this->controller();

        self::assertStringContainsString('change->'.self::IDENTIFIER.'#changed', $template, 'A field that was changed saves the draft.');
        self::assertStringNotContainsString('input->'.self::IDENTIFIER.'#changed', $template, 'Typing does not save the draft.');
        self::assertStringContainsString('this.markDirty();', $this->method('changed'));
        self::assertStringContainsString('this.scheduleSave();', $this->method('markDirty'), 'Adding or removing a line is saved too: both go through markDirty.');
        self::assertStringNotContainsString('scheduleSave', $this->method('typed'), 'Typing only marks the form as changed.');
        self::assertMatchesRegularExpression('/\n    scheduleSave\(\) \{\s*clearTimeout\(this\.saveTimer\);\s*this\.saveTimer = setTimeout\(\(\) => this\.save\(\), 600\);/s', $controller, 'The saves are grouped: a pause of 600 ms after the last change.');
        self::assertStringContainsString('this.markDirty();', $this->method('removeLine'));
        self::assertStringContainsString('this.markDirty();', $this->method('addProduct'));
    }

    public function testOnlyOneBackgroundSaveIsOnItsWayAndTheLastChangeIsTheOneSaved(): void
    {
        $controller = $this->controller();

        self::assertMatchesRegularExpression('/if \(this\.saveRequest\) \{\s*this\.saveAgain = true;\s*return;/s', $controller);
        self::assertMatchesRegularExpression('/finally \{.*?this\.saveRequest = null;.*?if \(this\.saveAgain/s', $controller);
        self::assertMatchesRegularExpression('/if \(sent === this\.changes\) \{\s*this\.dirty = false;/s', $controller, 'A change made while a save was on its way keeps the form dirty.');
    }

    public function testSendingTheRevisionCancelsTheBackgroundSaveOnItsWayAndStartsNoOtherOne(): void
    {
        $submitted = $this->method('submitted');

        self::assertStringContainsString('this.saveRequest?.abort();', $submitted, 'A save on its way would reach the server after the revision was sent and write a draft of its own.');
        self::assertStringContainsString('this.sending = true;', $submitted);
        self::assertStringContainsString('this.saveAgain = false;', $submitted, 'The save that was waiting for the one on its way is dropped.');
        self::assertStringContainsString('clearTimeout(this.saveTimer);', $submitted);
        self::assertMatchesRegularExpression('/async save\(\) \{\s*clearTimeout\(this\.saveTimer\);\s*if \(this\.sending\) \{\s*return;\s*\}/s', $this->controller(), 'Once the form is sent no change, however late, saves a draft.');
        self::assertMatchesRegularExpression('/data-action="[^"]*submit->'.self::IDENTIFIER.'#submitted/', $this->template(), 'The form tells the controller it is being sent.');
    }

    public function testTheBackgroundSaveSendsTheFormWithItsTokenToTheAddressTheRouterGaveAndTakesTheTotalsFromTheAnswer(): void
    {
        $template = $this->template();
        $controller = $this->controller();

        self::assertMatchesRegularExpression('/<form[^>]*data-'.self::IDENTIFIER.'-target="form"/', $template);
        self::assertMatchesRegularExpression('/<input type="hidden" name="_token" value="\{\{ assignToken\(\) \}\}">/', $template, 'The form carries the token the background save sends.');
        self::assertStringContainsString('new FormData(this.formTarget)', $controller);
        self::assertStringContainsString('fetch(this.autosaveUrlValue', $controller);
        self::assertStringNotContainsString('/admin', $controller, 'No address is written in the script: the obfuscation of the admin address would break it.');
        self::assertMatchesRegularExpression("/method: 'POST'/", $controller);

        preg_match_all('/data-total="(\w+)"/', $template, $marked);
        foreach (['items_untaxed', 'postage_untaxed', 'discount', 'total_untaxed', 'total_taxed'] as $figure) {
            self::assertContains($figure, $marked[1], 'The template marks the figure '.$figure.'.');
        }
        self::assertStringContainsString('data-granted="amount"', $template);
        self::assertStringContainsString('data-granted="percent"', $template);
        self::assertStringContainsString('[data-total="${key}"]', $controller);
        self::assertStringContainsString('[data-granted="${key}"]', $controller);
    }

    public function testARefusedOrFailedBackgroundSaveIsShownAndNeverTouchesTheFields(): void
    {
        $controller = $this->controller();

        self::assertStringContainsString('data-'.self::IDENTIFIER.'-target="error"', $this->template());
        self::assertMatchesRegularExpression('/catch \(error\) \{\s*if \(error\.name === \'AbortError\'\) \{\s*return;\s*\}\s*this\.showStatus\(\'\'\);\s*this\.showError\(/s', $controller);

        self::assertStringNotContainsString('.value =', str_replace('field.value = String(stored.item)', '', $this->method('applySaved')), 'The answer sets the ids of the lines, no other field.');
    }

    public function testALineTheShopAddsCarriesAFieldForTheIdTheServerStoresItUnder(): void
    {
        self::assertMatchesRegularExpression('/<template[^>]*lineTemplate.*?data-field="item".*?<\/template>/s', $this->template());
        self::assertStringContainsString('[name$="[item]"]', $this->controller());
    }

    public function testTheWordsTheScriptShowsComeFromTheTemplateAndThusFromTheCatalogue(): void
    {
        $template = $this->template();
        $controller = $this->controller();

        foreach (['saving' => 'saving', 'saved' => 'saved', 'save-failed' => 'saveFailed'] as $attribute => $key) {
            self::assertStringContainsString(' data-'.$attribute.'="{{ \'', $template, 'The template gives the text '.$attribute.'.');
            self::assertMatchesRegularExpression('/dataset\.'.$key.'\b/', $controller, 'The script reads the text '.$attribute.'.');
        }
    }

    public function testTheLinesOfAProductThatIsGoneAreTakenOffTheScreenOnceTheDraftIsSaved(): void
    {
        self::assertStringContainsString("{{ line.unavailable ? ' data-unavailable' : '' }}", $this->template());
        self::assertStringContainsString("querySelectorAll('tr[data-unavailable]')", $this->method('applySaved'));
        self::assertStringContainsString('data-'.self::IDENTIFIER.'-target="goneNotice"', $this->template());
        self::assertStringContainsString('this.goneNoticeTarget.hidden = true;', $this->method('applySaved'));
    }

    /**
     * The body of a method of the controller, up to the closing brace at its own indentation.
     */
    private function method(string $name): string
    {
        self::assertSame(1, preg_match('/\n    (?:async )?'.$name.'\([^)]*\) \{\n(.*?)\n    \}\n/s', $this->controller(), $found), 'The controller has no method '.$name.'.');

        return $found[1];
    }

    private function template(): string
    {
        return (string) file_get_contents(self::root().'/quote/_negotiation.html.twig');
    }

    private function controller(): string
    {
        return (string) file_get_contents(self::root().'/assets/controllers/bo-quote-negotiation_controller.js');
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
