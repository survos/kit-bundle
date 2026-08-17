# Webhooks between Survos services

How one Survos service tells another that asynchronous work finished — mediary announcing that
an image has been analysed, lingua announcing that a string has been translated.

This is the answer to a question that used to have no good answer: *how do we know when it's
done?* Before this, the receiving app either polled (`lingua:pull` on a timer, mostly finding
nothing) or waited for a human to notice that a locale looked empty or a thumbnail was missing.

Everything here is `symfony/webhook` + `symfony/remote-event`. We write two small classes per
receiver and one small service per sender. If you find yourself writing a controller, a message
class, or an `hash_hmac` call, stop — that is the thing this replaced.

---

## The shape

```
SENDER (mediary, lingua)                     RECEIVER (harvest, zm, openfoto, …)

  build a RemoteEvent(name, id, payload)
  dispatch SendWebhookMessage ─────┐
                                   │  [Messenger: `webhook` transport, retries]
                                   ▼
                    VerifyingWebhookTransport
                    POST /webhook/{name}
                    Webhook-Event / Webhook-Id / Webhook-Signature
                                   │
                                   └──────────►  FrameworkBundle WebhookController
                                                 └─ YourRequestParser  (verifies HMAC)
                                                    └─ 202, dispatch ConsumeRemoteEventMessage
                                                       │  [Messenger: your own transport]
                                                       ▼
                                                    #[AsRemoteEventConsumer]
                                                       └─ YourUpdateApplier  ──► your rows
                                                          └─ YourUpdatedEvent (app seam)
```

Both hops are asynchronous, and that is the point. The sender is not blocked on the receiver's
database, and the receiver's HTTP response does not wait on its own writes.

---

## Naming

| thing | rule | example |
|---|---|---|
| webhook name | the **sender**, not the payload | `mediary`, `lingua` |
| endpoint | `/webhook/{name}` | `/webhook/mediary` |
| event name | `noun.past-tense` | `asset.analyzed`, `translation.completed` |
| secret env var | `{SENDER}_WEBHOOK_SECRET` | `MEDIARY_WEBHOOK_SECRET` |

One sender may grow several event types; they all arrive on one endpoint under one secret and
are told apart by `RemoteEvent::getName()`. Do not add an endpoint per event.

---

## Authentication

`Webhook-Signature: sha256=<hmac>` over `name + id + body`, keyed with a shared secret. The
sending half is Symfony's `HeaderSignatureConfigurator`; the verifying half is
`Survos\Kit\Webhook\WebhookSignature`, which exists because Symfony ships the signer and not the
verifier.

Three rules, all enforced by `AbstractJsonWebhookParser` so no receiver has to remember them:

1. **Verify before decoding.** An unauthenticated caller never reaches `json_decode()`, and the
   event name that gets signed is the same one that gets acted on.
2. **`hash_equals`, never `===`.**
3. **An empty secret fails closed.** `framework.webhook.routing.*.secret` defaults to `''`, and
   treating that as "verification not required" turns a missing env var into an open endpoint.
   The parser answers 500 instead — the sender's retry is then correct behaviour, because the
   delivery *will* succeed once the variable is set.

One secret per sending service. Every subscriber is one of our own apps, so a per-subscriber
secret would need a subscriber registry that neither sender has. Revisit if a third party ever
subscribes.

### Rotating a secret

There is no dual-secret window, so rotation is a brief outage of *delivery*, not of data:
set the new value on every subscriber first, then on the sender. Deliveries signed with the old
secret get 401 → `failed` transport → replay with `messenger:failed:retry` once both ends agree.

---

## Writing a receiver

Two classes in the bundle that owns the entity. `AbstractSurvosBundle` auto-registers
`src/Webhook/` and `src/RemoteEvent/`, so there is no service wiring.

```php
// src/Webhook/MediaWebhookRequestParser.php
final class MediaWebhookRequestParser extends AbstractJsonWebhookParser
{
    public const string WEBHOOK_NAME = 'mediary';

    protected function webhookName(): string { return self::WEBHOOK_NAME; }
    protected function acceptedEvents(): array { return ['asset.analyzed']; }
}
```

