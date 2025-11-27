<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Search;

use ElasticPressIO\Sample\Client\ElasticsearchClient;
use ElasticPressIO\Sample\Config\Config;

/**
 * Manages ElasticPress.io search templates.
 *
 * Search templates allow frontend JavaScript to query Elasticsearch directly
 * without exposing authentication credentials, while maintaining security.
 *
 * @see https://www.elasticpress.io/resources/articles/instant-results-post-search-api/
 */
class SearchTemplateManager
{
    private ElasticsearchClient $client;
    private Config $config;

    public function __construct(?ElasticsearchClient $client = null, ?Config $config = null)
    {
        $this->client = $client ?? new ElasticsearchClient();
        $this->config = $config ?? Config::getInstance();
    }

    /**
     * Create an autosuggest search template.
     *
     * This template allows fast name suggestions without authentication.
     * Uses the {{ep_placeholder}} syntax required by ElasticPress.io.
     *
     * Structure exactly matches ElasticPress.io production autosuggest with
     * the proper nesting: bool -> should -> bool -> must -> bool -> should -> multi_match
     *
     * @param string $indexName Index name
     * @return array Response from ElasticPress.io
     */
    public function createAutosuggestTemplate(string $indexName): array
    {
        $template = [
            'template' => [
                'query' => [
                    'bool' => [
                        'should' => [
                            [
                                'match_phrase_prefix' => [
                                    'fullname' => [
                                        'query' => '{{ep_placeholder}}',
                                        'boost' => 3,
                                    ],
                                ],
                            ],
                            [
                                'match_phrase_prefix' => [
                                    'firstname' => [
                                        'query' => '{{ep_placeholder}}',
                                        'boost' => 2,
                                    ],
                                ],
                            ],
                            [
                                'match' => [
                                    'fullname' => [
                                        'query' => '{{ep_placeholder}}',
                                        'fuzziness' => 'auto',
                                        'boost' => 1,
                                    ],
                                ],
                            ],
                            [
                                'match' => [
                                    'motivation' => [
                                        'query' => '{{ep_placeholder}}',
                                        'boost' => 0.5,
                                    ],
                                ],
                            ],
                            [
                                'nested' => [
                                    'path' => 'affiliations',
                                    'query' => [
                                        'match' => [
                                            'affiliations.name' => [
                                                'query' => '{{ep_placeholder}}',
                                                'boost' => 0.5,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $this->client->put("/api/v1/search/posts/{$indexName}/template", $template);
    }

    /**
     * Create a full search template with faceting.
     *
     * This template provides full-text search with faceted filtering.
     *
     * @param string $indexName Index name
     * @return array Response from ElasticPress.io
     */
    public function createSearchTemplate(string $indexName): array
    {
        $template = [
            'template' => [
                'size' => 20,
                'from' => 0,
                'query' => [
                    'bool' => [
                        'must' => [],
                        'filter' => [],
                    ],
                ],
                'aggs' => [
                    'categories' => [
                        'terms' => [
                            'field' => 'category',
                            'size' => 10,
                        ],
                    ],
                    'genders' => [
                        'terms' => [
                            'field' => 'gender',
                            'size' => 10,
                        ],
                    ],
                    'year_stats' => [
                        'stats' => [
                            'field' => 'year',
                        ],
                    ],
                ],
                'sort' => [
                    ['year' => ['order' => 'desc']],
                    '_score',
                ],
            ],
        ];

        // Add search query if placeholder is provided
        $template['template']['query']['bool']['must'][] = [
            'multi_match' => [
                'query' => '{{ep_placeholder}}',
                'fields' => [
                    'fullname^3',
                    'firstname^2',
                    'surname^2',
                    'motivation',
                    'affiliations.name',
                ],
                'type' => 'best_fields',
                'operator' => 'or',
            ],
        ];

        return $this->client->put("/api/v1/search/posts/{$indexName}/template", $template);
    }

    /**
     * Get the current search template for an index.
     *
     * @param string $indexName Index name
     * @return array Template configuration
     */
    public function getTemplate(string $indexName): array
    {
        return $this->client->get("/api/v1/search/posts/{$indexName}/template");
    }

    /**
     * Delete a search template.
     *
     * @param string $indexName Index name
     * @return array Response from ElasticPress.io
     */
    public function deleteTemplate(string $indexName): array
    {
        return $this->client->delete("/api/v1/search/posts/{$indexName}/template");
    }

    /**
     * List all available search templates.
     *
     * @return array List of templates
     */
    public function listTemplates(): array
    {
        return $this->client->get('/api/v1/search/posts/templates');
    }

    /**
     * Get the public search API URL for an index.
     *
     * This URL can be called directly from frontend JavaScript without authentication.
     *
     * @param string $indexName Index name
     * @return string Public API URL
     */
    public function getPublicSearchUrl(string $indexName): string
    {
        $host = rtrim($this->config->getElasticsearchHost(), '/');
        return "{$host}/api/v1/search/posts/{$indexName}";
    }

    /**
     * Get the public autosuggest API URL for an index.
     *
     * This URL can be called directly from frontend JavaScript for autosuggest/autocomplete.
     *
     * @param string $indexName Index name
     * @return string Public autosuggest API URL
     */
    public function getAutosuggestUrl(string $indexName): string
    {
        $host = rtrim($this->config->getElasticsearchHost(), '/');
        return "{$host}/{$indexName}/autosuggest";
    }
}
