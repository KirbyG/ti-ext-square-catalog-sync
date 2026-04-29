<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Models;

use Igniter\Flame\Database\Model;

/**
 * Persists extension settings in TastyIgniter's extension_settings table.
 *
 * Usage:
 *   Settings::get('location_id')
 *   Settings::set('last_sync_at', now()->toDateTimeString())
 *
 * Access token is stored encrypted; use accessToken() to retrieve it.
 */
class Settings extends Model
{
    public array $implement = [\Igniter\System\Actions\SettingsModel::class];

    /** Key used in extension_settings.item */
    public string $settingsCode = 'kirbygo_squarecatalogsync_settings';

    /** Points to resources/models/settings.php for form field definitions */
    public string $settingsFieldsConfig = 'settings';

    // ------------------------------------------------------------------
    // Credential helpers
    // ------------------------------------------------------------------

    /**
     * Return the decrypted Square access token, or null if not set / corrupted.
     */
    public function accessToken(): ?string
    {
        $encrypted = static::get('access_token_encrypted');

        if (! $encrypted) {
            return null;
        }

        try {
            return decrypt($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Encrypt and persist the access token.
     */
    public static function storeAccessToken(string $token): void
    {
        static::set('access_token_encrypted', encrypt($token));
    }

    public static function locationId(): ?string
    {
        return static::get('location_id') ?: null;
    }

    public static function environment(): string
    {
        return static::get('environment', 'sandbox');
    }

    public static function isSandbox(): bool
    {
        return static::environment() === 'sandbox';
    }

    public static function webhookSignatureKey(): ?string
    {
        $encrypted = static::get('webhook_signature_key_encrypted');

        if (! $encrypted) {
            return null;
        }

        try {
            return decrypt($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function storeWebhookSignatureKey(string $key): void
    {
        static::set('webhook_signature_key_encrypted', encrypt($key));
    }

    // ------------------------------------------------------------------
    // Sync state helpers (stored in the same settings bag for simplicity)
    // ------------------------------------------------------------------

    public static function lastSyncVersion(): ?string
    {
        return static::get('last_sync_version') ?: null;
    }

    public static function setLastSyncVersion(string $version): void
    {
        static::set('last_sync_version', $version);
    }

    public static function lastSyncAt(): ?string
    {
        return static::get('last_sync_at') ?: null;
    }

    public static function setLastSyncAt(string $datetime): void
    {
        static::set('last_sync_at', $datetime);
    }

    public static function lastSyncStatus(): string
    {
        return static::get('last_sync_status', 'never');
    }

    public static function setLastSyncStatus(string $status): void
    {
        // allowed: never | running | success | failed
        static::set('last_sync_status', $status);
    }

    public static function lastSyncCount(): int
    {
        return (int) static::get('last_sync_count', 0);
    }

    public static function setLastSyncCount(int $count): void
    {
        static::set('last_sync_count', $count);
    }
}
