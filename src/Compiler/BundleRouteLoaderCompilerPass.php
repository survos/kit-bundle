<?php

declare(strict_types=1);

namespace Survos\Kit\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Chains a bundle's BundleRouteLoader into the router.resource stack.
 *
 * Each bundle using HasConfigurableRoutes registers one of these passes.
 * The pass reads the current router.resource value, sets it as the loader's
 * $originalResource, then replaces router.resource with the bundle's loader id.
 * Multiple bundles stack cleanly — each captures what the previous set.
 */
final class BundleRouteLoaderCompilerPass implements CompilerPassInterface
{
    public function __construct(private readonly string $loaderServiceId) {}

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition($this->loaderServiceId)) {
            return;
        }

        if (!$container->hasParameter('router.resource')) {
            return;
        }

        $router  = $container->findDefinition('router.default');
        $options = $router->getArgument(2);
        if (!\is_array($options) || ($options['resource_type'] ?? null) !== 'service') {
            return;
        }

        $originalResource = $container->getParameter('router.resource');
        $container->getDefinition($this->loaderServiceId)
            ->setArgument('$originalResource', $originalResource);

        $container->setParameter('router.resource', $this->loaderServiceId);
    }
}
