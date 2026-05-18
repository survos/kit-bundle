<?php

declare(strict_types=1);

namespace Survos\Kit\Traits;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Auto-registers a bundle's src/Entity/ directory with Doctrine ORM.
 *
 * Mix this into any AbstractSurvosBundle subclass that owns Doctrine entities.
 * AbstractSurvosBundle::prependExtension() detects the trait and calls
 * prependDoctrineMapping() automatically — no manual wiring needed.
 *
 * Usage:
 *
 *     class SurvosMyBundle extends AbstractSurvosBundle
 *     {
 *         use HasDoctrineEntities;
 *
 *         // Override entityNamespace() only when entities are not in src/Entity.
 *     }
 *
 * Override doctrineAlias() when the default (class name minus 'Bundle') is wrong.
 * Override doctrineMappingName() when more than one entity set must be registered.
 */
trait HasDoctrineEntities
{
    protected function entityNamespace(): string
    {
        return (new \ReflectionClass($this))->getNamespaceName() . '\\Entity';
    }

    protected function doctrineAlias(): string
    {
        $shortName = (new \ReflectionClass($this))->getShortName();

        return preg_replace('/Bundle$/', '', $shortName) ?? $shortName;
    }

    protected function doctrineMappingName(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    protected function prependDoctrineMapping(ContainerBuilder $builder): void
    {
        $dir = $this->getPath() . '/src/Entity';
        if (!is_dir($dir)) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    $this->doctrineMappingName() => [
                        'is_bundle' => false,
                        'type'      => 'attribute',
                        'dir'       => $dir,
                        'prefix'    => $this->entityNamespace(),
                        'alias'     => $this->doctrineAlias(),
                    ],
                ],
            ],
        ]);
    }
}
