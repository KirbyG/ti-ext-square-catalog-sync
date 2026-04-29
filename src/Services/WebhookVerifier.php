<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Services;

use Illuminate\Http\Request;

/**
 * Verifies Square webhook request signatures.
 *
 * Square signs webhook payloads with HMAC-SHA256 using the webhook
 * signature key from the Square Developer Dashboard. The signature
 * is in the X-Square-Hmacsha256-Signature header.
 *
 * @see https://developer.squareup.com/docs/webhooks/validate-webhooks
 */
class WebhookVerifier
{
    public function __construct(
        private readonly string $settingsClass
    ) {}

    /**
     * Return true if the request carries a valid Square signature.
     * Throws InvalidSignatureException if the signature is wrong.
     * Throws \RuntimeException if the signature key is not configured.
     */
    public function verify(Request $request): bool
    {
        $signingKey = ($this->settingsClass)::webhookSignatureKey();

        if (! $signingKey) {
            throw new \RuntimeException(
                'Webhook signature key is not configured. Visit Settings → Square Catalog Sync.'
            );
        }

        $signature = $request->header('X-Square-Hmacsha256-Signature');

        if (! $signature) {
            throw new InvalidSignatureException('Missing X-Square-Hmacsha256-Signature header.');
        }

        // Square's signature covers: notification URL + raw body
        $notificationUrl = $request->fullUrl();
        $body            = $request->getContent();
        $payload         = $notificationUrl . $body;

        $expected = base64_encode(
            hash_hmac('sha256', $payload, $signingKey, binary: true)
        );

        if (! hash_equals($expected, $signature)) {
            throw new InvalidSignatureException('Webhook signature mismatch.');
        }

        return true;
    }
}
