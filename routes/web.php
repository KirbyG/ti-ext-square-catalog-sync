<?php

use Kirbygo\SquareCatalogSync\Http\Controllers\Webhook;

/*
 * Public webhook endpoint — no auth middleware.
 * Square signs the payload with HMAC-SHA256; we verify the signature
 * inside the controller / WebhookVerifier.
 *
 * Register this URL in the Square Developer Dashboard as the
 * notification URL for the catalog.version.updated event type.
 */
Route::post('/square/webhook', [Webhook::class, 'handle'])
    ->name('kirbygo.squarecatalogsync.webhook');
