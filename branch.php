<?php
// Branch selection after login. 
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$u = current_user();
if (!$u) {
    redirect('login.php');
}
$branches = $_SESSION['branches'] ?? [];
if (!$branches) {
    $_SESSION = [];
    redirect('login.php');
}

$choose = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($branches as $b) {
        if ($b['branch_code'] === ($_POST['branch_code'] ?? '')) {
            $choose = $b;
        }
    }
    if (!$choose) {
        flash('error', 'Choose one of your branches.');
        redirect('branch.php');
    }
} elseif (count($branches) === 1) {
    $choose = $branches[0];
}

if ($choose) {
    $_SESSION['branch'] = $choose;
    $next = $_SESSION['after_login'] ?? 'dashboard.php';   // e.g. back to a form whose login had expired
    unset($_SESSION['after_login']);
    redirect($next);
}

$current = current_branch()['branch_code'] ?? '';
auth_page_start('Choose branch');
?>
<div class="auth-card">
    <h1>Choose branch</h1>
    <p class="muted">Welcome, <?= h($u['name']) ?>.</p>
    <form method="post" class="form-stack">
        <?= csrf_field() ?>
        <?php foreach ($branches as $b): ?>
            <button class="btn btn-block<?= $b['branch_code'] === $current ? ' btn-primary' : '' ?>" name="branch_code" value="<?= h($b['branch_code']) ?>">
                <?= icon('store') ?><?= h($b['branch_name']) ?>
            </button>
        <?php endforeach; ?>
    </form>
    <form action="logout.php" method="post" class="mt">
        <?= csrf_field() ?>
        <button class="btn btn-block"><?= icon('logout') ?>Sign out</button>
    </form>
</div>
<?php auth_page_end();
