<?php

declare(strict_types=1);

/**
 * Ask a question about Nobel Prize data using text-to-Elasticsearch RAG.
 *
 * The LLM reads the index mapping, composes the optimal ES query (aggregations,
 * sorts, filters, knn), executes it, then synthesizes an answer from the results.
 * This approach adapts to any index without code changes.
 *
 * Prerequisites:
 *   - Run bin/generate-embeddings.php to populate motivation_embedding fields.
 *   - Set OPENAI_API_KEY, OPENAI_API_BASE_URL, and OPENAI_CHAT_MODEL in .env.
 *
 * Usage:
 *   php bin/ask.php "What contributions did women make to physics?"
 *   php bin/ask.php "Who won the peace prize for nuclear disarmament?"
 *   php bin/ask.php "Which German-born scientists won the chemistry prize?"
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Shared\ServiceFactory;

echo "=== ElasticPress.io Nobel Prize Sample - Ask AI ===\n\n";

try {
    $config = Config::getInstance();
    $config->validate();

    // ── Parse arguments ───────────────────────────────────────────────────────

    $question = '';
    $verbose  = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "Usage: php bin/ask.php \"Your question here\" [--verbose]\n\n";
            echo "The AI reads the index schema and writes the optimal Elasticsearch query.\n";
            echo "No manual filter flags needed — just ask naturally.\n\n";
            echo "Options:\n";
            echo "  --verbose   Show the generated ES query and execution details\n\n";
            echo "Examples:\n";
            echo "  php bin/ask.php \"What contributions did women make to physics?\"\n";
            echo "  php bin/ask.php \"Which German-born scientists won the chemistry prize?\"\n";
            echo "  php bin/ask.php \"Who won the last Nobel Prize?\"\n";
            echo "  php bin/ask.php \"What areas are most common in medicine prizes?\" --verbose\n";
            exit(0);
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $verbose = true;
        } elseif (!str_starts_with($arg, '--')) {
            $question = $arg;
        }
    }

    if (empty($question)) {
        echo "Usage: php bin/ask.php \"Your question here\"\n";
        echo "Example: php bin/ask.php \"What contributions did women make to physics?\"\n";
        exit(0);
    }

    $indexName = $config->getIndexPrefix() . 'laureates';
    $ragService = ServiceFactory::ragService($config, $indexName);

    // ── Ask ───────────────────────────────────────────────────────────────────

    echo "Question: \"{$question}\"\n";
    echo "Thinking...\n\n";

    $debugCallback = null;
    if ($verbose) {
        $debugCallback = function (string $phase, mixed $data) {
            if ($phase === 'schema') {
                echo "  [schema] Index fields loaded\n";
            } elseif ($phase === 'query') {
                echo "  [query] Generated ES query:\n";
                echo "  " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
            } elseif ($phase === 'retry') {
                echo "  [retry] ES error: {$data['error']}\n";
                echo "  [retry] Attempting fix (attempt {$data['attempt']})...\n";
            } elseif ($phase === 'query_fixed') {
                echo "  [fixed] Corrected query:\n";
                echo "  " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
            } elseif ($phase === 'result_summary') {
                echo "  [results] Hits: {$data['hits']}, Has aggregations: " . ($data['aggs'] ? 'yes' : 'no') . "\n\n";
            }
        };
    }

    $result = $ragService->ask($question, $debugCallback);

    // ── Display answer ────────────────────────────────────────────────────────

    echo str_repeat('=', 60) . "\n";
    echo "Answer:\n";
    echo str_repeat('-', 60) . "\n";
    echo $result['answer'] . "\n";
    echo str_repeat('=', 60) . "\n\n";

    // Display sources if documents were returned
    $sources = $result['sources'] ?? [];
    if (!empty($sources)) {
        $seen = [];
        $unique = [];
        foreach ($sources as $s) {
            $id = $s['id'] ?? ($s['fullname'] ?? json_encode($s));
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $unique[] = $s;
            }
        }

        echo "Sources (" . count($unique) . " documents):\n\n";
        foreach (array_slice($unique, 0, 20) as $i => $source) {
            $fullname   = $source['fullname'] ?? 'Unknown';
            $category   = ucfirst($source['category'] ?? '');
            $year       = $source['year'] ?? '';
            $motivation = $source['motivation'] ?? '';
            if (strlen($motivation) > 100) {
                $motivation = substr($motivation, 0, 100) . '...';
            }
            echo "  [" . ($i + 1) . "] {$fullname} — {$category} {$year}\n";
            if ($motivation) {
                echo "       {$motivation}\n";
            }
            echo "\n";
        }
        if (count($unique) > 20) {
            echo "  ... and " . (count($unique) - 20) . " more.\n";
        }
    }

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
