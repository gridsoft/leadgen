<?php
/**
 * Minimal test runner (the project has no test framework or Composer
 * dev tooling). Each tests/*Test.php file returns an array of
 * 'test name' => function () { ... } closures that throw on failure.
 *
 * Usage: php tests/run.php [filter]
 *
 * Tests never touch the network or the real AI API — HTTP and the AI
 * client are replaced with fakes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line: php tests/run.php\n");
}

final class AssertionFailed extends Exception {}

function assert_same($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? "$message\n" : '') . "  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true));
    }
}
function assert_true($condition, string $message = 'expected true'): void {
    if ($condition !== true) {
        throw new AssertionFailed($message);
    }
}
function assert_contains(string $needle, string $haystack, string $message = ''): void {
    if (strpos($haystack, $needle) === false) {
        throw new AssertionFailed(($message !== '' ? "$message\n" : '') . "  expected to find: " . var_export($needle, true) . "\n  in: " . var_export(mb_substr($haystack, 0, 400), true));
    }
}
function assert_not_contains(string $needle, string $haystack, string $message = ''): void {
    if (strpos($haystack, $needle) !== false) {
        throw new AssertionFailed(($message !== '' ? "$message\n" : '') . "  did not expect: " . var_export($needle, true));
    }
}
function fixture(string $name): string {
    return file_get_contents(__DIR__ . '/fixtures/' . $name);
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = [];
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $tests = require $file;
    $suite = basename($file, '.php');
    foreach ($tests as $name => $test) {
        $label = "$suite: $name";
        if ($filter !== '' && stripos($label, $filter) === false) {
            continue;
        }
        try {
            $test();
            $passed++;
            echo "  \u{2713} $label\n";
        } catch (Throwable $e) {
            $failed[] = $label;
            echo "  \u{2717} $label\n" . ($e instanceof AssertionFailed ? '' : '  ' . get_class($e) . ': ') . $e->getMessage() . "\n";
        }
    }
}

echo "\n$passed passed, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
