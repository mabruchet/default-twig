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

namespace BackOfficeDefaultTwigBundle\Repository;

use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use Propel\Runtime\Propel;
use Thelia\Domain\Catalog\Stock\OutOfStockPolicy;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\OrderStatus;

/**
 * The combinations the shop sold below zero stock: what it has to order from its suppliers.
 *
 * A combination is listed when its national stock is negative and its sale mode still sells
 * (a combination that refuses the sale never goes below zero). What is to order is the stock
 * it lacks, read on the stock itself and never on the sum of the preorder lines: the lines
 * were sold above the physical stock one after the other, and the first of them may have
 * been served from what was left. The own stock of a sale unit is not counted.
 */
final readonly class PreorderTrackingRepository
{
    /** The orders that still hold their units: neither shipped, nor canceled, nor refunded. */
    private const OPEN_ORDER = 'CASE WHEN `order_status`.`protected_status` = 1 THEN `order_status`.`code` ELSE COALESCE(NULLIF(`order_status`.`equivalent_code`, \'\'), `order_status`.`code`) END NOT IN (?, ?, ?)';

    public function __construct(
        private OrderAccess $orderAccess,
    ) {
    }

    /**
     * Soonest restock date first, the combinations without a date last, then the largest
     * shortage first.
     *
     * @return array{rows: list<array{id: int, product_id: int, product_title: string, ref: string, to_order: float, open_orders: int, restock_date: ?string}>, total: int, lastPage: int}
     */
    public function findPaginated(string $locale, int $page, int $perPage): array
    {
        $total = $this->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $lastPage));

        if (0 === $total) {
            return ['rows' => [], 'total' => 0, 'lastPage' => 1];
        }

        $statement = Propel::getConnection()->prepare(
            'SELECT `pse`.`id`, `pse`.`product_id`, COALESCE(NULLIF(`product_i18n`.`title`, \'\'), `product`.`ref`), `pse`.`ref`, -`pse`.`quantity`, '
            .'('.$this->openOrdersSql().') AS `open_orders`, `pse`.`restock_date` '
            .$this->fromAndWhere()
            .'ORDER BY (`pse`.`restock_date` IS NULL) ASC, `pse`.`restock_date` ASC, `pse`.`quantity` ASC, `pse`.`id` ASC '
            .'LIMIT ? OFFSET ?',
        );

        $position = 1;
        foreach ([OrderStatus::CODE_SENT, OrderStatus::CODE_CANCELED, OrderStatus::CODE_REFUNDED] as $closedCode) {
            $statement->bindValue($position++, $closedCode, \PDO::PARAM_STR);
        }
        $statement->bindValue($position++, $locale, \PDO::PARAM_STR);
        $statement->bindValue($position++, OutOfStockPolicy::Refuse->value, \PDO::PARAM_INT);
        $statement->bindValue($position++, $perPage, \PDO::PARAM_INT);
        $statement->bindValue($position, ($page - 1) * $perPage, \PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as [$id, $productId, $title, $ref, $toOrder, $openOrders, $restockDate]) {
            $rows[] = [
                'id' => (int) $id,
                'product_id' => (int) $productId,
                'product_title' => (string) $title,
                'ref' => (string) $ref,
                'to_order' => max(0.0, round((float) $toOrder, 6)),
                'open_orders' => (int) $openOrders,
                'restock_date' => null === $restockDate || '' === $restockDate ? null : (string) $restockDate,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'lastPage' => $lastPage];
    }

    public function count(): int
    {
        $statement = Propel::getConnection()->prepare(
            'SELECT COUNT(*) FROM `'.ProductSaleElementsTableMap::TABLE_NAME.'` AS `pse` '
            .'INNER JOIN `product` ON `product`.`id` = `pse`.`product_id` '
            .$this->where(),
        );
        $statement->bindValue(1, OutOfStockPolicy::Refuse->value, \PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * The orders still open that hold a preorder line of the combination, counted once each.
     * The administrator restricted to some sale units counts those of its units only.
     */
    private function openOrdersSql(): string
    {
        return 'SELECT COUNT(DISTINCT `order_product`.`order_id`) FROM `order_product` '
            .'INNER JOIN `order` ON `order`.`id` = `order_product`.`order_id` '
            .'INNER JOIN `order_status` ON `order_status`.`id` = `order`.`status_id` '
            .'WHERE `order_product`.`product_sale_elements_id` = `pse`.`id` AND `order_product`.`is_preorder` = 1 '
            .'AND '.self::OPEN_ORDER
            .$this->orderAccess->sqlCondition('`order`.`sale_unit_id`');
    }

    private function fromAndWhere(): string
    {
        return 'FROM `'.ProductSaleElementsTableMap::TABLE_NAME.'` AS `pse` '
            .'INNER JOIN `product` ON `product`.`id` = `pse`.`product_id` '
            .'LEFT JOIN `product_i18n` ON `product_i18n`.`id` = `product`.`id` AND `product_i18n`.`locale` = ? '
            .$this->where();
    }

    private function where(): string
    {
        // The combination of a bundle holds no stock of its own.
        $bundles = BundleCore::present() ? 'AND `product`.`is_bundle` = 0 ' : '';

        return 'WHERE `pse`.`quantity` < 0 AND `pse`.`out_of_stock_policy` <> ? '.$bundles;
    }
}
