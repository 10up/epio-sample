<?php

declare(strict_types=1);

/**
 * Ask a question about Nobel Prize data using an agentic RAG approach.
 *
 * The language model autonomously decides which search tools to call and
 * how to filter them — you do not need to specify filters manually.
 * For example: "What contributions did women make to physics?" will cause
 * the LLM to search with {gender: "female", category: "physics"} on its own.
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
            echo "The AI will automatically decide what to search for and how to filter results.\n";
            echo "No manual filter flags needed — just ask naturally.\n\n";
            echo "Options:\n";
            echo "  --verbose   Show each tool call the AI makes (useful for debugging)\n\n";
            echo "Examples:\n";
            echo "  php bin/ask.php \"What contributions did women make to physics?\"\n";
            echo "  php bin/ask.php \"Which German-born scientists won the chemistry prize?\"\n";
            echo "  php bin/ask.php \"Who won the peace prize for nuclear disarmament?\"\n";
            echo "  php bin/ask.php \"What breakthroughs in cancer research won Nobel Prizes?\" --verbose\n";
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

    $ragService = ServiceFactory::ragService($config);

    // ── Ask ───────────────────────────────────────────────────────────────────

    $indexName = $config->getIndexPrefix() . 'laureates';

    echo "Question: \"{$question}\"\n";
    echo "Thinking...\n\n";

    $debugCallback = null;
    if ($verbose) {
        $debugCallback = function (string $phase, mixed $data) {
            if ($phase === 'query_plan') {
                echo "  [phase 1] query understanding:\n";
                echo "            keywords:      " . (json_encode($data['keywords'] ?: '(none)')) . "\n";
                echo "            filters:       " . (empty($data['filters']) ? '(none)' : json_encode($data['filters'])) . "\n";
                echo "            semantic_query: " . json_encode($data['semantic_query']) . "\n";
            } elseif ($phase === 'retrieval') {
                $summary = [];
                foreach ($data as $strategy => $count) {
                    $summary[] = "{$strategy}: {$count}";
                }
                echo "  [phase 2] retrieval: " . implode(', ', $summary) . "\n";
            } elseif ($phase === 'fused') {
                echo "  [phase 2b] RRF fused: {$data} unique documents\n";
            } elseif ($phase === 'context') {
                echo "  [phase 2c] context:  {$data['count']} documents ({$data['type']})\n";
            } elseif ($phase === 'aggregate') {
                echo "  [aggregate] computing: count_by_{$data}\n";
            }
        };
    }

    $result = $ragService->ask($indexName, $question, $debugCallback);

    // ── Display answer ────────────────────────────────────────────────────────

    echo str_repeat('=', 60) . "\n";
    echo "Answer:\n";
    echo str_repeat('-', 60) . "\n";
    echo $result['answer'] . "\n";
    echo str_repeat('=', 60) . "\n\n";

    // Display unique sources
    $seen    = [];
    $sources = [];
    foreach ($result['sources'] as $s) {
        $id = $s['id'] ?? ($s['fullname'] ?? '');
        if (!isset($seen[$id])) {
            $seen[$id] = true;
            $sources[] = $s;
        }
    }

    if (!empty($sources)) {
        echo "Sources (" . count($sources) . " unique documents):\n\n";
        foreach ($sources as $i => $source) {
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
    }

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
