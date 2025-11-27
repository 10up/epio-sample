<?php

declare(strict_types=1);

/**
 * Returns the autosuggest query template as JSON.
 *
 * This endpoint provides the ElasticSearch query template used for autosuggest,
 * ensuring the frontend uses the same configuration as the backend.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchTemplateManager;

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

    $indexName = $config->getIndexPrefix() . '-laureates';
    $templateManager = new SearchTemplateManager();

    // Get the template and extract just the query structure
    $template = $templateManager->getTemplate($indexName);

    // Return the template
    echo json_encode([
        'success' => true,
        'template' => $template['template'] ?? null,
        'index' => $indexName,
    ], JSON_PRETTY_PRINT);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
