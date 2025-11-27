<?php
require_once __DIR__ . '/../vendor/autoload.php';

use ElasticPressIO\Sample\Config\Config;
use ElasticPressIO\Sample\Search\SearchTemplateManager;

try {
    $config = Config::getInstance();
    $config->validate();

    $indexName = $config->getIndexPrefix() . '-laureates';
    $templateManager = new SearchTemplateManager();
    $apiUrl = $templateManager->getPublicSearchUrl($indexName);
    $autosuggestUrl = $templateManager->getAutosuggestUrl($indexName);
} catch (\Exception $e) {
    // Fallback if config fails
    $apiUrl = '';
    $autosuggestUrl = '';
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
        <div class="search-container">
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

        <div class="content">
            <div class="facets" id="facets">
                <h3>Filters</h3>
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
        // Configuration - Automatically set from .env configuration
        // This is the PUBLIC API endpoint that requires NO authentication
        const ELASTICPRESS_API_URL = <?php echo json_encode($apiUrl); ?>;
        const ELASTICPRESS_AUTOSUGGEST_URL = <?php echo json_encode($autosuggestUrl); ?>;

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
                const response = await fetch('autosuggest-template.php');
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

                // Use the template from backend and replace placeholder with actual query
                // Convert template to string, replace placeholder, then parse back to JSON
                const templateStr = JSON.stringify(autosuggestTemplate);
                const replacedStr = templateStr.replace(/\{\{ep_placeholder\}\}/g, query);
                const searchBody = JSON.parse(replacedStr);

                console.log('Autosuggest query:', query);
                console.log('Request body:', searchBody);

                const response = await fetch(`${ELASTICPRESS_AUTOSUGGEST_URL}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-ElasticPress-Request-ID': requestId
                    },
                    body: JSON.stringify(searchBody)
                });

                console.log('Response status:', response.status);
                const data = await response.json();
                console.log('Response data:', data);
                console.log('Autosuggest Request ID:', requestId);

                displayAutosuggest(data, query);
            } catch (error) {
                console.error('Autosuggest error:', error);
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
                        `<div class="error">Error: ${data.error}</div>`;
                }
            } catch (error) {
                document.getElementById('resultsContent').innerHTML =
                    `<div class="error">Error: ${error.message}</div>`;
            }
        }

        function displayResults(data) {
            const resultsCount = document.getElementById('resultsCount');
            const resultsContent = document.getElementById('resultsContent');

            resultsCount.textContent = `Found ${data.total} results`;

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
