<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Search;

use ElasticPressIO\Sample\Client\ElasticsearchClient;

/**
 * Service for searching Nobel Prize data with faceted search capabilities.
 *
 * Supports:
 * - Full-text search across names, motivations, and affiliations
 * - Faceted filtering by category, year range, gender, countries
 * - Multi-index searching
 * - Aggregations for facet counts
 *
 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-search.html
 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-aggregations.html
 */
class SearchService
{
    private ElasticsearchClient $client;

    public function __construct(?ElasticsearchClient $client = null)
    {
        $this->client = $client ?? new ElasticsearchClient();
    }

    /**
     * Search Nobel Prize laureates with faceting support.
     *
     * @param string|array $index Index name or array of index names
     * @param string $query Search query text
     * @param array $filters Filters to apply (category, year, gender, countries)
     * @param int $from Offset for pagination
     * @param int $size Number of results to return
     * @param bool $includeFacets Whether to include facet aggregations
     * @return array Search results with hits and facets
     */
    public function search(
        string|array $index,
        string $query = '',
        array $filters = [],
        int $from = 0,
        int $size = 20,
        bool $includeFacets = true
    ): array {
        $indexPath = is_array($index) ? implode(',', $index) : $index;

        $body = $this->buildSearchQuery($query, $filters, $from, $size, $includeFacets);

        $response = $this->client->post("/{$indexPath}/_search", $body);

        return $this->formatSearchResponse($response);
    }

    /**
     * Build Elasticsearch query DSL for search.
     *
     * @param string $query Search query text
     * @param array $filters Applied filters
     * @param int $from Pagination offset
     * @param int $size Results per page
     * @param bool $includeFacets Include aggregations
     * @return array Query body
     */
    private function buildSearchQuery(
        string $query,
        array $filters,
        int $from,
        int $size,
        bool $includeFacets
    ): array {
        $body = [
            'from' => $from,
            'size' => $size,
            'query' => $this->buildQueryClause($query, $filters),
        ];

        if ($includeFacets) {
            $body['aggs'] = $this->buildAggregations();
        }

        // Add sorting
        $body['sort'] = [
            ['year' => ['order' => 'desc']],
            '_score',
        ];

        return $body;
    }

    /**
     * Build the query clause combining full-text search and filters.
     *
     * Uses a bool query to combine:
     * - must: Full-text search requirements
     * - filter: Exact match filters (won't affect scoring)
     *
     * @param string $query Search query text
     * @param array $filters Applied filters
     * @return array Query clause
     */
    private function buildQueryClause(string $query, array $filters): array
    {
        $bool = [
            'must' => [],
            'filter' => [],
        ];

        // Full-text search on multiple fields
        if (!empty($query)) {
            $bool['must'][] = [
                'multi_match' => [
                    'query' => $query,
                    'fields' => [
                        'fullname^3',        // Boost name matches
                        'firstname^2',
                        'surname^2',
                        'motivation',
                        'affiliations.name',
                        'birth_city',
                        'birth_country_name',
                    ],
                    'type' => 'best_fields',
                    'operator' => 'or',
                ],
            ];
        } else {
            // If no query, match all documents
            $bool['must'][] = ['match_all' => new \stdClass()];
        }

        // Apply filters
        if (!empty($filters['category'])) {
            $bool['filter'][] = ['term' => ['category' => $filters['category']]];
        }

        if (!empty($filters['gender'])) {
            $bool['filter'][] = ['term' => ['gender' => $filters['gender']]];
        }

        if (!empty($filters['birth_country'])) {
            $bool['filter'][] = ['term' => ['birth_country' => $filters['birth_country']]];
        }

        if (!empty($filters['prize_country'])) {
            $bool['filter'][] = ['term' => ['prize_countries' => $filters['prize_country']]];
        }

        // Year range filter
        if (!empty($filters['year_from']) || !empty($filters['year_to'])) {
            $rangeFilter = ['year' => []];

            if (!empty($filters['year_from'])) {
                $rangeFilter['year']['gte'] = (int) $filters['year_from'];
            }

            if (!empty($filters['year_to'])) {
                $rangeFilter['year']['lte'] = (int) $filters['year_to'];
            }

            $bool['filter'][] = ['range' => $rangeFilter];
        }

        return ['bool' => $bool];
    }

