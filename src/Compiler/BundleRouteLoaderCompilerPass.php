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
 *
 * When the bundle's loader was never registered (routes_enabled: false, or no
 * Controller/ dir), this pass instead strips the `routing.controller` /
 * `controller.service_arguments` tags from the bundle's own controller
 * services. Those tags are what Symfony's generic `routing.controllers`
 * special resource (`resource: routing.controllers` in an app's routes.yaml,
 * consumed by RoutingControllerPass/AttributeServicesLoader) uses to
 * independently rediscover #[Route] attributes project-wide — a second,
 * separate discovery path that otherwise bypasses routes_enabled entirely.
 * Must run BEFORE RoutingControllerPass (framework-bundle, priority 0), see
 * the explicit priority passed in HasConfigurableRoutes::addRouteLoaderCompilerPass().
 */
final class BundleRouteLoaderCompilerPass implements CompilerPassInterface
{
    /**
     * Applied by HasConfigurableRoutes::registerRouteLoader(), removed here.
     * A definition still carrying it after every instance of this pass has run
     * was never chained into router.resource — see AssertRouteLoadersChainedPass.
     */
    public const UNCHAINED_TAG = 'survos_kit.unchained_route_loader';

    public function __construct(
        private readonly string $loaderServiceId,
        private readonly string $controllerNamespace,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition($this->loaderServiceId)) {
            $this->stripRoutingTags($container);

            return;
        }

        // Clear the marker as soon as we have SEEN the definition, not only on
        // the success path below: the remaining early returns are legitimate
        // "this app's router isn't service-resourced" cases, not wiring bugs,
        // and flagging them would make the assertion cry wolf.
        $container->getDefinition($this->loaderServiceId)->clearTag(self::UNCHAINED_TAG);

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

    private function stripRoutingTags(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            if (!\is_string($class) || !\str_starts_with($class, $this->controllerNamespace)) {
                continue;
            }

            $definition->clearTag('routing.controller');
            $definition->clearTag('controller.service_arguments');
        }
    }
}
