<?php

declare(strict_types=1);

/**
 * Semantic and hybrid search for Nobel Prize data.
 *
 * Generates a vector embedding for the query and uses kNN search to find
 * semantically similar laureates. Hybrid mode combines BM25 keyword scoring
 * with vector similarity for best-of-both-worlds retrieval.
 *
 * Prerequisites:
 *   - Run bin/generate-embeddings.php to populate motivation_embedding fields.
 *   - Set OPENAI_API_KEY and OPENAI_API_BASE_URL in .env.
 *
 * Usage:
 *   php bin/semantic-search.php "quantum mechanics"
 *   php bin/semantic-search.php "quantum mechanics" --mode=semantic
 *   php bin/semantic-search.php "einstein" --mode=hybrid
 *   php bin/semantic-search.php "organic chemistry" --mode=semantic --k=5 --category=chemistry
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchService;
use ElasticPressIO\Sample\Shared\ServiceFactory;

echo "=== ElasticPress.io Nobel Prize Sample - Semantic Search ===\n\n";

try {
    $config = Config::getInstance();
    $config->validate();

    // ── Parse arguments ───────────────────────────────────────────────────────

    $query   = '';
    $mode    = 'semantic';
    $k       = 10;
    $filters = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--mode=')) {
            $mode = substr($arg, 7);
        } elseif (str_starts_with($arg, '--k=')) {
            $k = max(1, (int) substr($arg, 4));
        } elseif (str_starts_with($arg, '--')) {
            [$key, $value] = explode('=', substr($arg, 2), 2) + ['', ''];
            if ($value !== '') {
                $filters[str_replace('-', '_', $key)] = $value;
            }
        } else {
            $query = $arg;
        }
    }

    if (!in_array($mode, ['semantic', 'hybrid'])) {
        echo "❌ Unknown mode '{$mode}'. Use --mode=semantic or --mode=hybrid\n";
        exit(1);
    }

    if (empty($query)) {
        echo "Usage: php bin/semantic-search.php \"search query\" [--mode=semantic|hybrid] [--k=10]\n";
        echo "       [--category=physics] [--year-from=2000] [--year-to=2023]\n\n";
        echo "Modes:\n";
        echo "  semantic  Vector kNN search — find results by meaning (default)\n";
        echo "  hybrid    Combined BM25 + vector search\n";
        exit(0);
    }

    // ── Embed query ───────────────────────────────────────────────────────────

    $embeddingService = ServiceFactory::embeddingService($config); // throws if API key missing

    echo "Mode: {$mode}\n";
    echo "Query: \"{$query}\"\n";
    if (!empty($filters)) {
        echo "Filters: " . json_encode($filters) . "\n";
    }
    echo "\nGenerating query embedding...\n";

    $queryVector = $embeddingService->embedQuery($query);

    echo "Searching...\n\n";

    // ── Execute search ────────────────────────────────────────────────────────

    $indexName     = $config->getIndexPrefix() . 'laureates';
    $searchService = new SearchService();

    if ($mode === 'semantic') {
        $results = $searchService->semanticSearch($indexName, $queryVector, $filters, $k, $k * 10);
    } else {
        $results = $searchService->hybridSearch($indexName, $query, $queryVector, $filters, 0, $k);
    }

    // ── Display results ───────────────────────────────────────────────────────

    echo "Found {$results['total']} matching documents (showing top {$k})\n";
    echo str_repeat('=', 60) . "\n\n";

    foreach ($results['results'] as $i => $result) {
        $source = $result['source'];
        $score  = isset($result['score']) ? sprintf(' [score: %.4f]', $result['score']) : '';

        echo ($i + 1) . ". {$source['fullname']}{$score}\n";
        echo "   Category: {$source['category']} | Year: {$source['year']} | Gender: " . ($source['gender'] ?? 'N/A') . "\n";

        if (!empty($source['motivation'])) {
            $motivation = strlen($source['motivation']) > 120
                ? substr($source['motivation'], 0, 120) . '...'
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

    if (empty($results['results'])) {
        echo "No results found. Have you run bin/generate-embeddings.php?\n";
    }

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
