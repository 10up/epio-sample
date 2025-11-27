<?php

declare(strict_types=1);

namespace ElasticPressIO\Sample\Data;

/**
 * Transforms Nobel Prize API v2.1 data into Elasticsearch-ready documents.
 *
 * This class normalizes and enriches the raw API data to match our
 * Elasticsearch mapping, preparing it for indexing.
 *
 * @see https://www.nobelprize.org/about/developer-zone-2/
 */
class NobelDataTransformer
{
    private NobelDataFetcher $fetcher;

    public function __construct(NobelDataFetcher $fetcher)
    {
        $this->fetcher = $fetcher;
    }

    /**
     * Transform laureate data from API v2.1 into Elasticsearch documents.
     *
     * In v2.1, laureate data includes nobelPrizes array directly within each laureate.
     *
     * @param array $laureates Raw laureate data from API v2.1
     * @param array $prizes Raw prize data from API v2.1 (not used in v2.1)
     * @return array Array of transformed documents ready for indexing
     */
    public function transformLaureates(array $laureates, array $prizes): array
    {
        $documents = [];

        foreach ($laureates as $laureate) {
            $laureateId = $laureate['id'] ?? null;
            if (!$laureateId) {
                continue;
            }

            // Get all Nobel Prizes for this laureate (v2.1 embeds prizes in laureate)
            $nobelPrizes = $laureate['nobelPrizes'] ?? [];

            // Create one document per prize
            foreach ($nobelPrizes as $prize) {
                $document = $this->buildLaureateDocument($laureate, $prize);
                if ($document) {
                    $documents[] = $document;
                }
            }
        }

        return $documents;
    }

    /**
     * Build a single laureate document for indexing from v2.1 data.
     *
     * @param array $laureate Laureate data from API v2.1
     * @param array $prize Prize data for this specific award
     * @return array|null Elasticsearch document or null if invalid
     */
    private function buildLaureateDocument(array $laureate, array $prize): ?array
    {
        $awardYear = $prize['awardYear'] ?? null;
        $category = $this->fetcher->getMultilingualText($prize['category'] ?? null);

        if (!$awardYear || !$category) {
            return null;
        }

        // Extract names
        $givenName = $this->fetcher->getMultilingualText($laureate['givenName'] ?? null) ?? '';
        $familyName = $this->fetcher->getMultilingualText($laureate['familyName'] ?? null) ?? '';
        $knownName = $this->fetcher->getMultilingualText($laureate['knownName'] ?? null);
        $fullName = $this->fetcher->getMultilingualText($laureate['fullName'] ?? null);

        // Use knownName or fullName as fallback
        if (!$fullName) {
            $fullName = $knownName ?? trim($givenName . ' ' . $familyName);
        }

        // Parse birth information
        $birth = $laureate['birth'] ?? [];
        $birthDate = $this->normalizeDate($birth['date'] ?? null);
        $birthYear = null;
        if (isset($birth['date'])) {
            $birthYear = (int) substr($birth['date'], 0, 4);
            if ($birthYear == 0) {
                $birthYear = null;
            }
        }

        $birthPlace = $birth['place'] ?? [];
        $birthCity = $this->fetcher->getMultilingualText($birthPlace['city'] ?? null);
        $birthCountry = $this->fetcher->getCountryName($birthPlace['country'] ?? null);

        // Parse death information if exists
        $death = $laureate['death'] ?? [];
        $deathDate = $this->normalizeDate($death['date'] ?? null);
        $deathYear = null;
        if (isset($death['date'])) {
            $deathYear = (int) substr($death['date'], 0, 4);
            if ($deathYear == 0) {
                $deathYear = null;
            }
        }

        $deathPlace = $death['place'] ?? [];
        $deathCity = $this->fetcher->getMultilingualText($deathPlace['city'] ?? null);
        $deathCountry = $this->fetcher->getCountryName($deathPlace['country'] ?? null);

        // Process affiliations
        $affiliations = [];
        $prizeCountries = [];
        if (!empty($prize['affiliations'])) {
            foreach ($prize['affiliations'] as $affiliation) {
                $affiliationName = $this->fetcher->getMultilingualText($affiliation['name'] ?? null);
                $affiliationCity = $this->fetcher->getMultilingualText($affiliation['city'] ?? null);
                $affiliationCountry = $this->fetcher->getCountryName($affiliation['country'] ?? null);

                $affiliations[] = [
                    'name' => $affiliationName,
                    'city' => $affiliationCity,
                    'country' => $affiliationCountry,
                    'country_name' => $affiliationCountry, // Same in v2.1
                ];

                if ($affiliationCountry) {
                    $prizeCountries[] = $affiliationCountry;
                }
            }
        }

        // Extract motivation
        $motivation = $this->fetcher->getMultilingualText($prize['motivation'] ?? null);

        // Parse portion to get share (e.g., "1/3" -> 3)
        $portion = $prize['portion'] ?? '1';
        $share = 1;
        if (strpos($portion, '/') !== false) {
            $parts = explode('/', $portion);
            $share = isset($parts[1]) ? (int) $parts[1] : 1;
        }

        return [
            'id' => $laureate['id'] . '-' . $awardYear . '-' . $category,
            'laureate_id' => $laureate['id'],
            'firstname' => $givenName,
            'surname' => $familyName,
            'fullname' => $fullName,
            'category' => $category,
            'year' => (int) $awardYear,
            'share' => $share,
            'motivation' => $motivation,
            'gender' => $laureate['gender'] ?? null,
            'birth_date' => $birthDate,
            'birth_year' => $birthYear,
            'birth_country' => $birthCountry,
            'birth_country_name' => $birthCountry,
            'birth_city' => $birthCity,
            'death_date' => $deathDate,
            'death_year' => $deathYear,
            'death_country' => $deathCountry,
            'death_country_name' => $deathCountry,
            'death_city' => $deathCity,
            'affiliations' => $affiliations,
            'prize_countries' => array_unique($prizeCountries),
        ];
    }

