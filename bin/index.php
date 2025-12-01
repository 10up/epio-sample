<?php

declare(strict_types=1);

/**
 * Indexing script for ElasticPress.io Nobel Prize sample.
 *
 * This script:
 * 1. Fetches Nobel Prize data from the API
 * 2. Transforms the data for indexing
 * 3. Bulk indexes the data to ElasticPress.io
 *
 * Usage: php bin/index.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Data\NobelDataFetcher;
use ElasticPressIO\Sample\Data\NobelDataTransformer;
use ElasticPressIO\Sample\Index\BulkIndexer;
use ElasticPressIO\Sample\Index\IndexManager;

echo "=== ElasticPress.io Nobel Prize Sample - Indexing ===\n\n";

try {
    // Load configuration
    $config = Config::getInstance();
    $config->validate();

    $indexName = $config->getIndexPrefix() . 'laureates';

    // Check if index exists
    echo "1. Checking if index exists...\n";
    $indexManager = new IndexManager();
    if (!$indexManager->indexExists($indexName)) {
        echo "   ❌ Index '{$indexName}' does not exist\n";
        echo "   Please run 'php bin/setup.php' first\n";
        exit(1);
    }
    echo "   ✓ Index exists\n\n";

    // Fetch data
    echo "2. Fetching Nobel Prize data from API...\n";
    $fetcher = new NobelDataFetcher();
    $data = $fetcher->fetchAll();
    echo "   ✓ Data fetched successfully\n\n";

    // Transform data
    echo "3. Transforming data for indexing...\n";
    $transformer = new NobelDataTransformer($fetcher);
    $documents = $transformer->transformLaureates($data['laureates'], $data['prizes']);
    echo "   ✓ Transformed " . count($documents) . " documents\n\n";

    // Show statistics
    echo "4. Data statistics:\n";
    $stats = $transformer->getStatistics($documents);
    echo "   Total documents: " . $stats['total'] . "\n";
    echo "   Year range: " . $stats['year_range']['min'] . " - " . $stats['year_range']['max'] . "\n";
    echo "   By category:\n";
    foreach ($stats['by_category'] as $category => $count) {
        echo "     - {$category}: {$count}\n";
    }
    echo "   By gender:\n";
    foreach ($stats['by_gender'] as $gender => $count) {
        echo "     - {$gender}: {$count}\n";
    }
    echo "\n";

    // Index documents
    echo "5. Indexing documents to ElasticPress.io...\n";
    $bulkIndexer = new BulkIndexer();
    $result = $bulkIndexer->indexDocuments($indexName, $documents);

    echo "\n";
    echo "=== Indexing Complete ===\n";
    echo "Total: {$result['total']}\n";
    echo "Successful: {$result['successful']}\n";
    echo "Failed: {$result['failed']}\n";

    if (!empty($result['errors'])) {
        echo "\nErrors:\n";
        foreach (array_slice($result['errors'], 0, 5) as $error) {
            echo "  - " . json_encode($error) . "\n";
        }
        if (count($result['errors']) > 5) {
            echo "  ... and " . (count($result['errors']) - 5) . " more\n";
        }
    }

    // Refresh index
    echo "\n6. Refreshing index...\n";
    $indexManager->refreshIndex($indexName);
    echo "   ✓ Index refreshed\n\n";

    echo "Next step: Run 'php bin/search.php \"query\"' to search the data\n";
    echo "Or start the web server: 'php -S localhost:8000 -t public'\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    if ($config->isDevelopment()) {
        echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}
