<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Services;

use Illuminate\Support\Facades\DB;
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
 *   - categories          → category_id (PK), name, status
 *   - menu_categories     → menu_id, category_id  (pivot; menus has NO category_id column)
 *   - menus               → menu_id (PK), menu_name, menu_description, menu_price, menu_status
 *   - menu_options        → option_id (PK), option_name, display_type
 *   - menu_option_values  → option_value_id (PK), option_id, name, price
 *   - menu_item_options   → menu_option_id (PK), option_id, menu_id, min_selected, max_selected, is_required
 *   - No 'taxes' table — TI stores a single global tax_percentage in settings
 *
 * Each upsert method is idempotent: running it twice produces the same result.
 * Rows are matched by square_object_id; soft-deleted when Square marks is_deleted.
 */
class CatalogMapper
{
    private int $upsertCount = 0;

    public function getUpsertCount(): int
    {
        return $this->upsertCount;
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

    private function upsertCategory(CatalogObjectCategory $wrapper): void
    {
        $data = $wrapper->getCategoryData();
        if (! $data) {
            return;
        }

        DB::table('categories')->upsert(
            [
                'square_object_id' => $wrapper->getId(),
                'name'             => $data->getName() ?? 'Unnamed Category',
                'description'      => '',
                'status'           => 1,
                'priority'         => 0,
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['name', 'updated_at'],
        );

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
            update: ['menu_name', 'menu_description', 'menu_price', 'updated_at'],
        );

        $this->upsertCount++;

        $menuId = DB::table('menus')
            ->where('square_object_id', $wrapper->getId())
            ->value('menu_id');

        if ($menuId) {
            $this->syncItemCategories($menuId, $data->getCategories() ?? []);
        }

        if (count($variations) > 1 && $menuId) {
            foreach ($variations as $variation) {
                $this->upsertVariation($variation->getValue(), $wrapper->getId());
            }
        }

        if ($menuId) {
            $this->syncItemModifierLists($menuId, $wrapper->getId(), $data->getModifierListInfo() ?? []);
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
    private function upsertVariation(mixed $varWrapper, string $parentItemSquareId): void
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

        // TI column is 'name' (not 'value'); no updated_at column on this table
        DB::table('menu_option_values')->upsert(
            [
                'square_object_id' => $varWrapper->getId(),
                'option_id'        => $optionId,
                'name'             => $varData->getName() ?? 'Unnamed',
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
    // Images
    // ------------------------------------------------------------------

    private function upsertImage(CatalogObjectImage $wrapper): void
    {
        $data = $wrapper->getImageData();
        if (! $data?->getUrl()) {
            return;
        }

        // TODO: Download image into Igniter media manager, keyed by
        // square_object_id + getVersion() to avoid re-downloading unchanged images.
        SyncLog::info('Image sync not yet implemented', [
            'square_id' => $wrapper->getId(),
            'url'       => $data->getUrl(),
        ]);
    }

    // ------------------------------------------------------------------
    // Soft-delete
    // ------------------------------------------------------------------

    /**
     * Mark rows as deleted when Square returns is_deleted: true.
     * Never hard-deletes — historical orders may reference the row.
     */
    private function softDelete(string $type, string $id): void
    {
        $tableMap = [
            'CATEGORY'      => 'categories',
            'MODIFIER_LIST' => 'menu_options',
            'MODIFIER'      => 'menu_option_values',
            'ITEM'          => 'menus',
        ];

        $table = $tableMap[$type] ?? null;
        if (! $table) {
            return;
        }

        DB::table($table)
            ->where('square_object_id', $id)
            ->update([
                'status'     => 0,
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        SyncLog::info("Soft-deleted {$type}", ['square_id' => $id]);
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
