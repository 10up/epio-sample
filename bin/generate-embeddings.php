<?php

declare(strict_types=1);

/**
 * Generate and store vector embeddings for all Nobel Prize laureate documents.
 *
 * This script scrolls through the Elasticsearch index, generates embeddings via
 * an OpenAI-compatible API, and bulk-updates each document with its vector.
 * Embeddings are stored in the 'motivation_embedding' dense_vector field.
 *
 * Prerequisites:
 *   1. Run bin/setup.php (or bin/update-mapping.php) so the index has the
 *      motivation_embedding field in its mapping.
 *   2. Set OPENAI_API_KEY (and optionally OPENAI_API_BASE_URL) in .env.
 *
 * Usage:
 *   php bin/generate-embeddings.php
 *   php bin/generate-embeddings.php --batch-size=50
 *   php bin/generate-embeddings.php --force          # re-embed docs that already have embeddings
 *   php bin/generate-embeddings.php --dry-run        # show what would happen without API calls
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Client\ElasticsearchClient;
use ElasticPressIO\Sample\Index\IndexManager;
use ElasticPressIO\Sample\Embeddings\EmbeddingUpdater;
use ElasticPressIO\Sample\Shared\ServiceFactory;

echo "=== ElasticPress.io Nobel Prize Sample - Generate Embeddings ===\n\n";

// ── Parse arguments ───────────────────────────────────────────────────────────

$batchSize   = 50;
$skipExisting = true; // default: skip docs that already have embeddings
$dryRun      = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--batch-size=')) {
        $batchSize = max(1, min(100, (int) substr($arg, 13)));
    } elseif ($arg === '--force') {
        $skipExisting = false;
    } elseif ($arg === '--skip-existing') {
        $skipExisting = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php bin/generate-embeddings.php [options]\n\n";
        echo "Options:\n";
        echo "  --batch-size=N    Documents per OpenAI API call (default: 50, max: 100)\n";
        echo "  --skip-existing   Skip docs that already have embeddings (default)\n";
        echo "  --force           Re-generate embeddings even if already present\n";
        echo "  --dry-run         Count eligible documents without making API calls\n";
        exit(0);
    }
}

try {
    $config = Config::getInstance();
    $config->validate();

    echo "Configuration:\n";
    echo "   API base URL:      " . $config->getOpenAIApiBaseUrl() . "\n";
    echo "   Embedding model:   " . $config->getOpenAIEmbeddingModel() . "\n";
    echo "   Dimensions:        " . $config->getOpenAIEmbeddingDimensions() . "\n";
    echo "   Batch size:        {$batchSize}\n";
    echo "   Skip existing:     " . ($skipExisting ? 'yes' : 'no (--force)') . "\n";
    if ($dryRun) {
        echo "   Mode:              DRY RUN (no API calls will be made)\n";
    }
    echo "\n";

    // ── Check index ────────────────────────────────────────────────────────────

    $indexName    = $config->getIndexPrefix() . 'laureates';
    $indexManager = new IndexManager();

    echo "1. Checking index: {$indexName}...\n";

    if (!$indexManager->indexExists($indexName)) {
        echo "   ❌ Index not found. Run bin/setup.php first.\n";
        exit(1);
    }

    $stats = $indexManager->getStats($indexName);
    $docCount = $stats['indices'][$indexName]['total']['docs']['count'] ?? 0;
    echo "   ✓ Index has {$docCount} documents.\n\n";

    if ($dryRun) {
        echo "DRY RUN: Would process up to {$docCount} documents.\n";
        if ($skipExisting) {
            echo "         Only documents without motivation_embedding would be processed.\n";
        }
        echo "\nRe-run without --dry-run to generate embeddings.\n";
        exit(0);
    }

    // ── Generate embeddings ────────────────────────────────────────────────────

    echo "2. Generating embeddings...\n";

    $embeddingService = ServiceFactory::embeddingService($config); // throws if API key missing

    $updater = new EmbeddingUpdater(
        new ElasticsearchClient(),
        $embeddingService,
        100,
        $batchSize
    );

    $startTime = microtime(true);
    $lastDone  = 0;

    $result = $updater->updateEmbeddings(
        $indexName,
        $skipExisting,
        function (int $done, int $skipped) use (&$lastDone) {
            if ($done > $lastDone) {
                echo "   Processed {$done} documents (skipped: {$skipped})...\r";
                $lastDone = $done;
            }
        }
    );

    $elapsed = round(microtime(true) - $startTime, 1);
    echo "\n\n";

    // ── Summary ────────────────────────────────────────────────────────────────

    echo "3. Refreshing index...\n";
    $indexManager->refreshIndex($indexName);
    echo "   ✓ Index refreshed.\n\n";

    echo "=== Embedding Generation Complete ===\n\n";
    echo "   Processed: {$result['processed']} documents\n";
    echo "   Updated:   {$result['updated']} documents\n";
    echo "   Skipped:   {$result['skipped']} documents\n";
    echo "   Failed:    {$result['failed']} documents\n";
    echo "   Time:      {$elapsed}s\n\n";

    // Cost estimate for OpenAI (approximate, based on ~80 tokens per document)
    if ($result['updated'] > 0 && str_contains($config->getOpenAIApiBaseUrl(), 'openai.com')) {
        $estimatedTokens = $result['updated'] * 80;
        // text-embedding-3-small: $0.02/1M tokens
        $estimatedCost = round($estimatedTokens / 1_000_000 * 0.02, 6);
        echo "   Estimated cost: \${$estimatedCost} (text-embedding-3-small @ \$0.02/1M tokens)\n\n";
    }

    if ($result['updated'] > 0) {
        echo "Next steps:\n";
        echo "  php bin/semantic-search.php \"quantum mechanics\" --mode=semantic\n";
        echo "  php bin/ask.php \"What contributions did physicists make to quantum theory?\"\n";
    }

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
