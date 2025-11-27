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
