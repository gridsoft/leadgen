<?php
define('AUTH_PUBLIC_PAGE', true);
require_once __DIR__ . '/config.php';

auth_start_session();
$next = auth_safe_next((string) ($_POST['next'] ?? $_GET['next'] ?? ''));

if (auth_user() !== null) {
    header('Location: ' . $next);
    exit;
}

$configured = auth_is_configured();
$error = '';
$username = '';

if ($configured && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $username = trim((string) ($_POST['username'] ?? ''));
    if (auth_failures($ip) >= AUTH_MAX_FAILURES) {
        $error = 'Too many failed attempts. Try again in ' . (AUTH_LOCKOUT_SECONDS / 60) . ' minutes.';
    } elseif (auth_attempt($username, (string) ($_POST['password'] ?? ''))) {
        auth_failures($ip, 'clear');
        header('Location: ' . $next);
        exit;
    } else {
        auth_failures($ip, 'add');
        sleep(1);
        $error = 'Wrong username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Log in — Leadgen</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="login-page">
  <form method="post" action="login.php" class="card login-card">
    <div class="sidebar-brand"><span class="brand-text">Leadgen</span></div>
    <?php if (!$configured): ?>
      <div class="notice">
        Login isn't set up yet. Add <code>admin_user</code> and <code>admin_password_hash</code>
        to <code>config.local.php</code> — create the hash with <code>php make_password_hash.php</code>.
      </div>
    <?php else: ?>
      <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" autocomplete="username" autocapitalize="none" required autofocus>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
      <button type="submit">Log in</button>
    <?php endif; ?>
  </form>
</main>
</body>
</html>
