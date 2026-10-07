<?php
require_once __DIR__ . '/ContactStatus.php';

/**
 * Single write path for saving a search result as a prospect, shared by
 * the interactive search page and the batch search CLI tool. Dedupes two
 * ways: by the source's own place ID (same business found twice from the
 * same provider) and by normalized website domain (same business found
 * via two different providers with two different place IDs — the case
 * plain place-ID dedup can't catch).
 */
class ProspectStore {
    /**
     * @param array<string, mixed> $result Shape produced by *Client::textSearch(): name, website, phone, email, address, city, place_id, review_count.
     * @param string $source Which client produced this result: 'osm', 'places', 'foursquare', 'clutch', or 'manual'.
     * @return array{outcome: string, id: int, contact_status: string} outcome is 'new', 'merged', or 'duplicate'.
     */
    public static function save(PDO $pdo, array $result, string $category, string $location, string $source): array {
        $website = $result['website'] ?: null;
        $phone = $result['phone'] ?: null;
        $email = ($result['email'] ?? null) ?: null;
        $city = ($result['city'] ?? null) ?: $location;
        $address = $result['address'] ?? null;
        $reviewCount = $result['review_count'] ?? null;
        $placeId = $result['place_id'] ?? null;
        $domain = self::normalizeDomain($website);

        if ($domain !== null) {
            $existing = $pdo->prepare('SELECT * FROM prospects WHERE website_domain = :domain LIMIT 1');
            $existing->execute(['domain' => $domain]);
            $row = $existing->fetch();

            if ($row) {
                $mergedPhone = $row['phone'] ?: $phone;
                $mergedEmail = $row['contact_email'] ?: $email;
                $mergedCategory = $row['category'] ?: $category;
                $mergedCity = $row['city'] ?: $city;
                $mergedAddress = $row['address'] ?: $address;
                $mergedReviewCount = $row['review_count'] ?? $reviewCount;
                $mergedSource = self::addSource($row['source'] ?? null, $source);
                $status = ContactStatus::compute($website ?: $row['website'], $mergedPhone, $mergedEmail);

                $pdo->prepare(
                    'UPDATE prospects SET phone = :phone, contact_email = :email, category = :category,
                        city = :city, address = :address, review_count = :review_count, source = :source,
                        contact_status = :status
                     WHERE id = :id'
                )->execute([
                    'phone' => $mergedPhone,
                    'email' => $mergedEmail,
                    'category' => $mergedCategory,
                    'city' => $mergedCity,
                    'address' => $mergedAddress,
                    'review_count' => $mergedReviewCount,
                    'source' => $mergedSource,
                    'status' => $status,
                    'id' => $row['id'],
                ]);

                return ['outcome' => 'merged', 'id' => (int) $row['id'], 'contact_status' => $status];
            }
        }

        $status = ContactStatus::compute($website, $phone, $email);
        $insert = $pdo->prepare(
            'INSERT INTO prospects (business_name, category, city, website, website_domain, phone, address, google_place_id, review_count, contact_email, contact_status, source)
             VALUES (:business_name, :category, :city, :website, :website_domain, :phone, :address, :google_place_id, :review_count, :contact_email, :contact_status, :source)
             ON DUPLICATE KEY UPDATE business_name = VALUES(business_name)'
        );
        $insert->execute([
            'business_name' => $result['name'],
            'category' => $category,
            'city' => $city,
            'website' => $website,
            'website_domain' => $domain,
            'phone' => $phone,
            'address' => $address,
            'google_place_id' => $placeId,
            'review_count' => $reviewCount,
            'contact_email' => $email,
            'contact_status' => $status,
            'source' => $source,
        ]);

        if ($insert->rowCount() === 1) {
            return ['outcome' => 'new', 'id' => (int) $pdo->lastInsertId(), 'contact_status' => $status];
        }

        // Duplicate place_id — look up its id (lastInsertId isn't reliable here).
        $lookup = $pdo->prepare('SELECT id FROM prospects WHERE google_place_id = :pid');
        $lookup->execute(['pid' => $placeId]);
        return ['outcome' => 'duplicate', 'id' => (int) ($lookup->fetchColumn() ?: 0), 'contact_status' => $status];
    }

    /**
     * Appends a source to the existing comma-separated list if not already present,
     * so a business found via both OSM and Foursquare shows "osm, foursquare".
     */
    private static function addSource(?string $existing, string $new): string {
        if (!$existing) {
            return $new;
        }
        $parts = array_map('trim', explode(',', $existing));
        if (in_array($new, $parts, true)) {
            return $existing;
        }
        $parts[] = $new;
        return implode(', ', $parts);
    }

    public static function normalizeDomain(?string $url): ?string {
        if (!$url) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            // parse_url needs a scheme to find the host on some inputs.
            $host = parse_url('http://' . $url, PHP_URL_HOST);
        }
        if (!$host) {
            return null;
        }
        $host = strtolower($host);
        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }
}