    /**
     * Build aggregations for faceted search.
     *
     * Aggregations provide counts for each facet value, enabling
     * users to see available filter options and their frequencies.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-aggregations.html
     *
     * @return array Aggregations configuration
     */
    private function buildAggregations(): array
    {
        return [
            // Category facet
            'categories' => [
                'terms' => [
                    'field' => 'category',
                    'size' => 10,
                ],
            ],

            // Gender facet
            'genders' => [
                'terms' => [
                    'field' => 'gender',
                    'size' => 10,
                ],
            ],

            // Birth country facet
            'birth_countries' => [
                'terms' => [
                    'field' => 'birth_country',
                    'size' => 50,
                ],
            ],

            // Prize country facet
            'prize_countries' => [
                'terms' => [
                    'field' => 'prize_countries',
                    'size' => 50,
                ],
            ],

            // Year range statistics
            'year_stats' => [
                'stats' => [
                    'field' => 'year',
                ],
            ],

            // Year histogram (for year range faceting)
            'years' => [
                'histogram' => [
                    'field' => 'year',
                    'interval' => 10,
                    'min_doc_count' => 1,
                ],
            ],
        ];
    }

    /**
     * Format the raw Elasticsearch response into a cleaner structure.
     *
     * @param array $response Raw Elasticsearch response
     * @return array Formatted response
     */
    private function formatSearchResponse(array $response): array
    {
        $total = $response['hits']['total']['value'] ?? 0;
        $hits = $response['hits']['hits'] ?? [];
        $aggregations = $response['aggregations'] ?? [];

        // Format hits
        $results = [];
        foreach ($hits as $hit) {
            $results[] = [
                'id' => $hit['_id'],
                'score' => $hit['_score'],
                'source' => $hit['_source'],
            ];
        }

        // Format facets
        $facets = $this->formatFacets($aggregations);

        return [
            'total' => $total,
            'results' => $results,
            'facets' => $facets,
        ];
    }

    /**
     * Format aggregations into user-friendly facets.
     *
     * @param array $aggregations Raw aggregations from Elasticsearch
     * @return array Formatted facets
     */
    private function formatFacets(array $aggregations): array
    {
        $facets = [];

        // Categories
        if (isset($aggregations['categories']['buckets'])) {
            $facets['categories'] = array_map(function ($bucket) {
                return [
                    'value' => $bucket['key'],
                    'count' => $bucket['doc_count'],
                ];
            }, $aggregations['categories']['buckets']);
        }

        // Genders
        if (isset($aggregations['genders']['buckets'])) {
            $facets['genders'] = array_map(function ($bucket) {
                return [
                    'value' => $bucket['key'],
                    'count' => $bucket['doc_count'],
                ];
            }, $aggregations['genders']['buckets']);
        }

        // Birth countries
        if (isset($aggregations['birth_countries']['buckets'])) {
            $facets['birth_countries'] = array_map(function ($bucket) {
                return [
                    'value' => $bucket['key'],
                    'count' => $bucket['doc_count'],
                ];
            }, $aggregations['birth_countries']['buckets']);
        }

        // Prize countries
        if (isset($aggregations['prize_countries']['buckets'])) {
            $facets['prize_countries'] = array_map(function ($bucket) {
                return [
                    'value' => $bucket['key'],
                    'count' => $bucket['doc_count'],
                ];
            }, $aggregations['prize_countries']['buckets']);
        }

        // Year stats
        if (isset($aggregations['year_stats'])) {
            $facets['year_range'] = [
                'min' => (int) ($aggregations['year_stats']['min'] ?? 0),
                'max' => (int) ($aggregations['year_stats']['max'] ?? 0),
                'avg' => round($aggregations['year_stats']['avg'] ?? 0, 1),
            ];
        }

        // Year histogram
        if (isset($aggregations['years']['buckets'])) {
            $facets['year_histogram'] = array_map(function ($bucket) {
                return [
                    'year' => (int) $bucket['key'],
                    'count' => $bucket['doc_count'],
                ];
            }, $aggregations['years']['buckets']);
        }

        return $facets;
    }

    /**
     * Get a document by ID.
     *
     * @param string $index Index name
     * @param string $id Document ID
     * @return array|null Document or null if not found
     */
    public function getDocument(string $index, string $id): ?array
    {
        try {
            $response = $this->client->get("/{$index}/_doc/{$id}");
            return $response['_source'] ?? null;
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw $e;
        }
    }
}
