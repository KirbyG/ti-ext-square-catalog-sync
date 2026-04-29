<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Http\Controllers;

use Igniter\Admin\Classes\AdminController;
use Kirbygo\SquareCatalogSync\Jobs\SyncSquareCatalog;
use Kirbygo\SquareCatalogSync\Models\Settings as SettingsModel;
use Kirbygo\SquareCatalogSync\Models\SyncLog;

/**
 * Admin settings page for Square Catalog Sync.
 *
 * Accessible at: admin/kirbygo/squarecatalogsync/settings
 *
 * Responsibilities:
 *  - Display and save API credentials (access token, location ID, environment,
 *    webhook signature key)
 *  - Show last sync status (time, count, status)
 *  - Offer a "Sync Now" button that dispatches SyncSquareCatalog
 *  - Display the last 20 log entries
 */
class Settings extends AdminController
{
    public array $implement = [
        \Igniter\Admin\Actions\FormController::class,
    ];

    public array $formConfig = [
        'name' => 'Square Catalog Sync Settings',
        'model' => \Kirbygo\SquareCatalogSync\Models\Settings::class,
        'edit' => [
            'title' => 'Square Catalog Sync',
            'redirect' => 'kirbygo/squarecatalogsync/settings',
            'redirectClose' => 'kirbygo/squarecatalogsync/settings',
        ],
        'configFile' => 'settings_form',
    ];

    public ?string $pageTitle = 'Square Catalog Sync';

    protected null|string|array $requiredPermissions = 'Kirbygo.SquareCatalogSync.Manage';

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function index(): void
    {
        // Redirect to the edit form (settings are always single-record).
        redirect()->to(admin_url('kirbygo/squarecatalogsync/settings/edit'))->send();
    }

    public function edit(?int $recordId = null): mixed
    {
        $this->vars['syncStatus'] = SettingsModel::lastSyncStatus();
        $this->vars['syncAt'] = SettingsModel::lastSyncAt();
        $this->vars['syncCount'] = SettingsModel::lastSyncCount();
        $this->vars['recentLogs'] = SyncLog::recent(20)->get();

        return $this->asExtension('FormController')->edit($recordId);
    }

    // ------------------------------------------------------------------
    // AJAX handlers
    // ------------------------------------------------------------------

    /**
     * "Sync Now" button handler — dispatches a background sync job.
     */
    public function onSyncNow(): array
    {
        if (! SettingsModel::locationId() || ! (new SettingsModel())->accessToken()) {
            return [
                '#notification' => $this->makePartial('flash_message', [
                    'type' => 'danger',
                    'message' => 'Please save your Square credentials before running a sync.',
                ]),
            ];
        }

        SyncSquareCatalog::dispatch();

        flash()->success('Sync job queued. Check the log below for progress.');

        return [
            '#notification' => $this->makePartial('flash_message', [
                'type' => 'success',
                'message' => 'Sync job queued.',
            ]),
        ];
    }

    /**
     * Save settings — wraps the FormController save, plus handles the
     * plaintext access token → encrypted storage conversion.
     */
    public function onSave(): mixed
    {
        $post = post();

        // Pull out plaintext secrets before they reach the model's generic setter.
        if (! empty($post['Settings']['access_token'])) {
            SettingsModel::storeAccessToken($post['Settings']['access_token']);
            unset($post['Settings']['access_token']);
        }

        if (! empty($post['Settings']['webhook_signature_key'])) {
            SettingsModel::storeWebhookSignatureKey($post['Settings']['webhook_signature_key']);
            unset($post['Settings']['webhook_signature_key']);
        }

        request()->merge($post);

        return $this->asExtension('FormController')->onSave();
    }

    // ------------------------------------------------------------------
    // Form model override
    // ------------------------------------------------------------------

    /**
     * FormController calls this to load the record.
     * Settings are single-instance, so we always return the singleton.
     */
    public function formFindModelObject(mixed $recordId): SettingsModel
    {
        return SettingsModel::instance();
    }
}
