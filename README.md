# Square Catalog Sync for TastyIgniter

Keeps your TastyIgniter menu in sync with your Square Catalog. **Square is the source of truth** — changes you make in Square Dashboard flow into TastyIgniter automatically.

## What it does

- Pulls categories, items, modifier lists, modifiers, and images from Square
- Creates and updates TastyIgniter menu items, categories, and modifier options
- Assigns items to their Square categories (multi-category items use all their categories)
- Wires modifier lists to menu items with the correct min/max selection rules
- Soft-deletes TastyIgniter rows when Square marks objects deleted
- Logs every sync run with timestamps and object counts
- Accepts Square `catalog.version.updated` webhooks for near-real-time incremental syncs

## Requirements

- TastyIgniter 4.2 or later
- PHP 8.2 or later
- A Square account with an API access token
- Queue worker running (for background sync jobs)

## Installation

```bash
composer require kirbygo/ti-ext-square-catalog-sync
php artisan igniter:up
```

Then go to **Admin → Manage → Extensions**, find **Square Catalog Sync**, and click **Install**.

## Configuration

Navigate to **Admin → Square Catalog Sync** (or via the extension settings gear icon).

| Field | Where to find it |
|---|---|
| Access Token | Square Developer Dashboard → your app → Production Access Token |
| Location ID | Square Dashboard → Locations → click your location → the ID in the URL |
| Environment | Start with Sandbox; switch to Production when verified |
| Webhook Signature Key | Square Developer Dashboard → Webhooks → your subscription → Signature key |

The access token and webhook signature key are stored **encrypted** in the database. They are never echoed back into the settings form.

## Running a sync

**Manual:** Click **Sync Now** on the settings page. The job runs in the background — refresh after a few seconds to see results.

**Automatic:** The extension schedules an hourly sync. Make sure your queue worker is running:

```bash
php artisan queue:work
```

**Webhook-triggered:** Configure a Square webhook subscription pointing to:

```
https://yourdomain.com/square/webhook
```

Subscribe to the `catalog.version.updated` event. Each time your Square catalog changes, Square notifies this endpoint and an incremental sync is queued automatically.

## What gets synced

| Square object | TastyIgniter target |
|---|---|
| CATEGORY | `categories` table |
| ITEM | `menus` table + `menu_categories` pivot |
| ITEM VARIATION (>1 per item) | `menu_options` + `menu_option_values` (as a "Size" option) |
| MODIFIER_LIST | `menu_options` table |
| MODIFIER | `menu_option_values` table |
| IMAGE | logged only (not yet downloaded) |
| TAX | logged only — TI uses a single global tax rate; configure manually in TI Settings |

## Known limitations

**Taxes:** Square supports multiple tax rates per item (e.g. a food tax and an alcohol tax). TastyIgniter applies a single global tax percentage to all items. The extension logs all Square taxes with their rates so you can configure TI's global tax setting manually. If you only have one tax rate, set it in Admin → Settings → Tax.

**Images:** Image URLs are logged but not downloaded into TI's media library. This is planned for a future release.

**Multi-category items:** TI supports assigning a menu item to multiple categories via the `menu_categories` pivot. The sync writes all Square category assignments. If your TI frontend theme only renders the first category, this is a theme limitation.

**Sync direction:** This is strictly one-way, Square → TastyIgniter. Changes made directly to TI's menu will be overwritten on the next sync.

## Troubleshooting

**Settings page shows no token field value** — correct; secrets are never echoed back. If the "Access Token set" check passes, the token is stored.

**"Sync job queued" but nothing happens** — your queue worker isn't running. Run `php artisan queue:work` in a terminal or configure a supervisor process.

**Items appear in TI but with no category** — run the sync twice. On the first run, if a page returns items before their categories, the pivot is skipped. The second run corrects it. For production, the hourly scheduler handles this automatically.

**Webhook returns 401** — the signature key in TI settings doesn't match the one in Square Dashboard → Webhooks. Re-copy it and save settings.

**Webhook accepted without a key** — if no signature key is configured, the endpoint accepts all requests and logs a warning. Set the key before going live.

## License

MIT
