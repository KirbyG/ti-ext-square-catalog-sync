<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Kirbygo\SquareCatalogSync\Models\Settings;
use Kirbygo\SquareCatalogSync\Models\SyncLog;
use Kirbygo\SquareCatalogSync\Services\CatalogFetcher;
use Kirbygo\SquareCatalogSync\Services\CatalogMapper;

/**
 * Syncs Square Catalog objects into TastyIgniter.
 *
 * Dispatched by:
 *  - Admin "Sync Now" button  (full sync, no afterVersion)
 *  - ProcessWebhook job       (incremental, afterVersion set)
 *  - Scheduler (hourly cron)  (incremental if version known, else full)
 *
 * A cache lock prevents concurrent runs from double-writing.
 */
class SyncSquareCatalog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How long to hold the lock (10 minutes — generous for large catalogs) */
    private const LOCK_TTL = 600;

    private const LOCK_KEY = 'kirbygo_squarecatalogsync_lock';

    public function __construct(
        /**
         * When set, only fetch objects updated since this Square catalog version.
         * null = full sync.
         */
        public readonly ?string $afterVersion = null,
    ) {}

    public function handle(CatalogFetcher $fetcher, CatalogMapper $mapper): void
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        if (! $lock->get()) {
            SyncLog::info('Sync skipped — another run is in progress.');
            return;
        }

        try {
            Settings::setLastSyncStatus('running');
            SyncLog::info('Sync started', ['mode' => $this->afterVersion ? 'incremental' : 'full']);

            $this->runSync($fetcher, $mapper);

            Settings::setLastSyncStatus('success');
            Settings::setLastSyncAt(now()->toDateTimeString());
            Settings::setLastSyncCount($mapper->getUpsertCount());

            SyncLog::info('Sync complete', ['objects_upserted' => $mapper->getUpsertCount()]);
        } catch (\Throwable $e) {
            Settings::setLastSyncStatus('failed');
            SyncLog::error('Sync failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            throw $e; // Let Laravel's queue retry/fail machinery handle it.
        } finally {
            $lock->release();
        }
    }

    private function runSync(CatalogFetcher $fetcher, CatalogMapper $mapper): void
    {
        if ($this->afterVersion) {
            // Incremental: only objects changed since $afterVersion
            foreach ($fetcher->fetchSince($this->afterVersion) as $objects) {
                foreach ($objects as $object) {
                    $mapper->upsert($object);
                }
            }

            // Bump last known version
            // Square doesn't return the new version in listCatalog; the new version
            // comes from the webhook event or can be obtained via retrieveCatalogInfo.
            // We'll store the afterVersion + 1 as a conservative step; the next
            // webhook or cron run will correct it via the new event version.
            Settings::setLastSyncVersion((string) ((int) $this->afterVersion + 1));
        } else {
            // Full sync
            foreach ($fetcher->fetchAll() as $objects) {
                foreach ($objects as $object) {
                    $mapper->upsert($object);
                }
            }

            // Record the current catalog version after a full sync
            Settings::setLastSyncVersion((string) time());
        }
    }
}
