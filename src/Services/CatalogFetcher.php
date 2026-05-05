<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Services;

use Square\Types\CatalogObject;
use Square\Catalog\Requests\SearchCatalogObjectsRequest;
use Square\Catalog\Requests\ListCatalogRequest;
use Square\Catalog\Requests\BatchGetCatalogObjectsRequest;
use Square\Exceptions\SquareApiException;

/**
 * Fetches objects from the Square Catalog API (SDK v42).
 *
 * Responsibilities:
 *  - Full fetch: all object types, paginated via search()
 *  - Incremental fetch: objects updated since a given catalog version via list()
 *  - Fetch by IDs: used during webhook processing via batchGet()
 *  - No DB writes; returns raw Square SDK model objects
 *
 * v42 error handling: methods throw SquareApiException on API errors rather than
 * returning error objects. Wrap all calls in try/catch.
 */
class CatalogFetcher
{
    /**
     * Object types we care about, in dependency order.
     * (Images and taxes have no dependencies; items depend on categories + modifier lists)
     */
    private const OBJECT_TYPES = [
        'TAX',
        'IMAGE',
        'CATEGORY',
        'MODIFIER_LIST',
        'MODIFIER',
        'ITEM',
    ];

    public function __construct(
        private readonly SquareClientFactory $clientFactory
    ) {}

    // ------------------------------------------------------------------
    // Full fetch
    // ------------------------------------------------------------------

    /**
     * Fetch all catalog objects, paginated.
     * Returns a generator that yields arrays of CatalogObject.
     *
     * @return \Generator<int, CatalogObject[]>
     * @throws \RuntimeException on API errors
     */
    public function fetchAll(): \Generator
    {
        $api    = $this->clientFactory->catalog();
        $cursor = null;

        do {
            $request = new SearchCatalogObjectsRequest();
            $request->setObjectTypes(self::OBJECT_TYPES);
            $request->setIncludeRelatedObjects(false);
            $request->setIncludeDeletedObjects(false);

            if ($cursor) {
                $request->setCursor($cursor);
            }

            try {
                $response = $api->search($request);
            } catch (SquareApiException $e) {
                throw new \RuntimeException('Square Catalog search failed: ' . $e->getMessage(), 0, $e);
            }

            $objects = $response->getObjects() ?? [];
            $cursor  = $response->getCursor();

            yield $objects;

        } while ($cursor !== null);
    }

    // ------------------------------------------------------------------
    // Incremental fetch
    // ------------------------------------------------------------------

    /**
     * Fetch objects updated since $afterVersion (Square catalog version integer).
     * Returns a generator that yields arrays of CatalogObject (including deleted ones).
     *
     * Uses list() which supports catalog_version filtering for incremental sync.
     * The Pager returned by list() handles cursor pagination internally.
     *
     * @return \Generator<int, CatalogObject[]>
     * @throws \RuntimeException on API errors
     */
    public function fetchSince(string $afterVersion): \Generator
    {
        $api = $this->clientFactory->catalog();

        $request = new ListCatalogRequest();
        $request->setTypes(implode(',', self::OBJECT_TYPES));
        $request->setCatalogVersion((int) $afterVersion);

        try {
            $pager = $api->list($request);
        } catch (SquareApiException $e) {
            throw new \RuntimeException('Square Catalog list failed: ' . $e->getMessage(), 0, $e);
        }

        // Pager::getPages() yields Page objects; each Page::getItems() is CatalogObject[]
        foreach ($pager->getPages() as $page) {
            yield $page->getItems();
        }
    }

    // ------------------------------------------------------------------
    // Fetch specific objects by ID (used during webhook processing)
    // ------------------------------------------------------------------

    /**
     * @param  string[]  $ids
     * @return CatalogObject[]
     * @throws \RuntimeException on API errors
     */
    public function fetchByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $api = $this->clientFactory->catalog();

        $request = new BatchGetCatalogObjectsRequest(objectIds: $ids);

        try {
            $response = $api->batchGet($request);
        } catch (SquareApiException $e) {
            throw new \RuntimeException('Square batch retrieve failed: ' . $e->getMessage(), 0, $e);
        }

        return $response->getObjects() ?? [];
    }
}
