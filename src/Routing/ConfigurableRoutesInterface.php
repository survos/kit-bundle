<?php

declare(strict_types=1);

namespace Survos\Kit\Routing;

/**
 * Marks a bundle whose routes are auto-registered and app-configurable.
 *
 * The implementation lives in Survos\Kit\Traits\HasConfigurableRoutes. This
 * interface exists alongside it because a PHP trait cannot implement an
 * interface, and two pieces of kit machinery need a public, statically
 * analysable handle on the capability:
 *
 *   - AbstractSurvosBundle, to decide whether to drive the route wiring.
 *   - BundleRouteLoaderCompilerPass, to assert at compile time that it ran.
 *
 * Declaring `implements ConfigurableRoutesInterface` is OPTIONAL:
 * AbstractSurvosBundle also accepts the bare `use HasConfigurableRoutes;`, so
 * every existing bundle keeps working untouched. It is nonetheless the
 * preferred form, because PHPStan and the IDE can follow `instanceof` but
 * cannot follow trait-name sniffing. New bundles should declare it.
 */
interface ConfigurableRoutesInterface
{
    /**
     * True once captureRouteConfig() has run for this bundle.
     *
     * AbstractSurvosBundle::loadExtension() always calls it, so a false value
     * at container-compile time means loadExtension() was overridden without
     * calling parent::loadExtension() — which silently yields a bundle whose
     * routes are never loaded. BundleRouteLoaderCompilerPass turns that into
     * an exception instead.
     */
    public function routeConfigWasCaptured(): bool;

    /**
     * True once addRouteLoaderCompilerPass() has run for this bundle.
     *
     * Same idea for the other half of the contract: AbstractSurvosBundle::build()
     * always calls it, so a false value once loadExtension() is running means
     * build() was overridden without calling parent::build().
     */
    public function routeCompilerPassWasAdded(): bool;
}
