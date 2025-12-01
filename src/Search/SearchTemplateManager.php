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
     * Create a search template.
     *
     * This template allows fast name suggestions without authentication.
     * Uses the {{ep_placeholder}} syntax required by ElasticPress.io.
     *
     * @see https://www.elasticpress.io/resources/articles/instant-results-post-search-api/
     *
     * @param string $indexName Index name
     * @return array Response from ElasticPress.io
     */
    public function createSearchTemplate(string $indexName): array
    {
        $template = [
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
}
