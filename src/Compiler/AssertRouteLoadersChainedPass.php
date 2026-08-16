<?php

declare(strict_types=1);

namespace Survos\Kit\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Dev-only guard against a bundle that registers a route loader and never chains it.
 *
 * HasConfigurableRoutes has two halves that must both happen: the loader
 * service is registered while the extension loads, and a
 * BundleRouteLoaderCompilerPass is added during build() to splice that loader
 * into the router.resource chain. Doing the first without the second produces
 * no error anywhere — the service exists, is tagged routing.route_loader, is
 * injectable, passes lint:container — and the bundle's routes simply do not
 * exist. debug:router does not list them and gives no hint why. That cost real
 * time to find in survos/elastic-bundle (see survos/mono#43).
 *
 * BundleRouteLoaderCompilerPass clears the UNCHAINED_TAG from every loader it
 * processes. This pass runs after all of them and reports whatever is left.
 *
 * Registered by SurvosKitBundle in debug only: it is a bundle-authoring
 * mistake, so it should stop a developer or CI rather than a production deploy
 * of an app that pins an older, still-broken bundle.
 */
final class AssertRouteLoadersChainedPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $unchained = array_keys($container->findTaggedServiceIds(BundleRouteLoaderCompilerPass::UNCHAINED_TAG));
        if ([] === $unchained) {
            return;
        }

        throw new \LogicException(sprintf(
            'These bundle route loaders were registered but never chained into the router, '
            . 'so their bundles serve no routes at all: %s. '
            . 'Each one\'s bundle called registerRouteLoader() without ever adding its '
            . 'BundleRouteLoaderCompilerPass. Fix by letting AbstractSurvosBundle drive the '
            . 'wiring — extend it, and if you override build(), call parent::build($container). '
            . 'A bundle that extends AbstractBundle directly must still call '
            . '$this->addRouteLoaderCompilerPass($container) in build() itself.',
            implode(', ', $unchained),
        ));
    }
}
