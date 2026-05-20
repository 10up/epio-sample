<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\RAG;

use ElasticPressIO\Sample\Client\ElasticsearchClient;
use ElasticPressIO\Sample\Embeddings\EmbeddingService;

/**
 * Retrieval-Augmented Generation (RAG) service using text-to-Elasticsearch-DSL.
 *
 * The LLM reads the index mapping, composes the optimal ES query DSL
 * (aggregations, sorts, knn, filters), executes it, then synthesizes
 * an answer from the raw results. This adapts to any index schema
 * without requiring per-dataset code changes.
 *
 * Pipeline:
 *   1. Schema discovery — read the index mapping, present fields + types to the LLM
 *   2. Query generation — LLM writes ES _search body JSON
 *   3. Execution — POST to Elasticsearch (with one auto-retry on query errors)
 *   4. Answer synthesis — LLM interprets hits/aggregations and produces a response
 *
 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-search.html
 */
class RagService
{
    private const MAX_RETRIES = 1;

    private const QUERY_GENERATION_PROMPT = <<<'PROMPT'
You are an Elasticsearch query expert. Given a user question and an index schema, write the optimal Elasticsearch query DSL to retrieve the data needed to answer the question.

Current date: {current_date}
Index name: {index_name}

## Index schema (field → type)
{schema}

## Rules
- Return ONLY a valid JSON object that can be sent to the Elasticsearch _search endpoint.
- Always include "_source" to select only the fields needed for the answer (never include motivation_embedding).
- Use "size": 0 when only aggregation results are needed.
- Use aggregations (terms, stats, date_histogram, etc.) for counting, ranking, grouping, or "most/least" questions.
- Use "sort" for temporal queries (most recent, oldest, first, last).
- Use knn for semantic similarity searches (conceptual questions about motivations/topics).
  knn format: {"field": "motivation_embedding", "query_vector": [PLACEHOLDER], "k": N, "num_candidates": N*10}
  Write [PLACEHOLDER] as the literal string — it will be replaced with the actual vector before execution.
  Only use knn when the question is about conceptual similarity of prize motivations/topics.
- For keyword matching on text fields (names, motivations), use multi_match or match queries.
- For exact matching on keyword fields (category, gender, birth_country, prize_countries), use term/terms filters.
- Combine filters with bool queries. Put filters in the "filter" clause (no scoring needed).
- For "how many" questions with no grouping, use size:0 with a match_all and rely on hits.total.
- For questions about specific people, match on fullname or firstname+surname.
- The field "year" is an integer (not a date). Use range queries for year filtering.
- The field "birth_country" is a keyword with ISO-2 codes. Use "birth_country_name" (text) for country name matching.
- The field "affiliations" is nested. Use nested queries to filter/match on affiliation fields.
- Keep size reasonable: use 20-50 for listing questions, 200+ only if you need to scan many docs.
- Prefer aggregations over fetching all documents when counting or ranking.
- You can only aggregate on keyword fields or fields with a .keyword sub-field. Text-only fields (like "motivation") cannot be aggregated — fetch documents and let the synthesis step analyze their content instead.
- When the question asks about topics/themes/areas (which are semantic, not a stored field), fetch the relevant documents with their motivations and let the answer synthesis step identify patterns.
- Some documents have empty string values for text fields (e.g. organizations without a person name). When aggregating on fullname.keyword or similar, add "missing": "__none__" or a min_doc_count to exclude empty buckets, or use "exclude": "" to filter them out.

## Examples

Question: "who won the last Nobel Prize"
{"size":20,"sort":[{"year":"desc"}],"_source":["fullname","category","year","motivation","birth_country_name","gender"],"query":{"match_all":{}}}

Question: "which country produced the most Nobel laureates"
{"size":0,"aggs":{"by_country":{"terms":{"field":"birth_country_name.keyword","size":20}}},"query":{"match_all":{}}}

Question: "what areas are most common in medicine prizes in the last 10 years"
{"size":50,"sort":[{"year":"desc"}],"_source":["fullname","category","year","motivation"],"query":{"bool":{"filter":[{"term":{"category":"Physiology or Medicine"}},{"range":{"year":{"gte":2016}}}]}}}

Question: "Tell me about Marie Curie"
{"size":10,"_source":["fullname","category","year","motivation","birth_country_name","gender","affiliations","birth_year"],"query":{"multi_match":{"query":"Marie Curie","fields":["fullname^3","firstname^2","surname^2"]}}}

Question: "how many female Nobel laureates have there been"
{"size":0,"query":{"bool":{"filter":[{"term":{"gender":"female"}}]}}}

Question: "Nobel prizes related to quantum mechanics"
{"size":20,"_source":["fullname","category","year","motivation"],"knn":{"field":"motivation_embedding","query_vector":"[PLACEHOLDER]","k":20,"num_candidates":200}}

Question: "compare the number of physics vs chemistry prizes per decade"
{"size":0,"query":{"bool":{"filter":[{"terms":{"category":["Physics","Chemistry"]}}]}},"aggs":{"by_category":{"terms":{"field":"category"},"aggs":{"by_decade":{"histogram":{"field":"year","interval":10}}}}}}

Question: "which person won the most Nobel prizes"
{"size":0,"aggs":{"by_person":{"terms":{"field":"fullname.keyword","size":10,"order":{"_count":"desc"}}}}}
PROMPT;

