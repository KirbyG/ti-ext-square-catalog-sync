<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Services;

use Square\SquareClient;
use Square\Environments;
use Square\Catalog\CatalogClient;
use Square\Locations\LocationsClient;

/**
 * Builds a configured Square SDK client from stored settings.
 *
 * Uses Square PHP SDK v42 where API resources are properties on the client
 * ($client->catalog, $client->locations) rather than method calls.
 *
 * The Square-Version header is pinned explicitly so that silent API
 * upgrades don't break the sync. Bump it deliberately after testing.
 */
class SquareClientFactory
{
    /**
     * Pinned Square API version. Update consciously after testing.
     */
    public const SQUARE_VERSION = '2025-04-16';

    public function __construct(
        private readonly string $settingsClass
    ) {}

    /**
     * Build and return a configured Square client.
     *
     * @throws \RuntimeException if credentials are missing.
     */
    public function make(): SquareClient
    {
        $settings = new ($this->settingsClass)();
        $token = $settings->accessToken();

        if (! $token) {
            throw new \RuntimeException(
                'Square access token is not configured. Visit Settings → Square Catalog Sync.'
            );
        }

        $isSandbox = ($this->settingsClass)::isSandbox();

        return new SquareClient(
            token: $token,
            options: [
                'environment'   => $isSandbox ? Environments::Sandbox : Environments::Production,
                'squareVersion' => self::SQUARE_VERSION,
            ]
        );
    }

    /**
     * Build a client and return its Catalog API resource (v42: property, not method).
     */
    public function catalog(): CatalogClient
    {
        return $this->make()->catalog;
    }

    /**
     * Build a client and return its Locations API resource (v42: property, not method).
     */
    public function locations(): LocationsClient
    {
        return $this->make()->locations;
    }
}
