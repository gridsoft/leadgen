<?php
/**
 * Shared page shell (sidebar + top bar). Include after computing $pageTitle
 * and $activeNav ('dashboard' | 'search' | 'add'), and after any redirects —
 * this echoes output immediately. Pair with layout_footer.php.
 */
$pageTitle = $pageTitle ?? 'Leadgen';
$activeNav = $activeNav ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — Leadgen</title>
<link rel="stylesheet" href="<?= isset($assetPrefix) ? $assetPrefix : '' ?>assets/style.css">
<script>
try { if (localStorage.getItem("sidebarCollapsed") === "1") document.documentElement.classList.add("sidebar-collapsed"); } catch (e) {}
</script>
</head>
<body>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand"><span class="brand-text">Leadgen</span></div>
  <nav class="sidebar-nav">
    <?php
    // "For analysis" (includes/ForAnalysis.php): a saved dashboard filter, listed under
    // Agency outreach. It's the active item when the dashboard shows exactly these filters.
    require_once __DIR__ . '/ForAnalysis.php';
    $forAnalysisFilters = ForAnalysis::FILTERS;
    $forAnalysisHref = ForAnalysis::dashboardUrl();
    if ($activeNav === 'dashboard' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php' && trim((string) ($_GET['q'] ?? '')) === '') {
        $matches = true;
        foreach (['status', 'category', 'source', 'email', 'contacted', 'ai'] as $filterKey) {
            $current = array_map('strval', (array) ($_GET[$filterKey] ?? []));
            $wanted = $forAnalysisFilters[$filterKey] ?? [];
            sort($current);
            sort($wanted);
            $matches = $matches && $current === $wanted;
        }
        if ($matches) {
            $activeNav = 'for_analysis';
        }
    }

    // [activeNav key, href, label, SVG path data (24×24, stroked), optional sub-items of the same shape]
    $navItems = [
        ["dashboard", "index.php", "Dashboard", "<rect x=\"3\" y=\"3\" width=\"7\" height=\"9\" rx=\"1.5\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"5\" rx=\"1.5\"/><rect x=\"14\" y=\"12\" width=\"7\" height=\"9\" rx=\"1.5\"/><rect x=\"3\" y=\"16\" width=\"7\" height=\"5\" rx=\"1.5\"/>"],
        ["search", "search.php", "Find prospects", "<circle cx=\"11\" cy=\"11\" r=\"7\"/><path d=\"m20 20-3.5-3.5\"/>"],
        ["clutch", "clutch_import.php", "Import from Clutch", "<path d=\"M12 3v12\"/><path d=\"m7 10 5 5 5-5\"/><path d=\"M5 21h14\"/>"],
        ["add", "add.php", "Add manually", "<circle cx=\"12\" cy=\"12\" r=\"9\"/><path d=\"M12 8v8M8 12h8\"/>"],
        ["bulk_analyze", "bulk_analyze.php", "Bulk analyze", "<path d=\"M13 2 4 14h7l-1 8 9-12h-7l1-8z\"/>"],
        ["email_finder", "email_finder.php", "Find emails", "<rect x=\"3\" y=\"5\" width=\"18\" height=\"14\" rx=\"2\"/><path d=\"m3 7 9 6 9-6\"/>"],
        ["agencies", "agency_outreach.php", "Agency outreach", "<rect x=\"3\" y=\"7\" width=\"18\" height=\"13\" rx=\"2\"/><path d=\"M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\"/><path d=\"M3 13h18\"/>", [
            ["for_analysis", $forAnalysisHref, "For analysis", "<path d=\"M3 5h18l-7 8v6l-4 2v-8z\"/>"],
        ]],
    ];
    foreach ($navItems as $item):
        [$key, $href, $label, $icon] = $item; ?>
    <a href="<?= $href ?>" class="<?= $activeNav === $key ? "active" : "" ?>" data-label="<?= htmlspecialchars($label) ?>"<?= $activeNav === $key ? " aria-current=\"page\"" : "" ?>>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg>
      <span class="label"><?= htmlspecialchars($label) ?></span>
    </a>
      <?php foreach ($item[4] ?? [] as [$subKey, $subHref, $subLabel, $subIcon]): ?>
    <a href="<?= htmlspecialchars($subHref) ?>" class="sidebar-sub<?= $activeNav === $subKey ? " active" : "" ?>" data-label="<?= htmlspecialchars($subLabel) ?>"<?= $activeNav === $subKey ? " aria-current=\"page\"" : "" ?>>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><?= $subIcon ?></svg>
      <span class="label"><?= htmlspecialchars($subLabel) ?></span>
    </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-controls="sidebar" aria-expanded="true" title="Collapse sidebar">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
    <span class="label">Collapse</span>
  </button>
  <nav class="sidebar-nav sidebar-account">
    <a href="logout.php" data-label="Log out" title="Logged in as <?= htmlspecialchars((string) (app_config()['admin_user'] ?? '')) ?>">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
      <span class="label">Log out</span>
    </a>
  </nav>
</aside>
<script>
(function() {
  var root = document.documentElement;
  var btn = document.getElementById("sidebarToggle");
  function sync() {
    var collapsed = root.classList.contains("sidebar-collapsed");
    btn.setAttribute("aria-expanded", collapsed ? "false" : "true");
    btn.title = collapsed ? "Expand sidebar" : "Collapse sidebar";
  }
  btn.addEventListener("click", function() {
    var collapsed = root.classList.toggle("sidebar-collapsed");
    try { localStorage.setItem("sidebarCollapsed", collapsed ? "1" : "0"); } catch (e) {}
    sync();
  });
  sync();
})();
</script>
<div class="page">
<div class="topbar">
  <h1><?= htmlspecialchars($pageTitle) ?></h1>
  <?php if (!empty($topbarActions)): ?>
  <div class="topbar-actions"><?= $topbarActions ?></div>
  <?php endif; ?>
</div>
<main>
