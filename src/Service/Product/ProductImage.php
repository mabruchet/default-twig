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

use Thelia\Model\Product;
use TheliaLibrary\Service\ImagePluginService;
use Thelia\Api\Service\DataAccess\DataAccessService;


final readonly class ProductImage
{

    public function __construct(
        private readonly ImagePluginService $imagePluginService,
        private readonly DataAccessService $dataAccessService,
    ) {
    }

    public function renderImage(Product $product): string
    {
        $imagesPdt = $this->dataAccessService->resources(
            '/api/front/product_images',
            [
                'product.id' => $product->getId(),
                'visible' => true,
            ]
        );

        $defaultPse = $product->getDefaultSaleElements();

        $images = $this->dataAccessService->resources(
            '/api/front/product_sale_elements_product_image',
            [
                'productSaleElementsId' => $defaultPse->getId(),
                'productImageId' => array_map(static fn($img) => $img['id'], $imagesPdt),
                'productSaleElements.product.id' => $product->getId(),
                'visible' => true,
            ]
        );

        $image = $images[0]['productImageId'] ?? null;
        if ($image === null) {
            $image = $imagesPdt[0]['id'] ?? null;

            if ($image === null) {
                return '';
            }
        }

        return $this->imagePluginService->getImages([
            'img_id' => $image,
            'source_type' => 'product',
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
}
