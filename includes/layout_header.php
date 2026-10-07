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
    // [activeNav key, href, label, SVG path data (24×24, stroked)]
    $navItems = [
        ["dashboard", "index.php", "Dashboard", "<rect x=\"3\" y=\"3\" width=\"7\" height=\"9\" rx=\"1.5\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"5\" rx=\"1.5\"/><rect x=\"14\" y=\"12\" width=\"7\" height=\"9\" rx=\"1.5\"/><rect x=\"3\" y=\"16\" width=\"7\" height=\"5\" rx=\"1.5\"/>"],
        ["search", "search.php", "Find prospects", "<circle cx=\"11\" cy=\"11\" r=\"7\"/><path d=\"m20 20-3.5-3.5\"/>"],
        ["clutch", "clutch_import.php", "Import from Clutch", "<path d=\"M12 3v12\"/><path d=\"m7 10 5 5 5-5\"/><path d=\"M5 21h14\"/>"],
        ["add", "add.php", "Add manually", "<circle cx=\"12\" cy=\"12\" r=\"9\"/><path d=\"M12 8v8M8 12h8\"/>"],
        ["bulk_analyze", "bulk_analyze.php", "Bulk analyze", "<path d=\"M13 2 4 14h7l-1 8 9-12h-7l1-8z\"/>"],
        ["email_finder", "email_finder.php", "Find emails", "<rect x=\"3\" y=\"5\" width=\"18\" height=\"14\" rx=\"2\"/><path d=\"m3 7 9 6 9-6\"/>"],
        ["agencies", "agency_outreach.php", "Agency outreach", "<rect x=\"3\" y=\"7\" width=\"18\" height=\"13\" rx=\"2\"/><path d=\"M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\"/><path d=\"M3 13h18\"/>"],
    ];
    foreach ($navItems as [$key, $href, $label, $icon]): ?>
    <a href="<?= $href ?>" class="<?= $activeNav === $key ? "active" : "" ?>" data-label="<?= htmlspecialchars($label) ?>"<?= $activeNav === $key ? " aria-current=\"page\"" : "" ?>>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg>
      <span class="label"><?= htmlspecialchars($label) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-controls="sidebar" aria-expanded="true" title="Collapse sidebar">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
    <span class="label">Collapse</span>
  </button>
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
