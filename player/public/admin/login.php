<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';

admin_session_start();
admin_headers();

if (admin_is_signed_in()) {
    header('Location: index.php');
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // A wrong password costs a moment, which is enough to make guessing at
    // scale impractical without any extra machinery.
    usleep(300000);
    if (admin_check_password((string) ($_POST['user'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        admin_sign_in();
        header('Location: index.php');
        exit;
    }
    $error = admin_password_is_set()
        ? 'Wrong user name or password.'
        : 'This server has no usable admin password. The "hash" in the configuration '
          . 'must be what php tools/admin-password.php prints — not a password, and not '
          . 'a hash copied from somewhere else.';
}

admin_head('Sign in', false);
?>
<div class="login">
  <h2>Sign in</h2>
  <p class="lead">The courses and the students of this server.</p>
  <?php if ($error !== null): ?><div class="notice bad"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="card">
    <label for="user">User</label>
    <input id="user" name="user" type="text" autocomplete="username" autofocus>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password">
    <p><button class="btn primary" type="submit">Sign in</button></p>
  </form>
</div>
<?php admin_foot();
