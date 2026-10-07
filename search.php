<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/PlacesClient.php';
require_once __DIR__ . '/includes/OverpassClient.php';
require_once __DIR__ . '/includes/FoursquareClient.php';
require_once __DIR__ . '/includes/ContactStatus.php';
require_once __DIR__ . '/includes/ProspectStore.php';

// A state/country-wide OSM search can take a while against the public
// Overpass instance; the default PHP script timeout would kill it first.
set_time_limit(120);

$error = null;
$query = trim($_POST['query'] ?? '');
$location = trim($_POST['location'] ?? '');
$source = $_POST['source'] ?? 'osm';
if (!in_array($source, ['osm', 'places', 'foursquare'], true)) {
    $source = 'osm';
}
$maxResults = (int) ($_POST['max_results'] ?? 25);
if ($maxResults < 1 || $maxResults > 50) {
    $maxResults = 25;
}

// Google Places: split the location into an n×n grid. Each zone is its own
// search with its own 60-result cap, which is how you get past 60 total.
$gridSize = (int) ($_POST['grid'] ?? 1);
if (!in_array($gridSize, [1, 2, 3, 4, 5], true)) {
    $gridSize = 1;
}

$saved = [];
$statusCounts = [];
$newCount = 0;
$duplicateCount = 0;
$placesRequests = 0;
$cappedCells = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $query !== '' && $location !== '') {
    try {
        if ($source === 'places') {
            set_time_limit(0); // a 5×5 grid is up to 76 sequential API calls
            $client = new PlacesClient();
            if ($gridSize === 1) {
                $results = $client->textSearchAllPages("$query in $location");
                $placesRequests = (int) ceil(max(count($results), 1) / 20);
                $cappedCells = count($results) >= 60 ? 1 : 0;
            } else {
                $viewport = $client->geocodeViewport($location);
                $placesRequests = 1;
                if ($viewport === null) {
                    throw new RuntimeException("Google couldn't find the area \"$location\" to split into zones.");
                }
                $results = [];
                foreach (PlacesClient::splitViewport($viewport, $gridSize) as $cell) {
                    $cellResults = $client->textSearchAllPages($query, $cell);
                    $placesRequests += (int) ceil(max(count($cellResults), 1) / 20);
                    if (count($cellResults) >= 60) {
                        $cappedCells++;
                    }
                    // Neighbouring zones share edges — keep each place once.
                    foreach ($cellResults as $r) {
                        $results[$r['place_id'] ?? uniqid()] = $r;
                    }
                }
                $results = array_values($results);
            }
        } elseif ($source === 'foursquare') {
            $client = new FoursquareClient();
            $results = $client->textSearch($query, $location, $maxResults);
        } else {
            $client = new OverpassClient();
            $results = $client->textSearch($query, $location, $maxResults);
        }

        $pdo = get_db();

        foreach ($results as $r) {
            $saveResult = ProspectStore::save($pdo, $r, $query, $location, $source);

            if ($saveResult['outcome'] === 'new') {
                $newCount++;
            } else {
                $duplicateCount++;
            }

            $status = $saveResult['contact_status'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $saved[] = array_merge($r, ['contact_status' => $status, 'outcome' => $saveResult['outcome']]);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<?php
$pageTitle = 'Find prospects';
$activeNav = 'search';
require __DIR__ . '/includes/layout_header.php';
?>

<?php if ($source === 'places' && !has_google_api_key()): ?>
<div class="notice">
  No Google API key configured yet — Places search won't work until you copy
  <code>config.local.php.example</code> to <code>config.local.php</code> and add a key
  (requires a Google Cloud billing account with a card on file). Use the free OpenStreetMap
  source instead, or <a href="add.php">add prospects manually</a> in the meantime.
</div>
<?php endif; ?>

<?php if ($source === 'foursquare' && !has_foursquare_api_key()): ?>
<div class="notice">
  No Foursquare API key configured yet — copy <code>config.local.php.example</code> to
  <code>config.local.php</code> and add a Foursquare service key (10,000 free calls on
  Pro endpoints as of writing — verify current terms at signup). Use the free OpenStreetMap
  source instead, or <a href="add.php">add prospects manually</a> in the meantime.
</div>
<?php endif; ?>

<?php if ($source === 'foursquare' && has_foursquare_api_key()): ?>
<div class="notice">
  Foursquare matches business type loosely against name/category text, not as a strict filter —
  a single vague word (e.g. "cleaning") can return unrelated results. Use a specific two-word
  phrase that fully describes the business (e.g. "cleaning service", not "cleaning" or "house cleaning").
</div>
<?php endif; ?>

<?php if ($source === 'osm'): ?>
<div class="notice">
  OpenStreetMap has no result ranking — a state/country search has to scan every
  matching business in that whole area before applying your result cap, and common
  categories (restaurants, salons) over large regions are likely to time out on the
  shared public server. Narrower categories or smaller areas are more reliable.
</div>
<?php endif; ?>

<?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
<h2>Find prospects</h2>
<form method="post">
  <label>Source</label>
  <p style="margin-top:-.5rem">
    <label style="display:inline;font-weight:normal"><input type="radio" name="source" value="osm" <?= $source === 'osm' ? 'checked' : '' ?> style="width:auto;display:inline"> OpenStreetMap (free, no key)</label>
    &nbsp;&nbsp;
    <label style="display:inline;font-weight:normal"><input type="radio" name="source" value="places" <?= $source === 'places' ? 'checked' : '' ?> style="width:auto;display:inline"> Google Places (needs API key)</label>
    &nbsp;&nbsp;
    <label style="display:inline;font-weight:normal"><input type="radio" name="source" value="foursquare" <?= $source === 'foursquare' ? 'checked' : '' ?> style="width:auto;display:inline"> Foursquare (needs API key, free tier)</label>
  </p>
  <label for="query">Business type</label>
  <input type="text" id="query" name="query" placeholder="e.g. dentists" value="<?= htmlspecialchars($query) ?>" required>
  <label for="location">Location (city, state, or country)</label>
  <input type="text" id="location" name="location" placeholder="e.g. Chicago, Illinois, or USA" value="<?= htmlspecialchars($location) ?>" required>
  <div class="source-places">
    <label for="grid">Area coverage</label>
    <select id="grid" name="grid" class="select">
      <?php foreach ([1 => 'Whole area as one search — up to 60 results, ~3 API calls',
                      2 => 'Split into 2×2 = 4 zones — up to 240 results, ~13 API calls',
                      3 => 'Split into 3×3 = 9 zones — up to 540 results, ~28 API calls',
                      4 => 'Split into 4×4 = 16 zones — up to 960 results, ~49 API calls',
                      5 => 'Split into 5×5 = 25 zones — up to 1,500 results, ~76 API calls'] as $n => $label): ?>
        <option value="<?= $n ?>" <?= $gridSize === $n ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint">
      Google returns at most 60 results per search, so repeating the same search gives the same businesses.
      Splitting the area searches each zone separately. All pages are fetched automatically, and
      zones with few businesses use fewer calls than the estimate.
    </p>
  </div>
  <div class="source-other">
    <label for="max_results">
      Max results per search (up to 50). Foursquare and OpenStreetMap have no pagination, so this
      number is a hard ceiling.
    </label>
    <input type="number" id="max_results" name="max_results" min="1" max="50" value="<?= (int) $maxResults ?>">
  </div>
  <button type="submit" id="searchBtn">Search &amp; save all results</button>
</form>
</div>

<script>
(function() {
  function sync() {
    const places = document.querySelector('input[name=source]:checked').value === 'places';
    document.querySelector('.source-places').style.display = places ? '' : 'none';
    document.querySelector('.source-other').style.display = places ? 'none' : '';
  }
  document.querySelectorAll('input[name=source]').forEach(function(r) { r.addEventListener('change', sync); });
  sync();
  // Split-area searches take a while; make it obvious the page is working.
  document.getElementById('searchBtn').form.addEventListener('submit', function() {
    const btn = document.getElementById('searchBtn');
    btn.disabled = true;
    btn.textContent = 'Searching… split areas can take a minute';
  });
})();
</script>

<?php if ($saved): ?>
<div class="card">
<h2><?= count($saved) ?> results saved</h2>
<p><?= $newCount ?> new, <?= $duplicateCount ?> already in your list.
<?php if ($source === 'places'): ?>
  <span class="muted">· <?= $placesRequests ?> Google API call(s)<?= $gridSize > 1 ? ' across ' . ($gridSize * $gridSize) . ' zones' : '' ?></span>
<?php endif; ?>
</p>
<?php if ($source === 'places' && $cappedCells > 0): ?>
<div class="notice">
  <?= $gridSize === 1 ? 'This search' : "$cappedCells zone(s)" ?> hit Google's 60-result limit, so there are probably more businesses.
  <?php if ($gridSize < 5): ?>Run it again with a finer split; businesses you already have won't be duplicated.
  <?php else: ?>Try a smaller location (e.g. a neighborhood) or different wording for the business type.<?php endif; ?>
</div>
<?php endif; ?>
<p>
<?php foreach ($statusCounts as $status => $count): ?>
  <span class="badge badge-neutral"><?= htmlspecialchars(ContactStatus::label($status)) ?>: <?= $count ?></span>
<?php endforeach; ?>
</p>
<p><a class="btn" href="index.php">View in dashboard</a></p>
<table class="results-table">
<thead><tr><th>Name</th><th>Address</th><th>Website</th><th>Phone</th><th>Email</th><th>Status</th><th>Outcome</th></tr></thead>
<tbody>
<?php foreach ($saved as $r): ?>
<tr>
  <td><?= htmlspecialchars($r['name']) ?></td>
  <td><?= htmlspecialchars($r['address'] ?? '') ?></td>
  <td><?= $r['website'] ? htmlspecialchars($r['website']) : '—' ?></td>
  <td><?= !empty($r['phone']) ? htmlspecialchars($r['phone']) : '—' ?></td>
  <td><?= !empty($r['email'] ?? null) ? htmlspecialchars($r['email']) : '—' ?></td>
  <td><?= htmlspecialchars(ContactStatus::label($r['contact_status'])) ?></td>
  <td><?= $r['outcome'] === 'merged' ? 'merged into existing (same domain)' : $r['outcome'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
