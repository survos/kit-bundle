<?php

declare(strict_types=1);

namespace Survos\Kit\Traits;

use Survos\Kit\Compiler\BundleRouteLoaderCompilerPass;
use Survos\Kit\Routing\BundleRouteLoader;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds standardised route auto-registration to an AbstractSurvosBundle subclass.
 *
 * Exposes two app-developer config keys:
 *   routes_enabled  — toggle the bundle's route registration (default true)
 *   route_prefix    — URL prefix applied to all bundle routes
 *
 * Bundles call (in order):
 *   1. addRouteOptions($children, '/default-prefix')  in configure()
 *   2. captureRouteConfig($config)                    at top of loadExtension()
 *   3. registerRouteLoader($builder)                  later in loadExtension()
 *   4. addRouteLoaderCompilerPass($container)          in build()
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

    protected function captureRouteConfig(array $config): void
    {
        $this->routesEnabled  = (bool) ($config['routes_enabled'] ?? true);
        $this->routePrefix    = (string) ($config['route_prefix'] ?? '');
        $this->localePrefixed = (bool) ($config['locale_prefix'] ?? false);
    }

    protected function registerRouteLoader(ContainerBuilder $builder): void
    {
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
            ->setArgument('$localePrefixed',           $this->localePrefixed)
            ->setArgument('$enabledLocales',           '%kernel.enabled_locales%')
            ->setArgument('$defaultLocale',             '%kernel.default_locale%')
            ->addTag('routing.route_loader');
    }

    protected function addRouteLoaderCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new BundleRouteLoaderCompilerPass($this->routeLoaderServiceId()));
    }

    protected function controllerDirectory(): string
    {
        return \dirname((new \ReflectionClass($this))->getFileName()) . '/Controller/';
    }

    protected function routeLoaderServiceId(): string
    {
        return $this->getContainerExtension()->getAlias() . '.route_loader';
    }
}
