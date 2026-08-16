<?php

declare(strict_types=1);

namespace Survos\Kit\Traits;

use Survos\Kit\Compiler\BundleRouteLoaderCompilerPass;
use Survos\Kit\Routing\BundleRouteLoader;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds standardised route auto-registration to an AbstractSurvosBundle subclass.
 *
 * Exposes three app-developer config keys:
 *   routes_enabled  — toggle the bundle's route registration (default true)
 *   route_prefix    — URL prefix applied to all bundle routes
 *   locale_prefix   — prepend {_locale} to that prefix
 *
 * A bundle only has to do ONE thing:
 *
 *     use HasConfigurableRoutes;
 *
 *     public function configure(DefinitionConfigurator $definition): void
 *     {
 *         $this->addRouteOptions($definition->rootNode()->children(), '/my-prefix');
 *     }
 *
 * AbstractSurvosBundle drives the rest — captureRouteConfig() and
 * registerRouteLoader() from loadExtension(), addRouteLoaderCompilerPass()
 * from build(). Declaring the config node stays explicit because the default
 * prefix is the one genuinely bundle-specific decision here; skipping it is
 * detected and reported rather than silently defaulted (see
 * AbstractSurvosBundle::wireConfigurableRoutes()).
 *
 * Bundles written against the older four-step contract, which called all three
 * methods by hand, keep working: every step is idempotent, so the explicit call
 * and the automatic one collapse into a single effect. Those calls are now
 * redundant and can be deleted.
 *
 * Prefer also declaring `implements ConfigurableRoutesInterface` — a trait
 * cannot do it on the trait's behalf, and it is what makes the capability
 * visible to PHPStan and the IDE.
 *
 * App developers disable auto-registration with:
 *   survos_xxx:
 *       routes_enabled: false
 */
trait HasConfigurableRoutes
{
    private bool   $routesEnabled  = true;
    private string $routePrefix    = '';
    private bool   $localePrefixed = false;

    /**
     * Idempotency guards. Each step is driven by AbstractSurvosBundle and may
     * ALSO be called explicitly by a bundle still on the old manual contract;
     * these make the second call a no-op instead of a duplicate registration.
     */
    private bool $routeConfigCaptured    = false;
    private bool $routeLoaderRegistered  = false;
    private bool $routeCompilerPassAdded = false;

    public function routeConfigWasCaptured(): bool
    {
        return $this->routeConfigCaptured;
    }

    public function routeCompilerPassWasAdded(): bool
    {
        return $this->routeCompilerPassAdded;
    }

    protected function addRouteOptions(NodeBuilder $children, string $defaultPrefix, bool $defaultEnabled = true, bool $localePrefixDefault = false): void
    {
        $children
            ->booleanNode('routes_enabled')->defaultValue($defaultEnabled)
                ->info('Set false to manage this bundle\'s routes manually in your app. '
                    . 'Bundles exposing sensitive routes (e.g. running console commands) should default this off.')
            ->end()
            ->scalarNode('route_prefix')->defaultValue($defaultPrefix)
                ->info('URL prefix applied to all routes from this bundle.')
            ->end()
            ->booleanNode('locale_prefix')->defaultValue($localePrefixDefault)
                ->info('Prepend {_locale} (constrained to kernel.enabled_locales) to this bundle\'s route '
                    . 'prefix, e.g. /{_locale}/f instead of /f -- for bundles whose routes are meant to be '
                    . 'shared/bookmarked, so the URL itself carries the locale instead of a query param.')
            ->end()
        ;
    }

