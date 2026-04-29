<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Services;

use Illuminate\Support\Facades\DB;
use Kirbygo\SquareCatalogSync\Models\Settings;
use Kirbygo\SquareCatalogSync\Models\SyncLog;
use Square\Models\CatalogObject;
use Square\Models\CatalogCategory;
use Square\Models\CatalogItem;
use Square\Models\CatalogItemVariation;
use Square\Models\CatalogModifierList;
use Square\Models\CatalogModifier;
use Square\Models\CatalogTax;
use Square\Models\CatalogImage;

/**
 * Maps Square CatalogObjects to TastyIgniter database rows.
 *
 * Each upsert method is idempotent: running it twice produces the same result.
 * Rows are matched by square_object_id; soft-deleted when Square marks is_deleted.
 *
 * Table names are based on TastyIgniter's default schema — verify these against
 * your TastyIgniter version if anything doesn't map:
 *   categories, menus, menu_options, menu_option_values, taxes
 *
 * Currency is read from the Square location (see SquareClientFactory::locations()),
 * not hardcoded here.
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
     * Upsert a single CatalogObject into the appropriate TI table.
     */
    public function upsert(CatalogObject $object): void
    {
        if ($object->getIsDeleted()) {
            $this->softDelete($object);
            return;
        }

        match ($object->getType()) {
            'TAX'           => $this->upsertTax($object),
            'CATEGORY'      => $this->upsertCategory($object),
            'MODIFIER_LIST' => $this->upsertModifierList($object),
            'MODIFIER'      => $this->upsertModifier($object),
            'ITEM'          => $this->upsertItem($object),
            'IMAGE'         => $this->upsertImage($object),
            default         => null, // ignore unknown types
        };
    }

    // ------------------------------------------------------------------
    // Taxes → taxes table
    // ------------------------------------------------------------------

    private function upsertTax(CatalogObject $object): void
    {
        $data = $object->getTaxData();
        if (! $data) {
            return;
        }

        // Square percentage is stored as a string ("8.875")
        $percentage = (float) ($data->getPercentage() ?? '0');

        DB::table('taxes')->upsert(
            [
                'square_object_id' => $object->getId(),
                'tax_name'         => $data->getName() ?? 'Unnamed Tax',
                'tax_rate'         => $percentage,
                'priority'         => 1,
                'status'           => 1,
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['tax_name', 'tax_rate', 'updated_at'],
        );

        $this->upsertCount++;
    }

    // ------------------------------------------------------------------
    // Categories → categories table
    // ------------------------------------------------------------------

    private function upsertCategory(CatalogObject $object): void
    {
        $data = $object->getCategoryData();
        if (! $data) {
            return;
        }

        DB::table('categories')->upsert(
            [
                'square_object_id'   => $object->getId(),
                'name'               => $data->getName() ?? 'Unnamed Category',
                'description'        => '',
                'status'             => 1,
                'priority'           => 0,
                'updated_at'         => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['name', 'updated_at'],
        );

        $this->upsertCount++;
    }

    // ------------------------------------------------------------------
    // Modifier Lists → menu_options table
    // ------------------------------------------------------------------

    private function upsertModifierList(CatalogObject $object): void
    {
        $data = $object->getModifierListData();
        if (! $data) {
            return;
        }

        // Determine display type from selection type + min/max
        $selectionType = $data->getSelectionType() ?? 'SINGLE';
        $displayType   = $this->resolveOptionDisplayType(
            selectionType: $selectionType,
            min: null, // modifier list level min (item-level overrides handled separately)
            max: null,
        );

        DB::table('menu_options')->upsert(
            [
                'square_object_id' => $object->getId(),
                'option_name'      => $data->getName() ?? 'Unnamed Option',
                'display_type'     => $displayType,
                'status'           => 1,
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

    private function upsertModifier(CatalogObject $object): void
    {
        $data = $object->getModifierData();
        if (! $data) {
            return;
        }

        // Skip hidden-from-customer modifiers
        if ($data->getHiddenFromCustomer()) {
            return;
        }

        // Resolve the parent modifier list's TI row ID
        $modifierListId = $data->getModifierListId();
        $optionId = $modifierListId
            ? DB::table('menu_options')
                ->where('square_object_id', $modifierListId)
                ->value('option_id')
            : null;

        $priceMoney = $data->getPriceMoney();
        $priceAmount = $priceMoney ? $priceMoney->getAmount() : 0;
        $priceDollars = $priceAmount / 100; // Square stores in smallest currency unit

        DB::table('menu_option_values')->upsert(
            [
                'square_object_id' => $object->getId(),
                'option_id'        => $optionId,
                'value'            => $data->getName() ?? 'Unnamed Modifier',
                'price'            => $priceDollars,
                'is_default'       => $data->getOnByDefault() ? 1 : 0,
                'priority'         => 0,
                'status'           => 1,
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['option_id', 'value', 'price', 'is_default', 'updated_at'],
        );

        $this->upsertCount++;
    }

    // ------------------------------------------------------------------
    // Items → menus table (+ variations as options when > 1 variation)
    // ------------------------------------------------------------------

    private function upsertItem(CatalogObject $object): void
    {
        $data = $object->getItemData();
        if (! $data) {
            return;
        }

        // Resolve category_id from the first assigned category (TI is single-category)
        $categorySquareId = null;
        $categories = $data->getCategories() ?? [];
        if (! empty($categories)) {
            $categorySquareId = $categories[0]->getId();
        }

        $categoryId = $categorySquareId
            ? DB::table('categories')->where('square_object_id', $categorySquareId)->value('category_id')
            : null;

        // Derive price from first variation (master price for the menu row)
        $variations = $data->getVariations() ?? [];
        $masterPrice = 0.0;
        if (! empty($variations)) {
            $firstVar = $variations[0];
            $varData  = $firstVar->getItemVariationData();
            $priceMoney = $varData?->getPriceMoney();
            if ($priceMoney) {
                $masterPrice = $priceMoney->getAmount() / 100;
            }
        }

        $menuId = DB::table('menus')->upsert(
            [
                'square_object_id' => $object->getId(),
                'menu_name'        => $data->getName() ?? 'Unnamed Item',
                'menu_description' => $data->getDescription() ?? '',
                'menu_price'       => $masterPrice,
                'category_id'      => $categoryId,
                'menu_status'      => 1,
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['menu_name', 'menu_description', 'menu_price', 'category_id', 'updated_at'],
        );

        $this->upsertCount++;

        // Upsert variations (only when item has more than one variation)
        if (count($variations) > 1) {
            foreach ($variations as $variation) {
                $this->upsertVariation($variation, $object->getId());
            }
        }

        // Attach modifier lists to this menu item
        $this->syncItemModifierLists($object);
    }

    // ------------------------------------------------------------------
    // Item Variations → menu_options / menu_option_values
    // ------------------------------------------------------------------

    private function upsertVariation(CatalogObject $variation, string $parentItemSquareId): void
    {
        $varData = $variation->getItemVariationData();
        if (! $varData) {
            return;
        }

        $priceMoney = $varData->getPriceMoney();
        $price = $priceMoney ? $priceMoney->getAmount() / 100 : 0.0;

        // Ensure a "Size" option row exists for this item (keyed by item + sentinel)
        $sizeOptionSquareId = 'var_option_' . $parentItemSquareId;

        DB::table('menu_options')->upsert(
            [
                'square_object_id' => $sizeOptionSquareId,
                'option_name'      => 'Size',
                'display_type'     => 'radio',
                'status'           => 1,
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['updated_at'],
        );

        $optionId = DB::table('menu_options')
            ->where('square_object_id', $sizeOptionSquareId)
            ->value('option_id');

        DB::table('menu_option_values')->upsert(
            [
                'square_object_id' => $variation->getId(),
                'option_id'        => $optionId,
                'value'            => $varData->getName() ?? 'Unnamed',
                'price'            => $price,
                'is_default'       => 0,
                'priority'         => 0,
                'status'           => 1,
                'updated_at'       => now(),
            ],
            uniqueBy: ['square_object_id'],
            update: ['option_id', 'value', 'price', 'updated_at'],
        );

        $this->upsertCount++;
    }

    // ------------------------------------------------------------------
    // Item ↔ Modifier List associations
    // ------------------------------------------------------------------

    /**
     * Sync the menu_item_options pivot for this item's modifier lists.
     * Handles item-level overrides (min/max) by writing per-item config
     * rather than reusing the shared option — Square normalizes this,
     * TastyIgniter expects it denormalized.
     */
    private function syncItemModifierLists(CatalogObject $object): void
    {
        $data = $object->getItemData();

        $menuId = DB::table('menus')
            ->where('square_object_id', $object->getId())
            ->value('menu_id');

        if (! $menuId) {
            return;
        }

        $modifierListInfos = $data->getModifierListInfo() ?? [];

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
                    'item_square_id'          => $object->getId(),
                    'modifier_list_square_id' => $modifierListSquareId,
                ]);
                continue;
            }

            // Item-level min/max override
            $minSelected = $info->getMinSelectedModifiers();
            $maxSelected = $info->getMaxSelectedModifiers();

            // Upsert the pivot row (menu_menu_options is the typical TI pivot table name)
            DB::table('menu_menu_options')->upsert(
                [
                    'menu_id'    => $menuId,
                    'option_id'  => $optionId,
                    'min_option' => $minSelected ?? 0,
                    'max_option' => $maxSelected ?? 0,
                    'updated_at' => now(),
                ],
                uniqueBy: ['menu_id', 'option_id'],
                update: ['min_option', 'max_option', 'updated_at'],
            );
        }
    }

    // ------------------------------------------------------------------
    // Images → Igniter media
    // ------------------------------------------------------------------

    private function upsertImage(CatalogObject $object): void
    {
        $data = $object->getImageData();
        if (! $data?->getUrl()) {
            return;
        }

        // TODO: Download image into Igniter media manager, keyed by
        // square_object_id + object->getVersion() to avoid re-downloading
        // unchanged images. Implement in a follow-up.
        SyncLog::info('Image sync not yet implemented', [
            'square_id' => $object->getId(),
            'url'       => $data->getUrl(),
        ]);
    }

    // ------------------------------------------------------------------
    // Soft-delete
    // ------------------------------------------------------------------

    /**
     * Mark rows as deleted when Square returns is_deleted: true.
     * We never hard-delete — historical orders may reference the row.
     */
    private function softDelete(CatalogObject $object): void
    {
        $id   = $object->getId();
        $type = $object->getType();

        $tableMap = [
            'TAX'           => 'taxes',
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
     * Map Square selection type + min/max to a TastyIgniter display type.
     *
     * TI display types: radio, checkbox, select, quantity, text
     */
    private function resolveOptionDisplayType(
        string $selectionType,
        ?int $min,
        ?int $max,
    ): string {
        if ($selectionType === 'SINGLE') {
            return 'radio';
        }

        // MULTIPLE — use checkbox unless max == 1
        if ($max === 1) {
            return 'radio';
        }

        return 'checkbox';
    }
}
