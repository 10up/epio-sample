# ElasticPress.io Sample Project

![Support Level](https://img.shields.io/badge/support-paid-red)(#support-level) [![MIT License](https://img.shields.io/github/license/10up/epio-sample.svg)](https://github.com/10up/epio-sample/blob/trunk/LICENSE.md)

> A comprehensive example demonstrating how to use [ElasticPress.io](https://elasticpress.io) as a vector knowledge store for both traditional search and AI-powered applications, built with the Nobel Prize dataset.

**Data Source:** [Nobel Prize API v2.1](https://www.nobelprize.org/about/developer-zone-2/) by Nobel Prize Outreach.

## What This Project Demonstrates

**Search**
- Full-text keyword search with faceting, filters, and fuzzy matching
- Semantic (vector/kNN) search using dense embeddings
- Hybrid search combining BM25 and vector similarity
- Real-time autosuggest via ElasticPress.io search templates

**AI / RAG**
- Ask AI: natural language questions answered from the database with source citations
- Text-to-Elasticsearch-DSL: LLM reads index schema, composes optimal queries (aggregations, kNN, sorts), executes, synthesizes answers
- Compatible with any OpenAI-compatible API (OpenAI, Ollama, LM Studio, Groq, Azure)

**MCP Server**
- Exposes search and Ask AI as tools for AI models via the [Model Context Protocol](https://modelcontextprotocol.io)
- Works with Claude Desktop, Claude Code, and any MCP-compatible client
- Demonstrates using Elasticsearch as a local vector knowledge store for AI agents

**ElasticPress.io specifics**
- Correct bulk indexing format (no `_index` in action metadata)
- Index naming conventions
- Search template API for unauthenticated frontend access

## Prerequisites

- PHP 8.1+
- Composer
- An [ElasticPress.io](https://www.elasticpress.io/) account
- An OpenAI-compatible API key (for semantic search, Ask AI, and MCP tools)

## Quick Start

```bash
# Install dependencies
composer install

# Configure credentials
cp .env.example .env
# Edit .env — at minimum fill in ELASTICPRESS_* and OPENAI_API_KEY

# Create the index and import data
php bin/setup.php
php bin/index.php

# Generate vector embeddings (requires OPENAI_API_KEY)
php bin/generate-embeddings.php

# Set up the autosuggest search template
php bin/setup-template.php

# Start the web server
php -S localhost:8000 -t public
```

Open http://localhost:8000 — use the **Search** tab for keyword/semantic/hybrid search, and the **Ask AI** tab for natural language questions.

## Configuration

Copy `.env.example` to `.env` and fill in your credentials:

```env
# ElasticPress.io
ELASTICPRESS_HOST=https://your-endpoint.elasticpress.io
ELASTICPRESS_SUBSCRIPTION_ID=your-subscription-id
ELASTICPRESS_SUBSCRIPTION_TOKEN=your-subscription-token

# OpenAI-compatible API (OpenAI, Ollama, LM Studio, Groq, Azure, …)
OPENAI_API_BASE_URL=https://api.openai.com/v1
OPENAI_API_KEY=sk-your-api-key
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_EMBEDDING_DIMS=1536
OPENAI_CHAT_MODEL=gpt-4o-mini
```

**Using a local model?** Point `OPENAI_API_BASE_URL` at your provider:

| Provider | Base URL |
|----------|----------|
| OpenAI | `https://api.openai.com/v1` |
| Ollama | `http://localhost:11434/v1` |
| LM Studio | `http://localhost:1234/v1` |
| Groq | `https://api.groq.com/openai/v1` |

## Setup Steps

### 1. Create the index

```bash
php bin/setup.php
```

Creates the `{subscription-id}-laureates` index with full-text and vector field mappings.

If you already have the index and only need to add the vector field:

```bash
php bin/update-mapping.php
```

### 2. Index the data

```bash
php bin/index.php
```

Fetches ~1,000 laureate documents from the Nobel Prize API and bulk-indexes them.

> **ElasticPress.io note:** Bulk action metadata must not include `_index`. The index is specified in the URL instead. See `src/Index/BulkIndexer.php`.

### 3. Generate vector embeddings

```bash
php bin/generate-embeddings.php

# Options
php bin/generate-embeddings.php --dry-run          # preview without API calls
php bin/generate-embeddings.php --batch-size=50    # documents per API call
php bin/generate-embeddings.php --force            # re-generate existing embeddings
```

Each laureate document gets a `motivation_embedding` field (1536-dim dense vector) built from: `{category} {name} ({year}): {motivation}. Affiliated with: {institutions}`.

> Approximate cost with `text-embedding-3-small`: < $0.01 for the full dataset.

### 4. Set up the autosuggest template

```bash
php bin/setup-template.php
```

Creates an ElasticPress.io search template enabling unauthenticated autosuggest directly from the browser.

## Using the Web Interface

```bash
php -S localhost:8000 -t public
```

### Search tab

| Mode | Description |
|------|-------------|
| **Keyword** | BM25 full-text search with fuzzy matching across names, motivations, affiliations |
| **Semantic** | kNN vector search — finds results by meaning, not keyword match |
| **Hybrid** | Combines BM25 and kNN scores for best-of-both retrieval |

Results include relevance score. Filters (category, gender, year) apply to all modes.

### Ask AI tab

Ask natural language questions. The system uses a text-to-Elasticsearch-DSL approach that adapts to any index schema without code changes:
1. Reads the index mapping to discover available fields and their types
2. The LLM writes the optimal ES query DSL (aggregations, filters, kNN, sorts)
3. Executes the query against Elasticsearch with auto-retry on errors
4. The LLM synthesizes a grounded answer from the raw hits and aggregations

Example questions:
- *Which women won the physics prize?*
- *What breakthroughs in cancer research won Nobel Prizes?*
- *Which person won the most Nobel prizes?*
- *Which country produced the most chemistry laureates?*

## CLI Scripts

```bash
# Keyword search
php bin/search.php "einstein"
php bin/search.php --category=physics --gender=female
php bin/search.php "quantum" --year-from=2000

# Semantic and hybrid search (requires embeddings)
php bin/semantic-search.php "quantum entanglement"
php bin/semantic-search.php "nuclear structure" --mode=hybrid --k=10

# Ask AI (RAG)
php bin/ask.php "What contributions did women make to physics?"
php bin/ask.php "Which German physicists won prizes after 1950?" --verbose

# Manage templates
php bin/manage-templates.php list
php bin/manage-templates.php view {index-name}
```

## MCP Server

The project includes an [MCP (Model Context Protocol)](https://modelcontextprotocol.io) server that exposes search and Ask AI as tools for AI models.

### Available tools

| Tool | Description |
|------|-------------|
| `search` | Keyword search with filters (category, gender, country, year) |
| `semantic_search` | Vector kNN search by concept similarity |
| `hybrid_search` | Combined BM25 + kNN search |
| `ask` | Answer a question using RAG — the AI decides what to search for |
| `get_document` | Fetch a specific laureate document by ID |

### Adding to Claude Code (CLI)

```bash
claude mcp add nobel-prize \
  -e OPENAI_API_KEY="your-key" \
  -e OPENAI_API_BASE_URL="https://api.openai.com/v1" \
  -e OPENAI_CHAT_MODEL="gpt-4o-mini" \
  -e OPENAI_EMBEDDING_MODEL="text-embedding-3-small" \
  -- php /absolute/path/to/bin/mcp-server.php
```

The ElasticPress.io credentials are read from `.env` automatically. OpenAI credentials must be passed explicitly since the MCP process runs in an isolated environment.

Verify the server is running:

```bash
claude mcp list
# nobel-prize: php ... - ✓ Connected
```

### Adding to Claude Desktop

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "nobel-prize": {
      "command": "php",
      "args": ["/absolute/path/to/bin/mcp-server.php"],
      "env": {
        "OPENAI_API_KEY": "your-key",
        "OPENAI_API_BASE_URL": "https://api.openai.com/v1",
        "OPENAI_CHAT_MODEL": "gpt-4o-mini",
        "OPENAI_EMBEDDING_MODEL": "text-embedding-3-small"
      }
    }
  }
}
```

Restart Claude Desktop after saving. The Nobel Prize tools will appear in the tools menu.

### Example usage in Claude

Once connected, you can ask Claude to use the tools directly:

> *Use the `ask` tool: which women won the physics prize?*

> *Use the `search` tool to find peace prize winners from Japan*

> *Use the `hybrid_search` tool: breakthroughs in genetics*

## API Reference

### Search

```
GET /api.php?q=einstein&mode=keyword
GET /api.php?q=quantum+mechanics&mode=semantic
GET /api.php?q=einstein&mode=hybrid
```

Parameters: `q`, `mode` (keyword/semantic/hybrid), `category`, `gender`, `birth_country`, `prize_country`, `year_from`, `year_to`, `page`, `per_page`

### Ask AI

```
GET /api.php?ask=Which+women+won+the+physics+prize
```

Returns: `{ answer, sources[], question, mode: "rag" }`

### Document detail

```
GET /api.php?id={document-id}
```

## Project Structure

```
.
├── bin/
│   ├── setup.php                  # Create index
│   ├── index.php                  # Import Nobel Prize data
│   ├── update-mapping.php         # Add vector field to existing index
│   ├── generate-embeddings.php    # Generate and store vector embeddings
│   ├── search.php                 # CLI keyword search
│   ├── semantic-search.php        # CLI semantic/hybrid search
│   ├── ask.php                    # CLI Ask AI (RAG)
│   ├── mcp-server.php             # MCP server entry point
│   ├── setup-template.php         # Create autosuggest template
│   └── manage-templates.php       # Manage templates
├── public/
│   ├── index.php                  # Web interface (Search + Ask AI tabs)
│   ├── api.php                    # REST API
│   └── search-api-template.php    # Serves autosuggest template to frontend
└── src/
    ├── Client/ElasticsearchClient.php
    ├── Config/Config.php
    ├── Data/
    │   ├── NobelDataFetcher.php
    │   └── NobelDataTransformer.php
    ├── Embeddings/
    │   ├── EmbeddingProviderInterface.php
    │   ├── OpenAIEmbeddingProvider.php    # Any OpenAI-compatible endpoint
    │   ├── EmbeddingService.php           # Text composition + embedding
    │   └── EmbeddingUpdater.php           # Scroll + bulk-update embeddings
    ├── Index/
    │   ├── IndexManager.php
    │   └── BulkIndexer.php
    ├── Mapping/NobelPrizeMapping.php
    ├── MCP/
    │   ├── McpServer.php                  # JSON-RPC 2.0 over stdio
    │   └── ToolRegistry.php
    ├── RAG/
    │   ├── ChatProviderInterface.php
    │   ├── OpenAIChatProvider.php         # Any OpenAI-compatible endpoint
    │   └── RagService.php                 # 3-phase RAG pipeline
    ├── Search/SearchService.php           # Keyword, semantic, hybrid search
    ├── Search/SearchTemplateManager.php
    └── Shared/
        ├── HttpClientFactory.php          # Shared Guzzle client setup
        └── ServiceFactory.php             # Assembles AI services from config
```

## Troubleshooting

**`explicit index in bulk is not allowed`**
ElasticPress.io requires the `_index` field to be omitted from bulk metadata. The index is specified in the URL. See `src/Index/BulkIndexer.php`.

**Semantic search returns 0 results**
Embeddings have not been generated yet. Run `php bin/generate-embeddings.php`.

**Ask AI returns no results**
- Confirm `OPENAI_API_KEY` is set in `.env`
- Confirm embeddings are generated (`php bin/generate-embeddings.php`)
- Run `php bin/ask.php "your question" --verbose` to see what filters and strategies were used

**MCP server fails to connect**
- Ensure PHP is in your `PATH` or use the absolute path in the `command` field
- Check ElasticPress.io credentials are in `.env` (MCP reads them from there)
- Pass OpenAI credentials explicitly via `env` in the MCP config (they are not inherited from the shell)
- Test the server directly: `echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' | php bin/mcp-server.php`

**Changing the embedding model**
If you change `OPENAI_EMBEDDING_MODEL` or `OPENAI_EMBEDDING_DIMS`, you must recreate the index and regenerate all embeddings — the vector dimensions are fixed in the mapping and cannot be changed in place.

## Resources

- [ElasticPress.io](https://www.elasticpress.io/) — Managed Elasticsearch service
- [ElasticPress.io Developer Documentation](https://www.elasticpress.io/resources/section/developer-documentation/)
- [ElasticPress.io Post Search API](https://www.elasticpress.io/resources/articles/instant-results-post-search-api/)
- [Model Context Protocol](https://modelcontextprotocol.io)
- [Nobel Prize API v2.1](https://www.nobelprize.org/about/developer-zone-2/)

## Support Level

**Provided as-is.** This is a sample project with no support commitment. For engineering consulting enquiries visit [ElasticPress.io Consulting](https://www.elasticpress.io/elasticpress-consulting/).

## Like what you see?

[![Work with the 10up WordPress Practice at Fueled](https://github.com/10up/.github/blob/trunk/profile/10up-github-banner.jpg)](http://10up.com/contact/)
