<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/Stats.php';
require_once __DIR__ . '/includes/Settings.php';

$pdo = get_db();
$rows = Stats::byCategory($pdo);
$all = end($rows);
$types = array_slice($rows, 0, -1);
$daily = Stats::daily($pdo);
$verdicts = Stats::verdicts($pdo);
$sources = Stats::sources($pdo);
$cronLast = json_decode(Settings::get($pdo, 'cron_analyze_last'), true);
$quotaResetAt = (int) Settings::get($pdo, 'ai_quota_reset_at', '0');
$sentToday = end($daily)['sent'];

$h = fn($v) => htmlspecialchars((string) $v);
$num = fn(int $n) => number_format($n);
$pct = fn(int $part, int $whole) => $whole > 0 ? round($part / $whole * 100) . '%' : '—';

$pageTitle = 'Statistics';
$activeNav = 'stats';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="stats">

<section class="kpi-row" aria-label="Key numbers">
  <div class="kpi"><div class="kpi-label">Leads</div><div class="kpi-value"><?= $num($all['leads']) ?></div><div class="kpi-sub"><?= count($types) ?> business type<?= count($types) === 1 ? '' : 's' ?></div></div>
  <div class="kpi"><div class="kpi-label">With email</div><div class="kpi-value"><?= $num($all['with_email']) ?></div><div class="kpi-sub"><?= $pct($all['with_email'], $all['leads']) ?> of leads</div></div>
  <div class="kpi"><div class="kpi-label">Analyzed</div><div class="kpi-value"><?= $num($all['analyzed']) ?></div><div class="kpi-sub">agencies scored by the AI</div></div>
  <a class="kpi kpi-hero" href="agency_outreach.php?sort=score&amp;dir=desc&amp;decision%5B0%5D=SEND&amp;status%5B0%5D=analyzed&amp;email%5B0%5D=yes">
    <div class="kpi-label">Ready to send</div><div class="kpi-value"><?= $num($all['ready']) ?></div>
    <div class="kpi-sub"><?= isset($cronLast['pool']) ? 'stock target ' . (int) $cronLast['pool'] : 'verdict Send, has email' ?></div>
  </a>
  <div class="kpi"><div class="kpi-label">Emails sent</div><div class="kpi-value"><?= $num($all['sent']) ?></div><div class="kpi-sub"><?= $num($sentToday) ?> today</div></div>
  <div class="kpi"><div class="kpi-label">Replies</div><div class="kpi-value"><?= $num($all['replied']) ?></div><div class="kpi-sub"><?= $pct($all['replied'], $all['sent']) ?> reply rate</div></div>
</section>

