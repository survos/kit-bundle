<?php

declare(strict_types=1);

namespace Survos\Kit;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Base class for bundles that ship reusable frontend assets.
 *
 * This is the direct replacement for the old core AssetMapperBundle: bundles
 * with assets/ normally extend this and define ASSET_PACKAGE.
 */
abstract class AbstractUxBundle extends AbstractSurvosBundle implements CompilerPassInterface
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
    }

    protected function assetNamespace(): ?string
    {
        return '';
    }
}
