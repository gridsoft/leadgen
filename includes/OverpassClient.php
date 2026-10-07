<?php

/**
 * Free prospect discovery via OpenStreetMap: Nominatim for geocoding the
 * city into a bounding box, Overpass API for querying businesses inside
 * it. No API key, no billing account, no card — but coverage for small
 * local businesses is noticeably thinner than Google Places, and phone/
 * website tags are often missing entirely.
 *
 * Public instances of both services are shared infrastructure with usage
 * policies (Nominatim in particular: max ~1 request/second, a real
 * identifying User-Agent). This client makes one geocode call per search,
 * which is well within that.
 */
class OverpassClient {
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';
    private const USER_AGENT = 'LeadgenProspectTool/1.0 (local lead-generation tool)';

    // Common local-service categories mapped to their OSM tag. Not
    // exhaustive — anything unmapped still works via the name-text search
    // below, just with lower precision.
    private const CATEGORY_TAGS = [
        'dentist' => 'amenity=dentist',
        'dentists' => 'amenity=dentist',
        'dental' => 'amenity=dentist',
        'doctor' => 'amenity=doctors',
        'doctors' => 'amenity=doctors',
        'clinic' => 'amenity=clinic',
        'veterinarian' => 'amenity=veterinary',
        'vet' => 'amenity=veterinary',
        'restaurant' => 'amenity=restaurant',
        'cafe' => 'amenity=cafe',
        'coffee shop' => 'amenity=cafe',
        'bar' => 'amenity=bar',
        'gym' => 'leisure=fitness_centre',
        'fitness' => 'leisure=fitness_centre',
        'salon' => 'shop=hairdresser',
        'hair salon' => 'shop=hairdresser',
        'barber' => 'shop=hairdresser',
        'spa' => 'shop=beauty',
        'lawyer' => 'office=lawyer',
        'law firm' => 'office=lawyer',
        'attorney' => 'office=lawyer',
        'accountant' => 'office=accountant',
        'plumber' => 'craft=plumber',
        'electrician' => 'craft=electrician',
        'real estate' => 'office=estate_agent',
        'realtor' => 'office=estate_agent',
        'hotel' => 'tourism=hotel',
        'bakery' => 'shop=bakery',
        'florist' => 'shop=florist',
        'auto repair' => 'shop=car_repair',
        'mechanic' => 'shop=car_repair',
        'chiropractor' => 'healthcare=chiropractor',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function textSearch(string $category, string $location, int $maxResults = 15): array {
        $bbox = $this->geocode($location);
        if ($bbox === null) {
            throw new RuntimeException("Couldn't find \"$location\" via OpenStreetMap.");
        }

        $ql = $this->buildQuery($category, $bbox, $maxResults);

        $ch = curl_init(self::OVERPASS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['data' => $ql]),
            CURLOPT_USERAGENT => self::USER_AGENT,
            // A whole state/country bbox is far more expensive for Overpass to
            // evaluate than a single city, and more likely to hit the public
            // instance's fair-use limits. Give it real room before giving up.
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Overpass API request failed: $curlError");
        }
        if ($httpCode === 429) {
            throw new RuntimeException(
                'OpenStreetMap rate-limited this connection (too many requests to the shared public ' .
                'Overpass server recently). This clears on its own — wait about a minute and try again.'
            );
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("Overpass API error ($httpCode) — the public instance may be busy, try again shortly.");
        }

        $data = json_decode($response, true);
        $elements = $data['elements'] ?? [];

        $seen = [];
        $results = [];
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? null;
            if ($name === null || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $results[] = [
                // Prefixed so it never collides with a real Google place_id in the same column.
                'place_id' => 'osm:' . ($el['type'] ?? 'node') . ':' . ($el['id'] ?? uniqid()),
                'name' => $name,
                'address' => $this->buildAddress($tags),
                // The business's actual city from its address tags, not the
                // (possibly much broader) area the search covered.
                'city' => $tags['addr:city'] ?? null,
                'website' => $tags['contact:website'] ?? $tags['website'] ?? null,
                'phone' => $tags['contact:phone'] ?? $tags['phone'] ?? null,
                'email' => $tags['contact:email'] ?? $tags['email'] ?? null,
                'review_count' => null,
            ];

            if (count($results) >= $maxResults) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}|null south, west, north, east
     */
    private function geocode(string $location): ?array {
        $url = self::NOMINATIM_URL . '?' . http_build_query([
            'q' => $location,
            'format' => 'json',
            'limit' => 1,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        $bb = $data[0]['boundingbox'] ?? null; // [south, north, west, east] as strings
        if ($bb === null || count($bb) !== 4) {
            return null;
        }

        return [(float) $bb[0], (float) $bb[2], (float) $bb[1], (float) $bb[3]]; // south, west, north, east
    }

    private function buildQuery(string $category, array $bbox, int $maxResults): string {
        [$south, $west, $north, $east] = $bbox;
        $bboxStr = "$south,$west,$north,$east";

        $categoryKey = strtolower(trim($category));
        $tagFilter = self::CATEGORY_TAGS[$categoryKey] ?? null;

        // Escape for safe embedding inside a double-quoted Overpass QL regex literal.
        $safeCategory = addcslashes($category, '"\\');
        $limit = max(1, min($maxResults, 50)) * 2; // over-fetch a bit; duplicates/no-name entries get filtered

        $clauses = "  node[\"name\"~\"$safeCategory\",i]($bboxStr);\n  way[\"name\"~\"$safeCategory\",i]($bboxStr);\n";

        if ($tagFilter !== null) {
            [$key, $value] = explode('=', $tagFilter, 2);
            $key = addcslashes($key, '"\\');
            $value = addcslashes($value, '"\\');
            $clauses .= "  node[\"$key\"=\"$value\"][\"name\"]($bboxStr);\n";
            $clauses .= "  way[\"$key\"=\"$value\"][\"name\"]($bboxStr);\n";
        }

        return "[out:json][timeout:75];\n(\n$clauses);\nout center $limit;";
    }

    private function buildAddress(array $tags): string {
        $parts = array_filter([
            $tags['addr:housenumber'] ?? null,
            $tags['addr:street'] ?? null,
        ]);
        $line = implode(' ', $parts);
        $cityPart = $tags['addr:city'] ?? null;
        $full = trim(implode(', ', array_filter([$line, $cityPart])));
        return $full !== '' ? $full : ($tags['addr:full'] ?? '');
    }
}
