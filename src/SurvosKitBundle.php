<?php

declare(strict_types=1);

namespace Survos\Kit;

use Survos\Kit\Twig\SurvosStimulusExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosKitBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Ensure the registry parameter exists even when no UX bundle is installed.
        if (!$builder->hasParameter(AbstractUxBundle::UX_CONTROLLERS_PARAM)) {
            $builder->setParameter(AbstractUxBundle::UX_CONTROLLERS_PARAM, []);
        }

        // survos_stimulus() Twig helper. Guard on the Twig class existing rather than
        // $builder->hasExtension('twig'): kit boots early as a required bundle, before
        // TwigBundle's extension is visible to this builder, so hasExtension('twig')
        // is false here. (kit hard-requires symfony/twig-bundle, so the class is present.)
        if (class_exists(\Twig\Extension\AbstractExtension::class)) {
            $container->services()
                ->set('survos_kit.twig.stimulus', SurvosStimulusExtension::class)
                ->args([
                    new Parameter(AbstractUxBundle::UX_CONTROLLERS_PARAM),
                    new Parameter('kernel.debug'),
                ])
                ->tag('twig.extension');
        }
    }
}
