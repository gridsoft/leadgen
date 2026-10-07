<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/EmailFinder.php';
require_once __DIR__ . '/includes/AiChecker.php';

/**
 * Web front-end for find_emails.php. The browser drives the run one
 * prospect at a time (action=scan), so a few hundred sites never hit a
 * PHP or browser timeout, progress is live, and Stop works mid-run.
 */

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'list') {
        $rows = !empty($_POST['ids']) ? EmailFinder::byIds($pdo, explode(',', $_POST['ids'])) : EmailFinder::candidates(
            $pdo,
            $_POST['category'] ?? null,
            $_POST['city'] ?? null,
            ($_POST['rescan'] ?? '') === '1',
            max(1, min(5000, (int) ($_POST['limit'] ?? 500)))
        );
        $ai = AiChecker::latestFor($pdo, array_column($rows, 'id'));
        echo json_encode(array_map(fn($r) => [
            'id' => (int) $r['id'],
            'name' => $r['business_name'],
            'website' => $r['website'],
            'ai' => $ai[(int) $r['id']] ?? null,
        ], $rows));
        exit;
    }

    // AI checks are queued here and run by ai_worker.php (Claude Code on the
    // user's own login); the page polls ai_status for results.
    if ($action === 'ai_check') {
        $ids = array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
        $jobs = [];
        foreach ($ids as $id) {
            $jobs[$id] = AiChecker::enqueue($pdo, $id, (string) ($_POST['context'] ?? ''));
        }
        // Queuing work starts the worker if it isn't running.
        $start = AiChecker::workerOnline($pdo) ? null : AiChecker::startWorker($pdo);
        echo json_encode(['jobs' => $jobs, 'worker_online' => AiChecker::workerOnline($pdo), 'start' => $start]);
        exit;
    }

    if ($action === 'worker_start') {
        echo json_encode(AiChecker::startWorker($pdo));
        exit;
    }

    if ($action === 'worker_stop') {
        AiChecker::requestStop($pdo);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'ai_status') {
        echo json_encode([
            'jobs' => AiChecker::jobStatuses($pdo, explode(',', (string) ($_POST['job_ids'] ?? ''))),
            'worker_online' => AiChecker::workerOnline($pdo),
        ]);
        exit;
    }

    if ($action === 'scan') {
        set_time_limit(90);
        $stmt = $pdo->prepare('SELECT * FROM prospects WHERE id = :id');
        $stmt->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        $prospect = $stmt->fetch();
        if (!$prospect || empty($prospect['website'])) {
            echo json_encode(['outcome' => 'failed', 'email' => null, 'others' => [], 'note' => 'No website to scan']);
            exit;
        }
        try {
            echo json_encode((new EmailFinder())->findForProspect($pdo, $prospect));
        } catch (Throwable $e) {
            echo json_encode(['outcome' => 'failed', 'email' => null, 'others' => [], 'error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$categories = $pdo->query(
    "SELECT category, COUNT(*) AS n,
        SUM(website IS NOT NULL AND website != '' AND (contact_email IS NULL OR contact_email = '')) AS missing
     FROM prospects WHERE category IS NOT NULL AND category != '' GROUP BY category ORDER BY category"
)->fetchAll();
$cities = $pdo->query(
    "SELECT DISTINCT city FROM prospects WHERE city IS NOT NULL AND city != '' ORDER BY city"
)->fetchAll(PDO::FETCH_COLUMN);
$totals = $pdo->query(
    "SELECT SUM(contact_email IS NOT NULL AND contact_email != '') AS with_email,
        SUM(website IS NOT NULL AND website != '' AND (contact_email IS NULL OR contact_email = '')) AS missing
     FROM prospects"
)->fetch();

// Arriving from the dashboard's "Find emails for selected" button.
$selectedIds = array_values(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? ''))));
$selected = $selectedIds ? EmailFinder::byIds($pdo, $selectedIds) : [];

$pageTitle = 'Find emails';
$activeNav = 'email_finder';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="stat-row">
  <div class="stat"><div class="stat-value"><?= (int) $totals['with_email'] ?></div><div class="stat-label">Prospects with an email</div></div>
  <div class="stat"><div class="stat-value"><?= (int) $totals['missing'] ?></div><div class="stat-label">Have a website but no email yet</div></div>
</div>

