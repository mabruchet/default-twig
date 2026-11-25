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

namespace BackOfficeDefaultTwigBundle\Tests\Support;

use Thelia\Model\Company;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\Quote;
use Thelia\Model\QuoteItem;
use Thelia\Model\QuoteItemRequest;
use Thelia\Model\QuoteRevision;

/**
 * Rows of the quote tables written straight to the database, for the screens that read them:
 * the state of the quote is the one the test gives, no transition runs.
 *
 * For a test with a Propel connection.
 */
trait BuildsQuotes
{
    /**
     * @param array{ref?: string, state?: string, validUntil?: string|null, company?: Company|null, total?: string|null, currentRevision?: int, revisionState?: string, buyerComment?: string|null, author?: string, currencyId?: int, merchantNote?: string|null, discount?: string, postage?: string} $options total is the one frozen on the revision the quote stands at
     */
    private function aQuote(Customer $customer, array $options = []): Quote
    {
        $connection = $this->getPropelConnection();
        $number = $options['currentRevision'] ?? 1;

        $quote = (new Quote())
            ->setRef($options['ref'] ?? 'QUO-T'.bin2hex(random_bytes(6)))
            ->setCustomerId((int) $customer->getId())
            ->setCompanyId(isset($options['company']) ? $options['company']->getId() : null)
            ->setState($options['state'] ?? 'quoted')
            ->setCurrentRevision($number)
            ->setCurrencyId($options['currencyId'] ?? (int) Currency::getDefaultCurrency()->getId())
            ->setValidUntil($options['validUntil'] ?? null)
            ->setBuyerComment($options['buyerComment'] ?? null);
        $quote->save($connection);

        for ($revisionNumber = 1; $revisionNumber <= $number; ++$revisionNumber) {
            $isCurrent = $revisionNumber === $number;
            $revision = (new QuoteRevision())
                ->setQuoteId($quote->getId())
                ->setNumber($revisionNumber)
                ->setAuthor($isCurrent && isset($options['author']) ? $options['author'] : (1 === $revisionNumber && 'requested' === ($options['state'] ?? '') ? 'customer' : 'admin'))
                ->setState($isCurrent ? ($options['revisionState'] ?? 'sent') : 'sent')
                ->setMerchantNote($isCurrent ? ($options['merchantNote'] ?? null) : null)
                ->setDiscount($isCurrent ? ($options['discount'] ?? '0') : '0')
                ->setPostage($isCurrent ? ($options['postage'] ?? '0') : '0')
                ->setTotalTaxed($isCurrent ? ($options['total'] ?? null) : '1.000000')
                ->setTotalUntaxed($isCurrent ? ($options['total'] ?? null) : '1.000000');
            $revision->save($connection);
        }

        return $quote;
    }

    private function aRevisionOf(Quote $quote, int $number): QuoteRevision
    {
        return \Thelia\Model\QuoteRevisionQuery::create()->filterByQuoteId($quote->getId())->filterByNumber($number)->findOne($this->getPropelConnection())
            ?? throw new \LogicException('No revision '.$number);
    }

    private function aLine(QuoteRevision $revision, string $title, float $quantity, string $catalogPrice, string $price, ?int $productSaleElementsId = null, int $position = 1): QuoteItem
    {
        $line = (new QuoteItem())
            ->setQuoteRevisionId($revision->getId())
            ->setPosition($position)
            ->setProductSaleElementsId($productSaleElementsId)
            ->setProductRef('PROD-'.$position)
            ->setProductSaleElementsRef('PSE-'.$position)
            ->setTitle($title)
            ->setQuantity($quantity)
            ->setCatalogPrice($catalogPrice)
            ->setPrice($price);
        $line->save($this->getPropelConnection());

        return $line;
    }

    private function aCounterOffer(QuoteItem $line, ?float $quantity, ?string $price): QuoteItemRequest
    {
        $request = (new QuoteItemRequest())->setQuoteItemId($line->getId())->setQuantity($quantity)->setPrice($price);
        $request->save($this->getPropelConnection());

        return $request;
    }
}
