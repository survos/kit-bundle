<?php

declare(strict_types=1);

namespace Survos\Kit\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Puts the webhook transport middleware on every bus, just before send_message.
 *
 * It used to carry a bare `messenger.middleware` tag, which meant "every bus" until Symfony 8.2
 * began rejecting tags without a `bus` attribute. Editing each bus's `<bus>.middleware` parameter
 * (the list MessengerPass turns into the bus) needs no tag and no app config, and unlike a
 * prepended `framework.messenger.buses.*.middleware` it cannot be overwritten by another bundle.
 * An app that already lists the service on a bus keeps its own position.
 */
final class RemoteEventMiddlewarePass implements CompilerPassInterface
{
    public const SERVICE_ID = 'survos_kit.webhook.remote_event_middleware';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::SERVICE_ID)) {
            return;
        }
        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
            $param = $busId . '.middleware';
            if (!$container->hasParameter($param)) {
                continue;
            }
            $middleware = $container->getParameter($param);
            if (in_array(self::SERVICE_ID, array_column($middleware, 'id'), true)) {
                continue;
            }
            $at = array_search('send_message', array_column($middleware, 'id'), true);
            array_splice($middleware, $at === false ? \count($middleware) : $at, 0, [['id' => self::SERVICE_ID, 'arguments' => []]]);
            $container->setParameter($param, $middleware);
        }
    }
}
