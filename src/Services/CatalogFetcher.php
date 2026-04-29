<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Services;

use Square\Models\CatalogObject;
use Square\Models\SearchCatalogObjectsRequest;

/**
 * Fetches objects from the Square Catalog API.
 *
 * Responsibilities:
 *  - Full fetch: all object types, paginated
 *  - Incremental fetch: objects updated since a given catalog version
 *  - No DB writes; returns raw Square SDK model objects
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
     */
    public function fetchAll(): \Generator
    {
        $api = $this->clientFactory->catalog();
        $cursor = null;

        do {
            $request = new SearchCatalogObjectsRequest();
            $request->setObjectTypes(self::OBJECT_TYPES);
            $request->setIncludeRelatedObjects(false);
            $request->setIncludeDeletedObjects(false);

            if ($cursor) {
                $request->setCursor($cursor);
            }

            $response = $api->searchObjects($request);

            if ($response->isError()) {
                throw new \RuntimeException(
                    'Square Catalog search failed: ' . $this->formatErrors($response->getErrors())
                );
            }

            $result = $response->getResult();
            $objects = $result->getObjects() ?? [];

            yield $objects;

            $cursor = $result->getCursor();
        } while ($cursor !== null);
    }

    // ------------------------------------------------------------------
    // Incremental fetch
    // ------------------------------------------------------------------

    /**
     * Fetch objects updated since $afterVersion (Square catalog version token).
     * Returns a generator that yields arrays of CatalogObject (including deleted ones).
     *
     * @return \Generator<int, CatalogObject[]>
     */
    public function fetchSince(string $afterVersion): \Generator
    {
        $api = $this->clientFactory->catalog();
        $cursor = null;

        do {
            $body = ['types' => implode(',', self::OBJECT_TYPES)];

            if ($cursor) {
                $body['cursor'] = $cursor;
            }

            // listCatalog streams all objects; filter by begin_time isn't available
            // for version-based sync — we use the catalog version comparison instead.
            // Square's recommended approach for incremental sync is:
            // GET /v2/catalog/list?types=...&catalog_version={afterVersion}
            $response = $api->listCatalog(
                cursor: $cursor,
                types: implode(',', self::OBJECT_TYPES),
                catalogVersion: (int) $afterVersion,
            );

            if ($response->isError()) {
                throw new \RuntimeException(
                    'Square Catalog list failed: ' . $this->formatErrors($response->getErrors())
                );
            }

            $result = $response->getResult();
            $objects = $result->getObjects() ?? [];

            yield $objects;

            $cursor = $result->getCursor();
        } while ($cursor !== null);
    }

    // ------------------------------------------------------------------
    // Fetch specific objects by ID (used during webhook processing)
    // ------------------------------------------------------------------

    /**
     * @param  string[]  $ids
     * @return CatalogObject[]
     */
    public function fetchByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $api = $this->clientFactory->catalog();
        $response = $api->batchRetrieveCatalogObjects(
            new \Square\Models\BatchRetrieveCatalogObjectsRequest($ids)
        );

        if ($response->isError()) {
            throw new \RuntimeException(
                'Square batch retrieve failed: ' . $this->formatErrors($response->getErrors())
            );
        }

        return $response->getResult()->getObjects() ?? [];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function formatErrors(array $errors): string
    {
        return implode('; ', array_map(
            fn($e) => "[{$e->getCategory()}:{$e->getCode()}] {$e->getDetail()}",
            $errors
        ));
    }
}
