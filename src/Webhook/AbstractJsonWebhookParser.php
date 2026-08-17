<?php

declare(strict_types=1);

namespace Survos\Kit\Webhook;

use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Base request parser for a webhook sent by another Survos service.
 *
 * The whole receiving convention lives here, so a bundle that accepts a webhook writes one
 * class with one method (`webhookName()`) rather than re-deriving authentication:
 *
 *     final class MediaWebhookRequestParser extends AbstractJsonWebhookParser
 *     {
 *         protected function webhookName(): string { return 'mediary'; }
 *     }
 *
 * and then, in the app:
 *
 *     framework:
 *         webhook:
 *             routing:
 *                 mediary:
 *                     service: Survos\MediaBundle\Webhook\MediaWebhookRequestParser
 *                     secret: '%env(MEDIARY_WEBHOOK_SECRET)%'
 *
 * ## Why this is a base class and not a copied `doParse()`
 *
 * Three things about verification are easy to get subtly wrong, and all three are wrong in a
 * way that either silently accepts forged requests or silently rejects real ones:
 *
 *   1. **Order.** Verify before decoding, never after. An unauthenticated caller should not be
 *      able to reach `json_decode()` at all, and taking the event name from the *payload* while
 *      signing over the *header* value lets the two disagree.
 *   2. **Comparison.** `hash_equals()`, not `===`. See {@see WebhookSignature}.
 *   3. **The empty secret.** `framework.webhook.routing.*.secret` defaults to `''`. A parser
 *      that treats "no secret configured" as "no verification required" turns a missing env var
 *      into an open endpoint — exactly the state `/media/callback` shipped in. This class fails
 *      closed instead, with a 500 that says so.
 *
 * ## What subclasses still decide
 *
 * Only the payload contract. `doParse()` returns a {@see RemoteEvent} carrying the JSON body
 * verbatim; turning that into a domain object belongs in the `#[AsRemoteEventConsumer]`, which
 * is where it can be shared with the synchronous (pull/sync) code path.
 */
abstract class AbstractJsonWebhookParser extends AbstractRequestParser
{
    /**
     * The webhook's name — the `{name}` in `/webhook/{name}`, the key under
     * `framework.webhook.routing`, and the string passed to `#[AsRemoteEventConsumer]`.
     *
     * Name it after the SENDER (`mediary`, `lingua`), not after what the receiver does with it.
     * One sender may grow several event types, and they all arrive on one endpoint under one
     * secret; `RemoteEvent::getName()` is what distinguishes them.
     */
    abstract protected function webhookName(): string;

    /**
     * Where the framework's WebhookController is mounted. Override only if the app imports
     * `@FrameworkBundle/Resources/config/routing/webhook.php` under a different prefix.
     */
    protected function webhookPath(): string
    {
        return '/webhook/' . $this->webhookName();
    }

    protected function signingAlgorithm(): string
    {
        return WebhookSignature::DEFAULT_ALGO;
    }

    /**
     * Event names this receiver understands, or null for "any".
     *
     * An unrecognised name is ACKed and dropped rather than rejected — see doParse(). Declaring
     * the list is still worth it: it keeps a new event type from reaching a consumer written
     * before that type existed.
     *
     * @return string[]|null
     */
    protected function acceptedEvents(): ?array
    {
        return null;
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher(Request::METHOD_POST),
            // Anchored: `/webhook/lingua` must not also match `/webhook/lingua-admin`.
            new PathRequestMatcher('^' . preg_quote($this->webhookPath(), '{}') . '$'),
            new IsJsonRequestMatcher(),
        ]);
    }

    final protected function doParse(Request $request, #[\SensitiveParameter] string $secret): ?RemoteEvent
    {
        if ($secret === '') {
            // 500, not 401: the fault is ours, and a sender must not treat this as "my
            // credentials are wrong" and stop retrying. Retrying is the correct response —
            // the delivery will succeed once the env var is set.
            throw new RejectWebhookException(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                \sprintf('No secret configured for webhook "%s"; refusing to accept unverified deliveries.', $this->webhookName()),
            );
        }

        $name = (string) $request->headers->get(WebhookSignature::EVENT_HEADER, '');
        $id = (string) $request->headers->get(WebhookSignature::ID_HEADER, '');
        $signature = (string) $request->headers->get(WebhookSignature::SIGNATURE_HEADER, '');

        if ($name === '' || $id === '' || $signature === '') {
            throw new RejectWebhookException(
                Response::HTTP_BAD_REQUEST,
                \sprintf(
                    'Missing one of %s / %s / %s.',
                    WebhookSignature::EVENT_HEADER,
                    WebhookSignature::ID_HEADER,
                    WebhookSignature::SIGNATURE_HEADER,
                ),
            );
        }

        $body = $request->getContent();
        $expected = WebhookSignature::compute($name, $id, $body, $secret, $this->signingAlgorithm());

        if (!WebhookSignature::matches($expected, $signature)) {
            throw new RejectWebhookException(Response::HTTP_UNAUTHORIZED, 'Invalid webhook signature.');
        }

        $accepted = $this->acceptedEvents();
        if ($accepted !== null && !\in_array($name, $accepted, true)) {
            // Authenticated but uninteresting. Returning null makes WebhookController answer
            // with createRejectedResponse() — see that override below for why this must not
            // look like a delivery failure.
            return null;
        }

        $payload = json_decode($body, true);
        if (!\is_array($payload)) {
            // Signed by us and still not a JSON object: a publisher bug, not a transport one.
            throw new RejectWebhookException(Response::HTTP_BAD_REQUEST, 'Webhook body is not a JSON object.');
        }

        return new RemoteEvent($name, $id, $payload);
    }

    /**
     * 200, not the framework's default 406.
     *
     * A Survos service broadcasts to every subscriber that registered an item, and cannot know
     * which of them cares about a given event. "Authenticated, but not for me" is a NORMAL
     * outcome of that model, and answering 4xx would put a permanent failure in the sender's
     * queue for something that was never wrong. The genuine faults above all throw
     * RejectWebhookException instead, which carries its own status and never reaches here.
     */
    public function createRejectedResponse(string $reason, ?Request $request = null): Response
    {
        return new Response($reason, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
