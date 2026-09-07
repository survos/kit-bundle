<?php

declare(strict_types=1);

namespace Survos\Kit;

use Survos\Kit\Compiler\AssertRouteLoadersChainedPass;
use Survos\Kit\Twig\SurvosStimulusExtension;
use Survos\Kit\Webhook\RemoteEventTransportMiddleware;
use Survos\Kit\Webhook\VerifyingWebhookTransport;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Webhook\Client\RequestParserInterface;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class SurvosKitBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Priority 32: below the 64 used by every BundleRouteLoaderCompilerPass, so
        // all of them have had their chance to clear the marker tag before we look.
        if ($container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug')) {
            $container->addCompilerPass(new AssertRouteLoadersChainedPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 32);
        }
    }


    /**
     * Webhook wiring lives here, not in each app's services.yaml.
     *
     * kit shipped VerifyingWebhookTransport and RemoteEventTransportMiddleware but registered
     * neither, so every consumer hand-wrote a service definition against bundle internals —
     * mediary declared the transport's five collaborators, harvest declared the middleware's
     * transport map. That is how an app ends up owning a bundle's plumbing, and how a class
     * moving inside the bundle breaks apps with registrations the bundle never knew existed.
     *
     * Apps now supply only what is genuinely theirs: which HTTP client to deliver with, and
     * which remote-event name maps to which transport.
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('webhook')
                    ->addDefaultsIfNotSet()
                    ->children()
                        // Service id, because the client is an app concern: mediary delivers through
                        // a proxy-aware client so `.wip` subscriber URLs resolve locally. Null leaves
                        // the transport unregistered rather than guessing.
                        ->scalarNode('http_client')->defaultNull()->end()
                        ->arrayNode('transports')
                            ->info('remote event name => messenger transport name, e.g. mediary: media_callback')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

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

        // Gated deliberately: ssai requires kit but not symfony/webhook, so registering these
        // unconditionally would break every app that has no use for them.
        $webhook = $config['webhook'] ?? [];

        if (($webhook['transports'] ?? []) !== [] && interface_exists(RequestParserInterface::class)) {
            $container->services()
                ->set('survos_kit.webhook.remote_event_middleware', RemoteEventTransportMiddleware::class)
                ->args([$webhook['transports']])
                ->tag('messenger.middleware');
        }

        if (($webhook['http_client'] ?? null) !== null && interface_exists(RequestParserInterface::class)) {
            $container->services()
                ->set('survos_kit.webhook.verifying_transport', VerifyingWebhookTransport::class)
                ->decorate('webhook.transport')
                ->args([
                    service($webhook['http_client']),
                    service('webhook.headers_configurator'),
                    service('webhook.body_configurator.json'),
                    service('webhook.signer'),
                    service('logger')->nullOnInvalid(),
                ]);
        }
    }
}
