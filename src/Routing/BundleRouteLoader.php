<?php

declare(strict_types=1);

namespace Survos\Kit\Routing;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Generic route loader used by HasConfigurableRoutes.
 *
 * Each bundle that uses the trait registers one instance (service id derived
 * from the bundle alias). BundleRouteLoaderCompilerPass chains it into the
 * router.resource stack at compile time.
 *
 * At route-load time the loader:
 *   1. Loads the previous resource (the existing router chain)
 *   2. Scans the bundle's Controller/ directory via the attribute directory loader
 *   3. Applies the configured route prefix
 */
final class BundleRouteLoader
{
    public function __construct(
        private string          $originalResource,
        private string          $controllerDir,
        private string          $routePrefix,
        private LoaderInterface $attributeDirectoryLoader,
    ) {}

    public function __invoke(LoaderInterface $loader, ?string $_env): RouteCollection
    {
        /** @var RouteCollection $collection */
        $collection = $loader->load($this->originalResource);

        if (!\is_dir($this->controllerDir)) {
            return $collection;
        }

        /** @var RouteCollection $bundleRoutes */
        $bundleRoutes = $this->attributeDirectoryLoader->load($this->controllerDir, 'attribute');

        if ($this->routePrefix !== '') {
            $bundleRoutes->addPrefix($this->routePrefix);
        }

        $collection->addCollection($bundleRoutes);

        return $collection;
    }
}