```php
// src/RemoteEvent/MediaRemoteEventConsumer.php
#[AsRemoteEventConsumer(MediaWebhookRequestParser::WEBHOOK_NAME)]
final class MediaRemoteEventConsumer implements ConsumerInterface
{
    public function __construct(private readonly MediaUpdateApplier $applier) {}

    public function consume(RemoteEvent $event): void
    {
        $this->applier->apply(MediaUpdate::fromWebhook($event->getPayload()));
    }
}
```

Then in the **app**:

```yaml
# config/routes/webhook.yaml
webhook:
    resource: '@FrameworkBundle/Resources/config/routing/webhook.php'
    prefix: /webhook
```

```yaml
# config/packages/webhook.yaml
framework:
    webhook:
        routing:
            mediary:
                service: Survos\MediaBundle\Webhook\MediaWebhookRequestParser
                secret: '%env(default::MEDIARY_WEBHOOK_SECRET)%'
```

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            'Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage': media_callback
```

That routing line is **required**. Unrouted, Messenger handles the message inline and the
endpoint's 202 ("accepted, not yet applied") becomes a lie told while the sender waits.

### The consumer must not contain logic

It normalises the payload and calls an applier — the same applier the synchronous path uses
(`media:sync`, `lingua:pull`). Two code paths writing the same rows is how media rows ended up
with an origin URL and no dimensions for 26,244 records. If the webhook path can do something
the sync path cannot, that difference will eventually be a bug.

### App-specific work hangs off an event

The applier dispatches `MediaUpdatedEvent` / `TranslationUpdatedEvent`. Apps listen there. This
is what keeps mediary from ever learning about ssai's `Item` or harvest's `Img`.

### "Not for me" is a 200

A sender broadcasts to every subscriber that registered an item and cannot know which of them
cares. `AbstractJsonWebhookParser::createRejectedResponse()` returns **200**, not the
framework's 406, and appliers log-and-skip an unknown row. A 4xx here would park a whole page of
good data in the sender's failure transport because of one stale row.

---

## Several webhooks in one app

Messenger routes on message **class**, and every inbound webhook is the same class. Left alone,
"catch up on translations" also means "reprocess every image callback".

`RemoteEventTransportMiddleware` reads the webhook name off the message and stamps the matching
transport:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            media_callback:  'doctrine://default?queue_name=media_callback'
            lingua_callback: 'doctrine://default?queue_name=lingua_callback'
        routing:
            'Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage': media_callback
        buses:
            messenger.bus.default:
                middleware:
                    - Survos\Kit\Webhook\RemoteEventTransportMiddleware
```

```yaml
# config/services.yaml
Survos\Kit\Webhook\RemoteEventTransportMiddleware:
    arguments:
        $transports:
            mediary: media_callback
            lingua:  lingua_callback
```

A name with no entry falls through to the `routing` line, which therefore doubles as the
default. **With only one webhook, skip the middleware** — one transport is simpler and the
middleware buys nothing.

---

## Writing a sender

Do not write an HTTP client. Dispatch Symfony's own message:

```php
$this->bus->dispatch(new SendWebhookMessage(
    new Subscriber($callbackUrl, $this->secret),
    new RemoteEvent('asset.analyzed', $asset->id, $payload),
));
```

```yaml
framework:
    messenger:
        transports:
            webhook:
                dsn: 'doctrine://default?queue_name=webhook'
                retry_strategy: { max_retries: 5, delay: 5000, multiplier: 4, max_delay: 3600000 }
        routing:
            'Symfony\Component\Webhook\Messenger\SendWebhookMessage': webhook
```

Two decorations are needed and both are non-optional in practice:

