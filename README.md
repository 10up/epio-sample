# ElasticPress.io Sample Project

A comprehensive example demonstrating how to integrate with the managed Elasticsearch service [ElasticPress.io](https://elasticpress.io) outside of WordPress using the Nobel Prize dataset.

**Data Source:** This project uses data from the [Nobel Prize API v2.1](https://www.nobelprize.org/about/developer-zone-2/), provided by Nobel Prize Outreach. The API contains information about all Nobel Prize laureates from 1901 to present.

## What This Project Demonstrates

- Authentication with ElasticPress.io endpoints
- Creating and managing indexes with proper mappings
- Bulk indexing operations from external APIs
- Full-text search with faceting and filters
- Real-time searches using ElasticPress.io search templates
- Interactive web interface with detail views
- Key differences between standard Elasticsearch and ElasticPress.io

## Prerequisites

- PHP 8.1 or higher
- Composer
- An ElasticPress.io account with credentials ([Sign up here](https://www.elasticpress.io/))
- Web server (built-in PHP server works for development)

## Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Configure credentials
cp .env.example .env
# Edit .env with your ElasticPress.io credentials

# 3. Create index and import data
php bin/setup.php
php bin/index.php

# 4. Set up search template
php bin/setup-template.php

# 5. Start web server
php -S localhost:8000 -t public
```

Open http://localhost:8000 in your browser.

## Detailed Setup Guide

### Step 1: Configure Credentials

Edit `.env` with your ElasticPress.io credentials:

```env
ELASTICPRESS_HOST=https://your-endpoint.clients.hosted-elasticpress.io
ELASTICPRESS_SUBSCRIPTION_ID=your-subscription-id
ELASTICPRESS_SUBSCRIPTION_TOKEN=your-subscription-token
```

**Key Difference from Standard Elasticsearch:**
- Uses Subscription ID and Token instead of username/password
- Index names must be prefixed with your subscription ID (e.g., `subscription-id-index-name`)
- This is handled automatically by the `Config` class

### Step 2: Create the Index

```bash
php bin/setup.php
```

**What this does:**
1. Validates your ElasticPress.io credentials
2. Creates an index named `{subscription-id}-laureates`
3. Applies field mappings for Nobel Prize data (see `src/Mapping/NobelPrizeMapping.php`)

**Key Differences from Standard Elasticsearch:**
- Index name format must follow `{subscription-id}-{your-index-name}` pattern
- Uses HTTP Basic Auth with Subscription ID:Token
- Standard Elasticsearch index creation API works the same way otherwise

### Step 3: Index the Data

```bash
php bin/index.php
```

**What this does:**
1. Fetches data from [Nobel Prize API v2.1](https://www.nobelprize.org/about/developer-zone-2/)
2. Processes both laureates and prizes endpoints with pagination
3. Transforms and normalizes the data (handles multilingual fields, nested structures)
4. Bulk indexes ~1,000+ documents to ElasticPress.io
5. Displays statistics: total, successful, failed

**Key Differences from Standard Elasticsearch:**
- **CRITICAL:** ElasticPress.io does NOT allow the `_index` field in bulk operation metadata
- Standard Elasticsearch bulk format includes `{"index": {"_index": "name", "_id": "<unique-id>"}}`
- ElasticPress.io format: `{"index": {"_id": "<unique-id>"}}` (no `_index` field)
- The index name is specified in the URL path instead
- See `src/Index/BulkIndexer.php` for implementation

**Data Structure:**
Each laureate document includes:
- Personal info: name, gender, birth/death details
- Prize info: category, year, motivation, share
- Affiliations: institutions with locations (nested field)
- Unique ID format: `{laureate-id}-{year}-{category}`

### Step 4: Set Up Search Template

```bash
php bin/setup-template.php
```

**What this does:**
1. Creates a search template on ElasticPress.io using the [Post Search API](https://www.elasticpress.io/resources/articles/instant-results-post-search-api/)
2. Template uses `{{ep_placeholder}}` for query parameter substitution
3. Configures multiple search strategies:
   - `match_phrase_prefix` for autocomplete on fullname and firstname
   - `match` with `fuzziness: auto` for typo tolerance
   - `match` on motivation text
   - `nested` query for affiliation names

**Understanding ElasticPress.io Search Templates:**

Search templates are server-side query definitions that enable secure, unauthenticated access to your Elasticsearch data from frontend applications. Here's how it works:

1. **Create Template (authenticated):** You define a query structure with placeholders and store it on ElasticPress.io
   - Endpoint: `PUT /api/v1/search/posts/{index}/template`
   - Requires your Subscription ID and Token
   - Template contains your search logic with `{{ep_placeholder}}` for user input

2. **Frontend Usage (no authentication):** Your JavaScript can call the template directly
   - Endpoint: `GET /api/v1/search/posts/{index}?search={search term}`
   - No credentials required
   - User input is safely inserted into the template's placeholders
   - Fast response times (direct connection, no PHP proxy)

**Benefits:**
- **Security:** Search logic is controlled server-side; users can't modify queries
- **Performance:** Direct client-to-ElasticPress.io connection (no backend proxy needed)
- **Simplicity:** No need to expose or manage API credentials in frontend code
- **Rate Limiting:** ElasticPress.io handles abuse prevention automatically

**Key Differences from Standard Elasticsearch:**
- **CRITICAL:** The `/api/v1/search/posts/{index}` endpoint has strict parameter validation
- Template endpoint: `PUT /api/v1/search/posts/{index}/template`
- Frontend calls: `POST /api/v1/search/posts/{index}` (no authentication required!)
- Read more: [ElasticPress.io Post Search API Documentation](https://www.elasticpress.io/resources/articles/instant-results-post-search-api/)

### Step 5: Test the Web Interface

```bash
php -S localhost:8000 -t public
```

**Features:**
- **Autosuggest/Typeahead:** Real-time suggestions as you type (calls ElasticPress.io directly)
  - Shows matching field context (motivation, affiliation) when not a name match
- **Full-text search:** Searches across names, motivations, affiliations
- **Faceted filtering:** Interactive checkboxes for category and gender
- **Year range filters:** From/To inputs with validation
- **Detail views:** Click any result to see complete laureate information
- **Responsive design:** Works on desktop and mobile

## Project Structure

```
.
├── bin/                           # CLI scripts
│   ├── setup.php                  # Create index with mappings
│   ├── index.php                  # Fetch and index Nobel Prize data
│   ├── search.php                 # Command-line search
│   ├── setup-template.php         # Configure search template
│   └── manage-templates.php       # List/view/delete templates
├── public/                        # Web application
│   ├── index.php                  # Main UI (auto-configured from .env)
│   ├── api.php                    # REST API endpoint
│   └── search-api-template.php    # Serves template as JSON for frontend
├── src/
│   ├── Client/
│   │   └── ElasticsearchClient.php      # HTTP client with Basic Auth
│   ├── Config/
│   │   └── Config.php                    # Manages credentials and index prefix
│   ├── Data/
│   │   ├── NobelDataFetcher.php          # Fetches from Nobel Prize API
│   │   └── NobelDataTransformer.php      # Normalizes and structures data
│   ├── Index/
│   │   ├── IndexManager.php              # Create/delete/list indexes
│   │   └── BulkIndexer.php               # Bulk operations (NDJSON format)
│   ├── Mapping/
│   │   └── NobelPrizeMapping.php         # Field type definitions
│   └── Search/
│       ├── SearchService.php             # Query building and execution
│       └── SearchTemplateManager.php     # Template CRUD operations
├── .env.example                   # Environment variables template
├── composer.json                  # PHP dependencies
└── README.md                      # This file
```

## Key ElasticPress.io Differences

### Index Naming
**Standard Elasticsearch:**
```
my-index
my-other-index
```

**ElasticPress.io:**
```
subscription-id-my-index
subscription-id-my-other-index
```
The subscription ID prefix is mandatory and automatically added by `Config::getIndexPrefix()`.

### Bulk Indexing
**Standard Elasticsearch:**
```json
{"index": {"_index": "my-index", "_id": "123"}}
{"field": "value"}
```

**ElasticPress.io:**
```json
{"index": {"_id": "123"}}
{"field": "value"}
```
The `_index` field is disallowed; specify index in URL: `POST /{index}/_bulk`

### Authentication
**Standard Elasticsearch:**
- API keys, or
- Username/password, or
- No auth (local dev)

**ElasticPress.io:**
- HTTP Basic Auth with Subscription ID as username, Token as password
- Required for all requests except template-based searches

### Search Templates
**Standard Elasticsearch:**
- Stored scripts or search templates
- Full query DSL available

**ElasticPress.io:**
- Template API: `PUT /api/v1/search/posts/{index}/template`
- Uses `{{ep_placeholder}}` syntax for parameter substitution
- `/api/v1/search/posts/{index}` endpoint has stricter parameter validation
- No authentication required for template-based searches
- Enables secure, public-facing autocomplete functionality

## API Reference

### REST API Endpoint

**Search Documents:**
```
GET /api.php?q=einstein&category=physics&year_from=2000
```

Query Parameters:
- `q` - Search query string
- `category` - Filter by prize category
- `gender` - Filter by gender
- `year_from` - Filter by minimum year
- `year_to` - Filter by maximum year
- `page` - Page number (default: 1)
- `per_page` - Results per page (default: 20, max: 100)

**Get Document Details:**
```
GET /api.php?id={document-id}
```

Returns complete laureate information including affiliations and all available fields.

## Command-Line Usage

### Search
```bash
# Simple search
php bin/search.php "einstein"

# With filters
php bin/search.php "physics" --category=physics
php bin/search.php --category=chemistry --year-from=2000
php bin/search.php "marie" --gender=female
```

### Manage Templates
```bash
# List all templates
php bin/manage-templates.php list

# View specific template
php bin/manage-templates.php view {index-name}

# Delete template
php bin/manage-templates.php delete {index-name}
```

## Troubleshooting

### "explicit index in bulk is not allowed"
ElasticPress.io doesn't accept `_index` in bulk operation metadata.

**Solution:** Specify the index in the URL and omit `_index` from the action metadata.

### Connection errors
- Verify credentials in `.env`
- Ensure host URL starts with `https://`
- Check subscription is active
- Test with: `curl -u "subscription-id:token" https://your-host/`

### No search results
- Confirm indexing completed: `php bin/index.php` should show "successful" count
- Check index exists: `php bin/manage-templates.php list`
- Try search without filters first
- Verify document structure matches mapping

### Autosuggest/typeahead not working
- Ensure template was created: `php bin/setup-template.php`
- Check browser console for errors
- Verify API endpoint URL in page source
- Test template endpoint directly in browser dev tools

## Credits and Resources

- **Data Source:** [Nobel Prize API v2.1](https://www.nobelprize.org/about/developer-zone-2/) by Nobel Prize Outreach
- **Service:** [ElasticPress.io](https://www.elasticpress.io/) - Managed Elasticsearch service
- **Documentation:**
  - [ElasticPress.io Developer Documentation](https://www.elasticpress.io/resources/section/developer-documentation/)
  - [ElasticPress.io Resources](https://www.elasticpress.io/resources/articles/)
  - [ElasticPress.io Post Search API](https://www.elasticpress.io/resources/articles/instant-results-post-search-api/)
  - [Elasticsearch Query DSL](https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl.html)
  - [Elasticsearch Bulk API](https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-bulk.html)

## License

MIT

## Support Level

**Provided as-is:** This sample project is provided as-is and we do not provide support for this project. It is merely sample code to explain how to interact with ElasticPress. If you need engineering consulting don't hesitate to ask about [ElasticPress.io Consulting](https://www.elasticpress.io/elasticpress-consulting/)

## Like what you see?

<p align="center">
<a href="https://10up.com/contact/"><img src="https://10up.com/uploads/2016/10/10up-Github-Banner.png" width="850"></a>
</p>
