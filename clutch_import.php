<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ClutchParser.php';
require_once __DIR__ . '/includes/ContactStatus.php';
require_once __DIR__ . '/includes/ProspectStore.php';

$error = null;
$warnings = [];
$category = trim($_POST['category'] ?? '');
$location = trim($_POST['location'] ?? '');

$saved = [];
$statusCounts = [];
$newCount = 0;
$duplicateCount = 0;
$pageCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // A POST over post_max_size arrives with $_POST and $_FILES both empty.
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new RuntimeException(
                'The upload was larger than PHP allows in one request (post_max_size = ' . ini_get('post_max_size') .
                '). Upload fewer pages at a time, or raise post_max_size in php.ini.'
            );
        }

        /** @var array<string, string> $pages label => html */
        $pages = [];
        $pasted = trim($_POST['html'] ?? '');
        if ($pasted !== '') {
            $pages['pasted HTML'] = $pasted;
        }

        $files = $_FILES['pages'] ?? null;
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $fileName) {
                $code = $files['error'][$i];
                if ($code === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                    $warnings[] = "$fileName is larger than PHP's upload limit (upload_max_filesize = " . ini_get('upload_max_filesize') .
                        '). Paste its HTML instead, or raise upload_max_filesize in php.ini.';
                    continue;
                }
                if ($code !== UPLOAD_ERR_OK) {
                    $warnings[] = "$fileName failed to upload (error code $code).";
                    continue;
                }
                $pages[$fileName] = (string) file_get_contents($files['tmp_name'][$i]);
            }
        }

        if (!$pages && !$warnings) {
            throw new RuntimeException('Upload at least one saved Clutch page or paste its HTML.');
        }

        $results = [];
        foreach ($pages as $label => $html) {
            if (stripos($html, 'Just a moment...') !== false && stripos($html, 'challenges.cloudflare.com') !== false) {
                $warnings[] = "$label is Cloudflare's challenge page, not the directory. Wait for the listings to load in your browser before saving.";
                continue;
            }
            $parsed = ClutchParser::parse($html);
            if (!$parsed['results']) {
                $warnings[] = "No company listings found in $label. Make sure it's a Clutch directory page (e.g. clutch.co/web-developers/chicago), not a profile page.";
                continue;
            }
            $pageCount++;
            // Fill blank fields from the first page's URL; typed values win.
            $category = $category !== '' ? $category : (string) $parsed['category'];
            $location = $location !== '' ? $location : (string) $parsed['location'];
            foreach ($parsed['results'] as $r) {
                $results[$r['place_id']] = $r;
            }
        }

        if ($results) {
            $pdo = get_db();
            foreach ($results as $r) {
                $saveResult = ProspectStore::save($pdo, $r, $category, $location, 'clutch');

                if ($saveResult['outcome'] === 'new') {
                    $newCount++;
                } else {
                    $duplicateCount++;
                }

                $status = $saveResult['contact_status'];
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                $saved[] = array_merge($r, ['contact_status' => $status, 'outcome' => $saveResult['outcome']]);
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<?php
$pageTitle = 'Import from Clutch';
$activeNav = 'clutch';
require __DIR__ . '/includes/layout_header.php';
?>

<?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php foreach ($warnings as $w): ?><div class="notice"><?= htmlspecialchars($w) ?></div><?php endforeach; ?>

<div class="card">
<h2>Import from Clutch.co</h2>
<p>
  Clutch blocks automated requests, so import works from pages you open yourself:
</p>
<ol>
  <li>Open a Clutch directory page in your browser, e.g. <code>clutch.co/web-developers/chicago</code>.
      Each page lists about 50 firms; use <code>?page=1</code>, <code>?page=2</code>… for more.</li>
  <li>Save it with <strong>Ctrl+S → "Webpage, HTML Only"</strong>, or press <strong>Ctrl+U</strong> and copy the whole source.</li>
  <li>Upload the saved files (several at once is fine) or paste the source below.</li>
</ol>
<form method="post" enctype="multipart/form-data">
  <label for="pages">Saved Clutch pages (.html)</label>
  <input type="file" id="pages" name="pages[]" accept=".html,.htm,text/html" multiple>
  <p class="hint">Up to <?= htmlspecialchars(ini_get('upload_max_filesize')) ?> per file and <?= htmlspecialchars(ini_get('post_max_size')) ?> per import (PHP limits).</p>
  <label for="html">…or paste page source</label>
  <textarea id="html" name="html" rows="6" placeholder="&lt;!DOCTYPE html&gt;…"></textarea>
  <label for="category">Business type (optional; detected from the page URL if blank)</label>
  <input type="text" id="category" name="category" placeholder="e.g. Web Developers" value="<?= htmlspecialchars($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['category'] ?? '') : '') ?>">
  <label for="location">Location (optional; detected from the page URL if blank)</label>
  <input type="text" id="location" name="location" placeholder="e.g. Chicago" value="<?= htmlspecialchars($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['location'] ?? '') : '') ?>">
  <button type="submit">Import &amp; save all firms</button>
</form>
</div>

<?php if ($saved): ?>
<div class="card">
<h2><?= count($saved) ?> firms saved from <?= $pageCount ?> page(s)</h2>
<p><?= $newCount ?> new, <?= $duplicateCount ?> already in your list.
  <span class="muted">· saved as business type "<?= htmlspecialchars($category) ?>", location "<?= htmlspecialchars($location) ?>"</span>
</p>
<p>
<?php foreach ($statusCounts as $status => $count): ?>
  <span class="badge badge-neutral"><?= htmlspecialchars(ContactStatus::label($status)) ?>: <?= $count ?></span>
<?php endforeach; ?>
</p>
<p><a class="btn" href="index.php">View in dashboard</a></p>
<table class="results-table">
<thead><tr><th>Name</th><th>Location</th><th>Website</th><th>Rating</th><th>Reviews</th><th>Rate</th><th>Min. project</th><th>Employees</th><th>Outcome</th></tr></thead>
<tbody>
<?php foreach ($saved as $r): ?>
<tr>
  <td><a href="<?= htmlspecialchars($r['profile_url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($r['name']) ?></a></td>
  <td><?= htmlspecialchars($r['address'] ?: '—') ?></td>
  <td><?= $r['website'] ? htmlspecialchars($r['website']) : '—' ?></td>
  <td><?= htmlspecialchars($r['rating'] ?? '—') ?></td>
  <td><?= $r['review_count'] !== null ? (int) $r['review_count'] : '—' ?></td>
  <td><?= htmlspecialchars($r['hourly_rate'] ?? '—') ?></td>
  <td><?= htmlspecialchars($r['min_project'] ?? '—') ?></td>
  <td><?= htmlspecialchars($r['employees'] ?? '—') ?></td>
  <td><?= $r['outcome'] === 'merged' ? 'merged into existing (same domain)' : $r['outcome'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
