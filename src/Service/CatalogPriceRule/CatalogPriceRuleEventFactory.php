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

namespace BackOfficeDefaultTwigBundle\Service\CatalogPriceRule;

use BackOfficeDefaultTwigBundle\Service\CustomerGroup\PostedCustomerGroupIds;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\Event\CatalogPriceRule\CatalogPriceRuleCreateEvent;
use Thelia\Core\Event\CatalogPriceRule\CatalogPriceRuleUpdateEvent;
use Thelia\Model\CatalogPriceRule;
use Thelia\Model\CatalogPriceRuleCustomer;
use Thelia\Model\CatalogPriceRuleQuery;

/**
 * Builds the core events from what the screens post: the form fields, and the lists
 * the pickers send next to them.
 */
final readonly class CatalogPriceRuleEventFactory
{
    public function __construct(
        private CatalogPriceRuleRequestReader $requestReader,
        private PostedCustomerGroupIds $postedGroupIds,
    ) {
    }

    public function createEvent(array $formData, string $fallbackLocale): CatalogPriceRuleCreateEvent
    {
        return (new CatalogPriceRuleCreateEvent())
            ->setLocale((string) ($formData['locale'] ?? $fallbackLocale))
            ->setTitle((string) ($formData['title'] ?? ''))
            ->setActive(false)
            ->setEffectType(CatalogPriceRule::EFFECT_TYPE_PERCENTAGE)
            ->setPercentageValue(10.0);
    }

    public function updateEvent(int $ruleId, array $formData, Request $request, string $fallbackLocale): CatalogPriceRuleUpdateEvent
    {
        $event = new CatalogPriceRuleUpdateEvent($ruleId);
        $this->fill($event, $formData, $request, $fallbackLocale);
        $this->applyAudience($event, $ruleId, $formData, $request);

        return $event;
    }

    /**
     * The definition as posted, for the preview: what the rule would be if saved now.
     */
    public function definitionForPreview(int $ruleId, array $formData, Request $request, string $fallbackLocale): CatalogPriceRuleCreateEvent
    {
        $event = new CatalogPriceRuleCreateEvent();
        $this->fill($event, $formData, $request, $fallbackLocale);
        $this->applyAudience($event, $ruleId, $formData, $request);

        return $event;
    }

    private function fill(CatalogPriceRuleCreateEvent $event, array $formData, Request $request, string $fallbackLocale): void
    {
        $event
            ->setLocale((string) ($formData['locale'] ?? $fallbackLocale))
            ->setTitle((string) ($formData['title'] ?? ''))
            ->setDescription($this->stringOrNull($formData['description'] ?? null))
            ->setActive((bool) ($formData['active'] ?? false))
            ->setPriority((int) ($formData['priority'] ?? 100))
            ->setStopProcessing((bool) ($formData['stop_processing'] ?? false))
            ->setStartDate($this->dateOrNull($formData['start_date'] ?? null))
            ->setEndDate($this->dateOrNull($formData['end_date'] ?? null))
            ->setEffectType((int) ($formData['effect_type'] ?? CatalogPriceRule::EFFECT_TYPE_PERCENTAGE))
            ->setPercentageValue(is_numeric($formData['percentage_value'] ?? null) ? (float) $formData['percentage_value'] : null)
            ->setEffectValuesByCurrency($this->requestReader->effectValuesFromRequest($request))
            ->setDisplayInitialPrice((bool) ($formData['display_initial_price'] ?? false))
            ->setIncludeSubcategories((bool) ($formData['include_subcategories'] ?? false))
            ->setCriteria($this->requestReader->criteriaFromRequest($request));
    }

    private function applyAudience(CatalogPriceRuleCreateEvent $event, int $ruleId, array $formData, Request $request): void
    {
        // The audience is left out of the form for an admin who may not view customers:
        // the stored targeting is then kept as it is.
        if (!\array_key_exists('audience_mode', $formData)) {
            $rule = CatalogPriceRuleQuery::create()->findPk($ruleId);
            $event
                ->setAudienceMode((int) ($rule?->getAudienceMode() ?? CatalogPriceRule::AUDIENCE_MODE_PUBLIC))
                ->setCustomerIds(null === $rule ? [] : $this->storedCustomerIds($rule))
                ->setCustomerGroupIds(null === $rule ? [] : $rule->getCustomerGroupIds());

            return;
        }

        $audienceMode = (int) $formData['audience_mode'];
        $event
            ->setAudienceMode($audienceMode)
            ->setCustomerIds(
                CatalogPriceRule::AUDIENCE_MODE_CUSTOMERS === $audienceMode ? $this->requestReader->customerIdsFromRequest($request) : [],
            )
            ->setCustomerGroupIds(
                CatalogPriceRule::AUDIENCE_MODE_CUSTOMER_GROUPS === $audienceMode
                    ? $this->postedGroupIds->fromRequest($request, CatalogPriceRuleRequestReader::CUSTOMER_GROUPS_FIELD)
                    : [],
            );
    }

    /**
     * @return list<int>
     */
    private function storedCustomerIds(CatalogPriceRule $rule): array
    {
        $ids = [];

        foreach ($rule->getCatalogPriceRuleCustomers() as $ruleCustomer) {
            \assert($ruleCustomer instanceof CatalogPriceRuleCustomer);
            $ids[] = (int) $ruleCustomer->getCustomerId();
        }

        return $ids;
    }

    private function dateOrNull(mixed $value): ?\DateTimeInterface
    {
        $string = $this->stringOrNull($value);

        if (null === $string) {
            return null;
        }

        try {
            return new \DateTimeImmutable($string);
        } catch (\Exception) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $cast = trim((string) $value);

        return '' === $cast ? null : $cast;
    }
}
