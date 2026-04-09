<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\RAG;

use ElasticPressIO\Sample\Embeddings\EmbeddingService;
use ElasticPressIO\Sample\Search\SearchService;

/**
 * Retrieval-Augmented Generation (RAG) service for Nobel Prize data.
 *
 * Three-phase pipeline following established RAG patterns:
 *
 * Phase 1 — Query Understanding
 *   A single LLM call extracts structured search parameters from the question:
 *   keywords for text search, structured filters (category, gender, country…),
 *   and a rephrased semantic query optimised for vector similarity.
 *
 * Phase 2 — Multi-strategy Retrieval + Fusion
 *   Multiple Elasticsearch searches run with the extracted parameters:
 *   - Filter search: ensures complete recall for all filter-matching documents
 *   - Keyword search: high-precision text matching when keywords are present
 *   - Semantic search: conceptual coverage via kNN vector similarity
 *   Results are merged with Reciprocal Rank Fusion (RRF), a parameter-free
 *   algorithm that rewards documents appearing high across multiple result lists.
 *
 * Phase 3 — Grounded Answer Generation
 *   A single LLM call synthesises an answer from the top-K fused results.
 *   The system prompt strictly forbids using training knowledge.
 *
 * @see https://arxiv.org/abs/2009.01792 Reciprocal Rank Fusion
 * @see https://en.wikipedia.org/wiki/Retrieval-augmented_generation
 */
class RagService
{
    /**
     * RRF rank constant.
     * @see https://plg.uwaterloo.ca/~gvcormac/cormacksigir09-rrf.pdf
     */
    private const RRF_K = 60;

    /** Supported aggregation dimensions for analytical queries. */
    private const VALID_AGGREGATIONS = ['laureate', 'birth_country', 'prize_country', 'category', 'year'];

    /**
     * Maximum documents fetched by the filter-only strategy.
     * Large so enumerate queries ("which women won X") get all matching docs.
     */
    private const RETRIEVAL_K_FILTER = 100;

    /** Candidates per semantic / keyword retrieval strategy */
    private const RETRIEVAL_K_SEMANTIC = 15;

    /**
     * Maximum context documents sent to the LLM for synthesise queries.
     * Enumerate queries bypass this and send all filtered results.
     */
    private const CONTEXT_K_SYNTHESIZE = 10;

    /** System prompt for Phase 1: query understanding */
    private const UNDERSTANDING_PROMPT = <<<'PROMPT'
You are a query parser for a Nobel Prize database.
Given a question, return a JSON object with these keys: "keywords", "filters", "semantic_query", and optionally "aggregate_by".

The Nobel Prize database has these structured filter fields:
- category: Physics | Chemistry | Physiology or Medicine | Literature | Peace | Economic Sciences
- gender: female | male | org
- birth_country: ISO 3166-1 alpha-2 code (DE, US, FR, GB, PL, etc.)
- prize_country: ISO 3166-1 alpha-2 code
- year_from: integer
- year_to: integer

Important field notes:
- birth_country stores historical full country names (e.g. "Germany", "France", "United States").
  For nationality/country-of-birth questions put the country name in "keywords" — this searches the
  birth_country_name text field and correctly matches historical variants (Germany, West Germany, etc.).
  Do NOT put birth country in filters.
- prize_country stores ISO-2 institution codes (US, DE, GB…) and CAN be used as a filter.
- category values are: Physics | Chemistry | Physiology or Medicine | Literature | Peace | Economic Sciences
- gender values are: female | male | org

Use "aggregate_by" when the question asks for counts, rankings, or "most/least/how many" across a dimension:
- "aggregate_by": "laureate"       → count prizes per individual (most prizes won by one person)
- "aggregate_by": "birth_country"  → count prizes per birth country
- "aggregate_by": "prize_country"  → count prizes per institution country
- "aggregate_by": "category"       → count prizes per category
- "aggregate_by": "year"           → count prizes per year

Return ONLY a JSON object, no other text.

Here are examples:

Question: "What contributions did women make to physics?"
{"keywords":"","filters":{"category":"Physics","gender":"female"},"semantic_query":"female physicists Nobel Prize contributions"}

Question: "Which German-born scientists won the chemistry prize?"
{"keywords":"Germany","filters":{"category":"Chemistry"},"semantic_query":"German chemists Nobel Prize discoveries"}

Question: "Peace prize winners in the 21st century"
{"keywords":"","filters":{"category":"Peace","year_from":2000},"semantic_query":"Nobel Peace Prize 21st century laureates"}

Question: "What breakthroughs in DNA research won Nobel Prizes?"
{"keywords":"DNA genetics","filters":{},"semantic_query":"DNA genetics molecular biology Nobel Prize breakthroughs"}

Question: "Tell me about Marie Curie"
{"keywords":"Marie Curie","filters":{},"semantic_query":"Marie Curie radioactivity Nobel Prize Poland"}

Question: "organisations that won the peace prize"
{"keywords":"","filters":{"category":"Peace","gender":"org"},"semantic_query":"organizations institutions Nobel Peace Prize"}

Question: "medicine prizes for cancer treatment after 2000"
{"keywords":"cancer treatment","filters":{"category":"Physiology or Medicine","year_from":2000},"semantic_query":"cancer treatment therapy Nobel Prize medicine"}

Question: "which person won the most Nobel prizes"
{"keywords":"","filters":{},"semantic_query":"laureate multiple Nobel Prizes","aggregate_by":"laureate"}

Question: "which country produced the most physics Nobel laureates"
{"keywords":"","filters":{"category":"Physics"},"semantic_query":"Nobel physics prize countries by nationality","aggregate_by":"birth_country"}

Question: "which german people got Nobel prizes"
{"keywords":"Germany","filters":{},"semantic_query":"German Nobel Prize laureates born in Germany"}

Question: "how many prizes were awarded per year in the 2000s"
{"keywords":"","filters":{"year_from":2000,"year_to":2009},"semantic_query":"Nobel prizes per year","aggregate_by":"year"}
PROMPT;

