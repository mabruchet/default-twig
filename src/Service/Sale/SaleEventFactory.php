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

namespace BackOfficeDefaultTwigBundle\Service\Sale;

use BackOfficeDefaultTwigBundle\Repository\SaleRepository;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\Event\Sale\SaleCreateEvent;
use Thelia\Core\Event\Sale\SaleUpdateEvent;
use Thelia\Model\Sale;
use Thelia\Model\SaleCustomer;

final readonly class SaleEventFactory
{
    public function __construct(
        private SaleRepository $sales,
        private SaleCustomerIdsReader $customerIds,
    ) {
    }

    /**
     * @param array<string, mixed> $formData
     */
    public function createEvent(array $formData, string $fallbackLocale): SaleCreateEvent
    {
        return (new SaleCreateEvent())
            ->setLocale((string) ($formData['locale'] ?? $fallbackLocale))
            ->setTitle((string) ($formData['title'] ?? ''))
            ->setSaleLabel((string) ($formData['label'] ?? ''));
    }

    /**
     * @param array<string, mixed> $formData
     */
    public function updateEvent(int $saleId, array $formData, Request $request, string $fallbackLocale): SaleUpdateEvent
    {
        $countdownMode = (int) ($formData['countdown_mode'] ?? Sale::COUNTDOWN_MODE_NONE);

        $event = new SaleUpdateEvent($saleId);
        $event
            ->setStartDate($this->stringOrNull($formData['start_date'] ?? null))
            ->setEndDate($this->stringOrNull($formData['end_date'] ?? null))
            ->setActive((bool) ($formData['active'] ?? false))
            ->setDisplayInitialPrice((bool) ($formData['display_initial_price'] ?? false))
            ->setPriceOffsetType((int) ($formData['price_offset_type'] ?? Sale::OFFSET_TYPE_PERCENTAGE))
            ->setPriceOffsets($this->priceOffsets($request))
            ->setProducts($this->products($request))
            ->setProductAttributes($this->productAttributes($request))
            ->setLocale((string) ($formData['locale'] ?? $fallbackLocale))
            ->setTitle((string) ($formData['title'] ?? ''))
            ->setSaleLabel((string) ($formData['label'] ?? ''))
            ->setChapo((string) ($formData['chapo'] ?? ''))
            ->setDescription((string) ($formData['description'] ?? ''))
            ->setPostscriptum((string) ($formData['postscriptum'] ?? ''))
            ->setCountdownMode($countdownMode)
            // Only one mode counts hours: any other one clears them, so a stale threshold
            // cannot come back when the mode is switched again.
            ->setCountdownLeadHours(
                $countdownMode === Sale::COUNTDOWN_MODE_LEAD_HOURS
                    ? $this->intOrNull($formData['countdown_lead_hours'] ?? null)
                    : null,
            );

        $this->applyAudience($event, $saleId, $formData, $request);

        return $event;
    }

    /**
     * Who the sale is for, either as posted or - when the audience was not part of the
     * form because the admin may not view customers - as currently stored. The core
     * rewrites the whole targeting on every update, so sending an empty selection here
     * would silently un-reserve the sale.
     *
     * @param array<string, mixed> $formData
     */
    private function applyAudience(SaleUpdateEvent $event, int $saleId, array $formData, Request $request): void
    {
        if (!\array_key_exists('audience_mode', $formData)) {
            $sale = $this->sales->findById($saleId);
            $event
                ->setAudienceMode((int) ($sale?->getAudienceMode() ?? Sale::AUDIENCE_MODE_PUBLIC))
                ->setHideProducts((bool) $sale?->getHideProducts())
                ->setCustomerIds($sale === null ? [] : $this->storedCustomerIds($sale));

            return;
        }

        $audienceMode = (int) $formData['audience_mode'];
        $event
            ->setAudienceMode($audienceMode)
            ->setHideProducts((bool) ($formData['hide_products'] ?? false))
            ->setCustomerIds(
                $audienceMode === Sale::AUDIENCE_MODE_CUSTOMERS ? $this->customerIds->fromRequest($request) : [],
            );
    }

    /**
     * @return list<int>
     */
    private function storedCustomerIds(Sale $sale): array
    {
        $ids = [];
        foreach ($sale->getSaleCustomers() as $saleCustomer) {
            \assert($saleCustomer instanceof SaleCustomer);
            $ids[] = (int) $saleCustomer->getCustomerId();
        }

        return $ids;
    }

    /**
     * @return array<int, float>
     */
    private function priceOffsets(Request $request): array
    {
        $offsets = [];
        foreach ((array) $request->request->all('price_offset') as $currencyId => $offset) {
            $offsets[(int) $currencyId] = (float) $offset;
        }

        return $offsets;
    }

    /**
     * @return list<int>
     */
    private function products(Request $request): array
    {
        return array_values(array_filter(array_map('intval', (array) $request->request->all('products'))));
    }

    /**
     * Accepts `product_attributes[productId] = [avId, ...]` or the legacy
     * Smarty payload `product_attributes[productId] = "av1,av2"`.
     *
     * @return array<int, list<int>>
     */
    private function productAttributes(Request $request): array
    {
        $raw = (array) $request->request->all('product_attributes');
        $output = [];
        foreach ($raw as $productId => $avs) {
            $productId = (int) $productId;
            if ($productId < 1) {
                continue;
            }
            $list = \is_array($avs) ? $avs : explode(',', (string) $avs);
            $ids = array_values(array_filter(array_map('intval', $list)));
            if ($ids !== []) {
                $output[$productId] = $ids;
            }
        }

        return $output;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }
        $cast = trim((string) $value);

        return $cast === '' ? null : $cast;
    }
}
