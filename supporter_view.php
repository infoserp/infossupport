<?php
// Calls recorded by one supporter in the current branch.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();
$b = current_branch();

const PER_PAGE = 25;
$code = trim((string) ($_GET['code'] ?? ''));

// Name and role from the ERP; for someone no longer there, the name stored on their calls.
$u = null;
if (valid_user_code($code)) {
    $lookups = [
        ["SELECT TOP 1 m.emp_code AS code, e.emp_name AS name, 'admin' AS role FROM admin_emp_map m
            JOIN " . erp('emp_master') . ' e ON e.company_code = m.company_code AND e.emp_code = m.emp_code
            WHERE m.company_code = ? AND m.emp_code = ?', [$b['company_code'], $code]],
        ['SELECT TOP 1 user_id AS code, user_name AS name, \'admin\' AS role FROM ' . erp('branch_user') . " WHERE user_id = ? AND user_type = 'A'", [$code]],
        ['SELECT emp_code AS code, emp_name AS name, \'supporter\' AS role FROM ' . erp('emp_master') . " WHERE emp_code = ? AND company_code = ? AND emp_type = 'U'", [$code, $b['company_code']]],
        ['SELECT TOP 1 emp_code AS code, supporter_name AS name, NULL AS role FROM v_support_calls WHERE emp_code = ? AND company_code = ? ORDER BY doc_id DESC', [$code, $b['company_code']]],
    ];
    foreach ($lookups as [$sql, $args]) {
        $st = $conn->prepare($sql);
        $st->execute($args);
        if ($u = $st->fetch()) {
            $u['code'] = trim($u['code']);
            $u['name'] = trim((string) $u['name']) ?: $u['code'];
            break;
        }
    }
}
if (!$u) {
    flash('error', 'Supporter not found.');
    redirect('supporters.php');
}

$scope = [$b['company_code'], $b['branch_code'], $u['code']];
$sum = $conn->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN type = 'Online' THEN 1 ELSE 0 END) AS online,
        SUM(CASE WHEN type = 'Onsite' THEN 1 ELSE 0 END) AS onsite
    FROM v_support_calls WHERE company_code = ? AND branch_code = ? AND emp_code = ?");
$sum->execute($scope);
$s = array_map('intval', $sum->fetch());

$pages = max(1, (int) ceil($s['total'] / PER_PAGE));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$st = $conn->prepare('SELECT doc_id, doc_date, type, status, task_completed, ac_code, customer_name
    FROM v_support_calls WHERE company_code = ? AND branch_code = ? AND emp_code = ?
    ORDER BY doc_date DESC, doc_id DESC
    OFFSET ' . (($page - 1) * PER_PAGE) . ' ROWS FETCH NEXT ' . PER_PAGE . ' ROWS ONLY');
$st->execute($scope);
$rows = $st->fetchAll();

$roleLabel = ['admin' => 'Administrator', 'supporter' => 'Supporter'][$u['role'] ?? ''] ?? 'No longer in ERP';
page_start($u['name'], 'supporters');
?>
<div class="page-head">
    <div>
        <a class="back" href="supporters.php"><?= icon('arrow_back') ?>Supporters</a>
        <h1 class="row-gap"><?= h($u['name']) ?></h1>
        <p class="muted small"><span class="mono"><?= h($u['code']) ?></span> · <?= $roleLabel ?></p>
    </div>
    <div class="actions"><a class="btn" href="reports.php?supporter=<?= h(urlencode($u['code'])) ?>"><?= icon('bar_chart') ?>Report for this supporter</a></div>
</div>

<section class="kpis kpis-3">
    <div class="kpi"><div class="kpi-head"><span class="label-caps">Total recorded calls</span><?= icon('call_log') ?></div><div class="kpi-value"><?= number_format($s['total']) ?></div></div>
    <div class="kpi"><div class="kpi-head"><span class="label-caps">Online</span><?= icon('desktop_windows') ?></div><div class="kpi-value"><?= number_format($s['online']) ?></div></div>
    <div class="kpi"><div class="kpi-head"><span class="label-caps">Onsite</span><?= icon('location_on', 'text-red') ?></div><div class="kpi-value"><?= number_format($s['onsite']) ?></div></div>
</section>

<section class="card card-flush">
    <div class="table-head"><h2 class="card-title">Recorded support calls</h2></div>
    <?php if (!$rows): ?>
        <div class="empty"><?= icon('inbox') ?><p>No support calls recorded by this supporter.</p></div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Date</th><th>Customer</th><th>Type</th><th>Status</th><th>Task</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="mono nowrap"><?= h(fmt_date($r['doc_date'])) ?></td>
                    <td class="strong"><a href="customer_view.php?code=<?= h($r['ac_code']) ?>"><?= h($r['customer_name']) ?></a></td>
                    <td><?= type_badge($r['type']) ?></td>
                    <td><?= call_status_badge($r['status']) ?></td>
                    <td class="task"><?= h($r['task_completed']) ?></td>
                    <td class="right"><a class="btn btn-sm" href="call_view.php?id=<?= (int) $r['doc_id'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pager($page, $pages, $s['total'], count($rows), 'calls') ?>
    <?php endif; ?>
</section>
<?php page_end();
