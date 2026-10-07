<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ProspectStore.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $businessName = trim($_POST['business_name'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($businessName === '') {
        $error = 'Business name is required.';
    } elseif ($website === '' && $phone === '' && $email === '') {
        $error = 'At least one of website, phone, or email is required.';
    } else {
        $pdo = get_db();
        $result = [
            'name' => $businessName,
            'website' => $website ?: null,
            'phone' => $phone ?: null,
            'email' => $email ?: null,
            'address' => $_POST['address'] ?: null,
            'city' => $_POST['city'] ?: null,
            'place_id' => $_POST['google_place_id'] ?: null,
            'review_count' => $_POST['review_count'] !== '' ? $_POST['review_count'] : null,
        ];
        $saveResult = ProspectStore::save(
            $pdo, $result, trim($_POST['category'] ?? ''), $_POST['city'] ?? '', 'manual'
        );
        $id = $saveResult['id'];

        header('Location: ' . ($id ? "analyze.php?id=$id" : 'index.php'));
        exit;
    }
}
?>
<?php
$pageTitle = 'Add prospect';
$activeNav = 'add';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
<h2>Add prospect manually</h2>
<?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post">
  <label for="business_name">Business name</label>
  <input type="text" id="business_name" name="business_name" required value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>">
  <label for="category">Business type (optional, e.g. dentist)</label>
  <input type="text" id="category" name="category" value="<?= htmlspecialchars($_POST['category'] ?? '') ?>">
  <label for="website">Website (optional)</label>
  <input type="url" id="website" name="website" placeholder="https://example.com" value="<?= htmlspecialchars($_POST['website'] ?? '') ?>">
  <label for="city">City</label>
  <input type="text" id="city" name="city" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
  <label for="phone">Phone (optional)</label>
  <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
  <label for="email">Email (optional)</label>
  <input type="text" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
  <label for="review_count">Review count (optional)</label>
  <input type="text" id="review_count" name="review_count" value="<?= htmlspecialchars($_POST['review_count'] ?? '') ?>">
  <input type="hidden" name="address" value="">
  <input type="hidden" name="google_place_id" value="">
  <button type="submit">Add &amp; analyze</button>
</form>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
