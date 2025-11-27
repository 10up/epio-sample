<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Index;

use ElasticPressIO\Sample\Client\ElasticsearchClient;
use ElasticPressIO\Sample\Config\Config;

/**
 * Manages Elasticsearch indexes.
 *
 * Provides functionality to create, list, and delete indexes on ElasticPress.io.
 *
 * @see https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-indices-create
 * @see https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-indices-delete
 */
class IndexManager
{
    private ElasticsearchClient $client;
    private Config $config;

    public function __construct(?ElasticsearchClient $client = null, ?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();
        $this->client = $client ?? new ElasticsearchClient($this->config);
    }

    /**
     * Create a new index with optional settings and mappings.
     *
     * @see https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-indices-create
     *
     * @param string $indexName Name of the index to create
     * @param array $settings Index settings (shards, replicas, analysis, etc.)
     * @param array $mappings Field mappings for the index
     * @return array Response from Elasticsearch
     * @throws \RuntimeException If index creation fails
     */
    public function createIndex(string $indexName, array $settings = [], array $mappings = []): array
    {
        $body = [];

        if (!empty($settings)) {
            $body['settings'] = $settings;
        }

        if (!empty($mappings)) {
            $body['mappings'] = $mappings;
        }

        $response = $this->client->put("/{$indexName}", $body);

        if (!($response['acknowledged'] ?? false)) {
            throw new \RuntimeException("Failed to create index '{$indexName}'");
        }

        return $response;
    }

    /**
     * Check if an index exists.
     *
     * @param string $indexName Name of the index to check
     * @return bool True if index exists, false otherwise
     */
    public function indexExists(string $indexName): bool
    {
        try {
            $this->client->get("/{$indexName}");
            return true;
        } catch (\RuntimeException $e) {
            // 404 means index doesn't exist
            if (str_contains($e->getMessage(), '404')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Delete an index.
     *
     * @see https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-indices-delete
     *
     * @param string $indexName Name of the index to delete
     * @return array Response from Elasticsearch
     * @throws \RuntimeException If index deletion fails
     */
    public function deleteIndex(string $indexName): array
    {
        $response = $this->client->delete("/{$indexName}");

        if (!($response['acknowledged'] ?? false)) {
            throw new \RuntimeException("Failed to delete index '{$indexName}'");
        }

        return $response;
    }

    /**
     * List all indexes matching a pattern.
     *
     * @param string|null $pattern Index pattern (e.g., 'nobel-*'), null for all
     * @return array Array of index information
     */
    public function listIndexes(?string $pattern = null): array
    {
        $path = $pattern ? "/{$pattern}" : '/_all';

        try {
            $response = $this->client->get($path);

            // Transform response into simpler array format
            $indexes = [];
            foreach ($response as $indexName => $indexInfo) {
                $indexes[] = [
                    'name' => $indexName,
                    'health' => $indexInfo['settings']['index']['provided_name'] ?? $indexName,
                    'docs_count' => $indexInfo['settings']['index']['number_of_shards'] ?? null,
                ];
            }

            return $indexes;
        } catch (\RuntimeException $e) {
            // If no indexes found, return empty array
            if (str_contains($e->getMessage(), '404')) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Get detailed information about an index.
     *
     * @param string $indexName Name of the index
     * @return array Index information including settings and mappings
     */
    public function getIndex(string $indexName): array
    {
        return $this->client->get("/{$indexName}");
    }

    /**
     * Update index mappings.
     *
     * Note: You can only add new fields to existing mappings. You cannot modify
     * existing field mappings.
     *
     * @see https://www.elastic.co/docs/api/doc/elasticsearch/operation/operation-indices-put-mapping
     *
     * @param string $indexName Name of the index
     * @param array $mappings New field mappings to add
     * @return array Response from Elasticsearch
     * @throws \RuntimeException If mapping update fails
     */
    public function updateMappings(string $indexName, array $mappings): array
    {
        $response = $this->client->put("/{$indexName}/_mapping", $mappings);

        if (!($response['acknowledged'] ?? false)) {
            throw new \RuntimeException("Failed to update mappings for index '{$indexName}'");
        }

        return $response;
    }

    /**
     * Get the current mappings for an index.
     *
     * @param string $indexName Name of the index
     * @return array Current mappings
     */
    public function getMappings(string $indexName): array
    {
        $response = $this->client->get("/{$indexName}/_mapping");
        return $response[$indexName]['mappings'] ?? [];
    }

    /**
     * Refresh an index to make recent changes searchable.
     *
     * @param string $indexName Name of the index to refresh
     * @return array Response from Elasticsearch
     */
    public function refreshIndex(string $indexName): array
    {
        // ElasticPress.io refresh doesn't accept a body, use GET instead
        return $this->client->get("/{$indexName}/_refresh");
    }

    /**
     * Get statistics for an index.
     *
     * @param string $indexName Name of the index
     * @return array Index statistics
     */
    public function getStats(string $indexName): array
    {
        return $this->client->get("/{$indexName}/_stats");
    }

    /**
     * Get a prefixed index name based on configuration.
     *
     * @param string $baseName Base name of the index
     * @return string Full index name with prefix
     */
    public function getPrefixedIndexName(string $baseName): string
    {
        $prefix = $this->config->getIndexPrefix();
        return $prefix ? "{$prefix}-{$baseName}" : $baseName;
    }
}