    private const SYNTHESIS_PROMPT = <<<'PROMPT'
You are a research assistant. Answer the user's question using ONLY the Elasticsearch results provided below.

Current date: {current_date}

Rules:
- Base your answer entirely on the provided results.
- If the results contain aggregation buckets, interpret them (counts, rankings, distributions).
- If the results contain document hits, synthesize information from them.
- Cite laureates by full name, year, and prize category when relevant.
- Be concise but thorough. Use lists or tables when they make the answer clearer.
- If results are empty or insufficient, say so honestly.
- Do not invent or fabricate any facts not present in the results.
- Do not use your training knowledge to supplement the results.
- Ignore aggregation buckets with empty string keys ("") — these are data artifacts (e.g. organizations without a person name), not real results.
- If an aggregation returns zero buckets after filtering (e.g. bucket_selector), that means no results matched the criteria — state this clearly as "none found" rather than "insufficient data".
PROMPT;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly ElasticsearchClient $esClient,
        private readonly ChatProviderInterface $chatProvider,
        private readonly string $indexName
    ) {}

    /**
     * Answer a question by generating and executing an Elasticsearch query.
     */
    public function ask(string $question, ?callable $debugCallback = null): array
    {
        // ── Step 1: Get index schema ──────────────────────────────────────────

        $schema = $this->getSchema();

        if ($debugCallback) {
            $debugCallback('schema', $schema);
        }

        // ── Step 2: Generate ES query ─────────────────────────────────────────

        $esQuery = $this->generateQuery($question, $schema);

        // Resolve knn vector placeholder before execution
        $esQuery = $this->resolveKnn($esQuery, $question);

        if ($debugCallback) {
            $debugCallback('query', $this->redactVector($esQuery));
        }

        // ── Step 3: Execute query (with retry on error) ───────────────────────

        $result = $this->executeWithRetry($question, $schema, $esQuery, $debugCallback);

        if ($debugCallback) {
            $debugCallback('result_summary', [
                'hits'  => $result['hits']['total']['value'] ?? 0,
                'aggs'  => !empty($result['aggregations']),
            ]);
        }

        // ── Step 4: Synthesize answer ─────────────────────────────────────────

        $answer = $this->synthesize($question, $result, $esQuery);

        // Extract sources for display
        $sources = $this->extractSources($result);

        return [
            'answer'     => $answer,
            'sources'    => $sources,
            'question'   => $question,
            'es_query'   => $this->redactVector($esQuery),
        ];
    }

    /**
     * Get a simplified schema description from the index mapping.
     */
    private function getSchema(): string
    {
        $mapping = $this->esClient->get("/{$this->indexName}/_mapping");

        $properties = $mapping[$this->indexName]['mappings']['properties'] ?? [];

        return $this->flattenMapping($properties);
    }

    /**
     * Flatten nested mapping into a readable "field: type" format.
     */
    private function flattenMapping(array $properties, string $prefix = ''): string
    {
        $lines = [];

        foreach ($properties as $field => $config) {
            $path = $prefix ? "{$prefix}.{$field}" : $field;
            $type = $config['type'] ?? 'object';

            // Skip the embedding vector from schema (too noisy)
            if ($type === 'dense_vector') {
                $lines[] = "{$path}: dense_vector (dims={$config['dims']}, for knn semantic search)";
                continue;
            }

            if ($type === 'nested' || $type === 'object') {
                $lines[] = "{$path}: {$type}";
                if (!empty($config['properties'])) {
                    $lines[] = $this->flattenMapping($config['properties'], $path);
                }
            } elseif ($type === 'keyword') {
                $lines[] = "{$path}: keyword [aggregatable, sortable, filterable]";
            } elseif ($type === 'integer' || $type === 'long' || $type === 'float' || $type === 'double') {
                $lines[] = "{$path}: {$type} [aggregatable, sortable, filterable]";
            } elseif ($type === 'date') {
                $format = $config['format'] ?? '';
                $lines[] = "{$path}: date ({$format}) [aggregatable, sortable, filterable]";
            } elseif ($type === 'text') {
                $hasKeyword = !empty($config['fields']['keyword']);
                if ($hasKeyword) {
                    $lines[] = "{$path}: text [full-text searchable, use {$path}.keyword for aggs/sort/filter]";
                } else {
                    $lines[] = "{$path}: text [full-text searchable only, NOT aggregatable]";
                }
            } else {
                $lines[] = "{$path}: {$type}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Ask the LLM to generate an Elasticsearch query.
     */
    private function generateQuery(string $question, string $schema): array
    {
        $prompt = str_replace(
            ['{current_date}', '{index_name}', '{schema}'],
            [date('Y-m-d'), $this->indexName, $schema],
            self::QUERY_GENERATION_PROMPT
        );

        $messages = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user',   'content' => $question],
        ];

        $raw = $this->chatProvider->complete($messages);

        return $this->parseQuery($raw);
    }

    /**
     * Execute the query, retrying once if ES returns an error.
     */
    private function executeWithRetry(string $question, string $schema, array $esQuery, ?callable $debugCallback): array
    {
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $result = $this->esClient->post("/{$this->indexName}/_search", $esQuery);
                return $result;
            } catch (\RuntimeException $e) {
                if ($attempt >= self::MAX_RETRIES) {
                    throw $e;
                }

                if ($debugCallback) {
                    $debugCallback('retry', ['error' => $e->getMessage(), 'attempt' => $attempt + 1]);
                }

                // Ask the LLM to fix the query
                $esQuery = $this->fixQuery($question, $schema, $esQuery, $e->getMessage());
                $esQuery = $this->resolveKnn($esQuery, $question);

                if ($debugCallback) {
                    $debugCallback('query_fixed', $this->redactVector($esQuery));
                }
            }
        }

        throw new \RuntimeException('Query execution failed after retries');
    }

    /**
     * Replace knn placeholder vector with the actual embedding of the question.
     */
    private function resolveKnn(array $query, string $question): array
    {
        if (!isset($query['knn']['query_vector'])) {
            return $query;
        }

        $vec = $query['knn']['query_vector'];
        if (is_string($vec) || (is_array($vec) && empty($vec))) {
            $query['knn']['query_vector'] = $this->embeddingService->embedQuery($question);
        }

        return $query;
    }

    /**
     * Redact the large vector array for display/logging purposes.
     */
    private function redactVector(array $query): array
    {
        if (isset($query['knn']['query_vector']) && is_array($query['knn']['query_vector'])) {
            $query['knn']['query_vector'] = '[vector:' . count($query['knn']['query_vector']) . 'd]';
        }
        return $query;
    }

    /**
     * Ask the LLM to fix a broken query based on the ES error message.
     */
    private function fixQuery(string $question, string $schema, array $failedQuery, string $error): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => str_replace(
                    ['{current_date}', '{index_name}', '{schema}'],
                    [date('Y-m-d'), $this->indexName, $schema],
                    self::QUERY_GENERATION_PROMPT
                ),
            ],
            ['role' => 'user', 'content' => $question],
            ['role' => 'assistant', 'content' => json_encode($failedQuery, JSON_PRETTY_PRINT)],
            [
                'role' => 'user',
                'content' => "That query returned an error: {$error}\n\nPlease fix the query and return only the corrected JSON.",
            ],
        ];

        $raw = $this->chatProvider->complete($messages);

        return $this->parseQuery($raw);
    }

    /**
     * Parse JSON query from LLM output.
     */
    private function parseQuery(string $raw): array
    {
        $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $raw = preg_replace('/```\s*$/m', '', $raw);

        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $this->normalizeQuery($decoded);
        }

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $this->normalizeQuery($decoded);
            }
        }

        throw new \RuntimeException("Failed to parse LLM output as JSON: " . substr($raw, 0, 200));
    }

    /**
     * Fix json_decode(assoc:true) converting {} to [] for ES clauses that require objects.
     */
    private function normalizeQuery(array $data): array
    {
        $objectKeys = ['match_all', 'match_none', 'params', 'settings', 'mappings'];

        foreach ($data as $key => &$value) {
            if (in_array($key, $objectKeys, true) && is_array($value) && empty($value)) {
                $value = new \stdClass();
            } elseif (is_array($value)) {
                $value = $this->normalizeQuery($value);
            }
        }

        return $data;
    }

    /**
     * Synthesize a natural language answer from the ES results.
     */
    private function synthesize(string $question, array $result, array $esQuery = []): string
    {
        $prompt = str_replace('{current_date}', date('Y-m-d'), self::SYNTHESIS_PROMPT);

        // Format results concisely for the LLM
        $resultContext = $this->formatResultsForLLM($result);

        // Include the query so the LLM knows what filters were applied
        $queryContext = '';
        if (!empty($esQuery)) {
            $queryContext = "Query executed:\n" . json_encode($this->redactVector($esQuery), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
        }

        $messages = [
            ['role' => 'system', 'content' => $prompt],
            [
                'role'    => 'user',
                'content' => "{$queryContext}Elasticsearch results:\n\n{$resultContext}\n\nQuestion: {$question}",
            ],
        ];

        return $this->chatProvider->complete($messages);
    }

    /**
     * Format ES results into a compact text representation for the LLM.
     */
    private function formatResultsForLLM(array $result): string
    {
        $parts = [];

        // Total hits — this count reflects the query filters applied
        $total = $result['hits']['total']['value'] ?? 0;
        $parts[] = "Total matching documents: {$total} (this is the count of documents matching all query filters)";

        // Aggregations
        if (!empty($result['aggregations'])) {
            $parts[] = "\n## Aggregations\n" . $this->formatAggregations($result['aggregations']);
        }

        // Hits
        $hits = $result['hits']['hits'] ?? [];
        if (!empty($hits)) {
            $lines = ["\n## Documents (showing " . count($hits) . " of {$total})"];
            foreach ($hits as $i => $hit) {
                $src = $hit['_source'] ?? [];
                unset($src['motivation_embedding']);
                $n = $i + 1;
                $lines[] = "[{$n}] " . json_encode($src, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = implode("\n", $lines);
        }

        return implode("\n", $parts);
    }

    /**
     * Recursively format aggregation results.
     */
    private function formatAggregations(array $aggs, int $depth = 0): string
    {
        $lines = [];
        $indent = str_repeat('  ', $depth);

        foreach ($aggs as $name => $agg) {
            if (isset($agg['buckets'])) {
                // Filter out empty-string buckets (unnamed organizations, etc.)
                $validBuckets = array_filter($agg['buckets'], function ($bucket) {
                    $key = $bucket['key_as_string'] ?? $bucket['key'] ?? '';
                    return $key !== '';
                });

                if (empty($validBuckets)) {
                    $lines[] = "{$indent}{$name}: (no results)";
                    continue;
                }

                $lines[] = "{$indent}{$name}:";
                foreach ($validBuckets as $bucket) {
                    $key = $bucket['key_as_string'] ?? $bucket['key'] ?? '?';
                    $count = $bucket['doc_count'] ?? 0;
                    $line = "{$indent}  - {$key}: {$count}";

                    $subAggs = array_filter($bucket, fn($v, $k) => is_array($v) && !in_array($k, ['key', 'key_as_string', 'doc_count', 'from', 'to']), ARRAY_FILTER_USE_BOTH);
                    if (!empty($subAggs)) {
                        $line .= "\n" . $this->formatAggregations($subAggs, $depth + 2);
                    }

                    $lines[] = $line;
                }
            } elseif (isset($agg['value'])) {
                $lines[] = "{$indent}{$name}: {$agg['value']}";
            } elseif (isset($agg['count'])) {
                $lines[] = "{$indent}{$name}: count={$agg['count']} min={$agg['min']} max={$agg['max']} avg=" . round($agg['avg'] ?? 0, 1);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Extract source documents from hits for display purposes.
     */
    private function extractSources(array $result): array
    {
        $sources = [];
        foreach ($result['hits']['hits'] ?? [] as $hit) {
            $src = $hit['_source'] ?? [];
            unset($src['motivation_embedding']);
            $sources[] = $src;
        }
        return $sources;
    }
}
