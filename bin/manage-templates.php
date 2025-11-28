<?php

declare(strict_types=1);

/**
 * Manage search templates for ElasticPress.io.
 *
 * This script allows you to list, view, and delete search templates.
 * @see https://www.elasticpress.io/resources/articles/instant-results-post-search-api/  
 *
 * Usage:
 *   php bin/manage-templates.php list
 *   php bin/manage-templates.php view <index-name>
 *   php bin/manage-templates.php delete <index-name>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchTemplateManager;

echo "=== ElasticPress.io Search Template Manager ===\n\n";

try {
    // Load configuration
    $config = Config::getInstance();
    $config->validate();

    $templateManager = new SearchTemplateManager();

    // Parse command
    $command = $argv[1] ?? 'list';
    $indexName = $argv[2] ?? '';

    switch ($command) {
        case 'list':
            echo "Listing all search templates...\n\n";
            try {
                $response = $templateManager->listTemplates();
                echo "Response:\n";
                echo json_encode($response, JSON_PRETTY_PRINT) . "\n\n";

                // Try to extract template list from response
                if (isset($response['templates']) && is_array($response['templates'])) {
                    echo "Found " . count($response['templates']) . " template(s):\n";
                    foreach ($response['templates'] as $template) {
                        echo "  - " . ($template['name'] ?? $template['index'] ?? json_encode($template)) . "\n";
                    }
                } elseif (is_array($response)) {
                    echo "Found " . count($response) . " template(s):\n";
                    foreach ($response as $key => $value) {
                        echo "  - {$key}\n";
                    }
                }
            } catch (\Exception $e) {
                echo "⚠ Error listing templates: " . $e->getMessage() . "\n";
                echo "This may indicate that the list endpoint is not available or templates are managed differently.\n";
            }
            break;

        case 'view':
        case 'get':
            if (empty($indexName)) {
                echo "Usage: php bin/manage-templates.php view <index-name>\n";
                echo "Example: php bin/manage-templates.php view {$config->getIndexPrefix()}-laureates\n";
                exit(1);
            }

            echo "Getting template for index '{$indexName}'...\n\n";
            try {
                $response = $templateManager->getTemplate($indexName);
                echo json_encode($response, JSON_PRETTY_PRINT) . "\n\n";

                // Show the public URL
                echo "Public Search URL:\n";
                echo "  " . $templateManager->getPublicSearchUrl($indexName) . "\n";
            } catch (\Exception $e) {
                echo "⚠ Error: " . $e->getMessage() . "\n";
            }
            break;

        case 'delete':
        case 'remove':
            if (empty($indexName)) {
                echo "Usage: php bin/manage-templates.php delete <index-name>\n";
                echo "Example: php bin/manage-templates.php delete {$config->getIndexPrefix()}-laureates\n";
                exit(1);
            }

            echo "Deleting template for index '{$indexName}'...\n";
            echo "Are you sure? (yes/no): ";
            $handle = fopen("php://stdin", "r");
            $line = trim(fgets($handle));
            fclose($handle);

            if (strtolower($line) !== 'yes') {
                echo "Cancelled.\n";
                exit(0);
            }

            try {
                $response = $templateManager->deleteTemplate($indexName);
                echo "✓ Template deleted successfully\n";
                echo "Response: " . json_encode($response) . "\n";
            } catch (\Exception $e) {
                echo "⚠ Error: " . $e->getMessage() . "\n";
            }
            break;

        case 'help':
        case '--help':
        case '-h':
        default:
            echo "Usage: php bin/manage-templates.php <command> [options]\n\n";
            echo "Commands:\n";
            echo "  list                    List all search templates\n";
            echo "  view <index-name>       View a specific template\n";
            echo "  delete <index-name>     Delete a specific template\n\n";
            echo "Examples:\n";
            echo "  php bin/manage-templates.php list\n";
            echo "  php bin/manage-templates.php view {$config->getIndexPrefix()}-laureates\n";
            echo "  php bin/manage-templates.php delete {$config->getIndexPrefix()}-laureates\n\n";
            break;
    }

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    if ($config->isDevelopment()) {
        echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}
