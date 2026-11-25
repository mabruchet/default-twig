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

use BackOfficeDefaultTwigBundle\Repository\ModuleRepository;
use BackOfficeDefaultTwigBundle\Repository\QuoteRepository;
use BackOfficeDefaultTwigBundle\Repository\SearchRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Quote\QuoteFacade;
use Thelia\Domain\Quote\QuoteTransitions;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Quote;
use Thelia\Model\QuoteItem;
use Thelia\Model\QuoteItemRequest;
use Thelia\Model\QuoteRevision;
use Thelia\Module\BaseModule;

/**
 * What the screen where the shop prices a quote shows: the revision being written, with one
 * row per line, what the buyer asked for next to what the shop proposes, and the foot of the
 * quote. Whatever the number of
 * lines, the revision costs the same queries: the lines, what was asked on them, and the
 * combinations they name.
 */
final readonly class QuoteNegotiationContext
{
    public function __construct(
        private QuoteRepository $quotes,
        private SearchRepository $search,
        private ModuleRepository $modules,
        private QuoteSheetContext $sheet,
        private QuoteFacade $facade,
        private QuoteTransitions $transitions,
        private QuoteGrantedDiscount $granted,
        private AdminAccessChecker $access,
        private MoneyFormatter $money,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when there is nothing to write: no draft, a quote the
     *                                   rules do not let the shop revise, or an administrator who may not change quotes
     */
    public function forScreen(Quote $quote, string $locale): ?array
    {
        $today = \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
        $mayWrite = $this->access->can(AdminResources::QUOTE, AccessManager::UPDATE)
            && $this->transitions->canBeDrafted($this->facade->effectiveState($quote, $today));
        $working = $mayWrite ? $this->quotes->draftRevision($quote) : null;

        if (!$working instanceof QuoteRevision) {
            return null;
        }

        $currency = (string) CurrencyQuery::create()->findPk($quote->getCurrencyId())?->getCode();
        $currencyCode = '' === $currency ? null : $currency;
        $rows = $this->quotes->lines($working);
        $asked = $this->askedBy($working, $quote, $rows);
        $labels = $this->search->attributeLabels(
            array_values(array_filter(array_map(static fn (array $row): ?int => $row['line']->getProductSaleElementsId(), $rows))),
            $locale,
            $locale,
        );

        $lines = [];
        $gone = 0;
        foreach ($rows as ['line' => $line]) {
            $saleElementId = $line->getProductSaleElementsId();
            $request = $asked[(int) $line->getId()] ?? null;
            $gone += null === $saleElementId ? 1 : 0;

            $lines[] = [
                'id' => (int) $line->getId(),
                'sale_element_id' => $saleElementId,
                'title' => (string) $line->getTitle(),
                'ref' => (string) $line->getProductRef(),
                'sale_element_ref' => (string) $line->getProductSaleElementsRef(),
                'combination' => null === $saleElementId ? '' : ($labels[(int) $saleElementId] ?? ''),
                'catalog_price' => $this->money->format((float) $line->getCatalogPrice(), $locale, $currencyCode),
                'price' => self::amount((string) $line->getPrice()),
                'quantity' => self::quantity((float) $line->getQuantity()),
                'unavailable' => null === $saleElementId,
                'asked_quantity' => null === $request || null === $request->getQuantity() ? null : self::quantity((float) $request->getQuantity()),
                'asked_price' => null === $request || null === $request->getPrice() ? null : $this->money->format((float) $request->getPrice(), $locale, $currencyCode),
            ];
        }

        return [
            'revision' => $working,
            'lines' => $lines,
            'gone_lines' => $gone,
            'has_counter_offer' => [] !== array_filter($lines, static fn (array $line): bool => null !== $line['asked_quantity'] || null !== $line['asked_price']),
            'discount' => self::amount((string) $working->getDiscount()),
            'postage' => self::amount((string) $working->getPostage()),
            'delivery_module_id' => $working->getDeliveryModuleId(),
            'delivery_modules' => $this->modules->findActiveByType(BaseModule::DELIVERY_MODULE_TYPE, $locale),
            'valid_until' => $working->getValidUntil('Y-m-d') ?? '',
            'earliest_valid_until' => \DateTimeImmutable::createFromInterface($this->clock->now())->format('Y-m-d'),
            'merchant_note' => (string) $working->getMerchantNote(),
            'totals' => $this->sheet->formattedTotals($working, $locale, $currencyCode),
            'granted' => $this->granted->of(array_map(static fn (array $row): QuoteItem => $row['line'], $rows), $locale, $currencyCode),
            'currency' => $currencyCode,
            'search_url' => $this->urls->generate('admin.quote.product_search', ['id' => $quote->getId()]),
            'save_url' => $this->urls->generate('admin.quote.save', ['id' => $quote->getId()]),
            'autosave_url' => $this->urls->generate('admin.quote.autosave', ['id' => $quote->getId()]),
            'send_url' => $this->urls->generate('admin.quote.send', ['id' => $quote->getId()]),
        ];
    }

    /**
     * What the buyer asked for, by line of the draft: the requests of the revision the quote
     * stands at, on the line that names the same combination.
     *
     * @param list<array{line: QuoteItem, request: QuoteItemRequest|null}> $rows
     *
     * @return array<int, QuoteItemRequest> by line id
     */
    private function askedBy(QuoteRevision $working, Quote $quote, array $rows): array
    {
        $current = $this->quotes->currentRevision($quote);
        if (!$current instanceof QuoteRevision || $current->getId() === $working->getId()) {
            return [];
        }

        $queues = [];
        foreach ($this->quotes->lines($current) as ['line' => $line, 'request' => $request]) {
            if ($request instanceof QuoteItemRequest && null !== $line->getProductSaleElementsId()) {
                $queues[(int) $line->getProductSaleElementsId()][] = $request;
            }
        }

        $asked = [];
        foreach ($rows as ['line' => $line]) {
            $saleElementId = $line->getProductSaleElementsId();
            if (null !== $saleElementId && [] !== ($queues[(int) $saleElementId] ?? [])) {
                $asked[(int) $line->getId()] = array_shift($queues[(int) $saleElementId]);
            }
        }

        return $asked;
    }

    /**
     * A stored amount as a field holds it: two decimals at least, more when the amount has them.
     */
    public static function amount(string $stored): string
    {
        if (!str_contains($stored, '.')) {
            return $stored.'.00';
        }

        $trimmed = rtrim($stored, '0');
        [$whole, $decimals] = explode('.', $trimmed, 2) + [1 => ''];

        return $whole.'.'.str_pad($decimals, 2, '0');
    }

    public static function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
    }
}