<?php if ($selectedIds): ?>
<div class="card">
<h2>Find emails for <?= count($selected) ?> selected prospect(s)</h2>
<p class="hint">
  Started automatically from your selection on the dashboard. Selected prospects are scanned even if they
  already have an email. <a href="email_finder.php">Run on a whole group instead</a>
</p>
<form id="finderForm" data-ids="<?= htmlspecialchars(implode(',', array_column($selected, 'id'))) ?>" data-autostart="1">
  <div class="button-row">
    <button type="submit" id="startBtn">Run again</button>
    <button type="button" id="stopBtn" class="btn-secondary" hidden>Stop</button>
    <a class="btn btn-secondary" href="index.php">Back to dashboard</a>
  </div>
</form>
</div>
<?php else: ?>
<div class="card">
<h2>Find emails on prospects' websites</h2>
<p class="hint">
  Opens each prospect's homepage and contact page and saves the best email address it finds —
  preferring the business's own domain and general inboxes like info@ or hello@. Free: no API
  credits are used. Takes about 2 seconds per website; keep this tab open while it runs.
</p>
<form id="finderForm">
  <div class="form-grid">
    <div>
      <label for="category">Business type</label>
      <select id="category" name="category" class="select">
        <option value="">All types</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= htmlspecialchars($c['category']) ?>"><?= htmlspecialchars($c['category']) ?> (<?= (int) $c['missing'] ?> missing email)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="city">City</label>
      <select id="city" name="city" class="select">
        <option value="">All cities</option>
        <?php foreach ($cities as $city): ?>
        <option value="<?= htmlspecialchars($city) ?>"><?= htmlspecialchars($city) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="limit">Max websites this run</label>
      <input type="number" id="limit" name="limit" min="1" max="5000" value="500">
    </div>
  </div>
  <label class="check-label">
    <input type="checkbox" id="rescan" name="rescan" value="1">
    Also re-scan prospects that already have an email (may replace it with a better pick)
  </label>
  <div class="button-row">
    <button type="submit" id="startBtn">Start finding emails</button>
    <button type="button" id="stopBtn" class="btn-secondary" hidden>Stop</button>
  </div>
</form>
</div>
<?php endif; ?>

<details class="card ai-settings" id="aiSettings">
  <summary>
    <span class="ai-spark" aria-hidden="true">&#10022;</span> AI check
    <span class="hint" style="margin:0">— Claude visits the site and searches the web to find an email and tell you if the lead is worth trying</span>
  </summary>
  <div class="worker-row">
    <div class="worker-status" id="workerStatus">
      <span class="worker-dot"></span>
      <span class="worker-text"></span>
    </div>
    <button type="button" id="workerStartBtn" class="btn-small">Start worker</button>
    <button type="button" id="workerStopBtn" class="btn-small btn-secondary" hidden>Stop worker</button>
  </div>
  <p class="hint worker-help" id="workerHelp">
    Checks are run by Claude Code on your own Claude login (no API key). "Start worker" opens a
    "Leadgen AI worker" window on your desktop; leave it open (minimizing is fine). It also starts by itself
    when you click "Check with AI".
  </p>
  <div class="ai-error" id="workerError" hidden></div>
  <label for="aiContext">Your outreach goal (Claude judges each lead against this)</label>
  <textarea id="aiContext" class="ai-context" placeholder="<?= htmlspecialchars(AiChecker::DEFAULT_CONTEXT) ?>"></textarea>
  <p class="hint">
    Saved in this browser. Each check takes about 30–90 seconds and counts toward your Claude plan's usage.
    The worker runs one check at a time.
  </p>
</details>

<div class="card" id="runCard" hidden>
  <div class="run-head">
    <h2 id="runTitle" style="margin:0">Running…</h2>
    <span class="muted" id="runEta"></span>
    <button type="button" id="aiAllBtn" class="btn-ai" hidden></button>
  </div>
  <div class="progress"><div class="progress-bar" id="progressBar"></div></div>
  <div class="run-stats">
    <span class="badge badge-done" id="statFound">0 found</span>
    <span class="badge badge-low" id="statNone">0 no email on site</span>
    <span class="badge badge-medium" id="statFailed">0 site didn't load</span>
    <span class="muted" id="statProgress">0 / 0</span>
  </div>
  <div class="log" id="log"></div>
</div>

