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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerChoiceProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Sale;
use Thelia\Model\SaleCustomer;
use Thelia\Model\SaleProduct;

final readonly class SaleEditContextBuilder
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private SaleProductAttributesProvider $productAttributesProvider,
        private CustomerChoiceProvider $customerChoices,
        private AdminAccessChecker $access,
    ) {
    }

    /**
     * @return array{
     *     form_data: array<string, mixed>,
     *     all_categories: list<array{id: int, title: string}>,
     *     selected_category_ids: list<int>,
     *     selected_products: list<array{id: int, ref: string, title: string}>,
     *     selected_product_attributes: array<int, list<int>>,
     *     currencies: list<array{id: int, code: string, symbol: string, offset: float}>,
     *     products_url: string,
     *     product_attributes_url_template: string,
     *     can_target_customers: bool,
     *     sale_is_reserved: bool,
     *     customer_choices: list<array{id: int, label: string}>,
     *     selected_customer_ids: list<int>,
     *     latest_customers_limit: int
     * }
     */
    public function build(Sale $sale, string $locale): array
    {
        $priceOffsets = $sale->getPriceOffsets();
        $selectedCustomerIds = $this->targetedCustomerIds($sale);
        // Names and e-mail addresses are personal data: an admin who may not view the
        // customer list is not handed one here either.
        $canTargetCustomers = $this->canTargetCustomers();

        $selectedCategoryIds = [];
        $selectedProducts = [];
        foreach ($sale->getSaleProductList() as $saleProduct) {
            \assert($saleProduct instanceof SaleProduct);
            $product = $saleProduct->getProduct();
            if ($product === null) {
                continue;
            }
            $product->setLocale($locale);
            $categoryId = (int) $product->getDefaultCategoryId();
            if (!\in_array($categoryId, $selectedCategoryIds, true)) {
                $selectedCategoryIds[] = $categoryId;
            }
            $selectedProducts[] = [
                'id' => (int) $product->getId(),
                'ref' => (string) $product->getRef(),
                'title' => (string) $product->getTitle(),
            ];
        }

        return [
            'form_data' => [
                'id' => (int) $sale->getId(),
                'locale' => $locale,
                'title' => (string) $sale->getTitle(),
                'label' => (string) $sale->getSaleLabel(),
                'chapo' => (string) $sale->getChapo(),
                'description' => (string) $sale->getDescription(),
                'postscriptum' => (string) $sale->getPostscriptum(),
                'active' => (bool) $sale->getActive(),
                'display_initial_price' => (bool) $sale->getDisplayInitialPrice(),
                'start_date' => $sale->getStartDate('Y-m-d H:i:s'),
                'end_date' => $sale->getEndDate('Y-m-d H:i:s'),
                'price_offset_type' => (int) $sale->getPriceOffsetType(),
                'audience_mode' => (int) $sale->getAudienceMode(),
                'hide_products' => (bool) $sale->getHideProducts(),
                'countdown_mode' => (int) $sale->getCountdownMode(),
                'countdown_lead_hours' => $sale->getCountdownLeadHours(),
            ],
            'all_categories' => $this->categoryChoices($locale),
            'selected_category_ids' => $selectedCategoryIds,
            'selected_products' => $selectedProducts,
            'selected_product_attributes' => $this->productAttributesProvider->selectedForSale($sale),
            'currencies' => $this->currencyOffsets($priceOffsets),
            'products_url' => $this->urls->generate('admin.sale.products-by-categories'),
            'product_attributes_url_template' => $this->urls->generate(
                'admin.sale.product-attributes',
                ['product_id' => 0],
            ),
            'can_target_customers' => $canTargetCustomers,
            // The stored audience, next to the ids it currently names: a reserved sale
            // whose last targeted customer was deleted keeps the mode and loses the
            // audience, and the screen has to be able to say so. Read as its own key
            // because the controller replaces the `sale` entry with the model itself.
            'sale_is_reserved' => $sale->isReserved(),
            'customer_choices' => $canTargetCustomers ? $this->customerChoices->choices($selectedCustomerIds) : [],
            'selected_customer_ids' => $selectedCustomerIds,
            'latest_customers_limit' => CustomerChoiceProvider::LATEST_LIMIT,
        ];
    }

    public function canTargetCustomers(): bool
    {
        return $this->access->canView(AdminResources::CUSTOMER);
    }

    /**
     * @return list<int>
     */
    private function targetedCustomerIds(Sale $sale): array
    {
        $ids = [];
        foreach ($sale->getSaleCustomers() as $saleCustomer) {
            \assert($saleCustomer instanceof SaleCustomer);
            $ids[] = (int) $saleCustomer->getCustomerId();
        }

        return $ids;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function categoryChoices(string $locale): array
    {
        $items = [];
        foreach (CategoryQuery::create()->orderByPosition()->find() as $category) {
            \assert($category instanceof Category);
            $category->setLocale($locale);
            $items[] = ['id' => (int) $category->getId(), 'title' => (string) $category->getTitle()];
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $items;
    }

    /**
     * @param array<int, float|string> $priceOffsets
     *
     * @return list<array{id: int, code: string, symbol: string, offset: float}>
     */
    private function currencyOffsets(array $priceOffsets): array
    {
        $items = [];
        foreach (CurrencyQuery::create()->filterByVisible(1)->orderByPosition()->find() as $currency) {
            \assert($currency instanceof Currency);
            $id = (int) $currency->getId();
            $items[] = [
                'id' => $id,
                'code' => (string) $currency->getCode(),
                'symbol' => (string) $currency->getSymbol(),
                'offset' => (float) ($priceOffsets[$id] ?? 0),
            ];
        }

        return $items;
    }
}