    /**
     * Normalize date format for Elasticsearch.
     *
     * Converts dates from 'YYYY-MM-DD' or 'YYYY-00-00' format to proper date format,
     * handling missing or partial dates.
     *
     * @param string|null $date Date string
     * @return string|null Normalized date or null
     */
    private function normalizeDate(?string $date): ?string
    {
        if (!$date || $date === '0000-00-00') {
            return null;
        }

        // Handle YYYY-00-00 format (unknown month/day)
        if (preg_match('/^(\d{4})-00-00$/', $date, $matches)) {
            return $matches[1] . '-01-01';
        }

        // If only year is provided
        if (preg_match('/^\d{4}$/', $date)) {
            return $date . '-01-01';
        }

        return $date;
    }

    /**
     * Get summary statistics about the transformed data.
     *
     * @param array $documents Transformed documents
     * @return array Statistics
     */
    public function getStatistics(array $documents): array
    {
        $stats = [
            'total' => count($documents),
            'by_category' => [],
            'by_gender' => [],
            'year_range' => ['min' => null, 'max' => null],
        ];

        foreach ($documents as $doc) {
            // Count by category
            $category = $doc['category'] ?? 'unknown';
            $stats['by_category'][$category] = ($stats['by_category'][$category] ?? 0) + 1;

            // Count by gender
            $gender = $doc['gender'] ?? 'unknown';
            $stats['by_gender'][$gender] = ($stats['by_gender'][$gender] ?? 0) + 1;

            // Track year range
            $year = $doc['year'] ?? null;
            if ($year) {
                if ($stats['year_range']['min'] === null || $year < $stats['year_range']['min']) {
                    $stats['year_range']['min'] = $year;
                }
                if ($stats['year_range']['max'] === null || $year > $stats['year_range']['max']) {
                    $stats['year_range']['max'] = $year;
                }
            }
        }

        return $stats;
    }
}