<section class="card">
  <h2>Where your leads are</h2>
  <p class="hint">Each bar is one business type, split by the furthest stage its leads have reached. Hover a segment for its number, or open Show as table.</p>
  <ul class="viz-legend">
    <?php $slot = 0; foreach (Stats::STAGES as $key => $label): $slot++; ?>
      <li><span class="viz-swatch" style="background: var(--series-<?= $slot ?>)"></span><?= $h($label) ?> <span class="muted"><?= $num($all['stages'][$key]) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <div class="stage-bars">
    <?php foreach (array_merge([$all], $types) as $r): ?>
    <div class="stage-row<?= $r === $all ? ' stage-row-all' : '' ?>">
      <div class="stage-name"><?= $h($r === $all ? 'All leads' : $r['category']) ?> <span class="muted"><?= $num($r['leads']) ?></span></div>
      <div class="stage-bar">
        <?php $slot = 0; foreach (Stats::STAGES as $key => $label): $slot++; $n = $r['stages'][$key]; if ($n === 0) continue; ?>
          <span class="stage-seg" style="flex: <?= $n ?> 1 0; background: var(--series-<?= $slot ?>)" tabindex="0"
            data-tip-value="<?= $num($n) ?> leads (<?= $pct($n, $r['leads']) ?>)" data-tip-label="<?= $h($label) ?> · <?= $h($r === $all ? 'All leads' : $r['category']) ?>"></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <details class="table-view">
    <summary>Show as table</summary>
    <div class="table-scroll">
    <table class="stats-table">
      <thead><tr><th>Business type</th><?php foreach (Stats::STAGES as $label): ?><th class="num"><?= $h($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r === $all ? ' class="total-row"' : '' ?>>
          <td><?= $h($r['category']) ?></td>
          <?php foreach (array_keys(Stats::STAGES) as $key): ?><td class="num"><?= $num($r['stages'][$key]) ?> <span class="muted"><?= $pct($r['stages'][$key], $r['leads']) ?></span></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="hint">"Contacted" also includes leads you marked Reached out on the dashboard without sending an email.</p>
  </details>
</section>

<section class="card">
  <h2>By business type</h2>
  <div class="table-scroll">
  <table class="stats-table">
    <thead><tr>
      <th>Business type</th><th class="num">Leads</th><th class="num">With email</th><th class="num">Analyzed</th>
      <th class="num">Ready to send</th><th class="num">Sent</th><th class="num">Replied</th><th class="num">Reply rate</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr<?= $r === $all ? ' class="total-row"' : '' ?>>
        <td><?= $h($r['category']) ?></td>
        <td class="num"><?= $num($r['leads']) ?></td>
        <td class="num"><?= $num($r['with_email']) ?> <span class="muted"><?= $pct($r['with_email'], $r['leads']) ?></span></td>
        <td class="num"><?= $num($r['analyzed']) ?></td>
        <td class="num"><?= $num($r['ready']) ?></td>
        <td class="num"><?= $num($r['sent']) ?></td>
        <td class="num"><?= $num($r['replied']) ?></td>
        <td class="num"><?= $pct($r['replied'], $r['sent']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint">Analyzed, ready to send, sent and replied count agencies (one per website). Reply rate = replied ÷ sent.</p>
</section>

<section class="card">
  <h2>Last 30 days</h2>
  <div class="small-multiples">
    <?php foreach (['analyses' => 'AI analyses per day', 'sent' => 'Emails sent per day'] as $key => $title):
      $max = max(1, max(array_column($daily, $key)));
      $total = array_sum(array_column($daily, $key)); ?>
    <figure class="column-chart">
      <figcaption><?= $h($title) ?> <span class="muted"><?= $num($total) ?> in 30 days</span></figcaption>
      <div class="col-plot">
        <span class="col-max"><?= $num($max) ?></span>
        <div class="col-bars">
          <?php foreach ($daily as $day => $v): $n = $v[$key]; ?>
            <span class="col" tabindex="0" data-tip-value="<?= $num($n) ?>" data-tip-label="<?= $h(date('D, M j', strtotime($day))) ?>">
              <?php if ($n > 0): ?><span class="col-bar" style="height: <?= round($n / $max * 100, 1) ?>%"></span><?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-axis"><span><?= $h(date('M j', strtotime(array_key_first($daily)))) ?></span><span>Today</span></div>
    </figure>
    <?php endforeach; ?>
  </div>
  <details class="table-view">
    <summary>Show as table</summary>
    <div class="table-scroll">
    <table class="stats-table">
      <thead><tr><th>Day</th><th class="num">AI analyses</th><th class="num">Emails sent</th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($daily, true) as $day => $v): ?>
        <tr><td><?= $h(date('D, M j', strtotime($day))) ?></td><td class="num"><?= $num($v['analyses']) ?></td><td class="num"><?= $num($v['sent']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
</section>

<div class="stats-grid">
  <section class="card">
    <h2>AI verdicts</h2>
    <p class="hint">Latest analysis of each agency.</p>
    <?php $vMax = max(1, max(array_column($verdicts, 'count')));
    foreach (['SEND' => 'Send', 'SEND_LOW_PRIORITY' => 'Low priority', 'SKIP' => 'Skip'] as $key => $label): $v = $verdicts[$key]; ?>
      <div class="hbar-row">
        <div class="hbar-name"><?= $h($label) ?><?php if ($v['avg_score'] !== null): ?> <span class="muted">avg score <?= (int) $v['avg_score'] ?></span><?php endif; ?></div>
        <div class="hbar-track"><span class="hbar" style="width: <?= round($v['count'] / $vMax * 100, 1) ?>%"></span><span class="hbar-value"><?= $num($v['count']) ?></span></div>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="card">
    <h2>Lead sources</h2>
    <p class="hint">Where the leads were found. A lead found in several places counts for each.</p>
    <?php if (!$sources): ?><p class="muted">No leads yet.</p><?php endif;
    $sMax = max(1, $sources ? max($sources) : 1);
    foreach ($sources as $source => $n): ?>
      <div class="hbar-row">
        <div class="hbar-name"><?= $h(ucfirst($source)) ?></div>
        <div class="hbar-track"><span class="hbar" style="width: <?= round($n / $sMax * 100, 1) ?>%"></span><span class="hbar-value"><?= $num($n) ?></span></div>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="card">
    <h2>Background analysis</h2>
    <?php if (!is_array($cronLast)): ?>
      <p class="hint">The cron job (cron_analyze.php) hasn't run yet.</p>
    <?php else: ?>
      <dl class="kv">
        <dt>Last run</dt><dd><?= $h(date('M j, H:i', (int) $cronLast['at'])) ?></dd>
        <dt>Result</dt><dd><?= (int) $cronLast['analyzed'] ?> analyzed, <?= (int) $cronLast['failed'] ?> failed</dd>
        <dt>Stopped</dt><dd><?= $h($cronLast['stopped']) ?></dd>
        <?php if (isset($cronLast['pool'])): ?><dt>Stock</dt><dd><?= $num($all['ready']) ?> of <?= (int) $cronLast['pool'] ?> ready to send</dd><?php endif; ?>
        <?php if ($quotaResetAt > time()): ?><dt>Paused</dt><dd>until <?= $h(date('M j, H:i', $quotaResetAt)) ?> (free AI quota used up)</dd><?php endif; ?>
      </dl>
    <?php endif; ?>
    <p class="hint">Still to analyze: <?= $num($all['stages']['to_analyze']) ?> leads with an email address.</p>
  </section>
</div>

</div>

<div class="viz-tip" id="vizTip" role="tooltip" hidden><strong></strong><span></span></div>
<script>
// One tooltip for every mark with data-tip-value: value first, label second, on hover and keyboard focus.
(function() {
  const tip = document.getElementById('vizTip');
  const value = tip.querySelector('strong');
  const label = tip.querySelector('span');
  function show(el) {
    value.textContent = el.dataset.tipValue;
    label.textContent = el.dataset.tipLabel;
    tip.hidden = false;
    const r = el.getBoundingClientRect();
    const left = Math.min(Math.max(8, r.left + r.width / 2 - tip.offsetWidth / 2), window.innerWidth - tip.offsetWidth - 8);
    tip.style.left = left + 'px';
    tip.style.top = Math.max(8, r.top - tip.offsetHeight - 8) + 'px';
  }
  function hide() { tip.hidden = true; }
  document.querySelectorAll('[data-tip-value]').forEach(function(el) {
    el.addEventListener('pointerenter', function() { show(el); });
    el.addEventListener('focus', function() { show(el); });
    el.addEventListener('pointerleave', hide);
    el.addEventListener('blur', hide);
  });
  window.addEventListener('scroll', hide, {passive: true});
})();
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
