<?php

declare(strict_types=1);

namespace Kirbygo\SquareCatalogSync\Services;

use Square\SquareClient;
use Square\Environments;

/**
 * Builds a configured Square SDK client from stored settings.
 *
 * The Square-Version header is pinned explicitly so that silent API
 * upgrades don't break the sync. Bump it deliberately when you've
 * verified the new version works end-to-end.
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
     * Build and return a Square client using the stored credentials.
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
                'environment' => $isSandbox ? Environments::Sandbox : Environments::Production,
                'squareVersion' => self::SQUARE_VERSION,
            ]
        );
    }

    /**
     * Convenience: build a client and return the Catalog API resource.
     */
    public function catalog(): \Square\Apis\CatalogApi
    {
        return $this->make()->getCatalogApi();
    }

    /**
     * Convenience: build a client and return the Locations API resource.
     */
    public function locations(): \Square\Apis\LocationsApi
    {
        return $this->make()->getLocationsApi();
    }
}
