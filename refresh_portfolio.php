<?php
/**
 * Checks the curated project list the outreach AI cites from
 * (prompts/agency-qualifier/portfolio.txt, edited by hand) against
 * https://dmmbs.com/projects/. It never touches portfolio.txt: it saves the
 * site's own list to portfolio.generated.txt for reference and prints any
 * project on the site that portfolio.txt doesn't mention yet, so you can add it.
 * Run it whenever projects are added to the portfolio site:
 *
 *   php refresh_portfolio.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line: php refresh_portfolio.php\n");
}

require_once __DIR__ . '/includes/AgencyScraper.php';

const SOURCE = 'https://dmmbs.com/projects/';
const CURATED = __DIR__ . '/prompts/agency-qualifier/portfolio.txt';
const TARGET = __DIR__ . '/prompts/agency-qualifier/portfolio.generated.txt';

$page = AgencyScraper::httpGet(SOURCE);
if (!$page['ok']) {
    fwrite(STDERR, 'Could not fetch ' . SOURCE . ': ' . $page['error'] . "\n");
    exit(1);
}

$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<?xml encoding="utf-8" ?>' . $page['html']);
libxml_clear_errors();
$xpath = new DOMXPath($doc);
$text = function (?DOMNode $n): string {
    return $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : '';
};

$byCategory = [];
$titles = [];
$count = 0;
foreach ($xpath->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' project-card ')]") as $card) {
    $link = $xpath->query(".//*[contains(@class, 'project-card__title')]//a", $card)->item(0);
    if (!$link) {
        continue;
    }
    $category = $text($xpath->query(".//*[contains(@class, 'project-card__domain')]/span[1]", $card)->item(0)) ?: 'Other';
    $excerpt = rtrim($text($xpath->query(".//*[contains(@class, 'project-card__excerpt')]", $card)->item(0)), " …\u{2026}");
    $role = $text($xpath->query(".//*[contains(@class, 'project-card__role')]", $card)->item(0));
    // data-stack lists every technology and API (the visible list shows only the first few).
    $stack = implode(', ', array_map(fn($s) => trim($s), array_filter(explode(',', $card->getAttribute('data-stack')))));

    $line = '- ' . $text($link) . ': ' . $excerpt . ($excerpt !== '' && !preg_match('/[.!?]$/', $excerpt) ? '…' : '');
    $details = array_filter([$role ? "Role: $role" : '', $stack ? "Stack: $stack" : '', 'Case study: ' . $link->getAttribute('href')]);
    $byCategory[$category][] = $line . "\n  " . implode(' | ', $details);
    $titles[] = $text($link);
    $count++;
}

if ($count < 5) {
    fwrite(STDERR, "Only found $count projects; the page layout may have changed.\n");
    exit(1);
}

$out = "Projects listed on " . SOURCE . ' (' . $count . ', fetched ' . date('Y-m-d') . " by refresh_portfolio.php).\n"
    . "Reference only: the AI reads the hand-curated portfolio.txt, not this file.\n";
foreach ($byCategory as $category => $lines) {
    $out .= "\n## " . html_entity_decode($category) . "\n" . implode("\n", $lines) . "\n";
}
file_put_contents(TARGET, $out);
echo "Site lists $count projects (saved to prompts/agency-qualifier/portfolio.generated.txt).\n";

// Which site projects does the curated list not mention? Matched on the project's name
// (the part before " — "), as whole words, ignoring case and punctuation.
$words = fn(string $s) => ' ' . trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(html_entity_decode($s)))) . ' ';
$curated = $words((string) @file_get_contents(CURATED));
$mentioned = function (string $title) use ($curated, $words): bool {
    $name = $words(explode(' — ', $title)[0]);
    if (strpos($curated, $name) !== false) {
        return true;
    }
    // Worded differently ("RAG Knowledge System" vs "Retrieval-Augmented Generation (RAG) Knowledge
    // System"): mentioned if every main word of the site's name is in the list.
    $main = array_filter(explode(' ', trim($name)), fn($w) => strlen($w) >= 4);
    return $main && !array_filter($main, fn($w) => strpos($curated, " $w ") === false);
};
$missing = array_filter($titles, fn($t) => !$mentioned($t));
if (!$missing) {
    echo "portfolio.txt mentions every project on the site.\n";
} else {
    echo "\nOn the site but not in portfolio.txt (add them there if the AI should be able to cite them):\n";
    foreach ($missing as $t) {
        echo "  - $t\n";
    }
}
