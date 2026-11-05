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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use Thelia\Core\Event\ProductSaleElement\ProductSaleElementUpdateEvent;
use Thelia\Domain\Catalog\Stock\OutOfStockPolicy;

/**
 * The stock settings of one combination, as a form posted them.
 *
 * A field the form did not send (null) is left alone. A field sent empty clears
 * the setting. A value that is not valid makes the whole submission invalid, and
 * the caller saves nothing for that combination.
 */
final readonly class StockSettingsSubmission
{
    /**
     * @param list<\Closure(ProductSaleElementUpdateEvent): void> $writes        the settings to write; one that is absent is not written
     * @param list<string>                                        $invalidFields
     */
    private function __construct(
        private array $writes,
        public array $invalidFields,
    ) {
    }

    public static function fromInput(mixed $outOfStockPolicy, mixed $preorderLimit, mixed $restockDate, mixed $lowStockThreshold): self
    {
        $writes = [];
        $invalid = [];

        if (null !== $outOfStockPolicy) {
            $policy = self::policy($outOfStockPolicy);
            if (false === $policy) {
                $invalid[] = 'out_of_stock_policy';
            } else {
                $writes[] = static function (ProductSaleElementUpdateEvent $event) use ($policy): void {
                    $event->setOutOfStockPolicy($policy);
                };
            }
        }

        if (null !== $preorderLimit) {
            $limit = self::quantity($preorderLimit);
            if (false === $limit) {
                $invalid[] = 'preorder_limit';
            } else {
                $writes[] = static function (ProductSaleElementUpdateEvent $event) use ($limit): void {
                    $event->setPreorderLimit($limit);
                };
            }
        }

        if (null !== $restockDate) {
            $date = self::date($restockDate);
            if (false === $date) {
                $invalid[] = 'restock_date';
            } else {
                $writes[] = static function (ProductSaleElementUpdateEvent $event) use ($date): void {
                    $event->setRestockDate($date);
                };
            }
        }

        if (null !== $lowStockThreshold) {
            $threshold = self::quantity($lowStockThreshold);
            if (false === $threshold) {
                $invalid[] = 'low_stock_threshold';
            } else {
                $writes[] = static function (ProductSaleElementUpdateEvent $event) use ($threshold): void {
                    $event->setLowStockThreshold($threshold);
                };
            }
        }

        return new self($writes, $invalid);
    }

    public function isValid(): bool
    {
        return [] === $this->invalidFields;
    }

    public function applyTo(ProductSaleElementUpdateEvent $event): void
    {
        if (!$this->isValid()) {
            throw new \LogicException('An invalid submission must not be applied.');
        }

        foreach ($this->writes as $write) {
            $write($event);
        }
    }

    /** Null when the field was sent empty, false when it is not a known mode. */
    private static function policy(mixed $raw): int|false|null
    {
        if (!\is_scalar($raw) || '' === trim((string) $raw)) {
            return null;
        }

        $text = trim((string) $raw);

        return ctype_digit($text) && null !== OutOfStockPolicy::tryFrom((int) $text) ? (int) $text : false;
    }

    /** Null when the field was sent empty, false when it is not a quantity of zero or more. */
    private static function quantity(mixed $raw): float|false|null
    {
        if (!\is_scalar($raw) || '' === trim((string) $raw)) {
            return null;
        }

        $text = trim((string) $raw);

        return is_numeric($text) && is_finite((float) $text) && (float) $text >= 0 ? (float) $text : false;
    }

    /** Null when the field was sent empty, false when it is not a real date (Y-m-d). */
    private static function date(mixed $raw): \DateTimeImmutable|false|null
    {
        if (!\is_scalar($raw) || '' === trim((string) $raw)) {
            return null;
        }

        $text = trim((string) $raw);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return false !== $date && $date->format('Y-m-d') === $text ? $date : false;
    }
}
