<?php

declare(strict_types=1);

namespace Survos\Kit\Webhook;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Server\RequestConfiguratorInterface;
use Symfony\Component\Webhook\Server\TransportInterface;
use Symfony\Component\Webhook\Subscriber;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sending transport that actually looks at the response.
 *
 * Drop-in replacement for `webhook.transport` ({@see \Symfony\Component\Webhook\Server\Transport}),
 * which ends with:
 *
 *     $this->client->request('POST', $subscriber->getUrl(), $options->toArray());
 *
 * and discards the result. Symfony's HttpClient is lazy, so that line does not even guarantee
 * the request completed, and it certainly never raises on a 500. The consequence is that
 * `SendWebhookMessage` can only ever succeed: Messenger's retry policy never fires, nothing
 * lands in the failure transport, and a subscriber that was down for a deploy silently loses
 * every event sent during it. That is the exact failure mediary's `media:replay-webhooks`
 * exists to clean up after — an after-the-fact repair for a delivery guarantee that was never
 * wired.
 *
 * Reading the status code forces the request to complete and turns delivery into something
 * Messenger can manage:
 *
 *   2xx           delivered. (Survos receivers answer 202 for "queued" and 200 for
 *                 "authenticated, but this asset isn't mine" — both are success; see
 *                 {@see AbstractJsonWebhookParser::createRejectedResponse()}.)
 *   3xx, 4xx      PERMANENT. A redirect, a bad signature or an unknown webhook name will not
 *                 fix itself on the third attempt; retrying burns the worker and hides the
 *                 problem. Thrown as UnrecoverableMessageHandlingException so Messenger stops
 *                 immediately and the message goes to the failure transport, where it is
 *                 visible and replayable once the cause is fixed.
 *   5xx, timeout  TRANSIENT. Thrown as a plain exception, so the retry strategy backs off and
 *                 tries again — a subscriber restarting mid-deploy recovers on its own.
 *
 * Registration (in the SENDING app, e.g. mediary or lingua):
 *
 *     Survos\Kit\Webhook\VerifyingWebhookTransport:
 *         decorates: 'webhook.transport'
 *         arguments:
 *             $client: '@survos.webhook.http_client'   # proxy-aware; see docs/webhooks.md
 *             $headers: '@webhook.headers_configurator'
 *             $body: '@webhook.body_configurator.json'
 *             $signer: '@webhook.signer'
 */
final class VerifyingWebhookTransport implements TransportInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly RequestConfiguratorInterface $headers,
        private readonly RequestConfiguratorInterface $body,
        private readonly RequestConfiguratorInterface $signer,
        ?LoggerInterface $logger = null,
        /** Seconds. Deliberately short: a webhook is a notification, not a transaction. */
        private readonly float $timeout = 10.0,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function send(Subscriber $subscriber, RemoteEvent $event): void
    {
        $options = new HttpOptions();

        // Order matters and is not ours to choose: the signer reads the body the body
        // configurator set, and signs over name + id + body. Same sequence as Symfony's
        // Transport, for the same reason.
        $this->headers->configure($event, $subscriber->getSecret(), $options);
        $this->body->configure($event, $subscriber->getSecret(), $options);
        $this->signer->configure($event, $subscriber->getSecret(), $options);

        $options->setTimeout($this->timeout);

        $url = $subscriber->getUrl();

        // Not caught: a TransportExceptionInterface (DNS, refused, timeout) is transient by
        // definition, and letting it out is what makes Messenger retry it.
        $response = $this->client->request('POST', $url, $options->toArray());

        // Forces the lazy request to complete. This single line is the difference between
        // "we sent something" and "they got it".
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $this->logger->info('webhook {event} {id} → {url} [{status}]', [
                'event' => $event->getName(),
                'id' => $event->getId(),
                'url' => $url,
                'status' => $status,
            ]);

            return;
        }

        $message = \sprintf(
            'Webhook %s (%s) to %s rejected with HTTP %d.',
            $event->getName(),
            $event->getId(),
            $url,
            $status,
        );

        if ($status < 500) {
            $this->logger->error($message . ' Not retrying — this is a configuration fault, not an outage.');

            throw new UnrecoverableMessageHandlingException($message);
        }

        $this->logger->warning($message . ' Retrying.');

        throw new \RuntimeException($message);
    }
}