    /** System prompt for Phase 3: answer generation */
    private const GENERATION_PROMPT = <<<'PROMPT'
You are a research assistant for Nobel Prize history.

Answer the question using ONLY the context documents provided.
Do not use your training knowledge to supplement or fill gaps.
If the context covers the topic only partially, answer based on what is there and note that coverage may be incomplete.
Do not invent or fabricate any facts, names, dates, or prizes.
Cite each laureate you mention by full name, year, and prize category.
PROMPT;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly SearchService $searchService,
        private readonly ChatProviderInterface $chatProvider
    ) {}

    /**
     * Answer a question about Nobel Prize data using the three-phase RAG pipeline.
     *
     * @param string        $indexName     Fully-qualified index name (with prefix)
     * @param string        $question      The user's question
     * @param callable|null $debugCallback Optional fn(string $phase, mixed $data) for logging
     * @return array{answer: string, sources: array[], question: string, query_plan: array}
     */
    public function ask(string $indexName, string $question, ?callable $debugCallback = null): array
    {
        // ── Phase 1: Query Understanding ──────────────────────────────────────

        $queryPlan = $this->understandQuery($question);

        if ($debugCallback !== null) {
            $debugCallback('query_plan', $queryPlan);
        }

        // ── Aggregate queries — short-circuit to a dedicated path ─────────────

        if (!empty($queryPlan['aggregate_by'])) {
            if ($debugCallback !== null) {
                $debugCallback('aggregate', $queryPlan['aggregate_by']);
            }
            return $this->handleAggregateQuery($indexName, $question, $queryPlan, $debugCallback);
        }

        // ── Phase 2: Multi-strategy Retrieval ─────────────────────────────────

        $resultLists = $this->retrieve($indexName, $queryPlan);

        if ($debugCallback !== null) {
            $counts = array_map(fn($list) => count($list), $resultLists);
            $debugCallback('retrieval', $counts);
        }

        // ── Phase 2b: Reciprocal Rank Fusion ──────────────────────────────────

        $fused = $this->fuseWithRRF($resultLists);

        // Enumerate queries (filter-only, no semantic keywords) should send all
        // filtered results to the LLM so it can give a complete answer.
        // Synthesize queries (semantic/conceptual) are capped to avoid padding
        // the context with loosely-related documents.
        $isEnumerateQuery = !empty($queryPlan['filters']) && empty($queryPlan['keywords']);
        $context = $isEnumerateQuery
            ? $fused
            : array_slice($fused, 0, self::CONTEXT_K_SYNTHESIZE);

        if ($debugCallback !== null) {
            $debugCallback('fused', count($fused));
            $debugCallback('context', [
                'type'  => $isEnumerateQuery ? 'enumerate (all results)' : 'synthesize (top ' . self::CONTEXT_K_SYNTHESIZE . ')',
                'count' => count($context),
            ]);
        }

        // ── Phase 3: Answer Generation ────────────────────────────────────────

        $answer = $this->generateAnswer($question, $context, $isEnumerateQuery);

        return [
            'answer'      => $answer,
            'sources'     => $context,
            'question'    => $question,
            'query_plan'  => $queryPlan,
        ];
    }

    // ── Phase 1 ───────────────────────────────────────────────────────────────

    /**
     * Use the LLM to extract structured search parameters from the question.
     *
     * Returns an array with keys: keywords, filters, semantic_query.
     */
    private function understandQuery(string $question): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::UNDERSTANDING_PROMPT],
            ['role' => 'user',   'content' => $question],
        ];

        $raw = $this->chatProvider->complete($messages);

        $plan = $this->parseJson($raw);

        $aggregateBy = trim($plan['aggregate_by'] ?? '');

        return [
            'keywords'       => trim($plan['keywords'] ?? ''),
            'filters'        => $this->sanitiseFilters($plan['filters'] ?? []),
            'semantic_query' => trim($plan['semantic_query'] ?? $question),
            'aggregate_by'   => in_array($aggregateBy, self::VALID_AGGREGATIONS) ? $aggregateBy : '',
        ];
    }

    /**
     * Parse JSON from an LLM response, tolerating markdown fences and extra text.
     */
    private function parseJson(string $raw): array
    {
        // Strip markdown code fences if present
        $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $raw = preg_replace('/```\s*$/m', '', $raw);

        // Try direct decode first
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Extract first JSON object from the text as a fallback
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Validate and normalise filters extracted by the LLM.
     *
     * Maps common aliases and lowercase names to the exact keyword values
     * stored in Elasticsearch (case-sensitive keyword fields).
     *
     * Actual stored category values (from ES aggregation):
     *   Physics, Chemistry, Physiology or Medicine, Literature, Peace, Economic Sciences
     */
    private function sanitiseFilters(array $raw): array
    {
        $filters = [];

        // Map LLM category output (any casing/alias) to the exact stored keyword value
        $categoryMap = [
            'physics'                => 'Physics',
            'chemistry'              => 'Chemistry',
            'medicine'               => 'Physiology or Medicine',
            'physiology'             => 'Physiology or Medicine',
            'physiology or medicine' => 'Physiology or Medicine',
            'medical'                => 'Physiology or Medicine',
            'literature'             => 'Literature',
            'peace'                  => 'Peace',
            'economics'              => 'Economic Sciences',
            'economic sciences'      => 'Economic Sciences',
            'economic'               => 'Economic Sciences',
        ];

        if (!empty($raw['category'])) {
            $key = strtolower(trim($raw['category']));
            if (isset($categoryMap[$key])) {
                $filters['category'] = $categoryMap[$key];
            }
        }

        $validGenders = ['male', 'female', 'org'];
        if (!empty($raw['gender']) && in_array(strtolower($raw['gender']), $validGenders)) {
            $filters['gender'] = strtolower($raw['gender']);
        }

        // birth_country stores full historical names ("Germany", "West Germany"…).
        // The prompt directs the LLM to put nationality in keywords instead;
        // this is a safety-net for well-formed full names passed as a filter.
        if (!empty($raw['birth_country']) && is_string($raw['birth_country'])) {
            $filters['birth_country'] = $raw['birth_country'];
        }
        // prize_country stores ISO-2 codes (institution/affiliation country).
        if (!empty($raw['prize_country']) && preg_match('/^[A-Za-z]{2}$/', $raw['prize_country'])) {
            $filters['prize_country'] = strtoupper($raw['prize_country']);
        }
        if (!empty($raw['year_from']) && is_numeric($raw['year_from'])) {
            $filters['year_from'] = (int) $raw['year_from'];
        }
        if (!empty($raw['year_to']) && is_numeric($raw['year_to'])) {
            $filters['year_to'] = (int) $raw['year_to'];
        }

        return $filters;
    }

    // ── Aggregate queries ─────────────────────────────────────────────────────

    /**
     * Handle aggregate queries ("which person won the most", "count by country", etc.).
     *
     * Fetches all relevant documents, computes the aggregation in PHP (group + count),
     * formats a compact summary, and asks the LLM to interpret it.
     */
    private function handleAggregateQuery(
        string $indexName,
        string $question,
        array $queryPlan,
        ?callable $debugCallback
    ): array {
        $aggregateBy = $queryPlan['aggregate_by'];
        $filters     = $queryPlan['filters'];
        $keywords    = $queryPlan['keywords'];

        // Fetch enough documents to compute the aggregation accurately.
        // Apply both filters and keywords so the aggregation is scoped to
        // the relevant subset (e.g. "most German laureates" should only count
        // documents matching Germany, not the full dataset).
        $result  = $this->searchService->search($indexName, $keywords, $filters, 0, 2000);
        $sources = $this->extractSources($result);

        if ($debugCallback !== null) {
            $debugCallback('retrieval', ['aggregate_fetch' => count($sources)]);
        }

        // Compute the aggregation in PHP
        $aggregated = $this->computeAggregation($sources, $aggregateBy);

        if ($debugCallback !== null) {
            $debugCallback('fused', count($aggregated));
        }

        // Format a compact summary for the LLM (no motivation text needed)
        $summaryLines = [];
        $rank = 1;
        foreach (array_slice($aggregated, 0, 50) as $group) {
            $label = $group['label'];
            $count = $group['count'];
            $detail = $group['detail'] ?? '';
            $summaryLines[] = "{$rank}. {$label}: {$count} prize(s)" . ($detail ? " ({$detail})" : '');
            $rank++;
        }

        $totalGroups = count($aggregated);
        $contextText = "Aggregation: count of Nobel Prizes by {$aggregateBy}\n"
            . "Total groups: {$totalGroups}\n\n"
            . implode("\n", $summaryLines);

        if ($totalGroups > 50) {
            $contextText .= "\n... and " . ($totalGroups - 50) . " more groups.";
        }

        // LLM interprets the aggregation result
        $messages = [
            ['role' => 'system', 'content' => self::GENERATION_PROMPT],
            [
                'role'    => 'user',
                'content' => "Aggregated data:\n\n{$contextText}\n\nQuestion: {$question}",
            ],
        ];

        $answer = $this->chatProvider->complete($messages);

        return [
            'answer'     => $answer,
            'sources'    => array_slice($sources, 0, 20), // representative sample for sidebar
            'question'   => $question,
            'query_plan' => $queryPlan,
        ];
    }

    /**
     * Group documents by a field and count occurrences, returning results
     * sorted by count descending.
     *
     * @param array[] $sources Documents from extractSources()
     * @param string  $by      Field to group by (laureate, birth_country, category, year, prize_country)
     * @return array<string, array{label: string, count: int, detail: string}>
     */
    private function computeAggregation(array $sources, string $by): array
    {
        $groups = [];

        foreach ($sources as $doc) {
            switch ($by) {
                case 'laureate':
                    $id    = $doc['laureate_id'] ?? $doc['id'] ?? '';
                    $label = $doc['fullname'] ?? ($doc['firstname'] ?? '') . ' ' . ($doc['surname'] ?? '');
                    $detail = ($doc['category'] ?? '') . ' ' . ($doc['year'] ?? '');
                    break;
                case 'birth_country':
                    $id     = $doc['birth_country'] ?? 'unknown';
                    $label  = $doc['birth_country_name'] ?? $id;
                    $detail = '';
                    break;
                case 'prize_country':
                    $countries = $doc['prize_countries'] ?? [];
                    foreach ((array) $countries as $c) {
                        if (!isset($groups[$c])) {
                            $groups[$c] = ['label' => $c, 'count' => 0, 'detail' => ''];
                        }
                        $groups[$c]['count']++;
                    }
                    continue 2;
                case 'category':
                    $id    = $doc['category'] ?? 'unknown';
                    $label = $id;
                    $detail = '';
                    break;
                case 'year':
                    $id    = (string) ($doc['year'] ?? 'unknown');
                    $label = $id;
                    $detail = '';
                    break;
                default:
                    continue 2;
            }

            if (!isset($groups[$id])) {
                $groups[$id] = ['label' => trim($label), 'count' => 0, 'detail' => ''];
            }
            $groups[$id]['count']++;

            // Accumulate details (categories/years) for the laureate aggregation
            if ($by === 'laureate' && $detail) {
                $current = $groups[$id]['detail'];
                $groups[$id]['detail'] = $current ? $current . ', ' . $detail : $detail;
            }
        }

        // Sort by count descending
        uasort($groups, fn($a, $b) => $b['count'] <=> $a['count']);

        return $groups;
    }

    // ── Phase 2 ───────────────────────────────────────────────────────────────

    /**
     * Run multiple retrieval strategies and return their ranked result lists.
     *
     * @return array[] Array of result lists, each list ordered by relevance
     */
    private function retrieve(string $indexName, array $queryPlan): array
    {
        $lists    = [];
        $keywords = $queryPlan['keywords'];
        $filters  = $queryPlan['filters'];
        $semQuery = $queryPlan['semantic_query'];

        // Strategy A: filter-only search — guarantees complete recall for all
        // structured constraints (gender, category, country, year).
        // Uses a high limit so enumerate queries get all matching documents.
        if (!empty($filters)) {
            $result = $this->searchService->search($indexName, '', $filters, 0, self::RETRIEVAL_K_FILTER);
            if ($result['total'] > 0) {
                $lists['filtered'] = $this->extractSources($result);
            }
        }

        // Strategy B: keyword search — high precision for names and specific terms.
        // Only run when keywords are non-trivial (skip for generic descriptions).
        if ($keywords !== '' && !$this->isGenericQuery($keywords)) {
            $result = $this->searchService->search($indexName, $keywords, $filters, 0, self::RETRIEVAL_K_SEMANTIC);
            if ($result['total'] > 0) {
                $lists['keyword'] = $this->extractSources($result);
            }
        }

        // Strategy C: semantic search — conceptual coverage.
        // Only adds value for synthesize queries; for enumerate queries the
        // filter strategy already has complete recall. We still run it so that
        // results from all strategies can be merged via RRF.
        try {
            $queryVector = $this->embeddingService->embedQuery($semQuery);
            $result = $this->searchService->semanticSearch(
                $indexName,
                $queryVector,
                $filters,
                self::RETRIEVAL_K_SEMANTIC,
                self::RETRIEVAL_K_SEMANTIC * 10
            );
            if ($result['total'] > 0) {
                $lists['semantic'] = $this->extractSources($result);
            }
        } catch (\Throwable) {
            // Semantic search requires embeddings — gracefully skip if unavailable
        }

        return $lists;
    }

    /**
     * Extract document source arrays from a SearchService result.
     * Removes the large embedding vector.
     *
     * @return array[] Ordered array of source documents
     */
    private function extractSources(array $searchResult): array
    {
        return array_map(function ($hit) {
            $src = $hit['source'];
            unset($src['motivation_embedding']);
            return $src;
        }, $searchResult['results']);
    }

    /**
     * Heuristic: is this query too generic to be useful for keyword search?
     * Generic descriptions (no proper nouns, no specific terms) tend to produce
     * false negatives when AND'd with filters in a multi_match query.
     */
    private function isGenericQuery(string $query): bool
    {
        $genericWords = ['contributions', 'contribution', 'research', 'work', 'studies',
                         'discoveries', 'discovery', 'achievements', 'laureates', 'winners',
                         'scientists', 'researchers', 'physicists', 'chemists', 'biologists'];

        $words = preg_split('/\s+/', strtolower(trim($query)));
        if (empty($words)) {
            return true;
        }

        $genericCount = count(array_intersect($words, $genericWords));
        // Consider generic if more than half the words are generic descriptors
        return $genericCount / count($words) > 0.5;
    }

    // ── Phase 2b ──────────────────────────────────────────────────────────────

    /**
     * Merge multiple ranked result lists using Reciprocal Rank Fusion (RRF).
     *
     * RRF score = Σ 1 / (k + rank_i) across all lists containing the document.
     * Documents appearing high in multiple lists get the highest scores.
     * Documents in only one list are still included, ranked by their position.
     *
     * @param array[] $resultLists Named arrays of source documents (ordered by relevance)
     * @return array[] Deduplicated documents ordered by RRF score (descending)
     */
    private function fuseWithRRF(array $resultLists): array
    {
        $scores = [];  // docId → RRF score
        $docs   = [];  // docId → source document

        foreach ($resultLists as $list) {
            foreach ($list as $rank => $doc) {
                $id = $doc['id'] ?? '';
                if ($id === '') {
                    continue;
                }

                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (self::RRF_K + $rank + 1);
                $docs[$id]   = $doc;
            }
        }

        arsort($scores);

        return array_values(array_map(fn($id) => $docs[$id], array_keys($scores)));
    }

    // ── Phase 3 ───────────────────────────────────────────────────────────────

    /**
     * Generate a grounded answer from the fused context documents.
     *
     * Enumerate queries (filter-only, expecting a complete list) build the
     * list directly in PHP — the LLM only writes a brief intro sentence.
     * This guarantees the list is never truncated regardless of token limits.
     *
     * Synthesize queries (conceptual) use the LLM for the full response.
     */
    private function generateAnswer(string $question, array $context, bool $isEnumerateQuery = false): string
    {
        if (empty($context)) {
            return 'No relevant documents were found in the database for this question.';
        }

        if ($isEnumerateQuery) {
            return $this->generateEnumerateAnswer($question, $context);
        }

        $contextBlock = $this->formatContext($context);

        $messages = [
            ['role' => 'system', 'content' => self::GENERATION_PROMPT],
            [
                'role'    => 'user',
                'content' => "Context documents:\n\n{$contextBlock}\n\nQuestion: {$question}",
            ],
        ];

        return $this->chatProvider->complete($messages);
    }

    /**
     * For enumerate queries, build the complete list in PHP and ask the LLM
     * only for a short introductory sentence.
     *
     * The list is constructed here, not by the LLM, so it is always complete.
     */
    private function generateEnumerateAnswer(string $question, array $context): string
    {
        $count = count($context);

        // Ask the LLM for a one-sentence intro only — cheap and reliable
        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a research assistant for Nobel Prize data. ' .
                             'Write exactly one concise introductory sentence for the list of results that follows. ' .
                             'Do not list the results yourself.',
            ],
            [
                'role'    => 'user',
                'content' => "Question: {$question}\nFound {$count} matching laureates in the database.",
            ],
        ];

        $intro = trim($this->chatProvider->complete($messages, ['max_completion_tokens' => 80]));

        // Build the complete list in PHP — no token limit applies here
        $lines = [];
        foreach ($context as $i => $doc) {
            $fullname   = $doc['fullname'] ?? trim(($doc['firstname'] ?? '') . ' ' . ($doc['surname'] ?? ''));
            $category   = $doc['category'] ?? '';
            $year       = $doc['year'] ?? '';
            $motivation = $doc['motivation'] ?? '';

            $entry = ($i + 1) . '. **' . $fullname . '** — ' . $category . ' ' . $year;
            if ($motivation) {
                // Trim motivation to ~120 chars for readability
                $short = mb_strlen($motivation) > 120
                    ? mb_substr($motivation, 0, 117) . '...'
                    : $motivation;
                $entry .= ': ' . $short;
            }
            $lines[] = $entry;
        }

        return $intro . "\n\n" . implode("\n", $lines);
    }

    /**
     * Format context documents for inclusion in the generation prompt.
     */
    private function formatContext(array $docs): string
    {
        $lines = [];

        foreach ($docs as $i => $doc) {
            $n        = $i + 1;
            $fullname = $doc['fullname'] ?? trim(($doc['firstname'] ?? '') . ' ' . ($doc['surname'] ?? ''));
            $category = ucfirst($doc['category'] ?? '');
            $year     = $doc['year'] ?? '';
            $motivation = $doc['motivation'] ?? 'No motivation recorded.';

            $line = "[{$n}] {$fullname} — {$category} Prize {$year}\n";
            $line .= "    Motivation: {$motivation}";

            $affiliations = $doc['affiliations'] ?? [];
            if (!empty($affiliations)) {
                $names = array_filter(array_map(fn($a) => $a['name'] ?? '', $affiliations));
                if (!empty($names)) {
                    $line .= "\n    Affiliation: " . implode(', ', $names);
                }
            }

            $lines[] = $line;
        }

        return implode("\n\n", $lines);
    }
}
