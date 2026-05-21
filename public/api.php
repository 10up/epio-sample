<?php

declare(strict_types=1);

/**
 * REST API for searching Nobel Prize data.
 *
 * Endpoints:
 *   GET /api.php?q=query                          Keyword search (default)
 *   GET /api.php?q=query&mode=semantic            Semantic (kNN vector) search
 *   GET /api.php?q=query&mode=hybrid              Hybrid BM25 + kNN search
 *   GET /api.php?ask=question                     RAG: answer a question using context
 *   GET /api.php?id={document-id}                 Fetch a single document by ID
 *
 * Semantic and hybrid modes require OPENAI_API_KEY to be configured.
 * Keyword search and document lookup work without an API key.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Prevent PHP warnings/notices from corrupting the JSON response body.
// Errors are still written to the server error log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchService;
use ElasticPressIO\Sample\Shared\ServiceFactory;

// Set headers for JSON API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $config = Config::getInstance();
    $config->validate();

    $indexName = $config->getIndexPrefix() . 'laureates';

    // ── Document detail request ───────────────────────────────────────────────

    if (!empty($_GET['id'])) {
        $client = new \ElasticPressIO\Sample\Client\ElasticsearchClient();

        // Use search with match query instead of _doc API
        $searchResult = $client->post("/{$indexName}/_search", [
            'query' => ['match' => ['id' => $_GET['id']]],
            'size'  => 1,
            '_source' => ['excludes' => ['motivation_embedding']],
        ]);

        if (isset($searchResult['hits']['hits'][0])) {
            echo json_encode([
                'success' => true,
                'result'  => $searchResult['hits']['hits'][0]['_source'],
            ], JSON_PRETTY_PRINT);
        } else {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error'   => 'Document not found',
            ], JSON_PRETTY_PRINT);
        }
        exit;
    }

    // ── Ask AI (RAG) request ──────────────────────────────────────────────────

    if (!empty($_GET['ask'])) {
        $question = trim($_GET['ask']);

        $ragService = ServiceFactory::ragService($config, $indexName);

        $result = $ragService->ask($question);

        $seen    = [];
        $sources = [];
        foreach ($result['sources'] as $s) {
            $id = $s['id'] ?? ($s['fullname'] ?? '');
            if ($id && !isset($seen[$id])) {
                $seen[$id]  = true;
                $sources[] = $s;
            }
        }

        echo json_encode([
            'success'  => true,
            'mode'     => 'rag',
            'question' => $result['question'],
            'answer'   => $result['answer'],
            'sources'  => $sources,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // ── Search request ────────────────────────────────────────────────────────

    $query   = $_GET['q'] ?? '';
    $mode    = $_GET['mode'] ?? 'keyword';
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
    $from    = ($page - 1) * $perPage;

    // Build filters
    $filters = [];
    if (!empty($_GET['category']))     $filters['category']     = $_GET['category'];
    if (!empty($_GET['gender']))        $filters['gender']        = $_GET['gender'];
    if (!empty($_GET['birth_country'])) $filters['birth_country'] = $_GET['birth_country'];
    if (!empty($_GET['prize_country'])) $filters['prize_country'] = $_GET['prize_country'];
    if (!empty($_GET['year_from']))     $filters['year_from']     = (int) $_GET['year_from'];
    if (!empty($_GET['year_to']))       $filters['year_to']       = (int) $_GET['year_to'];

    $searchService = new SearchService();

    // Semantic/hybrid require a non-empty query to generate an embedding.
    // Fall back to keyword mode silently when the search box is empty.
    if (($mode === 'semantic' || $mode === 'hybrid') && $query === '') {
        $mode = 'keyword';
    }

    if ($mode === 'semantic' || $mode === 'hybrid') {
        $embeddingService = ServiceFactory::embeddingService($config);
        $queryVector      = $embeddingService->embedQuery($query);

        if ($mode === 'semantic') {
            $results = $searchService->semanticSearch(
                $indexName,
                $queryVector,
                $filters,
                $perPage,
                $perPage * 5
            );
        } else {
            $results = $searchService->hybridSearch(
                $indexName,
                $query,
                $queryVector,
                $filters,
                $from,
                $perPage
            );
        }
    } else {
        // Keyword mode — no embedding needed
        $results = $searchService->search($indexName, $query, $filters, $from, $perPage);
    }

    echo json_encode([
        'success'     => true,
        'mode'        => $mode,
        'query'       => $query,
        'filters'     => $filters,
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $results['total'],
        'total_pages' => (int) ceil($results['total'] / $perPage),
        'results'     => array_map(fn($r) => $r['source'], $results['results']),
        'facets'      => $results['facets'],
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    $code = str_contains($e->getMessage(), 'OPENAI_API_KEY') ? 503 : 500;
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}

