<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kirbygo\SquareCatalogSync\Models\Settings;
use Kirbygo\SquareCatalogSync\Models\SyncLog;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectItem;
use Square\Types\CatalogObjectCategory;
use Square\Types\CatalogObjectTax;
use Square\Types\CatalogObjectModifierList;
use Square\Types\CatalogObjectModifier;
use Square\Types\CatalogObjectImage;

/**
 * Maps Square CatalogObjects to TastyIgniter database rows (SDK v42).
 *
 * In SDK v42, CatalogObject is a union type:
 *   - getType()  → string ('ITEM', 'TAX', etc.)
 *   - getValue() → typed wrapper (CatalogObjectItem, CatalogObjectTax, etc.)
 *
 * TastyIgniter schema notes (verified against actual DB):
 *   - categories          → category_id (PK), name, parent_id, status, nest_left, nest_right
 *                           Uses kalnoy/nestedset; call Category::fixTree() after batch writes.
 *   - menu_categories     → menu_id, category_id  (pivot; menus has NO category_id column)
 *   - menus               → menu_id (PK), menu_name, menu_description, menu_price, menu_status
 *   - menu_options        → option_id (PK), option_name, display_type  (no status column)
 *   - menu_option_values  → option_value_id (PK), option_id, name, price  (no status column)
 *   - menu_item_options   → menu_option_id (PK), option_id, menu_id, min_selected, max_selected, is_required
 *   - media_attachments   → polymorphic via attachment_type/attachment_id; tag='thumb' for menu images
 *   - No 'taxes' table — TI stores a single global tax_percentage in settings
 *
 * Each upsert method is idempotent: running it twice produces the same result.
 * Rows are matched by square_object_id; soft-deleted when Square marks is_deleted.
 *
 * Category sync uses MENU_CATEGORY objects only (Square's POS/online menu hierarchy).
 * REGULAR_CATEGORY and KITCHEN_CATEGORY are skipped — they are legacy accounting/POS tags.
 * SyncSquareCatalog calls upsertCategories() with all CATEGORY objects before items so
 * parent IDs can be resolved; after the batch Category::fixTree() rebuilds nest_left/nest_right.
 *
 * IMAGE objects are processed before ITEM objects (enforced by SyncSquareCatalog's
 * two-pass approach), so $imageLibrary is always populated before syncItemImages runs.
 */
class CatalogMapper
{
    private int $upsertCount = 0;

    /** Ordering Profile channel ID from settings; null = no channel filter */
    private readonly ?string $orderingChannelId;

    /** Square category IDs to treat as POS-only regardless of other flags */
    private readonly array $excludedCategoryIds;

    /** Square item IDs skipped this run (archived or missing ordering channel) */
    private array $filteredItemIds = [];

    /**
     * In-memory map of downloaded images: square_image_id → media row data.
     * Populated by upsertImage(), consumed by syncItemImages().
     *
     * @var array<string, array{disk: string, name: string, file_name: string, mime_type: string, size: int}>
     */
    private array $imageLibrary = [];

    public function __construct()
    {
        $this->orderingChannelId    = Settings::orderingChannelId();
        $this->excludedCategoryIds  = Settings::excludedCategoryIds();
    }

    public function getUpsertCount(): int
    {
        return $this->upsertCount;
    }

