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

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use BackOfficeDefaultTwigBundle\Repository\CategoryRepository;
use BackOfficeDefaultTwigBundle\Repository\ProductRepository;
use BackOfficeDefaultTwigBundle\Service\Product\ProductImage;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Category;
use Thelia\Model\Product;
use Thelia\Tools\TokenProvider;
use TheliaLibrary\Service\ImagePluginService;
use Thelia\Api\Service\DataAccess\DataAccessService;

final readonly class CategoryListPresenter
{
    private const PRODUCT_PAGE_SIZE = 20;

    public function __construct(
        private CategoryRepository $categories,
        private ProductRepository $products,
        private UrlGeneratorInterface $urls,
        private TokenProvider $tokens,
        private TranslatorInterface $translator,
        private readonly ImagePluginService $imagePluginService,
        private readonly DataAccessService $dataAccessService,
        private readonly ProductImage $productImageService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildListContext(int $parentId, int $productPage, string $locale): array
    {
        $children = $this->categories->findChildrenOrderedByPosition($parentId, $locale);
        $rows = [];
        foreach ($children as $child) {
            $rows[] = $this->categoryToRow($child);
        }

        $current = $this->categories->find($parentId, $locale);
        $breadcrumbPath = $this->categories->buildBreadcrumbPath($current, $locale);

        return [
            'rows' => $rows,
            'parent_id' => $parentId,
            'breadcrumb_path' => $breadcrumbPath,
            'update_position_url' => $this->urls->generate('admin.categories.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
        ] + $this->buildProductSection($parentId, $productPage, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProductSection(int $categoryId, int $page, string $locale): array
    {
        if ($categoryId === 0) {
            return [
                'product_rows' => [],
                'product_total' => 0,
                'product_pages' => 0,
                'product_current_page' => 1,
            ];
        }

        $total = $this->products->countInCategory($categoryId);
        if ($total === 0) {
            return [
                'product_rows' => [],
                'product_total' => 0,
                'product_pages' => 0,
                'product_current_page' => 1,
            ];
        }

        $pages = max(1, (int) ceil($total / self::PRODUCT_PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $products = $this->products->findInCategoryPage(
            $categoryId,
            $locale,
            ($page - 1) * self::PRODUCT_PAGE_SIZE,
            self::PRODUCT_PAGE_SIZE,
        );

        $productRows = [];
        foreach ($products as $product) {
            $productRows[] = $this->productToRow($product);
        }

        return [
            'product_rows' => $productRows,
            'product_total' => $total,
            'product_pages' => $pages,
            'product_current_page' => $page,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryToRow(Category $category): array
    {
        $id = (int) $category->getId();
        $title = (string) $category->getTitle();
        $browseUrl = $this->urls->generate('admin.categories.default', ['category_id' => $id]);

        $childCount = $this->categories->countChildren($id);
        $productCount = $this->products->countInCategory($id);

        return [
            'id' => $id,
            'image' => $this->renderImage($category),
            'title_html' => $this->renderTitleLink($browseUrl, $title, $productCount, $childCount),
            'title' => $title,
            'visible' => (bool) $category->getVisible(),
            'position' => (int) $category->getPosition(),
            'parent' => (int) $category->getParent(),
            'toggle_visible_url' => $this->tokenizedUrl('admin.categories.set-default', ['category_id' => $id]),
            'children_url' => $browseUrl,
            '_actions' => $this->buildCategoryActions($category, $id, $title, $browseUrl),
        ];
    }

    private function renderTitleLink(string $browseUrl, string $title, int $productCount, int $childCount): string
    {
        $products = match (true) {
            $productCount === 0 => $this->translator->trans('no product'),
            $productCount === 1 => $this->translator->trans('%count% product', ['%count%' => $productCount]),
            default => $this->translator->trans('%count% products', ['%count%' => $productCount]),
        };
        $children = match (true) {
            $childCount === 0 => $this->translator->trans('no sub-category'),
            $childCount === 1 => $this->translator->trans('%count% sub-category', ['%count%' => $childCount]),
            default => $this->translator->trans('%count% sub-categories', ['%count%' => $childCount]),
        };

        return \sprintf(
            '<a href="%s" class="text-decoration-none fw-medium">%s</a> <small class="text-muted">(%s, %s)</small>',
            htmlspecialchars($browseUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($products, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($children, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function renderImage(Category $category): string
    {
        $images =  $this->dataAccessService->resources(
            '/api/admin/category_images',
            [
                'category.id' => $category->getId(),
                'visible' => true,
            ]
        );

        if (null === $images || 0 === \count($images)) {
            return '';
        }

        return $this->imagePluginService->getImages([
            'img_id' => $images[0]['id'],
            'source_type' => 'category',
            'filters' => 'default',
            'wrapper' => 'figure',
            'limit' => 1,
            'visible' => true,
            'wrapper_attrs' => [
                'style' => 'width:50px;height:50px;overflow:hidden;display:flex;align-items:center;justify-content:center;margin:-16px;',
            ],
            'img_attrs' => [
                'loading' => 'lazy',
                'width' => '50',
                'height' => '50',
                'style' => 'width:100%;height:100%;object-fit:contain;',
            ],
        ]);
    }

    /**
     * @return list<RowAction>
     */
    private function buildCategoryActions(Category $category, int $id, string $title, string $browseUrl): array
    {
        return [
            new RowAction(
                kind: 'browse',
                label: $this->translator->trans('Browse this category'),
                href: $browseUrl,
                grantedAttribute: AccessManager::VIEW,
                grantedSubject: AdminResources::CATEGORY,
            ),
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit this category'),
                href: $this->urls->generate('admin.categories.update', ['category_id' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: AdminResources::CATEGORY,
            ),
            new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete this category'),
                modalTarget: '#category-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: AdminResources::CATEGORY,
                dataAttributes: [
                    'category-id' => $id,
                    'category-label' => $title,
                ],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productToRow(Product $product): array
    {
        $id = (int) $product->getId();
        $title = (string) $product->getTitle();
        $editUrl = $this->urls->generate('admin.products.update', ['product_id' => $id]);
        $ref = (string) $product->getRef();

        return [
            'id' => $id,
            'image' => $this->productImageService->renderImage($product),
            'ref' => $ref,
            'title' => $title,
            'title_html' => \sprintf(
                '<a href="%s" class="text-decoration-none">%s</a>',
                htmlspecialchars($editUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            ),
            'visible' => (bool) $product->getVisible(),
            'position' => (int) $product->getPosition(),
            'toggle_visible_url' => $this->tokenizedUrl('admin.products.set-default', ['product_id' => $id]),
            '_actions' => [
                new RowAction(
                    kind: 'edit',
                    label: $this->translator->trans('Edit this product'),
                    href: $editUrl,
                    grantedAttribute: AccessManager::UPDATE,
                    grantedSubject: AdminResources::PRODUCT,
                ),
                new RowAction(
                    kind: 'delete',
                    label: $this->translator->trans('Delete this product'),
                    modalTarget: '#category-product-delete-modal',
                    grantedAttribute: AccessManager::DELETE,
                    grantedSubject: AdminResources::PRODUCT,
                    dataAttributes: [
                        'product-id' => $id,
                        'product-label' => $title,
                    ],
                ),
            ],
        ];
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function tokenizedUrl(string $route, array $parameters): string
    {
        $url = $this->urls->generate($route, $parameters);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'_token='.$this->tokens->assignToken();
    }
}
