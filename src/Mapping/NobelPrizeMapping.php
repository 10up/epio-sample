<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Mapping;

/**
 * Defines the Elasticsearch mapping for Nobel Prize data.
 *
 * This mapping defines field types and properties for indexing Nobel Prize
 * laureates with support for faceted search by category, year, gender, and countries.
 *
 * Field types used:
 * - text: Full-text searchable fields (name, motivation)
 * - keyword: Exact match fields for filtering and aggregations (category, gender, country)
 * - integer: Numeric fields (year, share, birth year)
 * - date: Date fields with format specification
 *
 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/mapping-types.html
 * @see https://github.com/10up/ElasticPress/raw/refs/heads/develop/includes/mappings/post/7-0.php
 */
class NobelPrizeMapping
{
    /**
     * Get the complete mapping configuration for the Nobel Prize index.
     *
     * This mapping is designed to support:
     * - Full-text search on names, motivations, and affiliations
     * - Faceting by category, year range, gender, prize country, and birth country
     * - Sorting by year, share, and birth date
     *
     * @return array Elasticsearch mapping configuration
     */
    public static function getMapping(): array
    {
        return [
            'properties' => [
                // Laureate identification
                'id' => [
                    'type' => 'keyword',
                ],
                'firstname' => [
                    'type' => 'text',
                    'fields' => [
                        'keyword' => [
                            'type' => 'keyword',
                            'ignore_above' => 256,
                        ],
                    ],
                ],
                'surname' => [
                    'type' => 'text',
                    'fields' => [
                        'keyword' => [
                            'type' => 'keyword',
                            'ignore_above' => 256,
                        ],
                    ],
                ],
                'fullname' => [
                    'type' => 'text',
                    'analyzer' => 'standard',
                    'fields' => [
                        'keyword' => [
                            'type' => 'keyword',
                            'ignore_above' => 256,
                        ],
                    ],
                ],

                // Prize information
                'category' => [
                    'type' => 'keyword', // For exact matching and aggregations
                ],
                'year' => [
                    'type' => 'integer', // For range queries and aggregations
                ],
                'share' => [
                    'type' => 'integer',
                ],
                'motivation' => [
                    'type' => 'text',
                    'analyzer' => 'standard',
                ],

                // Personal information for faceting
                'gender' => [
                    'type' => 'keyword', // For gender faceting
                ],
                'birth_date' => [
                    'type' => 'date',
                    'format' => 'yyyy-MM-dd||yyyy||epoch_millis',
                    'ignore_malformed' => true,
                ],
                'birth_year' => [
                    'type' => 'integer',
                ],
                'birth_country' => [
                    'type' => 'keyword', // For birth country faceting
                ],
                'birth_country_name' => [
                    'type' => 'text',
                    'fields' => [
                        'keyword' => [
                            'type' => 'keyword',
                            'ignore_above' => 256,
                        ],
                    ],
                ],
                'birth_city' => [
                    'type' => 'text',
                    'fields' => [
                        'keyword' => [
                            'type' => 'keyword',
                            'ignore_above' => 256,
                        ],
                    ],
                ],

                // Death information
                'death_date' => [
                    'type' => 'date',
                    'format' => 'yyyy-MM-dd||yyyy||epoch_millis',
                    'ignore_malformed' => true,
                ],
                'death_year' => [
                    'type' => 'integer',
                ],
                'death_country' => [
                    'type' => 'keyword',
                ],
                'death_country_name' => [
                    'type' => 'text',
                    'fields' => [
                        'keyword' => [
                            'type' => 'keyword',
                            'ignore_above' => 256,
                        ],
                    ],
                ],
                'death_city' => [
                    'type' => 'text',
                    'fields' => [
                        'keyword' => [
                            'type' => 'keyword',
                            'ignore_above' => 256,
                        ],
                    ],
                ],

                // Affiliations - using nested type for complex objects
                'affiliations' => [
                    'type' => 'nested',
                    'properties' => [
                        'name' => [
                            'type' => 'text',
                            'fields' => [
                                'keyword' => [
                                    'type' => 'keyword',
                                    'ignore_above' => 256,
                                ],
                            ],
                        ],
                        'city' => [
                            'type' => 'text',
                            'fields' => [
                                'keyword' => [
                                    'type' => 'keyword',
                                    'ignore_above' => 256,
                                ],
                            ],
                        ],
                        'country' => [
                            'type' => 'keyword', // For prize country faceting
                        ],
                        'country_name' => [
                            'type' => 'text',
                            'fields' => [
                                'keyword' => [
                                    'type' => 'keyword',
                                    'ignore_above' => 256,
                                ],
                            ],
                        ],
                    ],
                ],

                // Additional metadata
                'prize_countries' => [
                    'type' => 'keyword', // Flattened list of all affiliation countries for easier faceting
                ],

                // Vector embedding for semantic / kNN search
                // Generated from: "{category} {fullname} ({year}): {motivation}. Affiliated with: ..."
                // index: true builds the HNSW graph required for ES 8.x knn queries.
                // similarity: cosine is standard for text embeddings (direction matters, magnitude does not).
                // WARNING: dims must match OPENAI_EMBEDDING_DIMS. Changing it requires re-creating the index
                // and regenerating all embeddings — it cannot be updated in place.
                'motivation_embedding' => [
                    'type'       => 'dense_vector',
                    'dims'       => 1536,
                    'index'      => true,
                    'similarity' => 'cosine',
                ],
            ],
        ];
    }

    /**
     * Get index settings for the Nobel Prize index.
     *
     * These settings configure the index behavior including:
     * - Number of shards and replicas
     * - Analysis settings for text processing
     *
     * @return array Index settings
     */
    public static function getSettings(): array
    {
        return [
            'number_of_shards' => 1,
            'number_of_replicas' => 1,
            'analysis' => [
                'analyzer' => [
                    'default' => [
                        'type' => 'standard',
                        'stopwords' => '_english_',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get the mapping patch for adding the vector embedding field to an existing index.
     *
     * Use this with IndexManager::updateMappings() when you want to add semantic
     * search support without re-creating the entire index.
     *
     * @return array Partial mapping to be PUT to /{index}/_mapping
     */
    public static function getEmbeddingMappingPatch(): array
    {
        return [
            'properties' => [
                'motivation_embedding' => [
                    'type'       => 'dense_vector',
                    'dims'       => 1536,
                    'index'      => true,
                    'similarity' => 'cosine',
                ],
            ],
        ];
    }

    /**
     * Get the complete index configuration (settings + mappings).
     *
     * @return array Complete index configuration
     */
    public static function getIndexConfiguration(): array
    {
        return [
            'settings' => self::getSettings(),
            'mappings' => self::getMapping(),
        ];
    }
}
