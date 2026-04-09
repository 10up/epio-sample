<?php

declare(strict_types=1);

/**
 * MCP (Model Context Protocol) Server for Nobel Prize / ElasticPress.io data.
 *
 * Exposes Nobel Prize search and RAG capabilities as MCP tools that any
 * compatible AI model or agent can call. This demonstrates using Elasticsearch
 * as a local vector knowledge store for AI-powered applications.
 *
 * Transport: JSON-RPC 2.0 over stdio (standard MCP transport)
 *
 * Exposed tools:
 *   search          - Keyword BM25 search with filters and facets
 *   semantic_search - Vector kNN semantic search
 *   hybrid_search   - Combined BM25 + kNN search
 *   ask             - RAG: answer a question using retrieved context
 *   get_document    - Fetch a specific laureate document by ID
 *
 * Usage (register in your MCP client config):
 *   command: php /path/to/bin/mcp-server.php
 *
 * Example: Claude Desktop claude_desktop_config.json
 *   {
 *     "mcpServers": {
 *       "nobel-prize": {
 *         "command": "php",
 *         "args": ["/path/to/bin/mcp-server.php"]
 *       }
 *     }
 *   }
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchService;
use ElasticPressIO\Sample\MCP\McpServer;
use ElasticPressIO\Sample\MCP\ToolRegistry;
use ElasticPressIO\Sample\Shared\ServiceFactory;

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$config = Config::getInstance();

// Validate ElasticPress.io credentials (required for all search tools)
try {
    $config->validate();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[mcp-server] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

$indexName     = $config->getIndexPrefix() . 'laureates';
$searchService = new SearchService();

// Build embedding/RAG services only when API key is available
$hasEmbeddings    = !empty($config->getOpenAIApiKey());
$embeddingService = null;
$ragService       = null;

if ($hasEmbeddings) {
    $embeddingService = ServiceFactory::embeddingService($config);
    $ragService       = ServiceFactory::ragService($config);
}

// ── JSON Schema definitions ───────────────────────────────────────────────────

$filtersSchema = [
    'type'       => 'object',
    'properties' => [
        'category'     => ['type' => 'string', 'description' => 'Nobel Prize category (physics, chemistry, medicine, literature, peace, economics)'],
        'gender'       => ['type' => 'string', 'description' => 'Laureate gender (male, female, org)'],
        'birth_country'=> ['type' => 'string', 'description' => 'Two-letter ISO country code for birth country'],
        'prize_country'=> ['type' => 'string', 'description' => 'Two-letter ISO country code for prize/affiliation country'],
        'year_from'    => ['type' => 'integer', 'description' => 'Earliest prize year (inclusive)'],
        'year_to'      => ['type' => 'integer', 'description' => 'Latest prize year (inclusive)'],
    ],
    'additionalProperties' => false,
];

// ── Register tools ────────────────────────────────────────────────────────────

$registry = new ToolRegistry();

// ── search ────────────────────────────────────────────────────────────────────
$registry->register(
    'search',
    'Keyword BM25 search for Nobel Prize laureates. Finds results by matching words in ' .
    'names, motivations, and affiliations. Supports filtering by category, year, gender, and country. ' .
    'Returns results with facet counts.',
    [
        'type'       => 'object',
        'properties' => [
            'query'    => ['type' => 'string', 'description' => 'Search query (names, keywords, institutions)'],
            'filters'  => $filtersSchema,
            'page'     => ['type' => 'integer', 'description' => 'Page number (default: 1)', 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'description' => 'Results per page (default: 10, max: 50)', 'minimum' => 1, 'maximum' => 50],
        ],
        'required' => [],
    ],
    function (array $args) use ($searchService, $indexName): array {
        $query   = $args['query'] ?? '';
        $filters = $args['filters'] ?? [];
        $page    = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($args['per_page'] ?? 10)));
        $from    = ($page - 1) * $perPage;

        $results = $searchService->search($indexName, $query, $filters, $from, $perPage);

        return formatSearchResults($results, $query, 'keyword', $page, $perPage);
    }
);

// ── semantic_search ───────────────────────────────────────────────────────────
$registry->register(
    'semantic_search',
    'Semantic vector search for Nobel Prize laureates using kNN similarity. ' .
    'Finds results based on meaning and concept similarity, not just keyword matching. ' .
    'Ideal for conceptual queries like "discovery of quantum effects" or "contributions to DNA research". ' .
    'Requires OPENAI_API_KEY to be configured.',
    [
        'type'       => 'object',
        'properties' => [
            'query'   => ['type' => 'string', 'description' => 'Natural language query describing what you are looking for'],
            'filters' => $filtersSchema,
            'k'       => ['type' => 'integer', 'description' => 'Number of results (default: 10)', 'minimum' => 1, 'maximum' => 50],
        ],
        'required' => ['query'],
    ],
    function (array $args) use ($searchService, $embeddingService, $indexName): array {
        if ($embeddingService === null) {
            throw new \RuntimeException('semantic_search requires OPENAI_API_KEY to be configured in .env');
        }

        $query   = $args['query'] ?? '';
        $filters = $args['filters'] ?? [];
        $k       = min(50, max(1, (int) ($args['k'] ?? 10)));

        $queryVector = $embeddingService->embedQuery($query);
        $results     = $searchService->semanticSearch($indexName, $queryVector, $filters, $k, $k * 10);

        return formatSearchResults($results, $query, 'semantic', 1, $k);
    }
);

// ── hybrid_search ─────────────────────────────────────────────────────────────
$registry->register(
    'hybrid_search',
    'Hybrid BM25 + vector search combining keyword matching with semantic similarity. ' .
    'Best for queries that benefit from both exact name/term matching and conceptual similarity. ' .
    'For example, searching for "Einstein quantum" will match both the name and related concepts. ' .
    'Requires OPENAI_API_KEY to be configured.',
    [
        'type'       => 'object',
        'properties' => [
            'query'     => ['type' => 'string', 'description' => 'Search query combining keywords and natural language'],
            'filters'   => $filtersSchema,
            'k'         => ['type' => 'integer', 'description' => 'Number of results (default: 10)', 'minimum' => 1, 'maximum' => 50],
            'knn_boost' => ['type' => 'number', 'description' => 'Weight for vector scores vs BM25 (0.0-2.0, default: 0.5)', 'minimum' => 0, 'maximum' => 2],
        ],
        'required' => ['query'],
    ],
    function (array $args) use ($searchService, $embeddingService, $indexName): array {
        if ($embeddingService === null) {
            throw new \RuntimeException('hybrid_search requires OPENAI_API_KEY to be configured in .env');
        }

        $query    = $args['query'] ?? '';
        $filters  = $args['filters'] ?? [];
        $k        = min(50, max(1, (int) ($args['k'] ?? 10)));
        $knnBoost = (float) ($args['knn_boost'] ?? 0.5);

        $queryVector = $embeddingService->embedQuery($query);
        $results     = $searchService->hybridSearch($indexName, $query, $queryVector, $filters, 0, $k, $knnBoost);

        return formatSearchResults($results, $query, 'hybrid', 1, $k);
    }
);

// ── ask ───────────────────────────────────────────────────────────────────────
$registry->register(
    'ask',
    'Answer a question about Nobel Prize history using Retrieval-Augmented Generation (RAG). ' .
    'Retrieves the most relevant laureate documents and uses an LLM to generate a grounded answer. ' .
    'The answer includes citations to the source documents used. ' .
    'Requires OPENAI_API_KEY to be configured.',
    [
        'type'       => 'object',
        'properties' => [
            'question' => ['type' => 'string', 'description' => 'Question to answer about Nobel Prizes or laureates. The AI will automatically decide what to search for and how to filter.'],
        ],
        'required' => ['question'],
    ],
    function (array $args) use ($ragService, $indexName): array {
        if ($ragService === null) {
            throw new \RuntimeException('ask requires OPENAI_API_KEY to be configured in .env');
        }

        $question = $args['question'] ?? '';
        if (empty($question)) {
            throw new \InvalidArgumentException('question is required');
        }

        $result = $ragService->ask($indexName, $question);

        return [
            'question' => $result['question'],
            'answer'   => $result['answer'],
            'sources'  => array_map(function ($s) {
                return [
                    'fullname'   => $s['fullname'] ?? '',
                    'category'   => $s['category'] ?? '',
                    'year'       => $s['year'] ?? null,
                    'motivation' => $s['motivation'] ?? null,
                ];
            }, $result['sources']),
        ];
    }
);

// ── get_document ──────────────────────────────────────────────────────────────
$registry->register(
    'get_document',
    'Fetch full details for a specific Nobel Prize laureate by their document ID. ' .
    'Document IDs are returned in search results and have the format "{laureate_id}-{year}-{category}".',
    [
        'type'       => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'description' => 'Document ID (e.g. "12-2020-physics")'],
        ],
        'required' => ['id'],
    ],
    function (array $args) use ($searchService, $indexName): array {
        $id = $args['id'] ?? '';
        if (empty($id)) {
            throw new \InvalidArgumentException('id is required');
        }

        $doc = $searchService->getDocument($indexName, $id);
        if ($doc === null) {
            throw new \RuntimeException("Document not found: {$id}");
        }

        // Remove large embedding vector from response
        unset($doc['motivation_embedding']);

        return $doc;
    }
);

// ── Start server ──────────────────────────────────────────────────────────────

$server = new McpServer($registry);
$server->run();

// ── Helper functions ──────────────────────────────────────────────────────────

/**
 * Format search results for MCP tool response.
 */
function formatSearchResults(array $results, string $query, string $mode, int $page, int $perPage): array
{
    return [
        'mode'     => $mode,
        'query'    => $query,
        'total'    => $results['total'],
        'page'     => $page,
        'per_page' => $perPage,
        'results'  => array_map(function ($r) {
            $source = $r['source'];
            // Strip the heavy embedding vector before returning
            unset($source['motivation_embedding']);
            return [
                'id'         => $source['id'] ?? $r['id'],
                'fullname'   => $source['fullname'] ?? '',
                'category'   => $source['category'] ?? '',
                'year'       => $source['year'] ?? null,
                'motivation' => $source['motivation'] ?? null,
                'gender'     => $source['gender'] ?? null,
                'birth_country_name' => $source['birth_country_name'] ?? null,
                'affiliations' => $source['affiliations'] ?? [],
                'score'      => $r['score'] ?? null,
            ];
        }, $results['results']),
        'facets'   => $results['facets'] ?? [],
    ];
}
