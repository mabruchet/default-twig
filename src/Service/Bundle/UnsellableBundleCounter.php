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

use Thelia\Model\ProductQuery;

/**
 * How many online bundles cannot be ordered: one of their required components is not for
 * sale (its combination is deleted, or offline, or its product is) or a component has no
 * stock left. The availability of the core is 0 in both cases, and it is read for every
 * bundle at once: the dashboard asks a fixed number of queries whatever the number of bundles.
 *
 * The low stock threshold of the other products does not apply to a bundle, whose stock is
 * the one of its components: a bundle is either orderable or not.
 */
final readonly class UnsellableBundleCounter
{
    public function __construct(
        private BundleCore $core,
    ) {
    }

    public function count(): int
    {
        // The core knows from its configuration, without a query, whether the shop sells a bundle.
        if (!$this->core->isAvailable() || !$this->core->catalogState()->hasBundles()) {
            return 0;
        }

        /** @var list<int> $bundleIds */
        $bundleIds = array_map(
            'intval',
            ProductQuery::create()->filterByIsBundle(1)->filterByVisible(1)->select(['Id'])->find()->getData(),
        );

        if ([] === $bundleIds) {
            return 0;
        }

        return \count(array_filter(
            $this->core->availability()->forBundles($bundleIds),
            static fn (int $availability): bool => 0 === $availability,
        ));
    }
}
