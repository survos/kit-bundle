<?php

declare(strict_types=1);

namespace Survos\Kit;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Base class for Survos bundles. Extend this instead of AbstractBundle.
 *
 * Conventions (override to customise or return null to disable):
 *
 *   templates/     → registered as a Twig namespace via twigNamespace()
 *   assets/        → registered with AssetMapper via assetNamespace() / ASSET_PACKAGE const
 *   src/Entity/    → Doctrine ORM mapping registered when HasDoctrineEntities is mixed in
 *   src/Command/   → all commands auto-registered (parent::loadExtension() scans the dir)
 *   src/Controller/→ controllers auto-registered; routes loaded via HasConfigurableRoutes
 *
 * AbstractBundle::getPath() returns the bundle root (parent of src/), so all
 * path helpers use $this->getPath() — no more dirname(__DIR__) in bundle classes.
 *
 * Typical bundle:
 *
 *     #[RequiredBundle(SurvosKitBundle::class)]
 *     class SurvosMyBundle extends AbstractSurvosBundle
 *     {
 *         use HasDoctrineEntities;
 *         use HasConfigurableRoutes;
 *
 *         // Override entityNamespace() only when entities are not in src/Entity.
 *     }
 */
abstract class AbstractSurvosBundle extends AbstractBundle
{
    /**
     * Twig namespace for templates/.
     * ''    → auto-derive: SurvosMyBundle → 'SurvosMy'
     * null  → skip
     * 'Foo' → explicit
     */
    protected function twigNamespace(): ?string
    {
        return '';
    }

    /**
     * AssetMapper namespace for assets/.
     * ''            → auto-derive from ASSET_PACKAGE const or class name
     * null          → skip (default when ASSET_PACKAGE is not defined)
     * '@survos/foo' → explicit
     */
    protected function assetNamespace(): ?string
    {
        return defined('static::ASSET_PACKAGE') ? '' : null;
    }

    /**
     * Auto-scans conventional src/ directories so their classes are registered
     * without listing each one explicitly. Registration is autoconfigured, so
     * attribute-tagged classes (#[AsCommand], #[AsTwigComponent], …) wire up on
     * their own — drop a class in the directory and it just works.
     *
     *   src/Command/         → console commands
     *   src/Controller/      → controllers
     *   src/Twig/Components/  → Twig/Live components (only when ux-twig-component
     *                          is installed; the files import its attribute)
     *
     * Subclasses that override loadExtension() must call parent::loadExtension().
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $namespace = (new \ReflectionClass($this))->getNamespaceName() . '\\';
        $srcDir    = $this->getPath() . '/src/';

        // path under src/  =>  namespace suffix
        $autoScan = [
            'Command'    => 'Command\\',
            'Controller' => 'Controller\\',
        ];
        if (class_exists(\Symfony\UX\TwigComponent\Attribute\AsTwigComponent::class)) {
            $autoScan['Twig/Components'] = 'Twig\\Components\\';
        }

        foreach ($autoScan as $path => $nsSuffix) {
            $fullDir = $srcDir . $path . '/';
            if (\is_dir($fullDir)) {
                $container->services()
                    ->defaults()->autowire()->autoconfigure()
                    ->load($namespace . $nsSuffix, $fullDir);
            }
        }
    }

    /**
     * Registers twig paths, AssetMapper paths, and — when HasDoctrineEntities is
     * mixed in — Doctrine ORM mappings. Subclasses that need additional prepends
     * should call parent::prependExtension() first.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->prependTwig($builder);
        $this->prependAssets($builder);

        // HasDoctrineEntities hook: called automatically when the trait is used.
        if (method_exists($this, 'prependDoctrineMapping')) {
            $this->prependDoctrineMapping($builder);
        }
    }

    /**
     * Register repository service definitions with the doctrine.repository_service tag.
     */
    protected function registerRepositories(ContainerConfigurator $container, string ...$classes): void
    {
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        foreach ($classes as $class) {
            $services->set($class)->tag('doctrine.repository_service');
        }
    }

    private function prependTwig(ContainerBuilder $builder): void
    {
        $ns = $this->twigNamespace();
        if ($ns === null) {
            return;
        }

        if ($ns === '') {
            $shortName = (new \ReflectionClass($this))->getShortName();
            $ns = preg_replace('/Bundle$/', '', $shortName) ?? $shortName;
        }

        $dir = $this->getPath() . '/templates';
        if (!is_dir($dir)) {
            return;
        }

        $builder->prependExtensionConfig('twig', ['paths' => [$dir => $ns]]);
    }

    private function prependAssets(ContainerBuilder $builder): void
    {
        $ns = $this->assetNamespace();
        if ($ns === null) {
            return;
        }

        if (!interface_exists(AssetMapperInterface::class) || !$builder->hasExtension('framework')) {
            return;
        }

        if ($ns === '') {
            $ns = $this->deriveAssetNamespace();
        }

        $dir = realpath($this->getPath() . '/assets');
        if (!$dir) {
            return;
        }

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => ['paths' => [$dir => $ns]],
        ]);
    }

    private function deriveAssetNamespace(): string
    {
        if (defined('static::ASSET_PACKAGE')) {
            $package = (string) constant('static::ASSET_PACKAGE');
            if (str_starts_with($package, '@')) {
                return $package;
            }
            $package = preg_replace('#^survos/#', '', $package) ?? $package;

            return '@survos/' . trim($package, '/');
        }

        $shortName = (new \ReflectionClass($this))->getShortName();
        $shortName = preg_replace('/^Survos/', '', $shortName) ?? $shortName;
        $shortName = preg_replace('/Bundle$/', '', $shortName) ?? $shortName;
        $slug      = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));

        return '@survos/' . $slug;
    }
}