    /**
     * First call wins. A second call carrying IDENTICAL values is a no-op —
     * that is the normal case for a bundle that still invokes this by hand as
     * well as having AbstractSurvosBundle invoke it.
     *
     * A second call carrying DIFFERENT values throws, unconditionally and in
     * every environment. Unlike the other checks in this file there is no safe
     * behaviour to fall back to: two different prefixes have been requested for
     * the same bundle, so the URLs the app ends up serving would depend on
     * which call happened to run first. Order-dependent routing that nothing
     * reports is the exact failure this whole mechanism exists to prevent, so
     * it is reported rather than resolved. A bundle that needs a prefix from
     * somewhere other than its own config node should compute it into the
     * `route_prefix` node in configure(), not re-capture here.
     */
    protected function captureRouteConfig(array $config): void
    {
        $routesEnabled  = (bool) ($config['routes_enabled'] ?? true);
        $routePrefix    = (string) ($config['route_prefix'] ?? '');
        $localePrefixed = (bool) ($config['locale_prefix'] ?? false);

        if ($this->routeConfigCaptured) {
            if ($routesEnabled === $this->routesEnabled
                && $routePrefix === $this->routePrefix
                && $localePrefixed === $this->localePrefixed
            ) {
                return;
            }

            throw new \LogicException(sprintf(
                '%s::captureRouteConfig() was called twice with conflicting values: '
                . 'first {routes_enabled: %s, route_prefix: "%s", locale_prefix: %s}, '
                . 'then {routes_enabled: %s, route_prefix: "%s", locale_prefix: %s}. '
                . 'AbstractSurvosBundle::loadExtension() already captures the bundle\'s own '
                . 'config, so an explicit second call is only needed when it would pass '
                . 'something different — and then the routes served depend on call order. '
                . 'Delete the manual captureRouteConfig() call, and make configure() produce '
                . 'the prefix you want in the "route_prefix" node.',
                static::class,
                $this->routesEnabled ? 'true' : 'false',
                $this->routePrefix,
                $this->localePrefixed ? 'true' : 'false',
                $routesEnabled ? 'true' : 'false',
                $routePrefix,
                $localePrefixed ? 'true' : 'false',
            ));
        }

        $this->routesEnabled       = $routesEnabled;
        $this->routePrefix         = $routePrefix;
        $this->localePrefixed      = $localePrefixed;
        $this->routeConfigCaptured = true;
    }

    protected function registerRouteLoader(ContainerBuilder $builder): void
    {
        if ($this->routeLoaderRegistered) {
            return;
        }
        $this->routeLoaderRegistered = true;

        if (!$this->routesEnabled) {
            return;
        }

        $controllerDir = $this->controllerDirectory();
        if (!\is_dir($controllerDir)) {
            return;
        }

        $builder->register($this->routeLoaderServiceId(), BundleRouteLoader::class)
            ->setArgument('$originalResource',         '')
            ->setArgument('$controllerDir',            $controllerDir)
            ->setArgument('$routePrefix',              $this->routePrefix)
            ->setArgument('$attributeDirectoryLoader', new Reference('routing.loader.attribute.directory'))
            // Unchained marker. BundleRouteLoaderCompilerPass removes this tag the
            // moment it sees the definition; anything still carrying it once every
            // such pass has run is a loader that was registered but never chained
            // into router.resource — i.e. a bundle that did step 3 and skipped
            // step 4, whose routes then silently do not exist. See
            // AssertRouteLoadersChainedPass, which turns that into an exception.
            ->addTag(BundleRouteLoaderCompilerPass::UNCHAINED_TAG)
            ->setArgument('$localePrefixed',           $this->localePrefixed)
            ->setArgument('$enabledLocales',           '%kernel.enabled_locales%')
            ->setArgument('$defaultLocale',             '%kernel.default_locale%')
            ->addTag('routing.route_loader');
    }

    protected function addRouteLoaderCompilerPass(ContainerBuilder $container): void
    {
        if ($this->routeCompilerPassAdded) {
            return;
        }
        $this->routeCompilerPassAdded = true;

        // Priority 64 (> 0) so this runs before framework-bundle's
        // RoutingControllerPass (registered at the TYPE_BEFORE_OPTIMIZATION
        // default priority 0) — otherwise a routes_enabled:false bundle's
        // controllers would already have been swept into the
        // routing.controllers auto-discovery list by the time we try to
        // exclude them. See BundleRouteLoaderCompilerPass for why this
        // second discovery path exists at all.
        $container->addCompilerPass(
            new BundleRouteLoaderCompilerPass($this->routeLoaderServiceId(), $this->controllerNamespace()),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            64,
        );
    }

    protected function controllerDirectory(): string
    {
        return \dirname((new \ReflectionClass($this))->getFileName()) . '/Controller/';
    }

    protected function controllerNamespace(): string
    {
        return (new \ReflectionClass($this))->getNamespaceName() . '\\Controller\\';
    }

    protected function routeLoaderServiceId(): string
    {
        return $this->getContainerExtension()->getAlias() . '.route_loader';
    }
}
