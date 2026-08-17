<?php

declare(strict_types=1);

namespace Survos\Kit\Webhook;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;

/**
 * Gives each webhook its own Messenger transport.
 *
 * ## The problem
 *
 * Messenger routes on the message CLASS, and every inbound webhook — from every sender, of
 * every event type — arrives as the same class:
 *
 *     framework:
 *         messenger:
 *             routing:
 *                 'Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage': async
 *
 * One class, therefore one route, therefore one queue for all of them. That is a real
 * regression for apps whose messenger.yaml deliberately gives each kind of work its own
 * transport so that draining one queue doesn't mean starting another (harvest's `media_callback`
 * exists for exactly that reason). Left alone, "catch up on translations" would also mean
 * "reprocess every image callback".
 *
 * ## The fix
 *
 * `ConsumeRemoteEventMessage` already carries the webhook's name in `getType()` — the `{name}`
 * from `/webhook/{name}`. This middleware turns that back into a routing decision by stamping
 * the envelope before `send_message` middleware reads it.
 *
 * ## When you need it
 *
 * Only when an app consumes MORE THAN ONE webhook and wants them drained independently. With a
 * single webhook, or with several that are all cheap row updates, one shared `webhook` transport
 * is the simpler and recommended default — do not wire this in "just in case".
 *
 * ## Wiring
 *
 *     # config/packages/messenger.yaml
 *     framework:
 *         messenger:
 *             transports:
 *                 media_callback: 'doctrine://default?queue_name=media_callback'
 *                 lingua_callback: 'doctrine://default?queue_name=lingua_callback'
 *             routing:
 *                 # Still required — it is what makes the message async at all. The stamp
 *                 # only redirects a message that is already going somewhere.
 *                 'Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage': media_callback
 *             buses:
 *                 messenger.bus.default:
 *                     middleware:
 *                         - Survos\Kit\Webhook\RemoteEventTransportMiddleware
 *
 *     # config/services.yaml
 *     Survos\Kit\Webhook\RemoteEventTransportMiddleware:
 *         arguments:
 *             $transports:
 *                 mediary: media_callback
 *                 lingua: lingua_callback
 *
 * A type with no entry falls through to whatever `routing` says, so the `routing` line doubles
 * as the default for unlisted webhooks.
 */
final class RemoteEventTransportMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, string|string[]> $transports webhook name => transport name(s)
     */
    public function __construct(
        private readonly array $transports = [],
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $envelope = $this->route($envelope);

        return $stack->next()->handle($envelope, $stack);
    }

    private function route(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof ConsumeRemoteEventMessage) {
            return $envelope;
        }

        // Middleware runs on the way out AND on the way back in. Stamping a message that a
        // worker has just received would be meaningless at best; skip it.
        if (null !== $envelope->last(ReceivedStamp::class)) {
            return $envelope;
        }

        // An explicit stamp from the caller wins — this is a default, not an override.
        if (null !== $envelope->last(TransportNamesStamp::class)) {
            return $envelope;
        }

        $transport = $this->transports[$message->getType()] ?? null;
        if (null === $transport) {
            return $envelope;
        }

        return $envelope->with(new TransportNamesStamp($transport));
    }
}
