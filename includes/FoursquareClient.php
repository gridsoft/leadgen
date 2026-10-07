<?php
require_once __DIR__ . '/../config.php';

/**
 * Foursquare Places API search — free tier is 10,000 calls on "Pro"
 * endpoints (which this search endpoint is), no explicit trial expiry
 * as of writing. Unlike Overpass, Foursquare geocodes the location
 * itself (the `near` parameter takes a plain place name), so this
 * client makes exactly one HTTP request per search.
 */
class FoursquareClient {
    private const SEARCH_URL = 'https://places-api.foursquare.com/places/search';
    private const API_VERSION = '2025-06-17';

    private string $apiKey;

    public function __construct() {
        if (!has_foursquare_api_key()) {
            throw new RuntimeException(
                'Foursquare API key not configured. Copy config.local.php.example to config.local.php ' .
                'and add a Foursquare service key.'
            );
        }
        $this->apiKey = app_config()['foursquare_api_key'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function textSearch(string $category, string $location, int $maxResults = 15): array {
        $limit = max(1, min($maxResults, 50));

        $url = self::SEARCH_URL . '?' . http_build_query([
            'query' => $category,
            'near' => $location,
            'limit' => $limit,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'X-Places-Api-Version: ' . self::API_VERSION,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Foursquare API request failed: $curlError");
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200) {
            $msg = $data['message'] ?? $response;
            throw new RuntimeException("Foursquare API error ($httpCode): $msg");
        }

        $results = [];
        foreach ($data['results'] ?? [] as $place) {
            $loc = $place['location'] ?? [];
            $results[] = [
                'place_id' => 'foursquare:' . ($place['fsq_place_id'] ?? uniqid()),
                'name' => $place['name'] ?? '',
                'address' => $loc['formatted_address'] ?? ($loc['address'] ?? ''),
                'city' => $loc['locality'] ?? null,
                'website' => $place['website'] ?? null,
                'phone' => $place['tel'] ?? null,
                'email' => $place['email'] ?? null,
                'review_count' => null,
            ];
        }
        return $results;
    }
}
