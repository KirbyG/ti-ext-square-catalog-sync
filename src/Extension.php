<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync;

use Igniter\System\Classes\BaseExtension;
use Kirbygo\SquareCatalogSync\Jobs\SyncSquareCatalog;
use Kirbygo\SquareCatalogSync\Models\Settings;
use Kirbygo\SquareCatalogSync\Services\CatalogFetcher;
use Kirbygo\SquareCatalogSync\Services\CatalogMapper;
use Kirbygo\SquareCatalogSync\Services\SquareClientFactory;
use Kirbygo\SquareCatalogSync\Services\WebhookVerifier;

class Extension extends BaseExtension
{
    public function register(): void
    {
        $this->app->singleton(SquareClientFactory::class, function ($app) {
            return new SquareClientFactory(Settings::class);
        });

        $this->app->bind(CatalogFetcher::class, function ($app) {
            return new CatalogFetcher($app->make(SquareClientFactory::class));
        });

        $this->app->bind(CatalogMapper::class);

        $this->app->bind(WebhookVerifier::class, function ($app) {
            return new WebhookVerifier(Settings::class);
        });
    }

    public function boot(): void
    {
        //
    }

    public function registerPermissions(): array
    {
        return [
            'Kirbygo.SquareCatalogSync.Manage' => [
                'label' => 'Manage Square Catalog Sync',
                'group' => 'module',
            ],
        ];
    }

    public function registerSettings(): array
    {
        return [
            'squarecatalogsync' => [
                'label' => 'Square Catalog Sync',
                'description' => 'Manage Square API credentials, sync status, and error log',
                'icon' => 'fa fa-rotate',
                'url' => admin_url('kirbygo/squarecatalogsync/settings'),
                'permissions' => ['Kirbygo.SquareCatalogSync.Manage'],
                'priority' => 500,
            ],
        ];
    }

    public function registerSchedule($schedule): void
    {
        // Cron fallback: runs regardless of whether webhooks fire.
        // withoutOverlapping() uses the cache lock to prevent double-writes.
        $schedule->job(SyncSquareCatalog::class)
            ->hourly()
            ->withoutOverlapping();
    }
}
