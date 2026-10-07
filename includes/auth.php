<?php
/**
 * Single-admin login. config.php calls auth_require_login() on load, so every
 * page and endpoint is protected without opting in; login.php and logout.php
 * opt out by defining AUTH_PUBLIC_PAGE first, and CLI scripts are exempt.
 *
 * The account lives in config.local.php as admin_user + admin_password_hash
 * (from password_hash()). Changing either logs out existing sessions.
 */

const AUTH_SESSION_NAME = 'leadgen_session';
const AUTH_IDLE_TIMEOUT = 12 * 3600;  // seconds of inactivity before re-login
const AUTH_MAX_FAILURES = 5;          // failed logins per IP ...
const AUTH_LOCKOUT_SECONDS = 15 * 60; // ... within this window locks that IP out

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) AUTH_IDLE_TIMEOUT);
    session_name(AUTH_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_is_configured(): bool {
    $c = app_config();
    $hash = (string) ($c['admin_password_hash'] ?? '');
    return (string) ($c['admin_user'] ?? '') !== '' && password_get_info($hash)['algoName'] !== 'unknown';
}

/** Ties a session to the current account, so editing the user or hash in config invalidates it. */
function auth_account_key(): string {
    $c = app_config();
    return hash('sha256', ($c['admin_user'] ?? '') . "\0" . ($c['admin_password_hash'] ?? ''));
}

/** The logged-in username, or null. Needs an active session; refreshes the idle timer. */
function auth_user(): ?string {
    $user = $_SESSION['auth_user'] ?? null;
    if ($user === null
        || time() - (int) ($_SESSION['auth_seen'] ?? 0) > AUTH_IDLE_TIMEOUT
        || !hash_equals(auth_account_key(), (string) ($_SESSION['auth_key'] ?? ''))) {
        return null;
    }
    $_SESSION['auth_seen'] = time();
    return $user;
}

function auth_require_login(): void {
    if (PHP_SAPI === 'cli' || defined('AUTH_PUBLIC_PAGE')) {
        return;
    }
    auth_start_session();
    $loggedIn = auth_user() !== null;
    // Release the session lock: long-running pages (bulk analyze, imports)
    // would otherwise block every other request, including status polling.
    session_write_close();
    if ($loggedIn) {
        return;
    }

    // Browser navigation goes to the login form; fetch()/POST calls get a 401
    // they can show, rather than a login page's HTML.
    $isPageLoad = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false;
    if ($isPageLoad) {
        $here = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
        if (($_SERVER['QUERY_STRING'] ?? '') !== '') {
            $here .= '?' . $_SERVER['QUERY_STRING'];
        }
        header('Location: login.php?next=' . rawurlencode($here));
        exit;
    }
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Your session has expired. Reload the page and log in again.\n");
}

function auth_attempt(string $user, string $password): bool {
    if (!auth_is_configured()) {
        return false;
    }
    $c = app_config();
    // Always run password_verify so a wrong username takes as long as a wrong password.
    $passwordOk = password_verify($password, (string) $c['admin_password_hash']);
    if (!$passwordOk || !hash_equals((string) $c['admin_user'], $user)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION = [
        'auth_user' => $user,
        'auth_seen' => time(),
        'auth_key' => auth_account_key(),
    ];
    return true;
}

function auth_logout(): void {
    auth_start_session();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $p['path'],
        'secure' => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'],
    ]);
    session_destroy();
}

/**
 * Failed-login bookkeeping per IP, kept in a temp file. $op: 'count' returns
 * the failures inside the lockout window, 'add' records one, 'clear' resets.
 * Best-effort: if the file can't be opened, nothing is throttled.
 */
function auth_failures(string $ip, string $op = 'count'): int {
    $fh = @fopen(sys_get_temp_dir() . '/leadgen_login_' . md5(__DIR__) . '.json', 'c+');
    if (!$fh) {
        return 0;
    }
    flock($fh, LOCK_EX);
    $data = json_decode((string) stream_get_contents($fh), true);
    $data = is_array($data) ? $data : [];
    $cutoff = time() - AUTH_LOCKOUT_SECONDS;
    foreach ($data as $key => $times) {
        $data[$key] = array_values(array_filter((array) $times, function ($t) use ($cutoff) { return $t > $cutoff; }));
        if (!$data[$key]) {
            unset($data[$key]);
        }
    }
    if ($op === 'add') {
        $data[$ip][] = time();
    } elseif ($op === 'clear') {
        unset($data[$ip]);
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN);
    fclose($fh);
    return count($data[$ip] ?? []);
}

/** Where to go after login: only a page of this app (e.g. "view.php?id=3"), never an outside URL. */
function auth_safe_next(string $next): string {
    if (preg_match('/^[A-Za-z0-9_-]+\.php(\?\S*)?$/', $next) && !preg_match('/^(login|logout)\.php/', $next)) {
        return $next;
    }
    return 'index.php';
}
