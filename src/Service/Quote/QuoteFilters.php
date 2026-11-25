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

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Domain\Quote\Enum\QuoteRevisionState;
use Thelia\Domain\Quote\Enum\QuoteState;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\Map\QuoteRevisionTableMap;
use Thelia\Model\Map\QuoteTableMap;
use Thelia\Model\QuoteQuery;

/**
 * The filters of the list of quotes: parsing, URL serialisation and the SQL they come to live
 * here, so that the controller stays thin.
 *
 * The state is the one the buyer sees: a quote that waits for the buyer past its last valid day
 * is expired whatever is written, so the filter on the state reads the day as the screen does.
 */
final readonly class QuoteFilters
{
    public const DEFAULT_SORT = 'created_at';
    public const DEFAULT_DIRECTION = 'desc';
    public const ALLOWED_DIRECTIONS = ['asc', 'desc'];
    public const ALLOWED_SORTS = ['ref', 'state', 'total', 'valid_until', 'created_at'];

    public function __construct(
        public ?QuoteState $state = null,
        public ?int $companyId = null,
        public string $search = '',
        public ?float $minTotal = null,
        public ?float $maxTotal = null,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validTo = null,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;

        $sort = (string) $query->get('order', self::DEFAULT_SORT);
        if (!\in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = strtolower((string) $query->get('direction', self::DEFAULT_DIRECTION));
        if (!\in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = self::DEFAULT_DIRECTION;
        }

        $minTotal = self::parseAmount((string) $query->get('min_total', ''));
        $maxTotal = self::parseAmount((string) $query->get('max_total', ''));
        if (null !== $minTotal && null !== $maxTotal && $minTotal > $maxTotal) {
            [$minTotal, $maxTotal] = [$maxTotal, $minTotal];
        }

        $validFrom = self::parseDate((string) $query->get('valid_from', ''));
        $validTo = self::parseDate((string) $query->get('valid_to', ''));
        if (null !== $validFrom && null !== $validTo && $validFrom > $validTo) {
            [$validFrom, $validTo] = [$validTo, $validFrom];
        }

        $companyId = (int) $query->get('company_id', 0);

        return new self(
            state: QuoteState::tryFrom((string) $query->get('state', '')),
            companyId: $companyId > 0 ? $companyId : null,
            search: trim((string) $query->get('q', '')),
            minTotal: $minTotal,
            maxTotal: $maxTotal,
            validFrom: $validFrom,
            validTo: $validTo,
            sort: $sort,
            direction: $direction,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->state
            && null === $this->companyId
            && '' === $this->search
            && null === $this->minTotal
            && null === $this->maxTotal
            && null === $this->validFrom
            && null === $this->validTo;
    }

    /**
     * @return array<string, scalar>
     */
    public function toQueryParams(): array
    {
        $params = [];

        if (null !== $this->state) {
            $params['state'] = $this->state->value;
        }
        if (null !== $this->companyId) {
            $params['company_id'] = $this->companyId;
        }
        if ('' !== $this->search) {
            $params['q'] = $this->search;
        }
        if (null !== $this->minTotal) {
            $params['min_total'] = $this->minTotal;
        }
        if (null !== $this->maxTotal) {
            $params['max_total'] = $this->maxTotal;
        }
        if (null !== $this->validFrom) {
            $params['valid_from'] = $this->validFrom->format('Y-m-d');
        }
        if (null !== $this->validTo) {
            $params['valid_to'] = $this->validTo->format('Y-m-d');
        }
        if (self::DEFAULT_SORT !== $this->sort) {
            $params['order'] = $this->sort;
        }
        if (self::DEFAULT_DIRECTION !== $this->direction) {
            $params['direction'] = $this->direction;
        }

        return $params;
    }

    /**
     * Applies the filters to a query that joins the customer and the current revision of the
     * quote, the revision under the `QuoteRevision` name.
     */
    public function applyTo(QuoteQuery $query, \DateTimeImmutable $today): QuoteQuery
    {
        $day = $today->format('Y-m-d');

        if (null !== $this->state) {
            $this->applyState($query, $this->state, $day);
        }

        if (null !== $this->companyId) {
            $query->filterByCompanyId($this->companyId);
        }

        if ('' !== $this->search) {
            $needle = '%'.self::escapeLike($this->search).'%';

            // The OR cluster is one AND clause of its own, or it would swallow the other filters.
            $query
                ->condition('search_ref', QuoteTableMap::COL_REF.' LIKE ?', $needle, \PDO::PARAM_STR)
                ->condition('search_customer_ref', CustomerTableMap::COL_REF.' LIKE ?', $needle, \PDO::PARAM_STR)
                ->condition('search_email', CustomerTableMap::COL_EMAIL.' LIKE ?', $needle, \PDO::PARAM_STR)
                ->condition('search_firstname', CustomerTableMap::COL_FIRSTNAME.' LIKE ?', $needle, \PDO::PARAM_STR)
                ->condition('search_lastname', CustomerTableMap::COL_LASTNAME.' LIKE ?', $needle, \PDO::PARAM_STR)
                ->combine(['search_ref', 'search_customer_ref', 'search_email', 'search_firstname', 'search_lastname'], Criteria::LOGICAL_OR);
        }

        // The amount is the one of the revision the quote stands at, once it was sent.
        if (null !== $this->minTotal || null !== $this->maxTotal) {
            $query->where(QuoteRevisionTableMap::COL_STATE.' = ?', QuoteRevisionState::Sent->value, \PDO::PARAM_STR);
        }
        if (null !== $this->minTotal) {
            $query->where(QuoteRevisionTableMap::COL_TOTAL_TAXED.' >= ?', $this->minTotal);
        }
        if (null !== $this->maxTotal) {
            $query->where(QuoteRevisionTableMap::COL_TOTAL_TAXED.' <= ?', $this->maxTotal);
        }

        if (null !== $this->validFrom) {
            $query->where(QuoteTableMap::COL_VALID_UNTIL.' >= ?', $this->validFrom->format('Y-m-d'), \PDO::PARAM_STR);
        }
        if (null !== $this->validTo) {
            $query->where(QuoteTableMap::COL_VALID_UNTIL.' <= ?', $this->validTo->format('Y-m-d'), \PDO::PARAM_STR);
        }

        return $query;
    }

    private function applyState(QuoteQuery $query, QuoteState $state, string $day): void
    {
        $overdue = $query
            ->condition('state_quoted', QuoteTableMap::COL_STATE.' = ?', QuoteState::Quoted->value, \PDO::PARAM_STR)
            ->condition('state_valid_until_set', QuoteTableMap::COL_VALID_UNTIL.' IS NOT NULL')
            ->condition('state_valid_until_past', QuoteTableMap::COL_VALID_UNTIL.' < ?', $day, \PDO::PARAM_STR);

        switch ($state) {
            case QuoteState::Expired:
                $overdue
                    ->condition('state_expired', QuoteTableMap::COL_STATE.' = ?', QuoteState::Expired->value, \PDO::PARAM_STR)
                    ->combine(['state_quoted', 'state_valid_until_set', 'state_valid_until_past'], Criteria::LOGICAL_AND, 'state_overdue')
                    ->where(['state_expired', 'state_overdue'], Criteria::LOGICAL_OR);

                return;
            case QuoteState::Quoted:
                // Still waiting for the buyer: valid today, or valid for ever.
                $overdue
                    ->condition('state_valid_until_open', QuoteTableMap::COL_VALID_UNTIL.' IS NULL')
                    ->condition('state_valid_until_today', QuoteTableMap::COL_VALID_UNTIL.' >= ?', $day, \PDO::PARAM_STR)
                    ->combine(['state_valid_until_open', 'state_valid_until_today'], Criteria::LOGICAL_OR, 'state_valid')
                    ->where(['state_quoted', 'state_valid'], Criteria::LOGICAL_AND);

                return;
            default:
                $query->filterByState($state->value);
        }
    }

    private static function escapeLike(string $text): string
    {
        return addcslashes($text, '\\%_');
    }

    private static function parseAmount(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));

        return '' !== $value && is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false !== $date && $date->format('Y-m-d') === trim($value) ? $date : null;
    }
}
