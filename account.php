<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$me = require_login();
$b = current_branch();

page_start('My account', 'account');
?>
<div class="page-head">
    <div><h1>My account</h1></div>
    <?php if (count($_SESSION['branches'] ?? []) > 1): ?>
    <div class="actions"><a class="btn" href="branch.php"><?= icon('store') ?>Switch branch</a></div>
    <?php endif; ?>
</div>
<section class="card form-narrow">
    <dl class="dl">
        <dt>Name</dt><dd><?= h($me['name']) ?></dd>
        <dt>Username</dt><dd class="mono"><?= h($me['login']) ?></dd>
        <?php if (strcasecmp($me['login'], $me['code']) !== 0): ?><dt>Employee code</dt><dd class="mono"><?= h($me['code']) ?></dd><?php endif; ?>
        <dt>Role</dt><dd><?= $me['role'] === 'admin' ? 'Administrator' : 'Supporter' ?></dd>
        <dt>Branch</dt><dd><?= h($b['branch_name']) ?></dd>
    </dl>
    <p class="muted small">Your name, username and password are managed in the ERP.</p>
</section>
<?php page_end();
