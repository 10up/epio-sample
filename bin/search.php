<?php

declare(strict_types=1);

/**
 * Search script for ElasticPress.io Nobel Prize sample.
 *
 * This script performs searches from the command line.
 *
 * Usage:
 *   php bin/search.php "search query"
 *   php bin/search.php "einstein" --category=physics
 *   php bin/search.php --category=chemistry --year-from=2000
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchService;

echo "=== ElasticPress.io Nobel Prize Sample - Search ===\n\n";

try {
    // Load configuration
    $config = Config::getInstance();
    $config->validate();

    // Parse arguments
    $query = '';
    $filters = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            // Parse filter arguments
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $filters[str_replace('-', '_', $key)] = $value;
        } else {
            // Search query
            $query = $arg;
        }
    }

    if (empty($query) && empty($filters)) {
        echo "Usage: php bin/search.php \"search query\" [--category=physics] [--year-from=2000] [--year-to=2023]\n";
        echo "       [--gender=male] [--birth-country=US] [--prize-country=US]\n\n";
        echo "Examples:\n";
        echo "  php bin/search.php \"einstein\"\n";
        echo "  php bin/search.php \"physics\" --category=physics\n";
        echo "  php bin/search.php --category=chemistry --year-from=2000\n";
        exit(0);
    }

    // Perform search
    $indexName = $config->getIndexPrefix() . 'laureates';
    $searchService = new SearchService();

    echo "Searching for: " . ($query ?: 'all') . "\n";
    if (!empty($filters)) {
        echo "Filters: " . json_encode($filters) . "\n";
    }
    echo "\n";

    $results = $searchService->search($indexName, $query, $filters, 0, 10);

    echo "Found {$results['total']} results\n";
    echo str_repeat('=', 60) . "\n\n";

    // Display results
    foreach ($results['results'] as $i => $result) {
        $source = $result['source'];
        echo ($i + 1) . ". {$source['fullname']}\n";
        echo "   Category: {$source['category']} | Year: {$source['year']} | Gender: " . ($source['gender'] ?? 'N/A') . "\n";
        if (!empty($source['motivation'])) {
            $motivation = strlen($source['motivation']) > 100
                ? substr($source['motivation'], 0, 100) . '...'
                : $source['motivation'];
            echo "   Motivation: {$motivation}\n";
        }
        if (!empty($source['birth_country_name'])) {
            echo "   Birth: {$source['birth_country_name']}";
            if (!empty($source['birth_year'])) {
                echo " ({$source['birth_year']})";
            }
            echo "\n";
        }
        echo "\n";
    }

    // Display facets
    if (!empty($results['facets'])) {
        echo str_repeat('=', 60) . "\n";
        echo "Facets:\n\n";

        if (!empty($results['facets']['categories'])) {
            echo "Categories:\n";
            foreach ($results['facets']['categories'] as $facet) {
                echo "  - {$facet['value']}: {$facet['count']}\n";
            }
            echo "\n";
        }

        if (!empty($results['facets']['genders'])) {
            echo "Genders:\n";
            foreach ($results['facets']['genders'] as $facet) {
                echo "  - {$facet['value']}: {$facet['count']}\n";
            }
            echo "\n";
        }

        if (!empty($results['facets']['year_range'])) {
            $range = $results['facets']['year_range'];
            echo "Year Range: {$range['min']} - {$range['max']} (avg: {$range['avg']})\n\n";
        }

        if (!empty($results['facets']['birth_countries'])) {
            echo "Top Birth Countries:\n";
            foreach (array_slice($results['facets']['birth_countries'], 0, 10) as $facet) {
                echo "  - {$facet['value']}: {$facet['count']}\n";
            }
            echo "\n";
        }
    }

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
