<?php
/**
 * Batch prospect search — runs one business type across many locations
 * and (optionally) multiple sources in one long-running process, saving
 * everything through the same dedup-aware ProspectStore used by the web
 * search page. Built as a CLI script, not a web page: getting into the
 * thousands means potentially hundreds of API calls with deliberate
 * pacing between them (especially for OSM's shared public Overpass
 * instance, which has a 2-concurrent-request quota per IP and will
 * 429 if hammered) — that's tens of minutes to hours of real wall-clock
 * time, far past what a browser request or Apache would tolerate.
 *
 * Usage:
 *   php batch_search.php "<business type>" <locations-file> [options]
 *
 * locations-file: one location per line (city, state, or country each);
 *   blank lines and lines starting with # are ignored.
 *
 * Options:
 *   --sources=osm,places,foursquare   (default: all sources with a configured key; osm is always available)
 *   --max-results=50                  (per location per source, 1-50)
 *   --db=leadgen                      (override target database, e.g. leadgen_test for a dry run)
 *   --osm-pause=15                    (seconds between OSM calls, default 15 — do not lower this casually)
 *   --pause=2                         (seconds between Places/Foursquare calls, default 2)
 *   --force                           (re-search combos already covered, ignoring the search log)
 *   --refresh-after-days=N            (treat a combo as due for a re-search after N days; default: never auto-refresh)
 *
 * Every (category, location, source) combination this tool searches is
 * logged. By default, a combo already logged is skipped entirely — no
 * source gives more results for the same combo on a later request, so
 * re-running it just burns an API call for zero new leads. To actually
 * get more leads on a later run, add new locations (neighboring suburbs,
 * a wider region) or a new category phrasing to the same locations file
 * and run again — already-covered combos skip instantly, new ones get
 * searched. Use --force to deliberately re-check a combo anyway (e.g. you
 * suspect new listings appeared), or --refresh-after-days to auto-expire
 * old entries.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("batch_search.php is a command-line tool, run it as: php batch_search.php \"category\" locations.txt\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/PlacesClient.php';
require_once __DIR__ . '/includes/OverpassClient.php';
require_once __DIR__ . '/includes/FoursquareClient.php';
require_once __DIR__ . '/includes/ProspectStore.php';
require_once __DIR__ . '/includes/SearchLog.php';

function arg_option(array $argv, string $name, $default) {
    foreach ($argv as $arg) {
        if (strpos($arg, "--$name=") === 0) {
            return substr($arg, strlen("--$name="));
        }
    }
    return $default;
}

$positional = array_values(array_filter($argv, fn($a, $i) => $i > 0 && strpos($a, '--') !== 0, ARRAY_FILTER_USE_BOTH));

if (count($positional) < 2) {
    fwrite(STDERR, "Usage: php batch_search.php \"<business type>\" <locations-file> [--sources=osm,places,foursquare] [--max-results=50] [--db=leadgen] [--osm-pause=15] [--pause=2] [--force] [--refresh-after-days=N]\n");
    exit(1);
}

$category = $positional[0];
$locationsFile = $positional[1];

if (!file_exists($locationsFile)) {
    fwrite(STDERR, "Locations file not found: $locationsFile\n");
    exit(1);
}

$locations = array_filter(
    array_map('trim', file($locationsFile)),
    fn($line) => $line !== '' && $line[0] !== '#'
);

$maxResults = max(1, min(50, (int) arg_option($argv, 'max-results', 50)));
$dbName = arg_option($argv, 'db', 'leadgen');
$osmPause = max(5, (int) arg_option($argv, 'osm-pause', 15));
$apiPause = max(0, (int) arg_option($argv, 'pause', 2));
$force = in_array('--force', $argv, true);
$refreshAfterDaysRaw = arg_option($argv, 'refresh-after-days', null);
$refreshAfterDays = $refreshAfterDaysRaw !== null ? (int) $refreshAfterDaysRaw : null;

$requestedSources = arg_option($argv, 'sources', null);
if ($requestedSources !== null) {
    $sources = array_filter(array_map('trim', explode(',', $requestedSources)));
} else {
    $sources = ['osm'];
    if (has_google_api_key()) {
        $sources[] = 'places';
    }
    if (has_foursquare_api_key()) {
        $sources[] = 'foursquare';
    }
}

$c = app_config();
$pdo = new PDO(
    "mysql:host={$c['db_host']};port={$c['db_port']};dbname=$dbName;charset=utf8mb4",
    $c['db_user'],
    $c['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

printf(
    "Batch search: category=\"%s\", %d locations, sources=[%s], max %d per (location, source), db=%s\n\n",
    $category, count($locations), implode(', ', $sources), $maxResults, $dbName
);

$totals = ['new' => 0, 'merged' => 0, 'duplicate' => 0, 'errors' => 0];
$skipped = 0;
$startTime = time();

foreach ($locations as $location) {
    foreach ($sources as $source) {
        $label = "[$location / $source]";

        if (!$force && SearchLog::isFresh($pdo, $category, $location, $source, $refreshAfterDays)) {
            $prior = SearchLog::find($pdo, $category, $location, $source);
            printf("%s already searched %s (%d results, %d were new at the time) — skipped\n",
                $label, $prior['searched_at'], $prior['result_count'], $prior['new_count']);
            $skipped++;
            continue;
        }

        try {
            if ($source === 'places') {
                $client = new PlacesClient();
                $pageToken = null;
                $callResults = [];
                do {
                    $page = $client->textSearch("$category in $location", $maxResults, $pageToken);
                    $callResults = array_merge($callResults, $page);
                    $pageToken = $client->getNextPageToken();
                    if ($pageToken) {
                        sleep($apiPause);
                    }
                } while ($pageToken && count($callResults) < 60);
            } elseif ($source === 'foursquare') {
                $client = new FoursquareClient();
                $callResults = $client->textSearch($category, $location, $maxResults);
            } elseif ($source === 'osm') {
                $client = new OverpassClient();
                $callResults = $client->textSearch($category, $location, $maxResults);
            } else {
                fwrite(STDERR, "$label unknown source, skipping\n");
                continue;
            }

            $counts = ['new' => 0, 'merged' => 0, 'duplicate' => 0];
            foreach ($callResults as $r) {
                $result = ProspectStore::save($pdo, $r, $category, $location, $source);
                $counts[$result['outcome']]++;
                $totals[$result['outcome']]++;
            }
            printf(
                "%s %d results -> %d new, %d merged, %d duplicate\n",
                $label, count($callResults), $counts['new'], $counts['merged'], $counts['duplicate']
            );
            // Only logged on success — a failed call (e.g. OSM rate-limited)
            // leaves this combo eligible for retry on the next run.
            SearchLog::record($pdo, $category, $location, $source, count($callResults), $counts['new']);
        } catch (Throwable $e) {
            fwrite(STDERR, "$label FAILED: {$e->getMessage()}\n");
            $totals['errors']++;
        }

        sleep($source === 'osm' ? $osmPause : $apiPause);
    }
}

$elapsed = time() - $startTime;
printf(
    "\nDone in %dm%ds. New: %d, merged (cross-source dedup): %d, duplicate: %d, errors: %d, skipped (already covered): %d.\n",
    intdiv($elapsed, 60), $elapsed % 60,
    $totals['new'], $totals['merged'], $totals['duplicate'], $totals['errors'], $skipped
);
