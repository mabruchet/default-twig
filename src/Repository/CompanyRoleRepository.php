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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Domain\Company\CompanyRoleCode;
use Thelia\Model\CompanyCustomerQuery;
use Thelia\Model\CompanyRole;
use Thelia\Model\CompanyRoleI18nQuery;
use Thelia\Model\CompanyRoleQuery;

/**
 * Reads of the buyer roles: the ones every company shares and the ones a company made for
 * itself, each with its title in the language asked for and the number of buyers who hold it.
 */
final readonly class CompanyRoleRepository
{
    private const FALLBACK_LOCALE = 'en_US';

    /** @var list<string> the roles the shop installs, which the shop looks up by their code */
    public const SHIPPED_CODES = [CompanyRoleCode::ADMINISTRATOR, CompanyRoleCode::BUYER, CompanyRoleCode::APPROVER];

    public function find(int $roleId): ?CompanyRole
    {
        return $roleId > 0 ? CompanyRoleQuery::create()->findPk($roleId) : null;
    }

    public function isShipped(CompanyRole $role): bool
    {
        return null === $role->getCompanyId() && \in_array($role->getCode(), self::SHIPPED_CODES, true);
    }

    /**
     * The roles a company may give: the shared ones first, then its own.
     *
     * @return list<array{id: int, code: string, companyId: int|null, title: string, permissions: list<string>, shipped: bool, buyers: int}>
     */
    public function availableTo(int $companyId, string $locale): array
    {
        return $this->rows(
            CompanyRoleQuery::create()
                ->condition('shared', 'company_role.company_id IS NULL')
                ->condition('own', 'company_role.company_id = ?', $companyId, \PDO::PARAM_INT)
                ->where(['shared', 'own'], Criteria::LOGICAL_OR),
            $locale,
        );
    }

    /**
     * @return list<array{id: int, code: string, companyId: int|null, title: string, permissions: list<string>, shipped: bool, buyers: int}>
     */
    public function shared(string $locale): array
    {
        return $this->rows(CompanyRoleQuery::create()->filterByCompanyId(null, Criteria::ISNULL), $locale);
    }

    /**
     * @return array<string, string> the title of a role in each language it has one in
     */
    public function titles(int $roleId): array
    {
        $titles = [];
        $rows = CompanyRoleI18nQuery::create()->filterById($roleId)->select(['Locale', 'Title'])->find();

        foreach ($rows as $row) {
            $titles[(string) $row['Locale']] = (string) $row['Title'];
        }

        return $titles;
    }

    public function countBuyers(int $roleId): int
    {
        return CompanyCustomerQuery::create()->filterByCompanyRoleId($roleId)->count();
    }

    public function codeUsedByAnyCompany(string $code): bool
    {
        return CompanyRoleQuery::create()->filterByCode($code)->filterByCompanyId(null, Criteria::ISNOTNULL)->exists();
    }

    public function sharedCodeExists(string $code): bool
    {
        return CompanyRoleQuery::create()->filterByCode($code)->filterByCompanyId(null, Criteria::ISNULL)->exists();
    }

    public function ownedCodeExists(string $code, int $companyId): bool
    {
        return CompanyRoleQuery::create()->filterByCode($code)->filterByCompanyId($companyId)->exists();
    }

    /**
     * @return list<array{id: int, code: string, companyId: int|null, title: string, permissions: list<string>, shipped: bool, buyers: int}>
     */
    private function rows(CompanyRoleQuery $query, string $locale): array
    {
        $roles = $query->orderByCompanyId()->orderById()->find();

        $ids = [];
        foreach ($roles as $role) {
            $ids[] = (int) $role->getId();
        }

        $titles = $this->localizedTitles($ids, $locale);
        $buyers = $this->buyerCounts($ids);

        $rows = [];
        foreach ($roles as $role) {
            $id = (int) $role->getId();
            $rows[] = [
                'id' => $id,
                'code' => (string) $role->getCode(),
                'companyId' => null === $role->getCompanyId() ? null : (int) $role->getCompanyId(),
                'title' => $titles[$id] ?? (string) $role->getCode(),
                'permissions' => $role->getPermissionList(),
                'shipped' => $this->isShipped($role),
                'buyers' => $buyers[$id] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $roleIds
     *
     * @return array<int, string> the title in the language, else in English, else none
     */
    private function localizedTitles(array $roleIds, string $locale): array
    {
        if ([] === $roleIds) {
            return [];
        }

        $found = [];
        $rows = CompanyRoleI18nQuery::create()
            ->filterById($roleIds, Criteria::IN)
            ->filterByLocale([$locale, self::FALLBACK_LOCALE], Criteria::IN)
            ->select(['Id', 'Locale', 'Title'])
            ->find();

        foreach ($rows as $row) {
            $title = trim((string) $row['Title']);
            if ('' === $title) {
                continue;
            }

            $id = (int) $row['Id'];
            if ($locale === $row['Locale'] || !isset($found[$id])) {
                $found[$id] = $title;
            }
        }

        return $found;
    }

    /**
     * @param list<int> $roleIds
     *
     * @return array<int, int>
     */
    private function buyerCounts(array $roleIds): array
    {
        if ([] === $roleIds) {
            return [];
        }

        $counts = [];
        $rows = CompanyCustomerQuery::create()
            ->filterByCompanyRoleId($roleIds, Criteria::IN)
            ->groupByCompanyRoleId()
            ->withColumn('COUNT(*)', 'buyer_count')
            ->select(['CompanyRoleId', 'buyer_count'])
            ->find();

        foreach ($rows as $row) {
            $counts[(int) $row['CompanyRoleId']] = (int) $row['buyer_count'];
        }

        return $counts;
    }
}
