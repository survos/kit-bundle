<?php

declare(strict_types=1);

namespace Survos\Kit\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers the "!"-prefixed bypass namespace (e.g. "!SurvosFolioBundle") for a
 * bundle's own templates/ dir, so `{% extends '@!Name/...' %}` works from an app
 * override the same way it does for Symfony's auto-derived bundle namespace.
 *
 * Symfony registers this pair itself (TwigExtension::loadTwig() calls addPath()
 * twice: once for the override-aware namespace, once for the "!"-prefixed
 * vendor-only one) — but only for the namespace IT derives from bundle metadata
 * (short class name, "Bundle" suffix stripped). AbstractSurvosBundle::prependTwig()
 * lets a bundle pick an explicit namespace instead (twigNamespace() returning a
 * non-empty string); that namespace never goes through Symfony's own pass, so it
 * gets override support (via the "twig.paths" config node, which prependTwig()
 * handles) but never gets a bang sibling. The "twig.paths" config node can't
 * express this itself: it's a strict one-namespace-per-path map, and the bang
 * sibling needs the SAME directory registered under a SECOND namespace — that
 * requires two addMethodCall('addPath', ...) calls directly on the loader
 * service, which only exists once bundles' load() calls have run, hence a
 * compiler pass rather than prependExtension().
 */
final class BangTwigNamespacePass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $namespace,
        private readonly string $dir,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('twig.loader.native_filesystem')) {
            return;
        }

        $container->getDefinition('twig.loader.native_filesystem')
            ->addMethodCall('addPath', [$this->dir, '!' . $this->namespace]);
    }
}
