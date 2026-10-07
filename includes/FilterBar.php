<?php
/**
 * The filter bar shared by the dashboard (index.php) and Agency outreach
 * (agency_outreach.php): a search box plus multi-select dropdowns, and the
 * removable chips for the active filters underneath. Behaviour (apply on
 * close, find box, Esc) lives in assets/filter-bar.js.
 *
 * A filter definition, keyed by its URL parameter (?key[]=value):
 *   label     dropdown / chip label
 *   empty     summary text when nothing is selected (e.g. "All")
 *   selected  selected values (strings)
 *   values    option values, in display order
 *   options   [label, count] per value, same order as values
 *   in        optional: render as an extra section inside another filter's dropdown
 *   section   optional: heading for this filter's own section when it hosts another
 */

/** Pairs values with options and resolves the selected labels. Call once before rendering. */
function filter_defs_prepare(array $defs): array {
    foreach ($defs as &$def) {
        $def['options'] = $def['values'] ? array_combine($def['values'], $def['options']) : [];
        $def['selectedLabels'] = array_map(fn($v) => $def['options'][$v][0] ?? $v, $def['selected']);
    }
    return $defs;
}

/**
 * @param callable(array): string $url builds this page's URL with the given parameters overridden (null removes one)
 * @param array<string, string> $hidden extra GET parameters to keep (sort, per_page…)
 */
function render_filter_bar(string $action, array $defs, string $search, string $searchPlaceholder, array $hidden, callable $url): string {
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ob_start(); ?>
<form method="get" action="<?= $e($action) ?>" class="filter-bar" data-filter-bar>
  <?php foreach ($hidden as $name => $value): ?><input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>"><?php endforeach; ?>
  <label class="filter-search">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input type="text" name="q" placeholder="<?= $e($searchPlaceholder) ?>" value="<?= $e($search) ?>" aria-label="Search">
  </label>
  <?php foreach ($defs as $key => $def):
    if (!$def['options'] || isset($def['in'])) continue;
    // This dropdown's own filter plus any filters shown as extra sections inside it.
    $sections = [$key => $def];
    foreach ($defs as $k2 => $d2) {
        if (($d2['in'] ?? null) === $key && $d2['options']) {
            $sections[$k2] = $d2;
        }
    }
    $summaryLabels = array_merge([], ...array_values(array_column($sections, 'selectedLabels')));
    $clearUrl = $url(array_fill_keys(array_keys($sections), null) + ['page' => null]); ?>
  <details class="filter-dd<?= $summaryLabels ? ' has-value' : '' ?>">
    <summary>
      <span class="dd-label"><?= $e($def['label']) ?></span>
      <span class="dd-value"><?= $e($summaryLabels ? implode(', ', $summaryLabels) : $def['empty']) ?></span>
      <?php if ($summaryLabels): ?>
        <a class="dd-clear" href="<?= $e($clearUrl) ?>" title="Clear <?= $e(strtolower($def['label'])) ?>" aria-label="Clear <?= $e(strtolower($def['label'])) ?>">&times;</a>
      <?php endif; ?>
      <span class="dd-caret" aria-hidden="true"></span>
    </summary>
    <div class="dd-panel">
      <?php if (count($def['options']) > 8): ?>
        <input type="text" class="dd-find" placeholder="Find <?= $e(strtolower($def['label'])) ?>&hellip;" aria-label="Find <?= $e(strtolower($def['label'])) ?>">
      <?php endif; ?>
      <div class="dd-options">
        <?php foreach ($sections as $sKey => $sDef): ?>
          <?php if (count($sections) > 1): ?><div class="dd-section"><?= $e($sDef['section'] ?? $sDef['label']) ?></div><?php endif; ?>
          <?php foreach ($sDef['options'] as $value => [$label, $n]): ?>
          <label class="dd-option">
            <input type="checkbox" name="<?= $e($sKey) ?>[]" value="<?= $e($value) ?>" <?= in_array((string) $value, $sDef['selected'], true) ? 'checked' : '' ?>>
            <span class="dd-option-label"><?= $e($label) ?></span>
            <span class="dd-count"><?= (int) $n ?></span>
          </label>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <div class="dd-actions">
        <a href="<?= $e($clearUrl) ?>">Clear</a>
        <button type="submit">Apply</button>
      </div>
    </div>
  </details>
  <?php endforeach; ?>
</form>
<?php
    return ob_get_clean();
}

/** The chips row: one chip per active filter (and the search), plus "Clear filters". Empty string when nothing is active. */
function render_active_filters(array $defs, string $search, callable $url): string {
    $active = array_filter($defs, fn($d) => !empty($d['selected']));
    if ($search === '' && !$active) {
        return '';
    }
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ob_start(); ?>
<div class="active-filters">
  <?php if ($search !== ''): ?>
    <a class="filter-chip" href="<?= $e($url(['q' => null, 'page' => null])) ?>" title="Remove search">
      <strong>Search:</strong> &ldquo;<?= $e($search) ?>&rdquo; <span class="x" aria-hidden="true">&times;</span>
    </a>
  <?php endif; ?>
  <?php foreach ($active as $key => $def): ?>
    <a class="filter-chip" href="<?= $e($url([$key => null, 'page' => null])) ?>" title="Remove this filter">
      <strong><?= $e($def['label']) ?>:</strong> <?= $e(implode(', ', $def['selectedLabels'])) ?> <span class="x" aria-hidden="true">&times;</span>
    </a>
  <?php endforeach; ?>
  <a class="clear-all" href="<?= $e($url(array_fill_keys(array_merge(array_keys($defs), ['q', 'page']), null))) ?>">Clear filters</a>
</div>
<?php
    return ob_get_clean();
}

/**
 * Reads a multi-select filter (?status[]=a&status[]=b). A plain ?status=a
 * from older links/bookmarks is accepted too. Only whitelisted values survive.
 */
function multi_param(string $key, array $allowed): array {
    $value = $_GET[$key] ?? [];
    if (!is_array($value)) {
        $value = $value === '' ? [] : [$value];
    }
    $value = array_map('strval', array_filter($value, 'is_scalar'));
    return array_values(array_intersect(array_unique($value), $allowed));
}
