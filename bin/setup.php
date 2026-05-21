<?php

declare(strict_types=1);

/**
 * Setup script for ElasticPress.io Nobel Prize sample.
 *
 * This script:
 * 1. Validates configuration
 * 2. Creates the Nobel Prize index with proper mappings
 * 3. Tests the connection to ElasticPress.io
 *
 * Usage: php bin/setup.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Index\IndexManager;
use ElasticPressIO\Sample\Mapping\NobelPrizeMapping;

echo "=== ElasticPress.io Nobel Prize Sample - Setup ===\n\n";

try {
    // Load and validate configuration
    echo "1. Loading configuration...\n";
    $config = Config::getInstance();
    $config->validate();
    echo "   ✓ Configuration loaded successfully\n";
    echo "   Host: " . $config->getElasticsearchHost() . "\n\n";

    // Initialize index manager
    echo "2. Connecting to ElasticPress.io...\n";
    $indexManager = new IndexManager();
    echo "   ✓ Connected successfully\n\n";

    // Create index
    $indexName = $config->getIndexPrefix() . 'laureates';
    echo "3. Creating index '{$indexName}'...\n";

    // Check if index already exists
    if ($indexManager->indexExists($indexName)) {
        echo "   ! Index already exists\n";
        echo "   Do you want to delete and recreate it? (yes/no): ";
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);

        if (strtolower($line) === 'yes') {
            echo "   Deleting existing index...\n";
            $indexManager->deleteIndex($indexName);
            echo "   ✓ Index deleted\n";
        } else {
            echo "   Keeping existing index\n";
            exit(0);
        }
    }

    // Create index with mappings
    $settings = NobelPrizeMapping::getSettings();
    $mappings = NobelPrizeMapping::getMapping($config->getOpenAIEmbeddingDimensions());

    $response = $indexManager->createIndex($indexName, $settings, $mappings);
    echo "   ✓ Index created successfully\n";
    echo "   Acknowledged: " . ($response['acknowledged'] ? 'yes' : 'no') . "\n\n";

    // List indexes
    echo "4. Listing indexes...\n";
    $indexes = $indexManager->listIndexes($config->getIndexPrefix() . '*');
    foreach ($indexes as $index) {
        echo "   - " . $index['name'] . "\n";
    }
    echo "\n";

    echo "=== Setup Complete ===\n";
    echo "Next step: Run 'php bin/index.php' to index the Nobel Prize data\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
