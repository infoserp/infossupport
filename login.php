<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user()) {
    redirect(current_branch() ? 'dashboard.php' : 'branch.php');
}

$error = '';
$username = trim($_POST['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = authenticate($username, (string) ($_POST['password'] ?? ''));
    if ($u) {
        login_user($u);
        redirect('branch.php');
    }
    $error = 'Incorrect username or password.';
}

auth_page_start('Sign in');
?>
<div class="auth-card">
    <h1>Sign in</h1>
    <p class="muted">Use your ERP username and password.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= icon('error') ?><div><?= h($error) ?></div></div><?php endif; ?>
    <form method="post" class="form-stack">
        <?= csrf_field() ?>
        <label class="field"><span>Username</span><input name="username" required autocapitalize="none" value="<?= h($username) ?>" autofocus autocomplete="username"></label>
        <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary btn-block"><?= icon('login') ?>Sign in</button>
    </form>
</div>
<?php auth_page_end();
