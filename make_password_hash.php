<?php
/**
 * Prints a hash for config.local.php's admin_password_hash (see includes/auth.php).
 *
 *   php make_password_hash.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line: php make_password_hash.php\n");
}

echo "Password: ";
$password = rtrim((string) fgets(STDIN), "\r\n");
if (strlen($password) < 10) {
    fwrite(STDERR, "Use at least 10 characters.\n");
    exit(1);
}

echo "\nPaste into config.local.php (single quotes — the hash contains \$ signs):\n\n";
echo "    'admin_password_hash' => '" . password_hash($password, PASSWORD_DEFAULT) . "',\n\n";
