<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Data;

use GuzzleHttp\Client;

/**
 * Fetches Nobel Prize data from the official Nobel Prize API v2.1.
 *
 * This class retrieves data from Nobel Prize API v2.1 endpoints:
 * - Nobel Prizes: Information about Nobel Prizes awarded each year
 * - Laureates: Information about Nobel Prize laureates
 *
 * @see https://www.nobelprize.org/about/developer-zone-2/ Nobel Prize API v2.1
 */
class NobelDataFetcher
{
    private const API_BASE_URL = 'https://api.nobelprize.org/2.1/';

    private Client $client;
    private array $countryCache = [];

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => self::API_BASE_URL,
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (compatible; ElasticPress.io-Sample/1.0)',
            ],
        ]);
    }

    /**
     * Fetch all Nobel Prizes from the API v2.1.
     *
     * @see https://www.nobelprize.org/about/developer-zone-2/
     *
     * @return array Array of prize data
     * @throws \RuntimeException If fetch fails
     */
    public function fetchPrizes(): array
    {
        echo "Fetching Nobel Prizes from API v2.1...\n";

        $allPrizes = [];
        $offset = 0;
        $limit = 100;

        // API v2.1 uses pagination
        do {
            $response = $this->client->get('nobelPrizes', [
                'query' => [
                    'offset' => $offset,
                    'limit' => $limit,
                ],
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to decode prizes JSON: ' . json_last_error_msg() . "\nBody: " . substr($body, 0, 200));
            }

            $prizes = $data['nobelPrizes'] ?? [];
            $allPrizes = array_merge($allPrizes, $prizes);

            echo "Fetched " . count($prizes) . " prizes (offset: {$offset})...\n";

            $offset += $limit;

            // Continue if we got a full page
        } while (count($prizes) === $limit);

        echo "Total prizes fetched: " . count($allPrizes) . "\n";

        return $allPrizes;
    }

    /**
     * Fetch all Nobel Laureates from the API v2.1.
     *
     * @see https://www.nobelprize.org/about/developer-zone-2/
     *
     * @return array Array of laureate data
     * @throws \RuntimeException If fetch fails
     */
    public function fetchLaureates(): array
    {
        echo "Fetching Nobel Laureates from API v2.1...\n";

        $allLaureates = [];
        $offset = 0;
        $limit = 100;

        // API v2.1 uses pagination
        do {
            $response = $this->client->get('laureates', [
                'query' => [
                    'offset' => $offset,
                    'limit' => $limit,
                ],
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to decode laureates JSON: ' . json_last_error_msg() . "\nBody: " . substr($body, 0, 200));
            }

            $laureates = $data['laureates'] ?? [];
            $allLaureates = array_merge($allLaureates, $laureates);

            echo "Fetched " . count($laureates) . " laureates (offset: {$offset})...\n";

            $offset += $limit;

            // Continue if we got a full page
        } while (count($laureates) === $limit);

        echo "Total laureates fetched: " . count($allLaureates) . "\n";

        return $allLaureates;
    }

    /**
     * Get country name from v2.1 API structure.
     *
     * In v2.1, country information is embedded within the data,
     * so we extract it directly from the laureate/prize data.
     *
     * @param array|null $countryData Country data structure from v2.1
     * @return string|null Country name or null if not found
     */
    public function getCountryName(?array $countryData): ?string
    {
        if (!$countryData) {
            return null;
        }

        // v2.1 format: {"en": "USA", "no": "USA", "se": "USA"}
        return $countryData['en'] ?? $countryData['no'] ?? $countryData['se'] ?? null;
    }

    /**
     * Get text value from v2.1 multilingual structure.
     *
     * @param array|string|null $data Multilingual text structure
     * @return string|null Text value or null
     */
    public function getMultilingualText($data): ?string
    {
        if (is_string($data)) {
            return $data;
        }

        if (is_array($data)) {
            return $data['en'] ?? $data['no'] ?? $data['se'] ?? null;
        }

        return null;
    }

    /**
     * Fetch all data needed for indexing.
     *
     * This is a convenience method that fetches all data sources.
     *
     * @return array{prizes: array, laureates: array}
     */
    public function fetchAll(): array
    {
        return [
            'prizes' => $this->fetchPrizes(),
            'laureates' => $this->fetchLaureates(),
        ];
    }
}
