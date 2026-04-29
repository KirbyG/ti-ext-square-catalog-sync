<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kirbygo\SquareCatalogSync\Jobs\ProcessWebhook;
use Kirbygo\SquareCatalogSync\Models\SyncLog;
use Kirbygo\SquareCatalogSync\Services\InvalidSignatureException;
use Kirbygo\SquareCatalogSync\Services\WebhookVerifier;

/**
 * Receives Square webhook events at POST /square/webhook.
 *
 * This controller is intentionally thin: it verifies the signature,
 * dispatches the job, and returns 200 immediately. Square considers
 * any non-200 response a delivery failure and will retry.
 */
class Webhook extends Controller
{
    public function __construct(
        private readonly WebhookVerifier $verifier
    ) {}

    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->verifier->verify($request);
        } catch (InvalidSignatureException $e) {
            SyncLog::warning('Webhook rejected — invalid signature', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        } catch (\RuntimeException $e) {
            // Signature key not configured — log and accept anyway during setup
            SyncLog::warning('Webhook signature key not set; accepting event without verification');
        }

        $payload = $request->json()->all();
        ProcessWebhook::dispatch($payload);

        return response()->json(['status' => 'queued']);
    }
}
