<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Http\Controllers;

use Igniter\Admin\Classes\AdminController;
use Igniter\Admin\Facades\Template;
use Igniter\Admin\Widgets\Form;
use Kirbygo\SquareCatalogSync\Jobs\SyncSquareCatalog;
use Kirbygo\SquareCatalogSync\Models\Settings as SettingsModel;
use Kirbygo\SquareCatalogSync\Models\SyncLog;
use Kirbygo\SquareCatalogSync\Services\CatalogFetcher;

/**
 * Admin settings page for Square Catalog Sync.
 *
 * URL: admin/kirbygo/squarecatalogsync/settings
 *
 * Intentionally does NOT use FormController behavior — the system Settings
 * controller (which we modelled this on) also manages its Form widget directly.
 * FormController is designed for multi-record CRUD; a single-record settings
 * page with extra panels (status, log) is cleaner without it.
 */
class Settings extends AdminController
{
    public ?string $pageTitle = 'Square Catalog Sync';

    protected null|string|array $requiredPermissions = 'Kirbygo.SquareCatalogSync.Manage';

    public ?Form $formWidget = null;

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function index(): void
    {
        Template::setTitle($this->pageTitle);
        Template::setHeading($this->pageTitle);

        $this->initForm();

        $this->vars['syncStatus'] = SettingsModel::lastSyncStatus();
        $this->vars['syncAt']     = SettingsModel::lastSyncAt();
        $this->vars['syncCount']  = SettingsModel::lastSyncCount();
        $this->vars['recentLogs'] = SyncLog::recent(20)->get();
    }

    // ------------------------------------------------------------------
    // AJAX handlers
    // ------------------------------------------------------------------

    /**
     * Save settings. Secrets (access token, webhook key) are encrypted
     * before storage; other fields are passed straight through to the
     * SettingsModel key-value store.
     */
    public function onSave(): mixed
    {
        $this->initForm();

        $data = $this->formWidget->getSaveData();

        // Pull secrets out before generic save so they go through encrypt().
        if (!empty($data['access_token'])) {
            SettingsModel::storeAccessToken($data['access_token']);
        }
        unset($data['access_token']);

        if (!empty($data['webhook_signature_key'])) {
            SettingsModel::storeWebhookSignatureKey($data['webhook_signature_key']);
        }
        unset($data['webhook_signature_key']);

        SettingsModel::set($data);

        flash()->success('Square Catalog Sync settings saved.');

        return $this->refresh();
    }

    /**
     * Scan all Square ITEM objects and return a channel-frequency table.
     * The channel with the fewest items is almost always the online-ordering channel.
     * Results are injected into #channels-result in the view.
     */
    public function onDetectChannels(): mixed
    {
        if (!(new SettingsModel())->accessToken()) {
            flash()->error('Save your Square credentials before scanning channels.');
            return $this->refresh();
        }

        $fetcher       = app(CatalogFetcher::class);
        $channelCounts = [];
        $itemsScanned  = 0;

        foreach ($fetcher->fetchAllItems() as $page) {
            foreach ($page as $obj) {
                $data = $obj->getValue()->getItemData();
                foreach ($data?->getChannels() ?? [] as $channelId) {
                    $channelCounts[$channelId] = ($channelCounts[$channelId] ?? 0) + 1;
                }
                $itemsScanned++;
            }
        }

        // Ascending by count — online channel appears on fewer items than universal POS channels
        asort($channelCounts);

        $this->vars['channelCounts']     = $channelCounts;
        $this->vars['itemsScanned']      = $itemsScanned;
        $this->vars['currentChannelId']  = SettingsModel::orderingChannelId();

        return $this->makePartial('settings/channels_detected');
    }

    /**
     * Dispatch a full sync job in the background.
     */
    public function onSyncNow(): mixed
    {
        if (!SettingsModel::locationId() || !(new SettingsModel())->accessToken()) {
            flash()->error('Please save your Square credentials before running a sync.');

            return $this->refresh();
        }

        SyncSquareCatalog::dispatch();

        flash()->success('Sync job queued. Refresh the page in a moment to see progress.');

        return $this->refresh();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function initForm(): void
    {
        $model = SettingsModel::instance();

        // loadConfig resolves 'settings' → resources/models/settings.php
        // and extracts the 'form' key from it.
        $fieldConfig = $model->loadConfig(
            $model->settingsFieldsConfig,
            ['form'],
            'form',
        );

        $formConfig = array_except($fieldConfig, 'toolbar');
        $formConfig['model']     = $model;
        $formConfig['alias']     = 'form';
        $formConfig['arrayName'] = 'Settings';
        $formConfig['context']   = 'edit';

        /** @var Form $formWidget */
        $formWidget = $this->makeWidget(Form::class, $formConfig);
        $formWidget->bindToController();
        $this->formWidget = $formWidget;
    }
}
