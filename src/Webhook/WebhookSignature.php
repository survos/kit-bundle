<?php

declare(strict_types=1);

namespace Survos\Kit\Webhook;

/**
 * The verifying half of Symfony's webhook signature.
 *
 * `symfony/webhook` ships the SENDING half — {@see \Symfony\Component\Webhook\Server\HeaderSignatureConfigurator}
 * computes `Webhook-Signature: sha256=<hmac>` over `name . id . body` — but nothing on the
 * receiving side. Every `AbstractRequestParser::doParse()` in every app is therefore expected
 * to re-derive that expression by hand, and a receiver that gets the concatenation order wrong
 * doesn't fail loudly: it fails *closed* on every delivery, which reads like a network problem.
 *
 * So this class exists to state the expression exactly once, next to a comment saying which
 * Symfony class it must agree with. If Symfony ever changes the signed string, one file here
 * changes and every Survos receiver follows.
 *
 * The header names are the framework's own defaults (`framework.webhook.event_header_name` and
 * friends). They are constants rather than configuration because both ends of every Survos
 * webhook are ours — a per-app header name would be a knob with no second position.
 */
final class WebhookSignature
{
    public const string SIGNATURE_HEADER = 'Webhook-Signature';
    public const string EVENT_HEADER = 'Webhook-Event';
    public const string ID_HEADER = 'Webhook-Id';
    public const string DEFAULT_ALGO = 'sha256';

    /**
     * MUST stay byte-identical to HeaderSignatureConfigurator::configure().
     *
     * Note the signature covers the event name and id as well as the body, which is why the
     * receiver reads them from headers rather than from the decoded payload: verifying first
     * and parsing second means malformed JSON from an unauthenticated caller never reaches
     * json_decode(), and it removes any chance of verifying one value while acting on another.
     */
    public static function compute(
        string $name,
        string $id,
        string $body,
        #[\SensitiveParameter] string $secret,
        string $algo = self::DEFAULT_ALGO,
    ): string {
        return $algo . '=' . hash_hmac($algo, $name . $id . $body, $secret);
    }

    /** Constant-time compare. Never use === on a signature. */
    public static function matches(string $expected, string $provided): bool
    {
        return hash_equals($expected, $provided);
    }
}
