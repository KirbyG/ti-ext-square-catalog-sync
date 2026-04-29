<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kirbygo\SquareCatalogSync\Models\SyncLog;

/**
 * Handles an inbound Square catalog.version.updated webhook event.
 *
 * The HTTP controller receives the webhook, verifies the signature,
 * and dispatches this job — keeping the HTTP response fast.
 *
 * This job then kicks off an incremental catalog sync from the
 * version reported in the webhook payload.
 */
class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $eventType = $this->payload['type'] ?? null;

        if ($eventType !== 'catalog.version.updated') {
            SyncLog::info('Ignoring unhandled webhook event type', ['type' => $eventType]);
            return;
        }

        $catalogVersion = $this->payload['data']['object']['catalog_version']['updated_version']
            ?? $this->payload['event_id']
            ?? null;

        SyncLog::info('Webhook received — queuing incremental sync', [
            'event_type'      => $eventType,
            'catalog_version' => $catalogVersion,
        ]);

        SyncSquareCatalog::dispatch(afterVersion: (string) $catalogVersion);
    }
}
