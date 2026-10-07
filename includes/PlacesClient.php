<?php
require_once __DIR__ . '/../config.php';

class PlacesClient {
    private string $apiKey;
    private ?string $nextPageToken = null;

    public function __construct() {
        if (!has_google_api_key()) {
            throw new RuntimeException(
                'Google API key not configured. Copy config.local.php.example to config.local.php ' .
                'and add a key with Places API (New) enabled.'
            );
        }
        $this->apiKey = app_config()['google_api_key'];
    }

    /**
     * Text Search (New) caps each page at 20 results and 60 total across
     * pages. Pass the token from getNextPageToken() as $pageToken to fetch
     * the next page of the *same* query — Google requires every other
     * parameter to stay identical between pages.
     *
     * $rectangle (from geocodeViewport()/splitViewport()) restricts results to
     * that box — the way past the 60 cap is many small boxes, each with its own 60.
     *
     * @return array<int, array<string, mixed>>
     */
    public function textSearch(string $query, int $maxResults = 10, ?string $pageToken = null, ?array $rectangle = null): array {
        $fieldMask = 'places.id,places.displayName,places.formattedAddress,places.websiteUri,'
            . 'places.nationalPhoneNumber,places.userRatingCount,nextPageToken';

        $requestBody = [
            'textQuery' => $query,
            'maxResultCount' => min(max($maxResults, 1), 20),
        ];
        if ($pageToken !== null) {
            $requestBody['pageToken'] = $pageToken;
        }
        if ($rectangle !== null) {
            $requestBody['locationRestriction'] = ['rectangle' => $rectangle];
        }

        $data = $this->post($requestBody, $fieldMask);

        $results = [];
        foreach ($data['places'] ?? [] as $place) {
            $results[] = [
                'place_id' => $place['id'] ?? null,
                'name' => $place['displayName']['text'] ?? '',
                'address' => $place['formattedAddress'] ?? '',
                'website' => $place['websiteUri'] ?? null,
                'phone' => $place['nationalPhoneNumber'] ?? null,
                'review_count' => $place['userRatingCount'] ?? null,
            ];
        }

        $this->nextPageToken = $data['nextPageToken'] ?? null;

        return $results;
    }

    /**
     * Fetches every page for one query (Google stops at 3 pages / 60 results).
     *
     * @return array<int, array<string, mixed>>
     */
    public function textSearchAllPages(string $query, ?array $rectangle = null): array {
        $results = [];
        $pageToken = null;
        do {
            $results = array_merge($results, $this->textSearch($query, 20, $pageToken, $rectangle));
            $pageToken = $this->nextPageToken;
        } while ($pageToken !== null && count($results) < 60);
        return $results;
    }

    /**
     * Bounding box Google uses for a place name like "Chicago, IL", in the
     * rectangle shape locationRestriction expects. Null if nothing matched.
     */
    public function geocodeViewport(string $location): ?array {
        $data = $this->post(['textQuery' => $location, 'pageSize' => 1], 'places.viewport');
        $viewport = $data['places'][0]['viewport'] ?? null;
        if (!isset($viewport['low'], $viewport['high'])) {
            return null;
        }
        return ['low' => $viewport['low'], 'high' => $viewport['high']];
    }

    /**
     * Splits a rectangle into an n×n grid of smaller rectangles.
     *
     * @return array<int, array{low: array, high: array}>
     */
    public static function splitViewport(array $rect, int $n): array {
        $lat0 = $rect['low']['latitude'];
        $lng0 = $rect['low']['longitude'];
        $dLat = ($rect['high']['latitude'] - $lat0) / $n;
        $dLng = ($rect['high']['longitude'] - $lng0) / $n;
        $tiles = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $tiles[] = [
                    'low' => ['latitude' => $lat0 + $i * $dLat, 'longitude' => $lng0 + $j * $dLng],
                    'high' => ['latitude' => $lat0 + ($i + 1) * $dLat, 'longitude' => $lng0 + ($j + 1) * $dLng],
                ];
            }
        }
        return $tiles;
    }

    private function post(array $requestBody, string $fieldMask): array {
        $ch = curl_init('https://places.googleapis.com/v1/places:searchText');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestBody),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $this->apiKey,
                'X-Goog-FieldMask: ' . $fieldMask,
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Places API request failed: $curlError");
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200) {
            $msg = $data['error']['message'] ?? $response;
            throw new RuntimeException("Places API error ($httpCode): $msg");
        }
        return $data ?? [];
    }

    /**
     * Set after textSearch() — non-null when more results exist beyond
     * what was just returned (up to Google's 60-result-total cap).
     */
    public function getNextPageToken(): ?string {
        return $this->nextPageToken;
    }
}