```yaml
# config/services.yaml

# `.wip` is not real DNS — it only resolves through the Symfony CLI proxy. Without this every
# local subscriber's delivery fails silently.
survos.webhook.http_client:
    class: Survos\FetchBundle\Http\WipProxyHttpClient
    arguments: { $client: '@http_client' }

# Symfony's webhook.transport calls request() and never reads the response, so a failed
# delivery is indistinguishable from a successful one and the retry policy never fires.
Survos\Kit\Webhook\VerifyingWebhookTransport:
    decorates: 'webhook.transport'
    arguments:
        $client: '@survos.webhook.http_client'
        $headers: '@webhook.headers_configurator'
        $body: '@webhook.body_configurator.json'
        $signer: '@webhook.signer'
        $logger: '@logger'
```

`VerifyingWebhookTransport` classifies the response:

| status | meaning | behaviour |
|---|---|---|
| 2xx | delivered | done |
| 3xx / 4xx | bad signature, wrong name, moved endpoint | **permanent** → `failed` transport, no retry |
| 5xx / timeout | subscriber restarting, transient | retried with backoff |

### Batch, don't flood

One webhook per unit of work is the obvious design and it is wrong at our volumes: a
`lingua:push` of 5,000 strings into three locales is 15,000 transitions. Dispatching from the
transition would produce 15,000 messages and 15,000 HTTP requests — the same shape that buried
mediary's queue when every workflow transition dispatched its own Meilisearch job.

lingua's answer is a coalescing drain (`FlushTranslationNotificationsMessage`): the transition
stays silent, and one message announces up to 500 finished translations per subscriber, then
re-dispatches itself — immediately when a page came back full, after a delay while work is still
in flight, and not at all when nothing is pending. Steady state is an empty queue, not a poll.

mediary does not need this: one asset produces one `asset.analyzed`, and registration is already
batched upstream.

---

## Operating it

```bash
# deliver queued webhooks (sender side)
bin/console messenger:consume webhook -v

# apply received webhooks (receiver side)
bin/console messenger:consume media_callback lingua_callback -v

# what failed, and why
bin/console messenger:failed:show
bin/console messenger:failed:retry

# lingua: announce anything the automatic chain gave up on
bin/console lingua:webhook:flush --all

# mediary: re-deliver to clients that never got it
bin/console media:replay-webhooks --client=harvest --limit=100

# mediary: after a subscriber moves its endpoint
bin/console webhook:migrate-callback-urls --from=/media/callback --to=/webhook/mediary
```

Both senders need a `webhook` worker in their `Procfile`, and every receiver needs a worker for
its callback transports. A webhook that is queued and never consumed looks exactly like a
webhook that was never sent.

### Testing a receiver by hand

```bash
SECRET=...; EVENT=asset.analyzed; ID=test-1
BODY='{"event":"asset.analyzed","originalUrl":"https://example.org/x.jpg","width":640}'
SIG="sha256=$(php -r 'echo hash_hmac("sha256", $argv[1].$argv[2].$argv[3], $argv[4]);' "$EVENT" "$ID" "$BODY" "$SECRET")"
curl -i -X POST https://your.wip/webhook/mediary \
  -H 'Content-Type: application/json' \
  -H "Webhook-Event: $EVENT" -H "Webhook-Id: $ID" -H "Webhook-Signature: $SIG" \
  -d "$BODY"
```

Expect `202`. `401` is a secret mismatch, `400` a missing header, `404` an unknown webhook name,
`500` an unset secret on the receiver.

---

## What this replaced

Three hand-rolled receivers, with three different conventions, one of which had no
authentication at all:

| | endpoint | auth | fate |
|---|---|---|---|
| `MediaCallbackController` | `/media/callback` | **none** | deleted |
| `LinguaWebhookController` | `/_lingua/webhook` | `X-Api-Key` | deleted (route was never registered) |
| mediary `SaisHookRequestParser` | `/webhook/sais-hook` | `X-Authentication-Token` | deleted (`make:webhook` scaffolding, never wired, referenced classes that do not exist) |
| harvest `WebHookController` | `/webhook` | none | deleted (echoed the request body back) |

The unauthenticated one mattered: anyone who could reach `/media/callback` could POST an
`originalUrl` plus an `s3Url`/`width` and rewrite the matching media row, because
`MediaUpdateApplier` derives the row key from the URL and writes.

See survos-sites/mediary#8.
