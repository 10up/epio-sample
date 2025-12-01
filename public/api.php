<?php

declare(strict_types=1);

/**
 * Simple REST API for searching Nobel Prize data.
 *
 * Endpoints:
 *   GET /api.php?q=query&category=physics&year_from=2000&year_to=2023
 *
 * This API provides JSON responses for the frontend to consume.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchService;

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
    // Load configuration
    $config = Config::getInstance();
    $config->validate();

    // Handle document detail request
    if (!empty($_GET['id'])) {
        $indexName = $config->getIndexPrefix() . 'laureates';
        $client = new \ElasticPressIO\Sample\Client\ElasticsearchClient();

        // Use search with match query instead of _doc API
        $searchResult = $client->post("/{$indexName}/_search", [
            'query' => [
                'match' => [
                    'id' => $_GET['id']
                ]
            ],
            'size' => 1
        ]);

        if (isset($searchResult['hits']['hits'][0])) {
            echo json_encode([
                'success' => true,
                'result' => $searchResult['hits']['hits'][0]['_source'],
            ], JSON_PRETTY_PRINT);
        } else {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Document not found',
            ], JSON_PRETTY_PRINT);
        }
        exit;
    }

    // Get search parameters from query string
    $query = $_GET['q'] ?? '';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
    $from = ($page - 1) * $perPage;

    // Build filters
    $filters = [];
    if (!empty($_GET['category'])) {
        $filters['category'] = $_GET['category'];
    }
    if (!empty($_GET['gender'])) {
        $filters['gender'] = $_GET['gender'];
    }
    if (!empty($_GET['birth_country'])) {
        $filters['birth_country'] = $_GET['birth_country'];
    }
    if (!empty($_GET['prize_country'])) {
        $filters['prize_country'] = $_GET['prize_country'];
    }
    if (!empty($_GET['year_from'])) {
        $filters['year_from'] = (int) $_GET['year_from'];
    }
    if (!empty($_GET['year_to'])) {
        $filters['year_to'] = (int) $_GET['year_to'];
    }

    // Perform search
    $indexName = $config->getIndexPrefix() . 'laureates';
    $searchService = new SearchService();

    $results = $searchService->search($indexName, $query, $filters, $from, $perPage);

    // Build response
    $response = [
        'success' => true,
        'query' => $query,
        'filters' => $filters,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $results['total'],
        'total_pages' => ceil($results['total'] / $perPage),
        'results' => array_map(function ($result) {
            return $result['source'];
        }, $results['results']),
        'facets' => $results['facets'],
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
