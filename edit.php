<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ContactStatus.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$pdo = get_db();

$stmt = $pdo->prepare('SELECT * FROM prospects WHERE id = :id');
$stmt->execute(['id' => $id]);
$prospect = $stmt->fetch();

if (!$prospect) {
    http_response_code(404);
    die('Prospect not found.');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $businessName = trim($_POST['business_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    $city = trim($_POST['city'] ?? '');

    if ($businessName === '') {
        $error = 'Business name is required.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look valid.';
    } else {
        $status = ContactStatus::compute($website ?: null, $phone ?: null, $email ?: null);

        $update = $pdo->prepare(
            'UPDATE prospects SET business_name = :business_name, category = :category, city = :city, website = :website,
                phone = :phone, contact_email = :contact_email, contact_status = :contact_status
             WHERE id = :id'
        );
        $update->execute([
            'business_name' => $businessName,
            'category' => $category ?: null,
            'city' => $city ?: null,
            'website' => $website ?: null,
            'phone' => $phone ?: null,
            'contact_email' => $email ?: null,
            'contact_status' => $status,
            'id' => $id,
        ]);

        header('Location: view.php?id=' . $id);
        exit;
    }

    // Keep the rejected input visible on the form after a validation error.
    $prospect = array_merge($prospect, [
        'business_name' => $businessName,
        'category' => $category,
        'city' => $city,
        'website' => $website,
        'phone' => $phone,
        'contact_email' => $email,
    ]);
}
?>
<?php
$pageTitle = 'Edit ' . $prospect['business_name'];
$activeNav = 'dashboard';
$topbarActions = '<a class="btn btn-secondary" href="view.php?id=' . $id . '">Back to prospect</a>';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
<h2>Edit prospect</h2>
<?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post">
  <input type="hidden" name="id" value="<?= $id ?>">
  <label for="business_name">Business name</label>
  <input type="text" id="business_name" name="business_name" required value="<?= htmlspecialchars($prospect['business_name']) ?>">
  <label for="category">Business type</label>
  <input type="text" id="category" name="category" value="<?= htmlspecialchars($prospect['category'] ?? '') ?>">
  <label for="city">City</label>
  <input type="text" id="city" name="city" value="<?= htmlspecialchars($prospect['city'] ?? '') ?>">
  <label for="website">Website</label>
  <input type="url" id="website" name="website" placeholder="https://example.com" value="<?= htmlspecialchars($prospect['website'] ?? '') ?>">
  <label for="phone">Phone</label>
  <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($prospect['phone'] ?? '') ?>">
  <label for="contact_email">Email</label>
  <input type="text" id="contact_email" name="contact_email" value="<?= htmlspecialchars($prospect['contact_email'] ?? '') ?>">
  <button type="submit">Save</button>
</form>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
