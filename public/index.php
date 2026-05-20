<?php
require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchTemplateManager;

try {
    $config = Config::getInstance();
    $config->validate();

    $indexName    = $config->getIndexPrefix() . 'laureates';
    $templateManager = new SearchTemplateManager();
    $searchApiUrl = $templateManager->getPublicSearchUrl($indexName);
} catch (\Exception $e) {
    $searchApiUrl = '';
    error_log('Failed to load ElasticPress.io config: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nobel Prize Search - ElasticPress.io Sample</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: #2c3e50;
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
        }

        header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        header p {
            opacity: 0.9;
        }

        .search-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            position: relative;
        }

        .search-input-wrapper {
            flex: 1;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }

        .search-box input:focus {
            outline: none;
            border-color: #3498db;
        }

        .search-box button {
            padding: 12px 24px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
        }

        .search-box button:hover {
            background: #2980b9;
        }

        .autosuggest-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            max-height: 400px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .autosuggest-dropdown.active {
            display: block;
        }

        .autosuggest-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .autosuggest-item:last-child {
            border-bottom: none;
        }

        .autosuggest-item:hover {
            background: #f5f5f5;
        }

        .autosuggest-item-name {
            font-weight: 500;
            color: #2c3e50;
        }

        .autosuggest-item-meta {
            font-size: 14px;
            color: #666;
        }

        .autosuggest-loading {
            padding: 12px 16px;
            text-align: center;
            color: #666;
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .content {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
        }

        .facets {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: fit-content;
        }

        .facets h3 {
            margin-bottom: 15px;
            color: #2c3e50;
        }

        .facet-group {
            margin-bottom: 20px;
        }

        .facet-group h4 {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }

        .facet-item {
            padding: 6px 0;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .facet-item label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            flex: 1;
            margin: 0;
        }

        .facet-item input[type="checkbox"] {
            cursor: pointer;
            width: 16px;
            height: 16px;
        }

        .facet-item:hover label {
            color: #3498db;
        }

        .facet-label {
            flex: 1;
        }

        .facet-count {
            color: #999;
            margin-left: auto;
        }

        .clear-filters-btn {
            margin-top: 20px;
            padding: 10px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            width: 100%;
            font-weight: 500;
        }

        .clear-filters-btn:hover {
            background: #c0392b;
        }

        .results {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .results-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }

        .result-item {
            padding: 20px;
            border-bottom: 1px solid #eee;
        }

        .result-item:last-child {
            border-bottom: none;
        }

        .result-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .result-meta {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .result-meta span {
            margin-right: 15px;
        }

        .result-motivation {
            color: #555;
            font-style: italic;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            background: #3498db;
            color: white;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .error {
            background: #e74c3c;
            color: white;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .content {
                grid-template-columns: 1fr;
            }

            .facets {
                display: none;
            }
        }

        /* ── Tabs ────────────────────────────────────────────────── */

        .tab-bar {
            display: flex;
            border-bottom: 2px solid #eee;
            margin-bottom: 24px;
            gap: 0;
        }

        .tab {
            padding: 10px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            font-size: 15px;
            font-weight: 500;
            color: #888;
            cursor: pointer;
            transition: color 0.15s, border-color 0.15s;
        }

        .tab:hover { color: #2c3e50; }

        .tab.active {
            color: #2c3e50;
            border-bottom-color: #3498db;
        }

        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* ── Search mode toggle ──────────────────────────────────── */

        .mode-toggle {
            display: flex;
            gap: 6px;
            margin-bottom: 16px;
            align-items: center;
        }

        .mode-toggle label {
            font-size: 13px;
            font-weight: 500;
            color: #555;
            margin-right: 4px;
        }

        .mode-btn {
            padding: 6px 16px;
            border: 2px solid #ddd;
            border-radius: 20px;
            background: white;
            color: #666;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
        }

        .mode-btn:hover { border-color: #3498db; color: #3498db; }

        .mode-btn.active              { border-color: #3498db; background: #3498db; color: white; }
        .mode-btn.active.semantic     { border-color: #8e44ad; background: #8e44ad; }
        .mode-btn.active.hybrid       { border-color: #27ae60; background: #27ae60; }

        /* Mode badge on results */
        .mode-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
            vertical-align: middle;
        }

        .mode-badge.semantic { background: #f3e5f5; color: #6a1b9a; }
        .mode-badge.hybrid   { background: #e8f5e9; color: #1b5e20; }

        /* ── Ask AI tab ──────────────────────────────────────────── */

        .ask-input-row {
            display: flex;
            gap: 10px;
        }

        .ask-input-row input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 15px;
        }

        .ask-input-row input:focus { outline: none; border-color: #8e44ad; }

        .ask-btn {
            padding: 12px 28px;
            background: #8e44ad;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            white-space: nowrap;
        }

        .ask-btn:hover { background: #7d3c98; }
        .ask-btn:disabled { background: #bbb; cursor: not-allowed; }

        .ask-loading {
            color: #8e44ad;
            font-size: 13px;
            font-style: italic;
            margin-top: 10px;
        }

        /* ── Ask AI answer (rendered in results panel) ───────────── */

        .ask-answer-header {
            font-size: 13px;
            color: #888;
            margin-bottom: 16px;
            font-style: italic;
        }

        .ask-answer-header strong { color: #555; font-style: normal; }

        /* Markdown-rendered answer */
        .markdown-content { line-height: 1.75; color: #333; }
        .markdown-content h2 { font-size: 1.2rem; margin: 18px 0 8px; color: #2c3e50; }
        .markdown-content h3 { font-size: 1.05rem; margin: 14px 0 6px; color: #2c3e50; }
        .markdown-content p  { margin: 0 0 12px; }
        .markdown-content ul, .markdown-content ol { margin: 0 0 12px 20px; }
        .markdown-content li { margin-bottom: 6px; }
        .markdown-content strong { color: #2c3e50; }

        /* ── Ask AI sources (shown in left sidebar) ──────────────── */

        .source-card {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }

        .source-card:last-child { border-bottom: none; }

        .source-card-name { font-weight: 600; color: #2c3e50; margin-bottom: 2px; }
        .source-card-meta { color: #888; font-size: 12px; margin-bottom: 4px; }
        .source-card-motivation { color: #555; font-style: italic; font-size: 12px; }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow: auto;
        }

        .modal.active {
            display: block;
        }

        .modal-content {
            background-color: white;
            margin: 50px auto;
            padding: 30px;
            border-radius: 8px;
            max-width: 800px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            position: relative;
        }

        .modal-close {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
        }

        .modal-close:hover {
            color: #000;
        }

        .modal-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
        }

        .modal-section {
            margin-bottom: 20px;
        }

        .modal-section-title {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
        }

        .modal-section-content {
            color: #333;
            line-height: 1.6;
        }

        .modal-affiliations {
            list-style: none;
            padding: 0;
        }

        .modal-affiliations li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .modal-affiliations li:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Nobel Prize Search</h1>
            <p>Powered by ElasticPress.io - Search through Nobel Prize laureates with faceted filters</p>
        </div>
    </header>

    <div class="container">

        <!-- Tab card: Search + Ask AI -->
        <div class="search-container">

            <div class="tab-bar">
                <button class="tab active" id="tab-search" onclick="switchTab('search')">Search</button>
                <button class="tab" id="tab-ask" onclick="switchTab('ask')">Ask AI</button>
            </div>

            <!-- Search tab panel -->
            <div class="tab-panel active" id="panel-search">
                <div class="mode-toggle">
                    <label>Mode:</label>
                    <button class="mode-btn active" id="mode-keyword" onclick="setSearchMode('keyword')">Keyword</button>
                    <button class="mode-btn semantic" id="mode-semantic" onclick="setSearchMode('semantic')">Semantic</button>
                    <button class="mode-btn hybrid" id="mode-hybrid" onclick="setSearchMode('hybrid')">Hybrid</button>
                </div>

                <div class="search-box">
                    <div class="search-input-wrapper">
                        <input type="text" id="searchInput" placeholder="Search by name, motivation, or affiliation..." autocomplete="off" />
                        <div id="autosuggestDropdown" class="autosuggest-dropdown"></div>
                    </div>
                    <button onclick="performSearch()">Search</button>
                </div>

                <div class="filters">
                    <div class="filter-group">
                        <label>Category</label>
                        <select id="categoryFilter" onchange="performSearch()">
                            <option value="">All Categories</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Gender</label>
                        <select id="genderFilter" onchange="performSearch()">
                            <option value="">All Genders</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Year From</label>
                        <input type="number" id="yearFromFilter" placeholder="e.g., 2000" onchange="performSearch()" />
                    </div>
                    <div class="filter-group">
                        <label>Year To</label>
                        <input type="number" id="yearToFilter" placeholder="e.g., 2023" onchange="performSearch()" />
                    </div>
                </div>
            </div>

            <!-- Ask AI tab panel -->
            <div class="tab-panel" id="panel-ask">
                <div class="ask-input-row">
                    <input type="text" id="askInput" placeholder="Ask anything about Nobel Prize history..." autocomplete="off" />
                    <button class="ask-btn" id="askBtn" onclick="performAsk()">Ask</button>
                </div>
                <div class="ask-loading" id="askLoading" style="display:none">Searching the knowledge base and generating answer...</div>
            </div>

        </div><!-- /.search-container -->

        <div class="content">
            <div class="facets" id="facets">
                <h3 id="sidebarTitle">Filters</h3>
                <div id="facetsContent"></div>
            </div>

            <div class="results">
                <div class="results-header">
                    <h2 id="resultsCount">Search Nobel Prize Laureates</h2>
                </div>
                <div id="resultsContent">
                    <div class="loading">Enter a search query or browse all laureates</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeDetailModal()">&times;</span>
            <div id="modalContent"></div>
        </div>
    </div>

    <script>
        // Public ElasticPress.io search API — no authentication required
        const ELASTICPRESS_SEARCH_API_URL = <?php echo json_encode($searchApiUrl); ?>;

        // Generate UUID v4 for request IDs (based on ElasticPress implementation)
        function generateUUID() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }

        // Generate ElasticPress request ID
        function generateRequestId(base = 'epio-sample-') {
            const uuid = generateUUID().replace(/-/g, '');
            return base + uuid;
        }

        let autosuggestTimeout = null;
        let autosuggestTemplate = null;
        const autosuggestDropdown = document.getElementById('autosuggestDropdown');
        const searchInput = document.getElementById('searchInput');

        // Load autosuggest template on page load
        async function loadAutosuggestTemplate() {
            try {
                const response = await fetch('search-api-template.php');
                const data = await response.json();
                if (data.success && data.template) {
                    autosuggestTemplate = data.template;
                    console.log('Autosuggest template loaded successfully');
                } else {
                    console.error('Failed to load autosuggest template:', data.error);
                }
            } catch (error) {
                console.error('Error loading autosuggest template:', error);
            }
        }

        // Load template when page loads
        loadAutosuggestTemplate();

        // Perform search on Enter key
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                hideAutosuggest();
                performSearch();
            }
        });

        // Autosuggest on input
        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.trim();

            // Clear existing timeout
            if (autosuggestTimeout) {
                clearTimeout(autosuggestTimeout);
            }

            if (query.length < 2) {
                hideAutosuggest();
                return;
            }

            // Debounce autosuggest requests
            autosuggestTimeout = setTimeout(() => {
                fetchAutosuggest(query);
            }, 300);
        });

        // Hide autosuggest when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-input-wrapper')) {
                hideAutosuggest();
            }
        });

        // Perform search on page load
        window.addEventListener('load', function() {
            performSearch();
        });

        async function fetchAutosuggest(query) {
            try {
                // Skip if template not loaded yet
                if (!autosuggestTemplate) {
                    console.warn('Autosuggest template not loaded yet');
                    return;
                }

                autosuggestDropdown.innerHTML = '<div class="autosuggest-loading">Loading suggestions...</div>';
                autosuggestDropdown.classList.add('active');

                // Generate request ID for tracking
                const requestId = generateRequestId();

                const response = await fetch(`${ELASTICPRESS_SEARCH_API_URL}?search=${encodeURIComponent(query)}`, {
                    method: 'GET',
                    headers: {
                        'X-ElasticPress-Request-ID': requestId
                    }
                });

                const data = await response.json();

                displayAutosuggest(data, query);
            } catch (error) {
                console.error('Search error:', error);
                hideAutosuggest();
            }
        }

        function displayAutosuggest(data, query) {
            const hits = data.hits?.hits || [];

            if (hits.length === 0) {
                hideAutosuggest();
                return;
            }

            // Remove duplicates by name
            const seen = new Set();
            const uniqueHits = hits.filter(hit => {
                const name = hit._source?.fullname || '';
                if (seen.has(name)) {
                    return false;
                }
                seen.add(name);
                return true;
            });

            let html = '';
            uniqueHits.slice(0, 8).forEach(hit => {
                const source = hit._source;
                const name = escapeHtml(source.fullname || '');
                const category = escapeHtml(source.category || '');
                const year = source.year || '';

                // Determine which field matched
                let matchInfo = '';
                const queryLower = query.toLowerCase();

                if (source.motivation && source.motivation.toLowerCase().includes(queryLower)) {
                    const motivationSnippet = source.motivation.substring(0, 60) + '...';
                    matchInfo = `<div style="font-size: 12px; color: #666; font-style: italic;">Motivation: ${escapeHtml(motivationSnippet)}</div>`;
                } else if (source.affiliations && source.affiliations.length > 0) {
                    const matchingAffiliation = source.affiliations.find(aff =>
                        aff.name && aff.name.toLowerCase().includes(queryLower)
                    );
                    if (matchingAffiliation) {
                        matchInfo = `<div style="font-size: 12px; color: #666;">Affiliation: ${escapeHtml(matchingAffiliation.name)}</div>`;
                    }
                }

                html += `
                    <div class="autosuggest-item" onclick="showDetail('${escapeHtml(hit._id)}', event)">
                        <div class="autosuggest-item-name">${name}</div>
                        <div class="autosuggest-item-meta">${category} ${year}</div>
                        ${matchInfo}
                    </div>
                `;
            });

            autosuggestDropdown.innerHTML = html;
            autosuggestDropdown.classList.add('active');
        }

        function selectSuggestion(name) {
            searchInput.value = name;
            hideAutosuggest();
            performSearch();
        }

        async function showDetail(docId, event) {
            if (event) {
                event.stopPropagation();
            }
            hideAutosuggest();

            try {
                const response = await fetch(`api.php?id=${encodeURIComponent(docId)}`);
                const data = await response.json();

                if (data.success && data.result) {
                    displayDetailModal(data.result);
                } else {
                    alert('Error loading details: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Error loading details: ' + error.message);
            }
        }

        function displayDetailModal(doc) {
            const modal = document.getElementById('detailModal');
            const content = document.getElementById('modalContent');

            let html = `
                <div class="modal-title">${escapeHtml(doc.fullname)}</div>

                <div class="modal-section">
                    <div class="modal-section-title">Prize Information</div>
                    <div class="modal-section-content">
                        <strong>Category:</strong> ${escapeHtml(doc.category)}<br>
                        <strong>Year:</strong> ${doc.year}<br>
                        <strong>Share:</strong> 1/${doc.share}
                    </div>
                </div>

                ${doc.motivation ? `
                <div class="modal-section">
                    <div class="modal-section-title">Motivation</div>
                    <div class="modal-section-content">"${escapeHtml(doc.motivation)}"</div>
                </div>
                ` : ''}

                <div class="modal-section">
                    <div class="modal-section-title">Personal Information</div>
                    <div class="modal-section-content">
                        ${doc.gender ? `<strong>Gender:</strong> ${escapeHtml(doc.gender)}<br>` : ''}
                        ${doc.birth_date ? `<strong>Born:</strong> ${escapeHtml(doc.birth_date)}` : ''}
                        ${doc.birth_city ? ` in ${escapeHtml(doc.birth_city)}, ` : ''}
                        ${doc.birth_country_name ? `${escapeHtml(doc.birth_country_name)}` : ''}
                        ${doc.birth_date || doc.birth_city || doc.birth_country_name ? '<br>' : ''}
                        ${doc.death_date ? `<strong>Died:</strong> ${escapeHtml(doc.death_date)}` : ''}
                        ${doc.death_city ? ` in ${escapeHtml(doc.death_city)}, ` : ''}
                        ${doc.death_country_name ? `${escapeHtml(doc.death_country_name)}` : ''}
                    </div>
                </div>

                ${doc.affiliations && doc.affiliations.length > 0 ? `
                <div class="modal-section">
                    <div class="modal-section-title">Affiliations</div>
                    <ul class="modal-affiliations">
                        ${doc.affiliations.map(aff => `
                            <li>
                                <strong>${escapeHtml(aff.name)}</strong><br>
                                ${aff.city ? `${escapeHtml(aff.city)}, ` : ''}
                                ${aff.country_name ? escapeHtml(aff.country_name) : ''}
                            </li>
                        `).join('')}
                    </ul>
                </div>
                ` : ''}
            `;

            content.innerHTML = html;
            modal.classList.add('active');
        }

        function closeDetailModal() {
            const modal = document.getElementById('detailModal');
            modal.classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('detailModal');
            if (event.target === modal) {
                closeDetailModal();
            }
        }

        function hideAutosuggest() {
            autosuggestDropdown.classList.remove('active');
            autosuggestDropdown.innerHTML = '';
        }

        // ── Tab switching ─────────────────────────────────────────────────────

        let currentTab = 'search';

        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-search').classList.toggle('active', tab === 'search');
            document.getElementById('tab-ask').classList.toggle('active', tab === 'ask');
            document.getElementById('panel-search').classList.toggle('active', tab === 'search');
            document.getElementById('panel-ask').classList.toggle('active', tab === 'ask');

            if (tab === 'search') {
                document.getElementById('sidebarTitle').textContent = 'Filters';
                // Restore search state
                performSearch();
            } else {
                // Clear results area ready for an answer
                document.getElementById('sidebarTitle').textContent = 'Sources';
                document.getElementById('facetsContent').innerHTML =
                    '<p style="color:#999;font-size:13px">Sources used by the AI will appear here after you ask a question.</p>';
                document.getElementById('resultsCount').textContent = 'Ask AI';
                document.getElementById('resultsContent').innerHTML =
                    '<div class="loading">Ask a question above to get a grounded answer from the Nobel Prize database.</div>';
                // Focus the ask input
                setTimeout(() => document.getElementById('askInput').focus(), 50);
            }
        }

        // ── Simple markdown renderer ──────────────────────────────────────────

        function renderMarkdown(text) {
            if (!text) return '';
            let html = escapeHtml(text);

            // Headings
            html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');

            // Bold and italic
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');

            // Numbered lists: collect consecutive `1. item` lines into <ol>
            html = html.replace(/((?:^\d+\. .+\n?)+)/gm, (block) => {
                const items = block.trim().split('\n')
                    .map(l => l.replace(/^\d+\. /, '').trim())
                    .map(l => `<li>${l}</li>`)
                    .join('');
                return `<ol>${items}</ol>`;
            });

            // Unordered lists
            html = html.replace(/((?:^[-*] .+\n?)+)/gm, (block) => {
                const items = block.trim().split('\n')
                    .map(l => l.replace(/^[-*] /, '').trim())
                    .map(l => `<li>${l}</li>`)
                    .join('');
                return `<ul>${items}</ul>`;
            });

            // Paragraphs: double newlines become paragraph breaks
            html = html.replace(/\n\n+/g, '</p><p>');
            html = html.replace(/\n/g, '<br>');
            html = `<p>${html}</p>`;

            // Clean up empty paragraphs and paragraphs around block elements
            html = html.replace(/<p>\s*(<(?:h[23]|ul|ol)>)/g, '$1');
            html = html.replace(/(<\/(?:h[23]|ul|ol)>)\s*<\/p>/g, '$1');
            html = html.replace(/<p>\s*<\/p>/g, '');

            return html;
        }

        // ── Search mode toggle ──────────────────────────────────────────────────

        let currentSearchMode = 'keyword';

        function setSearchMode(mode) {
            currentSearchMode = mode;

            // Update button states
            ['keyword', 'semantic', 'hybrid'].forEach(m => {
                const btn = document.getElementById(`mode-${m}`);
                if (btn) {
                    btn.classList.toggle('active', m === mode);
                }
            });

            performSearch();
        }

        async function performSearch() {
            const query = document.getElementById('searchInput').value;
            const category = document.getElementById('categoryFilter').value;
            const gender = document.getElementById('genderFilter').value;
            const yearFrom = document.getElementById('yearFromFilter').value;
            const yearTo = document.getElementById('yearToFilter').value;

            // Build query string
            const params = new URLSearchParams();
            if (query) params.append('q', query);
            if (category) params.append('category', category);
            if (gender) params.append('gender', gender);
            if (yearFrom) params.append('year_from', yearFrom);
            if (yearTo) params.append('year_to', yearTo);
            params.append('mode', currentSearchMode);

            // Show loading
            document.getElementById('resultsContent').innerHTML = '<div class="loading">Searching...</div>';

            try {
                const response = await fetch(`api.php?${params.toString()}`);
                const data = await response.json();

                if (data.success) {
                    displayResults(data);
                    displayFacets(data.facets);
                } else {
                    document.getElementById('resultsContent').innerHTML =
                        `<div class="error">Error: ${escapeHtml(data.error)}</div>`;
                }
            } catch (error) {
                document.getElementById('resultsContent').innerHTML =
                    `<div class="error">Error: ${escapeHtml(error.message)}</div>`;
            }
        }

        function displayResults(data) {
            const resultsCount = document.getElementById('resultsCount');
            const resultsContent = document.getElementById('resultsContent');

            const mode = data.mode || 'keyword';
            const modeBadge = (mode !== 'keyword')
                ? `<span class="mode-badge ${escapeHtml(mode)}">${escapeHtml(mode)}</span>`
                : '';

            resultsCount.innerHTML = `Found ${data.total} results ${modeBadge}`;

            if (data.results.length === 0) {
                resultsContent.innerHTML = '<div class="loading">No results found</div>';
                return;
            }

            resultsContent.innerHTML = data.results.map(result => `
                <div class="result-item" onclick="showDetail('${escapeHtml(result.id)}', event)" style="cursor: pointer;">
                    <div class="result-name">${escapeHtml(result.fullname)}</div>
                    <div class="result-meta">
                        <span><span class="badge">${escapeHtml(result.category)}</span></span>
                        <span><strong>Year:</strong> ${result.year}</span>
                        ${result.gender ? `<span><strong>Gender:</strong> ${escapeHtml(result.gender)}</span>` : ''}
                        ${result.birth_country_name ? `<span><strong>Birth:</strong> ${escapeHtml(result.birth_country_name)}</span>` : ''}
                    </div>
                    ${result.motivation ? `<div class="result-motivation">"${escapeHtml(result.motivation)}"</div>` : ''}
                </div>
            `).join('');
        }

        // ── Ask AI (RAG) ──────────────────────────────────────────────────────

        // Allow Enter key in Ask input
        document.getElementById('askInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') performAsk();
        });

        async function performAsk() {
            const question = document.getElementById('askInput').value.trim();
            if (!question) return;

            const btn     = document.getElementById('askBtn');
            const loading = document.getElementById('askLoading');

            btn.disabled = true;
            loading.style.display = 'block';
            document.getElementById('resultsCount').textContent = 'Ask AI';
            document.getElementById('resultsContent').innerHTML = '<div class="loading">Thinking...</div>';
            document.getElementById('facetsContent').innerHTML  = '<p style="color:#999;font-size:13px">Searching...</p>';

            try {
                const params = new URLSearchParams({ ask: question });
                const response = await fetch(`api.php?${params.toString()}`);
                const data = await response.json();

                if (data.success) {
                    // ── Answer in main panel ──────────────────────────────
                    document.getElementById('resultsCount').textContent = 'Answer';
                    document.getElementById('resultsContent').innerHTML = `
                        <div class="ask-answer-header">
                            <strong>Q:</strong> ${escapeHtml(question)}
                        </div>
                        <div class="markdown-content">${renderMarkdown(data.answer)}</div>
                    `;

                    // ── Sources in left sidebar ───────────────────────────
                    const sources = data.sources || [];
                    if (sources.length > 0) {
                        // Deduplicate by id
                        const seen = new Set();
                        const unique = sources.filter(s => {
                            const id = s.id || s.fullname;
                            if (!id || seen.has(id)) return false;
                            seen.add(id); return true;
                        });

                        document.getElementById('sidebarTitle').textContent = `Sources (${unique.length})`;
                        document.getElementById('facetsContent').innerHTML = unique.map((s, i) => {
                            const name = escapeHtml(s.fullname || 'Unknown');
                            const cat  = escapeHtml(s.category || '');
                            const year = s.year || '';
                            const mot  = s.motivation
                                ? escapeHtml(s.motivation.substring(0, 90)) + '...'
                                : '';
                            return `<div class="source-card">
                                <div class="source-card-name">${i + 1}. ${name}</div>
                                <div class="source-card-meta">${cat} &middot; ${year}</div>
                                ${mot ? `<div class="source-card-motivation">"${mot}"</div>` : ''}
                            </div>`;
                        }).join('');
                    } else {
                        document.getElementById('sidebarTitle').textContent = 'Sources';
                        document.getElementById('facetsContent').innerHTML =
                            '<p style="color:#999;font-size:13px">This query used aggregations — no individual documents were retrieved.</p>';
                    }
                } else {
                    document.getElementById('resultsContent').innerHTML =
                        `<div class="error">Error: ${escapeHtml(data.error || 'Unknown error')}</div>`;
                    document.getElementById('facetsContent').innerHTML = '';
                }
            } catch (error) {
                document.getElementById('resultsContent').innerHTML =
                    `<div class="error">Error: ${escapeHtml(error.message)}</div>`;
            } finally {
                btn.disabled = false;
                loading.style.display = 'none';
            }
        }

        function displayFacets(facets) {
            const facetsContent = document.getElementById('facetsContent');

            const currentCategory = document.getElementById('categoryFilter').value;
            const currentGender = document.getElementById('genderFilter').value;

            let html = '';

            // Categories
            if (facets.categories && facets.categories.length > 0) {
                html += '<div class="facet-group"><h4>Categories</h4>';
                facets.categories.forEach(facet => {
                    const isChecked = currentCategory === facet.value ? 'checked' : '';
                    const facetId = `facet-category-${facet.value.replace(/\s+/g, '-')}`;
                    html += `<div class="facet-item">
                        <label for="${facetId}">
                            <input type="checkbox" id="${facetId}" ${isChecked}
                                   onchange="toggleFilter('category', '${escapeHtml(facet.value)}', this.checked)">
                            <span class="facet-label">${escapeHtml(facet.value)}</span>
                            <span class="facet-count">${facet.count}</span>
                        </label>
                    </div>`;
                });
                html += '</div>';
            }

            // Genders
            if (facets.genders && facets.genders.length > 0) {
                html += '<div class="facet-group"><h4>Gender</h4>';
                facets.genders.forEach(facet => {
                    const isChecked = currentGender === facet.value ? 'checked' : '';
                    const facetId = `facet-gender-${facet.value.replace(/\s+/g, '-')}`;
                    html += `<div class="facet-item">
                        <label for="${facetId}">
                            <input type="checkbox" id="${facetId}" ${isChecked}
                                   onchange="toggleFilter('gender', '${escapeHtml(facet.value)}', this.checked)">
                            <span class="facet-label">${escapeHtml(facet.value)}</span>
                            <span class="facet-count">${facet.count}</span>
                        </label>
                    </div>`;
                });
                html += '</div>';
            }

            // Year range
            if (facets.year_range) {
                const currentYearFrom = document.getElementById('yearFromFilter').value;
                const currentYearTo = document.getElementById('yearToFilter').value;

                html += `<div class="facet-group"><h4>Year Range</h4>
                    <div style="font-size: 12px; color: #999; margin-bottom: 10px;">
                        Available: ${facets.year_range.min} - ${facets.year_range.max}
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label style="display: block; font-size: 12px; color: #666; margin-bottom: 4px;">From:</label>
                        <input type="number" id="yearFromFilterSidebar"
                               value="${currentYearFrom}"
                               placeholder="${facets.year_range.min}"
                               min="${facets.year_range.min}"
                               max="${facets.year_range.max}"
                               style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;"
                               onchange="updateYearFilter()">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; color: #666; margin-bottom: 4px;">To:</label>
                        <input type="number" id="yearToFilterSidebar"
                               value="${currentYearTo}"
                               placeholder="${facets.year_range.max}"
                               min="${facets.year_range.min}"
                               max="${facets.year_range.max}"
                               style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;"
                               onchange="updateYearFilter()">
                    </div>
                </div>`;
            }

            // Add Clear Filters button if any filter is active
            const hasActiveFilters = currentCategory || currentGender ||
                                    document.getElementById('yearFromFilter').value ||
                                    document.getElementById('yearToFilter').value;

            if (hasActiveFilters) {
                html += '<button class="clear-filters-btn" onclick="clearAllFilters()">Clear All Filters</button>';
            }

            facetsContent.innerHTML = html;

            // Populate filter dropdowns (keep for backward compatibility)
            if (facets.categories) {
                const categoryFilter = document.getElementById('categoryFilter');
                const currentValue = categoryFilter.value;
                categoryFilter.innerHTML = '<option value="">All Categories</option>' +
                    facets.categories.map(f => `<option value="${f.value}">${escapeHtml(f.value)}</option>`).join('');
                categoryFilter.value = currentValue;
            }

            if (facets.genders) {
                const genderFilter = document.getElementById('genderFilter');
                const currentValue = genderFilter.value;
                genderFilter.innerHTML = '<option value="">All Genders</option>' +
                    facets.genders.map(f => `<option value="${f.value}">${escapeHtml(f.value)}</option>`).join('');
                genderFilter.value = currentValue;
            }
        }

        function toggleFilter(type, value, checked) {
            if (type === 'category') {
                document.getElementById('categoryFilter').value = checked ? value : '';
            } else if (type === 'gender') {
                document.getElementById('genderFilter').value = checked ? value : '';
            }
            performSearch();
        }

        function updateYearFilter() {
            const yearFromSidebar = document.getElementById('yearFromFilterSidebar');
            const yearToSidebar = document.getElementById('yearToFilterSidebar');
            const yearFromTop = document.getElementById('yearFromFilter');
            const yearToTop = document.getElementById('yearToFilter');

            if (yearFromSidebar && yearFromTop) {
                yearFromTop.value = yearFromSidebar.value;
            }
            if (yearToSidebar && yearToTop) {
                yearToTop.value = yearToSidebar.value;
            }

            performSearch();
        }

        function setFilter(type, value) {
            if (type === 'category') {
                document.getElementById('categoryFilter').value = value;
            } else if (type === 'gender') {
                document.getElementById('genderFilter').value = value;
            }
            performSearch();
        }

        function clearAllFilters() {
            // Clear all filter inputs
            document.getElementById('categoryFilter').value = '';
            document.getElementById('genderFilter').value = '';
            document.getElementById('yearFromFilter').value = '';
            document.getElementById('yearToFilter').value = '';

            // Clear search input
            document.getElementById('searchInput').value = '';

            // Trigger search with no filters
            performSearch();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
