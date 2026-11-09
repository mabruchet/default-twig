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

namespace BackOfficeDefaultTwigBundle\Service\CatalogRestriction;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * What the form of a new assortment rule holds so far, read from the query of the page: the
 * screen is driven by plain GET requests (a search of a customer or of a product reloads it),
 * and only the confirmation writes. A value that is not understood is simply left unset.
 */
final readonly class CatalogRestrictionDraft
{
    public const SCOPE_GROUP = 'group';
    public const SCOPE_CUSTOMER = 'customer';
    public const TARGET_CATEGORY = 'category';
    public const TARGET_BRAND = 'brand';
    public const TARGET_PRODUCT = 'product';
    public const MODE_ALLOW = 'allow';
    public const MODE_DENY = 'deny';

    /**
     * @param list<int> $groupIds
     */
    public function __construct(
        public string $scopeType = self::SCOPE_GROUP,
        public array $groupIds = [],
        public ?int $customerId = null,
        public string $targetType = self::TARGET_CATEGORY,
        public ?int $categoryId = null,
        public ?int $brandId = null,
        public ?int $productId = null,
        public string $mode = self::MODE_DENY,
        public string $customerSearch = '',
        public string $productSearch = '',
    ) {
    }

    public static function fromInput(InputBag $input): self
    {
        $scopeType = (string) $input->get('scope_type', self::SCOPE_GROUP);
        $targetType = (string) $input->get('target_type', self::TARGET_CATEGORY);
        $mode = (string) $input->get('mode', self::MODE_DENY);

        return new self(
            scopeType: \in_array($scopeType, [self::SCOPE_GROUP, self::SCOPE_CUSTOMER], true) ? $scopeType : self::SCOPE_GROUP,
            groupIds: array_values(array_unique(array_filter(array_map('intval', array_filter((array) ($input->all()['group_ids'] ?? []), 'is_scalar')), static fn (int $id): bool => $id > 0))),
            customerId: self::id($input->get('customer_id')),
            targetType: \in_array($targetType, [self::TARGET_CATEGORY, self::TARGET_BRAND, self::TARGET_PRODUCT], true) ? $targetType : self::TARGET_CATEGORY,
            categoryId: self::id($input->get('category_id')),
            brandId: self::id($input->get('brand_id')),
            productId: self::id($input->get('product_id')),
            mode: \in_array($mode, [self::MODE_ALLOW, self::MODE_DENY], true) ? $mode : self::MODE_DENY,
            customerSearch: trim((string) $input->get('customer_q', '')),
            productSearch: trim((string) $input->get('product_q', '')),
        );
    }

    /**
     * The query that brings this draft back, with some of its values replaced (the customer or
     * the product a search result stands for).
     *
     * @param array<string, scalar|null> $replace
     *
     * @return array<string, mixed>
     */
    public function toQuery(array $replace = []): array
    {
        $query = array_filter([
            'scope_type' => $this->scopeType,
            'group_ids' => [] === $this->groupIds ? null : $this->groupIds,
            'customer_id' => $this->customerId,
            'target_type' => $this->targetType,
            'category_id' => $this->categoryId,
            'brand_id' => $this->brandId,
            'product_id' => $this->productId,
            'mode' => $this->mode,
        ], static fn (mixed $value): bool => null !== $value);

        return array_replace($query, $replace);
    }

    /**
     * The id of the target the type names, null when none is chosen.
     */
    public function targetId(): ?int
    {
        return match ($this->targetType) {
            self::TARGET_CATEGORY => $this->categoryId,
            self::TARGET_BRAND => $this->brandId,
            default => $this->productId,
        };
    }

    private static function id(mixed $value): ?int
    {
        $id = \is_scalar($value) ? (int) $value : 0;

        return $id > 0 ? $id : null;
    }
}
