<?php

declare(strict_types=1);

namespace Survos\Kit;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Base class for bundles that ship reusable frontend assets.
 *
 * This is the direct replacement for the old core AssetMapperBundle: bundles
 * with assets/ normally extend this and define ASSET_PACKAGE.
 */
abstract class AbstractUxBundle extends AbstractSurvosBundle implements CompilerPassInterface
{
    /** Container parameter holding the namespace => [controller ids] registry. */
    public const UX_CONTROLLERS_PARAM = 'survos_kit.ux_controllers';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Keep explicit registration even on Symfony 8.1: the framework
        // auto-registers bundle compiler passes at priority -10000, which is
        // too late for passes that prepare service definitions or Twig globals.
        $container->addCompilerPass($this, PassConfig::TYPE_BEFORE_OPTIMIZATION);

        // Publish this bundle's controllers into the shared registry so the
        // survos_stimulus() Twig helper can validate references against what's
        // actually installed.
        $this->contributeUxControllers($container);

        // Dev-only guard. A UX bundle's Stimulus controllers and importmap entries
        // are auto-registered by Symfony Flex's PackageJsonSynchronizer ONLY when the
        // package's top-level composer "keywords" contains "symfony-ux" (Flex's
        // resolvePackageJson() returns null otherwise, so assets/package.json is never
        // read). Forgetting it — e.g. when adding the first Twig component or Stimulus
        // controller to a bundle — makes the frontend silently not load. Fail loudly
        // here instead, at container-compile time, in debug only.
        if ($container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug')) {
            $this->assertUxConventions();
        }
    }

    public function process(ContainerBuilder $container): void
    {
    }

    /**
     * Merge this bundle's "@survos/{slug}" => [controller ids] into the shared
     * UX_CONTROLLERS_PARAM registry (read from its own assets/package.json). Each
     * bundle's build() runs before the container compiles, so the registry is fully
     * assembled by the time the Twig extension consumes it.
     */
    private function contributeUxControllers(ContainerBuilder $container): void
    {
        $packageJsonPath = $this->bundleRootPath() . '/assets/package.json';
        if (!is_file($packageJsonPath)) {
            return;
        }

        $pkg = json_decode((string) file_get_contents($packageJsonPath), true);
        if (!\is_array($pkg)) {
            return;
        }

        $map = $container->hasParameter(self::UX_CONTROLLERS_PARAM)
            ? (array) $container->getParameter(self::UX_CONTROLLERS_PARAM)
            : [];
        $map[$this->deriveAssetNamespace()] = array_keys($pkg['symfony']['controllers'] ?? []);
        $container->setParameter(self::UX_CONTROLLERS_PARAM, $map);
    }

    /**
     * Dev-only consistency checks for a UX bundle's frontend wiring. Reads the bundle's
     * composer.json + assets/package.json and asserts the two things that, when wrong,
     * make the frontend silently fail to load in consuming apps:
     *
     *   1. composer.json declares the "symfony-ux" keyword (Flex's
     *      PackageJsonSynchronizer::resolvePackageJson() returns null without it, so
     *      assets/package.json — controllers AND importmap — is never read).
     *   2. the derived asset namespace equals "@" + the composer package name
     *      ("@survos/iiif-bundle"), because Flex keys the app's controllers.json by
     *      the composer package. A stripped name ("@survos/iiif") registers
     *      controllers under an identifier nothing asks for.
     *   3. assets/package.json "name" equals that same namespace.
     *
     * Skips silently when a file is missing/unparseable — we only throw on a provable
     * mismatch.
     */
    private function assertUxConventions(): void
    {
        $root = $this->bundleRootPath();

        $composerPath = $root . '/composer.json';
        if (is_file($composerPath) && \is_array($composer = json_decode((string) file_get_contents($composerPath), true))) {
            if (!\in_array('symfony-ux', $composer['keywords'] ?? [], true)) {
                throw new \LogicException(sprintf(
                    '%s extends AbstractUxBundle but its composer.json (%s) is missing the "symfony-ux" keyword. '
                    . 'Symfony Flex only registers a bundle\'s Stimulus controllers and importmap entries when '
                    . '"symfony-ux" is in the top-level composer "keywords"; without it the bundle\'s frontend '
                    . '(controllers, UX Twig components, importmap) silently never loads in consuming apps. '
                    . 'Add it: "keywords": ["symfony-ux"].',
                    static::class,
                    $composerPath,
                ));
            }

            $composerNamespace = '@' . ($composer['name'] ?? '');
            if (isset($composer['name']) && $composerNamespace !== $this->deriveAssetNamespace()) {
                throw new \LogicException(sprintf(
                    '%s: the asset namespace is "%s" but the composer package is "%s". The namespace must be '
                    . '"%s" (keep the "-bundle" suffix): Flex keys the app\'s controllers.json by the composer '
                    . 'package, so any other name silently never mounts. Remove the ASSET_PACKAGE const or the '
                    . 'assetNamespace() override.',
                    static::class,
                    $this->deriveAssetNamespace(),
                    $composer['name'],
                    $composerNamespace,
                ));
            }
        }

        $packageJsonPath = $root . '/assets/package.json';
        if (is_file($packageJsonPath) && \is_array($pkg = json_decode((string) file_get_contents($packageJsonPath), true))) {
            $expected = $this->deriveAssetNamespace();
            $actual   = $pkg['name'] ?? null;
            if ($actual !== null && $actual !== $expected) {
                throw new \LogicException(sprintf(
                    '%s: assets/package.json "name" is "%s" but the derived asset namespace is "%s". '
                    . 'These must match, or the app registers the Stimulus controllers under a name templates '
                    . 'do not reference. Rename the package to "%s".',
                    static::class,
                    $actual,
                    $expected,
                    $expected,
                ));
            }
        }
    }

    protected function assetNamespace(): ?string
    {
        return '';
    }
}
