<?php

declare(strict_types=1);

namespace Survos\Kit;

use Survos\Kit\Compiler\BangTwigNamespacePass;
use Survos\Kit\Routing\ConfigurableRoutesInterface;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;

/**
 * Base class for Survos bundles. Extend this instead of AbstractBundle.
 *
 * Conventions (override to customise or return null to disable):
 *
 *   templates/     → registered as a Twig namespace via twigNamespace()
 *   assets/        → registered with AssetMapper via assetNamespace() / ASSET_PACKAGE const
 *   src/Entity/    → Doctrine ORM mapping registered when HasDoctrineEntities is mixed in
 *   src/Command/   → all commands auto-registered (parent::loadExtension() scans the dir)
 *   src/Controller/→ controllers auto-registered; routes fully wired when
 *                    HasConfigurableRoutes is mixed in — the bundle only declares its
 *                    default prefix in configure(), see wireConfigurableRoutes()
 *
 * AbstractBundle::getPath() returns the bundle root (parent of src/), so all
 * path helpers use $this->getPath() — no more dirname(__DIR__) in bundle classes.
 *
 * REQUIRED Flex marker: every concrete bundle that extends this base MUST carry the
 * literal-text comment shown in the example below, immediately above the class
 * declaration. Symfony Flex auto-registers a `type: symfony-bundle` package in
 * config/bundles.php only when the concrete class file contains the literal string
 * "Symfony\Component\HttpKernel\Bundle\Bundle" or "...\AbstractBundle" — a raw
 * str_contains() on the file bytes, with no autoloading or reflection (see
 * vendor/symfony/flex/src/SymfonyBundle.php::isBundleClass()). Because our bundles
 * extend an intermediate base (and ultimately
 * Symfony\Component\DependencyInjection\Kernel\AbstractBundle, which Flex does not look
 * for), that string is otherwise absent and registration silently fails: `composer req`
 * installs the package but never adds it to config/bundles.php. The comment supplies the
 * bytes Flex greps for. Per-bundle markers are kept to a single line that points back here.
 *
 * Typical bundle:
 *
 *     #[RequiredBundle(SurvosKitBundle::class)]
 *     // Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
 *     class SurvosMyBundle extends AbstractSurvosBundle
 *     {
 *         use HasDoctrineEntities;
 *         use HasConfigurableRoutes;
 *
 *         // Override entityNamespace() only when entities are not in src/Entity.
 *     }
 *
 * Configurable-route methods live in HasConfigurableRoutes and exist exactly
 * when a subclass mixes it in, which is what hasConfigurableRoutes() guards at
 * every call site. Do not advertise them with @method annotations here:
 * Symfony's DebugClassLoader treats inherited magic methods as APIs that each
 * concrete bundle should implement and emits false-positive deprecations.
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
        $srcDir    = $this->bundleRootPath() . '/src/';

        // path under src/  =>  namespace suffix
        $autoScan = [
            'Command'    => 'Command\\',
            'Controller' => 'Controller\\',
            // NOTE: src/Menu is intentionally NOT auto-scanned — some menu subscribers are
            // conditionally registered (e.g. CommandBundleMenuSubscriber only when routes_enabled,
            // since it links to a route that otherwise doesn't exist). Auto-scanning them
            // unconditionally breaks those guards. Register menu subscribers explicitly in each
            // bundle's loadExtension(). (TODO: revisit once subscribers self-guard on route existence.)
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

        $this->wireConfigurableRoutes($config, $builder);
    }

    /**
     * Drives the mechanical half of HasConfigurableRoutes.
     *
     * The trait used to require the bundle to call four of the trait's own
     * methods, spread across configure(), loadExtension() and build(). Steps
     * 2-4 take no bundle-specific decision, so they are done here instead;
     * omitting one of them used to produce a bundle with no routes and no
     * error anywhere (survos/mono#43). Bundles that still call them by hand are
     * unaffected — each step is idempotent.
     *
     * Step 1 (addRouteOptions, in configure()) stays with the bundle: the
     * default prefix is a real decision and cannot be guessed. Skipping it is
     * therefore checked rather than automated.
     */
    private function wireConfigurableRoutes(array $config, ContainerBuilder $builder): void
    {
        if (!$this->hasConfigurableRoutes()) {
            return;
        }

        if ($builder->hasParameter('kernel.debug') && $builder->getParameter('kernel.debug')) {
            $this->assertRouteOptionsDeclared($config);
        }

        $this->captureRouteConfig($config);
        $this->registerRouteLoader($builder);
    }

    /**
     * A bundle that mixes in HasConfigurableRoutes but never calls
     * addRouteOptions() has no routes_enabled / route_prefix / locale_prefix
     * nodes, so every one of them falls back to its hardcoded default: the
     * prefix is always '' and the app has no way to switch the routes off. That
     * is silent — the routes still load, just uncontrollably — which is why it
     * survived unnoticed in survos/iiif-bundle, a bundle exposing a debug
     * controller that ought to be switchable.
     *
     * A bundle that declares the nodes by hand instead of via addRouteOptions()
     * passes: the check is for the resulting config, not the call.
     */
    private function assertRouteOptionsDeclared(array $config): void
    {
        $missing = array_values(array_diff(['routes_enabled', 'route_prefix'], array_keys($config)));
        if ([] === $missing) {
            return;
        }

        throw new \LogicException(sprintf(
            '%s uses HasConfigurableRoutes but its resolved config has no %s key. '
            . 'Declare the route options in configure(), so apps can set the prefix and '
            . 'switch the routes off: '
            . '$this->addRouteOptions($definition->rootNode()->children(), \'/your-prefix\'); '
            . 'Without it the prefix is always empty and routes_enabled: false does nothing.',
            static::class,
            implode(' or ', $missing),
        ));
    }

    /**
     * Accepts either the declared interface or the bare trait.
     *
     * A PHP trait cannot implement an interface on its using class's behalf, so
     * requiring `implements ConfigurableRoutesInterface` would split the opt-in
     * into two steps a bundle could do half of — reintroducing exactly the class
     * of bug this is fixing. `use HasConfigurableRoutes;` is therefore accepted
     * on its own, and the interface is the preferred, statically analysable
     * spelling that bundles can adopt at their own pace.
     */
    private function hasConfigurableRoutes(): bool
    {
        if ($this instanceof ConfigurableRoutesInterface) {
            return true;
        }

        // class_uses() is neither inheritance- nor trait-recursive, and PHP ships
        // no helper that is, so walk both axes: the parent chain, and traits
        // composed into other traits.
        $seen  = [];
        $queue = [];
        for ($class = static::class; false !== $class; $class = get_parent_class($class)) {
            $queue = [...$queue, ...array_values(class_uses($class) ?: [])];
        }

        while ($queue) {
            $trait = array_pop($queue);
            if (isset($seen[$trait])) {
                continue;
            }
            $seen[$trait] = true;

            if (HasConfigurableRoutes::class === $trait) {
                return true;
            }
            $queue = [...$queue, ...array_values(class_uses($trait) ?: [])];
        }

        return false;
    }

    /**
     * Registers the "!"-prefixed bang-bypass Twig namespace (see BangTwigNamespacePass)
     * when twigNamespace() sets an explicit namespace. Subclasses that override
     * build() (e.g. AbstractUxBundle) must call parent::build($container).
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Step 4 of the HasConfigurableRoutes contract, see wireConfigurableRoutes().
        // Idempotent, so bundles still calling it themselves are unaffected.
        if ($this->hasConfigurableRoutes()) {
            $this->addRouteLoaderCompilerPass($container);
        }

        $ns = $this->twigNamespace();
        if ($ns !== null && $ns !== '') {
            $dir = $this->bundleRootPath() . '/templates';
            if (is_dir($dir)) {
                $container->addCompilerPass(new BangTwigNamespacePass($ns, $dir));
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

    protected function bundleRootPath(): string
    {
        $path = $this->getPath();
        if (is_dir($path . '/src')) {
            return $path;
        }

        $classFile = (new \ReflectionClass($this))->getFileName();
        if (false !== $classFile && basename(dirname($classFile)) === 'src') {
            return dirname(dirname($classFile));
        }

        return $path;
    }

    private function prependTwig(ContainerBuilder $builder): void
    {
        $rawNs = $this->twigNamespace();
        if ($rawNs === null) {
            return;
        }

        $shortName = (new \ReflectionClass($this))->getShortName();
        $isExplicit = $rawNs !== '';
        $ns = $isExplicit ? $rawNs : (preg_replace('/Bundle$/', '', $shortName) ?? $shortName);

        $dir = $this->bundleRootPath() . '/templates';
        if (!is_dir($dir)) {
            return;
        }

        $paths = [$dir => $ns];

        // Symfony auto-registers app-override support for its OWN derived namespace
        // (strip "Bundle" from the short class name) via kernel.bundles_metadata —
        // entirely independent of this method. A bundle that sets an explicit
        // twigNamespace() (keeping the "Bundle" suffix, say) sidesteps that
        // derivation and loses override support: templates/bundles/{ShortName}/ is
        // never consulted, so app skin templates placed there silently never
        // render. Replicate it here for the explicit-namespace case only — the
        // default ('') case is already covered by Symfony's own mechanism, so
        // adding it again there would just be a harmless but pointless duplicate.
        // The matching "!"-prefixed bypass namespace (for `{% extends '@!Name/...' %}`
        // from inside the override) needs a compiler pass — see build() below —
        // because it requires the SAME dir under a SECOND namespace, which the
        // "twig.paths" config node can't express (one namespace per path).
        if ($isExplicit && $builder->hasParameter('kernel.project_dir')) {
            $overrideDir = $builder->getParameter('kernel.project_dir') . '/templates/bundles/' . $shortName;
            if (is_dir($overrideDir)) {
                $paths = [$overrideDir => $ns] + $paths;
            }
        }

        $builder->prependExtensionConfig('twig', ['paths' => $paths]);
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

        $dir = realpath($this->bundleRootPath() . '/assets');
        if (!$dir) {
            return;
        }

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => ['paths' => [$dir => $ns]],
        ]);
    }

    protected function deriveAssetNamespace(): string
    {
        if (defined('static::ASSET_PACKAGE')) {
            $package = (string) constant('static::ASSET_PACKAGE');
            if (str_starts_with($package, '@')) {
                return $package;
            }
            $package = preg_replace('#^survos/#', '', $package) ?? $package;

            return '@survos/' . trim($package, '/');
        }

        // Keep the "Bundle" suffix: the asset namespace must equal the composer
        // package name ("@survos/iiif-bundle"), because Symfony UX resolves a
        // controllers.json key by stripping "@" and locating that composer package
        // (UxPackageReader::readPackageMetadata). Stripping "Bundle" here produced
        // "@survos/iiif", which has no composer package and can't be resolved.
        $shortName = (new \ReflectionClass($this))->getShortName();
        $shortName = preg_replace('/^Survos/', '', $shortName) ?? $shortName;
        $slug      = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));

        return '@survos/' . $slug;
    }
}
