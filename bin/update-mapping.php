<?php

declare(strict_types=1);

/**
 * Add the motivation_embedding dense_vector field to an existing index.
 *
 * Run this if you already have a populated index and want to add semantic search
 * support without re-creating the index from scratch.
 *
 * After running this script, run bin/generate-embeddings.php to populate the
 * embedding field for all existing documents.
 *
 * Usage:
 *   php bin/update-mapping.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Index\IndexManager;
use ElasticPressIO\Sample\Mapping\NobelPrizeMapping;

echo "=== ElasticPress.io Nobel Prize Sample - Update Mapping ===\n\n";

try {
    $config = Config::getInstance();
    $config->validate();

    $indexName    = $config->getIndexPrefix() . 'laureates';
    $indexManager = new IndexManager();

    echo "1. Checking index: {$indexName}...\n";

    if (!$indexManager->indexExists($indexName)) {
        echo "   ❌ Index '{$indexName}' does not exist.\n";
        echo "      Run bin/setup.php first to create the index.\n";
        exit(1);
    }

    echo "   ✓ Index exists.\n\n";

    echo "2. Applying embedding mapping patch...\n";

    $dims = $config->getOpenAIEmbeddingDimensions();
    $patch = NobelPrizeMapping::getEmbeddingMappingPatch($dims);
    $indexManager->updateMappings($indexName, $patch);

    echo "   ✓ Added 'motivation_embedding' (dense_vector, {$dims} dims, cosine similarity).\n\n";

    echo "=== Mapping Update Complete ===\n\n";
    echo "Next step: run php bin/generate-embeddings.php to populate embeddings.\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
