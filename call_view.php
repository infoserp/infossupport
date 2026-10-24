<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/calls_filter.php';
require_login();
$b = current_branch();

$st = $conn->prepare('SELECT ' . CALLS_COLUMNS . ', sc.last_username ' . CALLS_FROM . '
    WHERE sc.doc_id = ? AND sc.company_code = ? AND sc.branch_code = ?');
$st->execute([(int) ($_GET['id'] ?? 0), $b['company_code'], $b['branch_code']]);
$r = $st->fetch();
if (!$r) {
    http_response_code(404);
    flash('error', 'Support call not found.');
    redirect(calls_list_url());
}
$cust = find_customer($r['ac_code']);

page_start('Support Call #' . $r['doc_id'], 'calls-all');
?>
<div class="page-head">
    <div>
        <a class="back" href="<?= h(calls_list_url()) ?>"><?= icon('arrow_back') ?>All Support Calls</a>
        <h1>Support Call #<?= (int) $r['doc_id'] ?></h1>
    </div>
    <div class="actions">
        <?php if (can_edit_call($r)): ?>
            <form method="post" action="call_delete.php" class="inline">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['doc_id'] ?>">
                <button class="btn btn-danger-outline" data-confirm="Support call #<?= (int) $r['doc_id'] ?> for <?= h($r['customer_name']) ?> will be removed permanently. This cannot be undone."
                        data-confirm-title="Delete this support call?" data-confirm-ok="Delete call" data-confirm-danger><?= icon('delete') ?>Delete</button>
            </form>
            <span class="actions-gap"></span>
            <a class="btn btn-primary" href="call_add.php?id=<?= (int) $r['doc_id'] ?>"><?= icon('edit') ?>Edit</a>
        <?php endif; ?>
    </div>
</div>
<section class="card record">
    <dl class="dl dl-wide">
        <dt>Support Date</dt><dd class="mono"><?= h(fmt_long($r['doc_date'])) ?></dd>
        <dt>Customer</dt><dd><a href="customer_view.php?code=<?= h($r['ac_code']) ?>"><?= h($r['customer_name']) ?></a> <?= $cust && $cust['status'] !== 'Active' ? status_badge($cust['status']) : '' ?></dd>
        <dt>Supporter</dt><dd><a href="supporter_view.php?code=<?= h(urlencode($r['emp_code'])) ?>"><?= h($r['supporter_name']) ?></a></dd>
        <dt>Type</dt><dd><?= type_badge($r['type']) ?></dd>
        <?php if ($r['job_no'] !== null && $r['job_no'] !== ''): ?><dt>Job No</dt><dd class="mono"><?= h($r['job_no']) ?></dd><?php endif; ?>
        <?php if ($r['time_from']):
            $mins = intdiv(strtotime($r['time_to']) - strtotime($r['time_from']), 60); ?>
        <dt>Time</dt><dd class="mono"><?= h(substr($r['time_from'], 0, 5)) ?> – <?= h(substr($r['time_to'], 0, 5)) ?>
            <span class="chip"><?= ($mins >= 60 ? intdiv($mins, 60) . ' h ' : '') . ($mins % 60 ? $mins % 60 . ' m' : '') ?></span></dd>
        <?php endif; ?>
        <dt>Status</dt><dd><?= call_status_badge($r['status']) ?></dd>
        <dt>Task</dt><dd class="pre"><?= h($r['task_completed']) ?></dd>
        <?php if ($r['comment'] !== null && $r['comment'] !== ''): ?><dt>Comment</dt><dd class="pre"><?= h($r['comment']) ?></dd><?php endif; ?>
        <dt>Created Date/Time</dt><dd class="mono muted"><?= h(fmt_datetime($r['user_date'])) ?></dd>
        <?php if ($r['last_userdate']): ?>
        <dt>Last Edited</dt><dd class="mono muted"><?= h(fmt_datetime($r['last_userdate'])) ?> <span class="chip">by <?= h($r['last_username'] ?? $r['supporter_name']) ?></span></dd>
        <?php endif; ?>
    </dl>
</section>
<?php page_end();
