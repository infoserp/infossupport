<?php
// Supporters: the ERP logins for this branch, with the calls each recorded here.
// Accounts are managed in the ERP; anyone who has left it still appears while they have calls.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();
$b = current_branch();

$people = [];   // keyed by lower-case code
$add = function (string $code, string $name, ?string $role) use (&$people): void {
    $k = strtolower(trim($code));
    $people[$k] ??= ['code' => trim($code), 'name' => trim($name) ?: trim($code), 'role' => $role, 'calls' => 0, 'last_call' => null];
};

$st = $conn->prepare('SELECT bu.user_id, bu.user_name, m.emp_code, e.emp_name FROM ' . erp('branch_user') . ' bu
    LEFT JOIN admin_emp_map m ON m.company_code = ? AND m.user_id = bu.user_id
    LEFT JOIN ' . erp('emp_master') . " e ON e.company_code = m.company_code AND e.emp_code = m.emp_code
    WHERE bu.branch_code = ? AND bu.user_type = 'A'");
$st->execute([$b['company_code'], $b['branch_code']]);
foreach ($st as $r) {
    $r['emp_code'] ? $add($r['emp_code'], (string) $r['emp_name'], 'admin') : $add($r['user_id'], (string) $r['user_name'], 'admin');
}

$st = $conn->prepare('SELECT emp_code, emp_name FROM ' . erp('emp_master') . "
    WHERE company_code = ? AND branch_code = ? AND emp_type = 'U' AND ISNULL(active, 'Y') <> 'N'");
$st->execute([$b['company_code'], $b['branch_code']]);
foreach ($st as $r) $add($r['emp_code'], (string) $r['emp_name'], 'supporter');

$st = $conn->prepare('SELECT emp_code, MAX(supporter_name) AS name, COUNT(*) AS n, MAX(doc_date) AS last_call
    FROM v_support_calls WHERE company_code = ? AND branch_code = ? GROUP BY emp_code');
$st->execute([$b['company_code'], $b['branch_code']]);
foreach ($st as $r) {
    $add($r['emp_code'], $r['name'], null);
    $k = strtolower(trim($r['emp_code']));
    $people[$k]['calls'] = (int) $r['n'];
    $people[$k]['last_call'] = $r['last_call'];
}
uasort($people, fn($x, $y) => strcasecmp($x['name'], $y['name']));

page_start('Supporters', 'supporters');
?>
<div class="page-head">
    <div>
        <h1>Supporters</h1>
    </div>
</div>

<section class="card card-flush">
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Name</th><th>Code</th><th>Role</th><th class="right">Recorded calls</th><th>Last support</th><th class="right">Action</th></tr></thead>
            <tbody>
            <?php foreach ($people as $u): ?>
                <tr class="<?= $u['role'] ? '' : 'row-muted' ?>">
                    <td class="strong nowrap"><span class="mini-avatar"><?= h(initial($u['name'])) ?></span><a href="supporter_view.php?code=<?= h(urlencode($u['code'])) ?>"><?= h($u['name']) ?></a></td>
                    <td class="mono"><?= h($u['code']) ?></td>
                    <td><?php if ($u['role']): ?><span class="chip<?= $u['role'] === 'admin' ? ' chip-dark' : '' ?>"><?= $u['role'] === 'admin' ? 'Administrator' : 'Supporter' ?></span><?php else: ?><span class="badge badge-inactive"><span class="dot"></span>No longer in ERP</span><?php endif; ?></td>
                    <td class="right mono"><?= $u['calls'] ?></td>
                    <td class="mono small nowrap"><?= h(fmt_date($u['last_call'])) ?: '<span class="muted">—</span>' ?></td>
                    <td class="right nowrap"><a class="btn btn-sm" href="supporter_view.php?code=<?= h(urlencode($u['code'])) ?>">History</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_end();