    public function getFilteredItemIds(): array
    {
        return $this->filteredItemIds;
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    /**
     * Upsert a single CatalogObject (union type) into the appropriate TI table.
     */
    public function upsert(CatalogObject $object): void
    {
        $wrapper = $object->getValue();

        if (method_exists($wrapper, 'getIsDeleted') && $wrapper->getIsDeleted()) {
            $this->softDelete($object->getType(), $wrapper->getId());
            return;
        }

        match ($object->getType()) {
            'TAX'           => $this->logTax($wrapper),
            'CATEGORY'      => $this->upsertCategory($wrapper),
            'MODIFIER_LIST' => $this->upsertModifierList($wrapper),
            'MODIFIER'      => $this->upsertModifier($wrapper),
            'ITEM'          => $this->upsertItem($wrapper),
            'IMAGE'         => $this->upsertImage($wrapper),
            default         => null,
        };
    }

    // ------------------------------------------------------------------
    // Taxes — TastyIgniter has no per-row taxes table
    // ------------------------------------------------------------------

    private function logTax(CatalogObjectTax $wrapper): void
    {
        $data = $wrapper->getTaxData();
        // TI stores a single global tax_percentage in settings, not per-row taxes.
        // Log the Square taxes so an admin can manually configure TI's tax setting.
        SyncLog::info('Square tax found (manual TI configuration required)', [
            'square_id'      => $wrapper->getId(),
            'name'           => $data?->getName(),
            'percentage'     => $data?->getPercentage(),
            'inclusion_type' => $data?->getInclusionType(),
        ]);
    }

    // ------------------------------------------------------------------
    // Categories → categories table
    // ------------------------------------------------------------------

    /**
     * Batch-upsert all MENU_CATEGORY objects from a sync page collection.
     * Runs multi-pass so parents are always written before their children,
     * regardless of the order Square returns them.
     * Calls Category::fixTree() at the end to rebuild nest_left/nest_right.
     *
     * @param  CatalogObject[]  $categoryObjects  All objects whose getType() === 'CATEGORY'
     */
    public function upsertCategories(array $categoryObjects): void
    {
        // Index MENU_CATEGORY objects by Square ID, preserving API response order.
        // The Square API returns categories in the same order as the Dashboard,
        // so API position is used as the priority within each sibling group.
        $byId = [];
        foreach ($categoryObjects as $obj) {
            $wrapper = $obj->getValue();
            $data    = $wrapper->getCategoryData();
            if ($data?->getCategoryType() !== 'MENU_CATEGORY') {
                continue;
            }
            $byId[$wrapper->getId()] = $obj;
        }

        if (empty($byId)) {
            return;
        }

        // Pre-compute sibling position (priority) for each category.
        // Group by parent Square ID, then assign position * 10 within each group.
        $siblingCounters = [];
        $priorities      = [];
        foreach ($byId as $squareId => $obj) {
            $parentKey = $obj->getValue()->getCategoryData()->getParentCategory()?->getId() ?? '__root__';
            $siblingCounters[$parentKey] = ($siblingCounters[$parentKey] ?? 0);
            $priorities[$squareId]       = $siblingCounters[$parentKey];
            $siblingCounters[$parentKey] += 10;
        }

        // Multi-pass: each pass writes rows whose parent is already committed.
        // Handles arbitrary nesting depth; 5 passes covers any realistic menu tree.
        $processed = [];
        $remaining = $byId;

        for ($pass = 0; $pass < 5 && ! empty($remaining); $pass++) {
            foreach ($remaining as $squareId => $obj) {
                $wrapper        = $obj->getValue();
                $data           = $wrapper->getCategoryData();
                $parentSquareId = $data->getParentCategory()?->getId();

                if ($parentSquareId !== null && ! isset($processed[$parentSquareId])) {
                    continue; // Parent not yet written — defer to next pass
                }

                $parentTiId = $parentSquareId
                    ? DB::table('categories')->where('square_object_id', $parentSquareId)->value('category_id')
                    : null;

                // Status 0 if:
                //   (a) Square explicitly marks the category as hidden online, or
                //   (b) the admin has listed this Square category ID in excluded_category_ids.
                // Note: CatalogCategory.channels uses different IDs from item channels
                // and cannot be compared to the ordering_channel_id setting.
                $catStatus = ($data->getOnlineVisibility() === false
                    || in_array($squareId, $this->excludedCategoryIds, true))
                    ? 0
                    : 1;

                DB::table('categories')->upsert(
                    [
                        'square_object_id' => $squareId,
                        'name'             => $data->getName() ?? 'Unnamed Category',
                        'description'      => '',
                        'parent_id'        => $parentTiId,
                        'status'           => $catStatus,
                        'priority'         => $priorities[$squareId],
                        'updated_at'       => now(),
                    ],
                    uniqueBy: ['square_object_id'],
                    update: ['name', 'parent_id', 'status', 'priority', 'updated_at'],
                );

                $processed[$squareId] = true;
                unset($remaining[$squareId]);
                $this->upsertCount++;
            }
        }

        if (! empty($remaining)) {
            SyncLog::warning('Some categories could not be resolved after 5 passes', [
                'unresolved_square_ids' => array_keys($remaining),
            ]);
        }

        // Deactivate any Square-synced category NOT in the MENU_CATEGORY set.
        // This removes old REGULAR_CATEGORY rows written by earlier sync versions
        // and any categories deleted from the Square menu hierarchy.
        if (! empty($processed)) {
            DB::table('categories')
                ->whereNotNull('square_object_id')
                ->whereNotIn('square_object_id', array_keys($processed))
                ->update(['status' => 0, 'updated_at' => now()]);
        }

        // Populate permalink_slug for newly-inserted rows that don't have one yet.
        // We never overwrite an existing slug (stable URLs after first sync).
        $slugsInUse = DB::table('categories')->whereNotNull('permalink_slug')->pluck('permalink_slug')->flip();
        DB::table('categories')
            ->whereNull('permalink_slug')
            ->whereIn('square_object_id', array_keys($processed))
            ->get(['category_id', 'name'])
            ->each(function (object $row) use (&$slugsInUse): void {
                $base = Str::slug($row->name);
                $slug = $base;
                for ($i = 1; $slugsInUse->has($slug); $i++) {
                    $slug = $base . '-' . $i;
                }
                DB::table('categories')->where('category_id', $row->category_id)->update(['permalink_slug' => $slug]);
                $slugsInUse->put($slug, true);
            });

        // Rebuild the nested set (nest_left / nest_right) from parent_id values.
        // Must be called after all category rows are committed.
        \Igniter\Cart\Models\Category::fixTree();
    }

    private function upsertCategory(CatalogObjectCategory $wrapper): void
    {
        // Called from upsert() for individual objects (e.g. incremental webhook syncs).
        // Skips non-MENU_CATEGORY types; parent_id resolution is best-effort here
        // (parent may not exist yet for out-of-order incremental events).
        $data = $wrapper->getCategoryData();
        if (! $data || $data->getCategoryType() !== 'MENU_CATEGORY') {
            return;
        }

        $parentSquareId = $data->getParentCategory()?->getId();
        $parentTiId     = $parentSquareId
            ? DB::table('categories')->where('square_object_id', $parentSquareId)->value('category_id')
            : null;

        DB::table('categories')->upsert(
            [
                'square_object_id' => $wrapper->getId(),
                'name'             => $data->getName() ?? 'Unnamed Category',
                'description'      => '',
                'parent_id'        => $parentTiId,
                'status'           => 1,
                'priority'         => 0,
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['name', 'parent_id', 'status', 'updated_at'],
        );

        \Igniter\Cart\Models\Category::fixTree();
        $this->upsertCount++;
    }

    // ------------------------------------------------------------------
    // Modifier Lists → menu_options table
    // ------------------------------------------------------------------

    private function upsertModifierList(CatalogObjectModifierList $wrapper): void
    {
        $data = $wrapper->getModifierListData();
        if (! $data) {
            return;
        }

        $displayType = $this->resolveOptionDisplayType(
            selectionType: $data->getSelectionType() ?? 'SINGLE',
        );

        DB::table('menu_options')->upsert(
            [
                'square_object_id' => $wrapper->getId(),
                'option_name'      => $data->getName() ?? 'Unnamed Option',
                'display_type'     => $displayType,
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['option_name', 'display_type', 'updated_at'],
        );

        $this->upsertCount++;
    }

    // ------------------------------------------------------------------
    // Modifiers → menu_option_values table
    // ------------------------------------------------------------------

    private function upsertModifier(CatalogObjectModifier $wrapper): void
    {
        $data = $wrapper->getModifierData();
        if (! $data) {
            return;
        }

        $modifierListId = $data->getModifierListId();
        $optionId = $modifierListId
            ? DB::table('menu_options')
                ->where('square_object_id', $modifierListId)
                ->value('option_id')
            : null;

        $priceMoney   = $data->getPriceMoney();
        $priceDollars = $priceMoney ? $priceMoney->getAmount() / 100 : 0.0;

        // TI column is 'name' (not 'value'); no updated_at column on this table
        DB::table('menu_option_values')->upsert(
            [
                'square_object_id' => $wrapper->getId(),
                'option_id'        => $optionId,
                'name'             => $data->getName() ?? 'Unnamed Modifier',
                'price'            => $priceDollars,
                'priority'         => 0,
            ],
            uniqueBy: ['square_object_id'],
            update: ['option_id', 'name', 'price'],
        );

        $this->upsertCount++;
    }

    // ------------------------------------------------------------------
    // Items → menus + menu_categories pivot
    // ------------------------------------------------------------------

    private function upsertItem(CatalogObjectItem $wrapper): void
    {
        $data = $wrapper->getItemData();
        if (! $data) {
            return;
        }

        // CatalogItem::getVariations() returns ?array<CatalogObject> (outer union type)
        $variations  = $data->getVariations() ?? [];
        $masterPrice = 0.0;
        if (! empty($variations)) {
            $firstVarData = $variations[0]->getValue()->getItemVariationData();
            $priceMoney   = $firstVarData?->getPriceMoney();
            if ($priceMoney) {
                $masterPrice = $priceMoney->getAmount() / 100;
            }
        }

        // Archived items and items missing the ordering channel are POS-only.
        // Don't upsert them — track for hard deletion instead.
        $isArchived = (bool) $data->getIsArchived();
        $channels   = $data->getChannels() ?? [];
        $hasChannel = $this->orderingChannelId === null
            || in_array($this->orderingChannelId, $channels, true);

        if ($isArchived || ! $hasChannel) {
            $this->filteredItemIds[] = $wrapper->getId();
            return;
        }

        // menus table has no category_id column; relationship lives in menu_categories pivot
        DB::table('menus')->upsert(
            [
                'square_object_id' => $wrapper->getId(),
                'menu_name'        => $data->getName() ?? 'Unnamed Item',
                'menu_description' => $data->getDescription() ?? '',
                'menu_price'       => $masterPrice,
                'menu_status'      => 1,
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['menu_name', 'menu_description', 'menu_price', 'menu_status', 'updated_at'],
        );

        $this->upsertCount++;

        $menuId = DB::table('menus')
            ->where('square_object_id', $wrapper->getId())
            ->value('menu_id');

        if ($menuId) {
            $this->syncItemCategories($menuId, $data->getCategories() ?? []);
        }

        if (count($variations) > 1 && $menuId) {
            $itemName = $data->getName() ?? '';
            foreach ($variations as $variation) {
                $this->upsertVariation($variation->getValue(), $wrapper->getId(), $itemName);
            }
        }

        if ($menuId) {
            $this->syncItemModifierLists($menuId, $wrapper->getId(), $data->getModifierListInfo() ?? []);
        }

        if ($menuId) {
            $imageIds = $data->getImageIds() ?? [];
            if (! empty($imageIds)) {
                $this->syncItemImages($menuId, $imageIds);
            }
        }
    }

    // ------------------------------------------------------------------
    // Menu ↔ Category associations — menu_categories pivot
    // ------------------------------------------------------------------

    /**
     * @param  \Square\Types\CatalogObjectCategory[]  $squareCategories
     */
    private function syncItemCategories(int $menuId, array $squareCategories): void
    {
        $categoryIds = [];
        foreach ($squareCategories as $catWrapper) {
            $categoryId = DB::table('categories')
                ->where('square_object_id', $catWrapper->getId())
                ->value('category_id');

            if ($categoryId) {
                $categoryIds[] = $categoryId;
            }
        }

        if (empty($categoryIds)) {
            return;
        }

        // Replace all category associations for this menu item
        DB::table('menu_categories')->where('menu_id', $menuId)->delete();
        foreach ($categoryIds as $categoryId) {
            DB::table('menu_categories')->insert([
                'menu_id'     => $menuId,
                'category_id' => $categoryId,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Item Variations → menu_options / menu_option_values
    // ------------------------------------------------------------------

    /**
     * @param  \Square\Types\CatalogObjectItemVariation  $varWrapper
     */
    private function upsertVariation(mixed $varWrapper, string $parentItemSquareId, string $parentItemName = ''): void
    {
        $varData = $varWrapper->getItemVariationData();
        if (! $varData) {
            return;
        }

        $priceMoney = $varData->getPriceMoney();
        $price      = $priceMoney ? $priceMoney->getAmount() / 100 : 0.0;

        // A synthetic "Size" option keyed by parent item to avoid cross-item collisions
        $sizeOptionSquareId = 'var_option_' . $parentItemSquareId;

        DB::table('menu_options')->upsert(
            [
                'square_object_id' => $sizeOptionSquareId,
                'option_name'      => 'Size',
                'display_type'     => 'radio',
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['updated_at'],
        );

        $optionId = DB::table('menu_options')
            ->where('square_object_id', $sizeOptionSquareId)
            ->value('option_id');

        // Strip parent item name prefix that Square often embeds in variation names
        // e.g. "Earl Grey Decaf, 85 g - 85g" with parent "Earl Grey Decaf, 85 g" → "85g"
        $rawName = $varData->getName() ?? 'Unnamed';
        if ($parentItemName !== '' && str_starts_with($rawName, $parentItemName)) {
            $stripped = ltrim(substr($rawName, strlen($parentItemName)), ' -,');
            $name     = $stripped ?: $rawName;
        } else {
            $name = $rawName;
        }

        // TI column is 'name' (not 'value'); no updated_at column on this table
        DB::table('menu_option_values')->upsert(
            [
                'square_object_id' => $varWrapper->getId(),
                'option_id'        => $optionId,
                'name'             => $name,
                'price'            => $price,
                'priority'         => 0,
            ],
            uniqueBy: ['square_object_id'],
            update: ['option_id', 'name', 'price'],
        );

        $this->upsertCount++;
    }

    // ------------------------------------------------------------------
    // Item ↔ Modifier List associations — menu_item_options pivot
    // ------------------------------------------------------------------

    /**
     * @param  \Square\Types\CatalogItemModifierListInfo[]  $modifierListInfos
     */
    private function syncItemModifierLists(int $menuId, string $itemSquareId, array $modifierListInfos): void
    {
        foreach ($modifierListInfos as $info) {
            if (! $info->getEnabled()) {
                continue;
            }

            $modifierListSquareId = $info->getModifierListId();
            $optionId = DB::table('menu_options')
                ->where('square_object_id', $modifierListSquareId)
                ->value('option_id');

            if (! $optionId) {
                SyncLog::warning('Modifier list not yet synced when attaching to item', [
                    'item_square_id'          => $itemSquareId,
                    'modifier_list_square_id' => $modifierListSquareId,
                ]);
                continue;
            }

            // Item-level min/max (-1 in Square means "unlimited"; map to 0 for TI)
            $minSelected = max(0, $info->getMinSelectedModifiers() ?? 0);
            $maxSelected = max(0, $info->getMaxSelectedModifiers() ?? 0);
            $isRequired  = $minSelected > 0 ? 1 : 0;

            // TI pivot table is menu_item_options (not menu_menu_options).
            // No unique index on (option_id, menu_id) so use updateOrInsert.
            DB::table('menu_item_options')->updateOrInsert(
                ['option_id' => $optionId, 'menu_id' => $menuId],
                [
                    'min_selected' => $minSelected,
                    'max_selected' => $maxSelected,
                    'is_required'  => $isRequired,
                    'updated_at'   => now(),
                ],
            );
        }
    }

    // ------------------------------------------------------------------
    // Images — download Square images into TI media_attachments
    // ------------------------------------------------------------------

    private function upsertImage(CatalogObjectImage $wrapper): void
    {
        $data = $wrapper->getImageData();
        $url  = $data?->getUrl();
        if (! $url) {
            return;
        }

        $squareId  = $wrapper->getId();
        $urlPath   = parse_url($url, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION)) ?: 'jpg';

        // Deterministic filename based on Square ID — avoids re-downloading on re-sync
        $storedName = md5($squareId) . '.' . $extension;

        $diskName      = config('igniter-system.assets.attachment.disk', 'public');
        $folder        = rtrim((string) config('igniter-system.assets.attachment.folder', 'media/attachments/'), '/');
        $partitionPath = substr($storedName, 0, 3) . '/' . substr($storedName, 3, 3) . '/' . substr($storedName, 6, 3) . '/';
        $diskPath      = $folder . '/public/' . $partitionPath . $storedName;

        $disk     = Storage::disk($diskName);
        $fileSize = 0;

        if (! $disk->exists($diskPath)) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'sq_img_');
            try {
                // Stream to a temp file — avoids loading the full image body into PHP memory
                $response = Http::withOptions(['sink' => $tmpFile])->timeout(30)->get($url);
                if (! $response->successful()) {
                    SyncLog::warning('Failed to download Square image', [
                        'square_id' => $squareId,
                        'url'       => $url,
                        'status'    => $response->status(),
                    ]);
                    return;
                }
                $fileSize = (int) filesize($tmpFile);
                $stream   = fopen($tmpFile, 'rb');
                $disk->put($diskPath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } finally {
                @unlink($tmpFile);
            }
        } else {
            $fileSize = $disk->size($diskPath);
        }

        $mimeMap  = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $mimeType = $mimeMap[$extension] ?? 'image/jpeg';

        // Upsert a library row (no attachment) keyed by name for incremental-sync fallback
        $existing = DB::table('media_attachments')
            ->where('name', $storedName)
            ->first();

        if ($existing) {
            DB::table('media_attachments')
                ->where('id', $existing->id)
                ->update(['size' => $fileSize, 'updated_at' => now()]);
        } else {
            DB::table('media_attachments')->insert([
                'disk'              => $diskName,
                'name'              => $storedName,
                'file_name'         => 'image.' . $extension,
                'mime_type'         => $mimeType,
                'size'              => $fileSize,
                'tag'               => null,
                'attachment_type'   => null,
                'attachment_id'     => null,
                'is_public'         => 1,
                'custom_properties' => json_encode(['square_image_id' => $squareId]),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        $this->imageLibrary[$squareId] = [
            'disk'      => $diskName,
            'name'      => $storedName,
            'file_name' => 'image.' . $extension,
            'mime_type' => $mimeType,
            'size'      => $fileSize,
        ];

        $this->upsertCount++;
    }

    // ------------------------------------------------------------------
    // Item ↔ Image associations — media_attachments
    // ------------------------------------------------------------------

    /**
     * Replace square-synced media rows for a menu item with the current image set.
     * Manually uploaded images (no square_image_id in custom_properties) are preserved.
     *
     * @param  string[]  $imageIds
     */
    private function syncItemImages(int $menuId, array $imageIds): void
    {
        // Delete only rows we created (identified by square_image_id in custom_properties)
        $existing = DB::table('media_attachments')
            ->where('attachment_type', 'Igniter\Cart\Models\Menu')
            ->where('attachment_id', $menuId)
            ->get(['id', 'custom_properties']);

        $squareSyncedIds = $existing
            ->filter(fn($row) => ! empty(
                (json_decode($row->custom_properties ?? '{}', true)['square_image_id'] ?? null)
            ))
            ->pluck('id');

        if ($squareSyncedIds->isNotEmpty()) {
            DB::table('media_attachments')->whereIn('id', $squareSyncedIds)->delete();
        }

        foreach ($imageIds as $imageId) {
            $img = $this->imageLibrary[$imageId] ?? $this->findLibraryImage($imageId);

            if (! $img) {
                SyncLog::warning('Image not in library during item sync — run a full sync to download images', [
                    'square_image_id' => $imageId,
                    'menu_id'         => $menuId,
                ]);
                continue;
            }

            DB::table('media_attachments')->insert([
                'disk'              => $img['disk'],
                'name'              => $img['name'],
                'file_name'         => $img['file_name'],
                'mime_type'         => $img['mime_type'],
                'size'              => $img['size'],
                'tag'               => 'thumb',
                'attachment_type'   => 'Igniter\Cart\Models\Menu',
                'attachment_id'     => $menuId,
                'is_public'         => 1,
                'custom_properties' => json_encode(['square_image_id' => $imageId]),
                'priority'          => 0,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    /**
     * Fallback DB lookup for images not in the in-memory library
     * (needed during incremental webhook syncs when upsertImage wasn't called).
     *
     * @return array{disk: string, name: string, file_name: string, mime_type: string, size: int}|null
     */
    private function findLibraryImage(string $squareId): ?array
    {
        // Library rows are named md5($squareId).{ext}; query by the md5 prefix
        $row = DB::table('media_attachments')
            ->where('name', 'like', md5($squareId) . '.%')
            ->whereNull('attachment_type')
            ->first(['disk', 'name', 'file_name', 'mime_type', 'size']);

        if (! $row) {
            return null;
        }

        return [
            'disk'      => $row->disk,
            'name'      => $row->name,
            'file_name' => $row->file_name,
            'mime_type' => $row->mime_type,
            'size'      => $row->size,
        ];
    }

    // ------------------------------------------------------------------
    // Soft-delete
    // ------------------------------------------------------------------

    /**
     * Mark rows as deleted when Square returns is_deleted: true.
     * Never hard-deletes — historical orders may reference the row.
     *
     * Status column differs per table: menus uses menu_status; menu_options/values
     * have no status column at all (only deleted_at).
     */
    private function softDelete(string $type, string $id): void
    {
        $tableMap = [
            'CATEGORY'      => ['table' => 'categories',        'status_col' => 'status'],
            'MODIFIER_LIST' => ['table' => 'menu_options',      'status_col' => null],
            'MODIFIER'      => ['table' => 'menu_option_values', 'status_col' => null],
            'ITEM'          => ['table' => 'menus',             'status_col' => 'menu_status'],
        ];

        $config = $tableMap[$type] ?? null;
        if (! $config) {
            return;
        }

        $updates = ['deleted_at' => now(), 'updated_at' => now()];
        if ($config['status_col']) {
            $updates[$config['status_col']] = 0;
        }

        DB::table($config['table'])
            ->where('square_object_id', $id)
            ->update($updates);

        SyncLog::info("Soft-deleted {$type}", ['square_id' => $id]);
    }

    // ------------------------------------------------------------------
    // Post-sync cleanup
    // ------------------------------------------------------------------

    /**
     * Hard-delete Square-synced menu rows (and all related data) by Square item ID.
     * Used for items that failed the channel filter, are archived, or were removed
     * from the Square catalog entirely.
     *
     * @param  string[]  $squareIds
     */
    public function purgeItems(array $squareIds): int
    {
        if (empty($squareIds)) {
            return 0;
        }

        $rows = DB::table('menus')
            ->whereIn('square_object_id', $squareIds)
            ->get(['menu_id', 'square_object_id']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $menuIds    = $rows->pluck('menu_id')->all();
        $itemSqIds  = $rows->pluck('square_object_id')->all();

        // Square-synced media attachments
        DB::table('media_attachments')
            ->where('attachment_type', 'Igniter\Cart\Models\Menu')
            ->whereIn('attachment_id', $menuIds)
            ->delete();

        // Modifier-list pivot
        DB::table('menu_item_options')
            ->whereIn('menu_id', $menuIds)
            ->delete();

        // Synthetic variation option groups (var_option_{squareId}) and their values
        $varOptionSquareIds = array_map(fn($id) => 'var_option_' . $id, $itemSqIds);
        $varOptionIds = DB::table('menu_options')
            ->whereIn('square_object_id', $varOptionSquareIds)
            ->pluck('option_id')
            ->all();

        if (! empty($varOptionIds)) {
            DB::table('menu_option_values')->whereIn('option_id', $varOptionIds)->delete();
            DB::table('menu_options')->whereIn('option_id', $varOptionIds)->delete();
        }

        // Category pivot
        DB::table('menu_categories')->whereIn('menu_id', $menuIds)->delete();

        // Menu rows
        DB::table('menus')->whereIn('menu_id', $menuIds)->delete();

        SyncLog::info('Purged Square items', ['count' => count($menuIds)]);

        return count($menuIds);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Map Square selection type to a TastyIgniter display type.
     * TI display types: radio, checkbox, select, quantity, text
     */
    private function resolveOptionDisplayType(string $selectionType): string
    {
        return match ($selectionType) {
            'SINGLE'   => 'radio',
            'MULTIPLE' => 'checkbox',
            default    => 'radio',
        };
    }
}
