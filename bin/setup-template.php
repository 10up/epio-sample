<?php

declare(strict_types=1);

/**
 * Setup search templates for ElasticPress.io.
 *
 * This script creates search templates that allow frontend JavaScript
 * to query the index directly without exposing authentication credentials.
 *
 * @see https://www.elasticpress.io/resources/articles/instant-results-post-search-api/
 *
 * Usage: php bin/setup-template.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchTemplateManager;

echo "=== ElasticPress.io Search Template Setup ===\n\n";

try {
    // Load configuration
    $config = Config::getInstance();
    $config->validate();

    $indexName = $config->getIndexPrefix() . 'laureates';
    $templateManager = new SearchTemplateManager();

    echo "1. Creating search template for '{$indexName}'...\n";

    try {
        $response = $templateManager->createSearchTemplate($indexName);
        echo "   ✓ Search template created successfully\n";
        echo "   Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
    } catch (\Exception $e) {
        echo "   ⚠ Template creation returned: " . $e->getMessage() . "\n\n";
    }

    echo "2. Public Search API URL:\n";
    $publicUrl = $templateManager->getPublicSearchUrl($indexName);
    echo "   {$publicUrl}\n\n";

    echo "3. Testing public API (no auth required):\n";
    echo "   Example: curl \"{$publicUrl}?search=einstein\"\n\n";

    echo "4. Usage in JavaScript:\n";
    echo "   fetch('{$publicUrl}?search=' + encodeURIComponent(query))\n";
    echo "     .then(response => response.json())\n";
    echo "     .then(data => console.log(data));\n\n";

    echo "=== Setup Complete ===\n";
    echo "The search template is now available for direct frontend queries.\n";
    echo "No authentication is required for search requests using this template.\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    if ($config->isDevelopment()) {
        echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}
