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

namespace BackOfficeDefaultTwigBundle\Service\Bundle;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Thelia\Domain\Catalog\Bundle\BundleAvailability;
use Thelia\Domain\Catalog\Bundle\BundleCatalogState;
use Thelia\Domain\Catalog\Bundle\BundleComponentReader;
use Thelia\Domain\Catalog\Bundle\BundleItemRepository;
use Thelia\Domain\Catalog\Bundle\BundlePriceCalculator;

/**
 * The door to the product bundles of the core.
 *
 * The theme installs on a core that may predate them: every screen, route and query of the
 * feature asks this one question first, read on a class shipped with the feature. The core
 * services come through an optional locator so the container still compiles on an older
 * core, where none of these classes exists.
 */
final readonly class BundleCore
{
    /**
     * A string, not `BundleCompositionService::class`: the theme must read the same on a core
     * where the class does not exist.
     */
    public const string MARKER_CLASS = 'Thelia\\Domain\\Catalog\\Bundle\\BundleCompositionService';

    public function __construct(
        #[AutowireLocator([
            'availability' => '?'.BundleAvailability::class,
            'calculator' => '?'.BundlePriceCalculator::class,
            'items' => '?'.BundleItemRepository::class,
            'components' => '?'.BundleComponentReader::class,
            'catalogState' => '?'.BundleCatalogState::class,
        ])]
        private ContainerInterface $services,
        private string $markerClass = self::MARKER_CLASS,
    ) {
    }

    /**
     * For what is built without the container (a form type, a filter object).
     */
    public static function present(): bool
    {
        return class_exists(self::MARKER_CLASS);
    }

    public function isAvailable(): bool
    {
        return class_exists($this->markerClass);
    }

    public function availability(): BundleAvailability
    {
        return $this->services->get('availability');
    }

    public function calculator(): BundlePriceCalculator
    {
        return $this->services->get('calculator');
    }

    public function items(): BundleItemRepository
    {
        return $this->services->get('items');
    }

    public function catalogState(): BundleCatalogState
    {
        return $this->services->get('catalogState');
    }

    public function components(): BundleComponentReader
    {
        return $this->services->get('components');
    }
}