<script>
(function() {
  const form = document.getElementById('finderForm');
  const startBtn = document.getElementById('startBtn');
  const stopBtn = document.getElementById('stopBtn');
  const log = document.getElementById('log');
  let stopRequested = false;

  function post(data) {
    const body = new URLSearchParams(data);
    return fetch('email_finder.php', { method: 'POST', body: body }).then(function(r) { return r.json(); });
  }

  // ---------- AI check ----------
  const aiContext = document.getElementById('aiContext');
  const aiAllBtn = document.getElementById('aiAllBtn');
  try { aiContext.value = localStorage.getItem('leadgen.aiContext') || ''; } catch (e) {}
  aiContext.addEventListener('input', function() {
    try { localStorage.setItem('leadgen.aiContext', aiContext.value); } catch (e) {}
  });

  const VERDICTS = {
    worth_trying: { label: 'Worth trying', cls: 'badge-high' },
    maybe: { label: 'Maybe', cls: 'badge-medium' },
    skip: { label: 'Skip', cls: 'badge-skip' }
  };
  const noEmailRows = []; // rows whose scan found no email — candidates for "check all"

  function el(tag, cls, text) {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function renderAi(panel, ai) {
    panel.innerHTML = '';
    panel.hidden = false;
    const v = VERDICTS[ai.verdict] || VERDICTS.maybe;

    const head = el('div', 'ai-head');
    head.appendChild(el('span', 'badge ' + v.cls, v.label));
    const loc = el('span', 'ai-loc' + (ai.location_matches_search ? '' : ' ai-loc-bad'), (ai.location_matches_search ? '📍 ' : '⚠ ') + ai.based_in);
    head.appendChild(loc);
    head.appendChild(el('span', 'ai-meta', (ai.duration_seconds ? ai.duration_seconds + 's · ' : '') + ai.checked_at));
    panel.appendChild(head);

    panel.appendChild(el('p', 'ai-summary', ai.summary));

    const emailLine = el('div', 'ai-line');
    emailLine.appendChild(el('span', 'ai-label', 'Email'));
    if (ai.email) {
      const a = el('a', 'ai-email', ai.email);
      a.href = 'mailto:' + ai.email;
      emailLine.appendChild(a);
      if (ai.email_source_url) {
        const src = el('a', 'ai-src', 'source');
        src.href = ai.email_source_url; src.target = '_blank'; src.rel = 'noopener';
        emailLine.appendChild(src);
      }
      if (ai.email_saved) emailLine.appendChild(el('span', 'badge badge-done', 'saved to prospect'));
    } else {
      emailLine.appendChild(el('span', 'ai-none', 'none published'));
    }
    panel.appendChild(emailLine);

    if (ai.other_contacts && ai.other_contacts.length) {
      const line = el('div', 'ai-line');
      line.appendChild(el('span', 'ai-label', 'Other'));
      line.appendChild(el('span', '', ai.other_contacts.join(' · ')));
      panel.appendChild(line);
    }
    if (ai.red_flags && ai.red_flags.length) {
      const ul = el('ul', 'ai-flags');
      ai.red_flags.forEach(function(f) { ul.appendChild(el('li', '', f)); });
      panel.appendChild(ul);
    }
    if (ai.evidence && ai.evidence.length) {
      const det = el('details', 'ai-evidence');
      det.appendChild(el('summary', '', 'Evidence (' + ai.evidence.length + ')'));
      const ul = el('ul');
      ai.evidence.forEach(function(f) { ul.appendChild(el('li', '', f)); });
      det.appendChild(ul);
      panel.appendChild(det);
    }
  }

  // ---------- Worker status + job polling ----------
  const workerStatus = document.getElementById('workerStatus');
  const watching = {}; // job id -> { btn, panel, lastStatus }
  let workerOnline = false;

  const workerStartBtn = document.getElementById('workerStartBtn');
  const workerStopBtn = document.getElementById('workerStopBtn');
  const workerError = document.getElementById('workerError');
  let workerStarting = false;
  let workerStopping = false;

  function setWorkerOnline(online) {
    if (online) workerStarting = false;
    if (!online) workerStopping = false;
    workerOnline = online;
    workerStatus.classList.toggle('online', online);
    workerStatus.classList.toggle('starting', workerStarting && !online);
    workerStatus.querySelector('.worker-text').textContent = online
      ? (workerStopping ? 'Stopping after the current check…' : 'AI worker is running')
      : (workerStarting ? 'Starting AI worker…' : 'AI worker is not running');
    workerStartBtn.hidden = online;
    workerStartBtn.disabled = workerStarting;
    workerStopBtn.hidden = !online;
    workerStopBtn.disabled = workerStopping;
    document.getElementById('workerHelp').hidden = online;
    Object.keys(watching).forEach(function(id) { showPending(watching[id]); });
  }

  async function startWorker() {
    workerError.hidden = true;
    workerStarting = true;
    setWorkerOnline(false);
    const res = await post({ action: 'worker_start' });
    handleStartResult(res);
  }

  function handleStartResult(res) {
    if (!res) return;
    if (!res.ok) {
      workerStarting = false;
      workerError.textContent = res.message;
      workerError.hidden = false;
      document.getElementById('aiSettings').open = true;
    } else {
      workerStarting = !workerOnline;
      // Give up on "Starting…" if no heartbeat arrives within 30s.
      setTimeout(function() { if (!workerOnline && workerStarting) { workerStarting = false; setWorkerOnline(false); } }, 30000);
    }
    setWorkerOnline(workerOnline);
  }

  workerStartBtn.addEventListener('click', startWorker);
  workerStopBtn.addEventListener('click', async function() {
    workerStopping = true;
    setWorkerOnline(true);
    await post({ action: 'worker_stop' });
  });

  function showPending(w) {
    w.panel.hidden = false;
    w.panel.innerHTML = '';
    let text;
    if (w.lastStatus === 'running') {
      text = 'Claude is visiting the site and searching the web… (30–90s)';
    } else if (!workerOnline) {
      text = workerStarting ? 'Queued, starting the AI worker…' : 'Queued, waiting for the AI worker. Click "Start worker" above.';
    } else {
      text = 'Queued, the worker runs one check at a time…';
    }
    w.panel.appendChild(el('div', 'ai-pending', text));
  }

  async function poll() {
    const ids = Object.keys(watching);
    try {
      const res = await post({ action: 'ai_status', job_ids: ids.join(',') });
      if (res.worker_online !== workerOnline) setWorkerOnline(res.worker_online);
      ids.forEach(function(id) {
        const job = res.jobs[id];
        const w = watching[id];
        if (!job) return;
        if (job.status === 'done' || job.status === 'failed') {
          delete watching[id];
          w.btn.disabled = false;
          w.btn.classList.remove('is-loading');
          if (job.status === 'done' && job.ai) {
            renderAi(w.panel, job.ai);
            w.btn.textContent = 'Re-check with AI';
          } else {
            w.panel.innerHTML = '';
            w.panel.appendChild(el('div', 'ai-error', job.error || 'The check failed.'));
            w.btn.textContent = 'Retry AI check';
          }
          if (w.row) w.row.done = true;
          updateAiAllBtn();
        } else if (job.status !== w.lastStatus) {
          w.lastStatus = job.status;
          showPending(w);
        }
      });
    } catch (e) { /* try again next tick */ }
    setTimeout(poll, Object.keys(watching).length ? 3000 : 10000);
  }

  async function queueAiChecks(rows) {
    rows.forEach(function(r) {
      r.queued = true;
      r.btn.disabled = true;
      r.btn.textContent = 'Checking…';
      r.btn.classList.add('is-loading');
    });
    const res = await post({
      action: 'ai_check',
      ids: rows.map(function(r) { return r.item.id; }).join(','),
      context: aiContext.value
    });
    if (res.start) {
      handleStartResult(res.start);
    } else {
      setWorkerOnline(res.worker_online);
    }
    rows.forEach(function(r) {
      const jobId = res.jobs[r.item.id];
      watching[jobId] = { btn: r.btn, panel: r.panel, row: r, lastStatus: 'pending' };
      showPending(watching[jobId]);
    });
  }

  setWorkerOnline(<?= AiChecker::workerOnline($pdo) ? 'true' : 'false' ?>);
  poll();

  function addLogRow(item, result) {
    const wrap = el('div', 'log-item');
    const row = el('div', 'log-row log-' + result.outcome);
    const name = el('a', '', item.name);
    name.href = 'view.php?id=' + item.id;
    name.target = '_blank';
    let text;
    if (result.outcome === 'found') {
      text = result.email + (result.others.length ? '  (also: ' + result.others.join(', ') + ')' : '');
    } else {
      text = result.note || (result.error ? 'error: ' + result.error : (result.outcome === 'none' ? 'no email on site' : "site didn't load"));
    }
    const btn = el('button', 'btn-ai', item.ai ? 'Re-check with AI' : 'Check with AI');
    btn.type = 'button';
    row.appendChild(name);
    row.appendChild(el('span', 'log-detail', text));
    row.appendChild(btn);

    const panel = el('div', 'ai-panel');
    panel.hidden = true;
    if (item.ai) renderAi(panel, item.ai);

    const rowRef = { item: item, btn: btn, panel: panel };
    btn.addEventListener('click', function() { queueAiChecks([rowRef]); });
    wrap.appendChild(row);
    wrap.appendChild(panel);
    log.prepend(wrap);

    if (result.outcome !== 'found' && !item.ai) {
      noEmailRows.push(rowRef);
    }
  }

  function updateAiAllBtn() {
    const notQueued = noEmailRows.filter(function(r) { return !r.queued; });
    const running = noEmailRows.filter(function(r) { return r.queued && !r.done; }).length;
    aiAllBtn.hidden = notQueued.length === 0 && running === 0;
    aiAllBtn.disabled = notQueued.length === 0;
    aiAllBtn.textContent = notQueued.length
      ? '✦ Check all ' + notQueued.length + ' without email with AI'
      : '✦ AI checking… ' + running + ' left';
  }

  aiAllBtn.addEventListener('click', async function() {
    const notQueued = noEmailRows.filter(function(r) { return !r.queued; });
    if (!confirm('Queue AI checks for ' + notQueued.length + ' prospect(s)? The worker runs them one at a time '
        + '(about ' + notQueued.length + ' min), using your Claude plan.')) {
      return;
    }
    notQueued.forEach(function(r) { r.queued = true; });
    await queueAiChecks(notQueued);
    updateAiAllBtn();
  });

  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    stopRequested = false;
    startBtn.disabled = true;
    startBtn.textContent = 'Running…';
    stopBtn.hidden = false;
    log.innerHTML = '';
    noEmailRows.length = 0;
    aiAllBtn.hidden = true;
    document.getElementById('runCard').hidden = false;
    document.getElementById('runTitle').textContent = 'Running…';

    const items = await post(form.dataset.ids
      ? { action: 'list', ids: form.dataset.ids }
      : {
          action: 'list',
          category: form.category.value,
          city: form.city.value,
          limit: form.limit.value,
          rescan: form.rescan.checked ? '1' : '0'
        });

    const counts = { found: 0, none: 0, failed: 0 };
    const started = Date.now();
    const total = items.length;

    function render(done) {
      document.getElementById('progressBar').style.width = (total ? done / total * 100 : 100) + '%';
      document.getElementById('statFound').textContent = counts.found + ' found';
      document.getElementById('statNone').textContent = counts.none + ' no email on site';
      document.getElementById('statFailed').textContent = counts.failed + " site didn't load";
      document.getElementById('statProgress').textContent = done + ' / ' + total;
      if (done > 0 && done < total) {
        const secsLeft = Math.round((Date.now() - started) / done * (total - done) / 1000);
        document.getElementById('runEta').textContent = 'about ' + (secsLeft >= 60 ? Math.round(secsLeft / 60) + ' min' : secsLeft + ' s') + ' left';
      } else {
        document.getElementById('runEta').textContent = '';
      }
    }
    render(0);

    let done = 0;
    for (const item of items) {
      if (stopRequested) break;
      let result;
      try {
        result = await post({ action: 'scan', id: item.id });
      } catch (err) {
        result = { outcome: 'failed', email: null, others: [], error: 'request failed' };
      }
      counts[result.outcome] = (counts[result.outcome] || 0) + 1;
      done++;
      addLogRow(item, result);
      render(done);
    }

    document.getElementById('runTitle').textContent = total === 0
      ? 'Nothing to scan: every matching prospect already has an email'
      : (stopRequested ? 'Stopped after ' + done + ' of ' + total : 'Done. ' + counts.found + ' new email(s) saved');
    startBtn.disabled = false;
    startBtn.textContent = form.dataset.ids ? 'Run again' : 'Start finding emails';
    stopBtn.hidden = true;
    updateAiAllBtn();
  });

  stopBtn.addEventListener('click', function() {
    stopRequested = true;
    stopBtn.hidden = true;
    document.getElementById('runTitle').textContent = 'Stopping after the current website…';
  });

  if (form.dataset.autostart) {
    form.requestSubmit();
  }
})();
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
