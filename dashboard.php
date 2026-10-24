<?php
// Common dashboard for the current branch — identical for administrators and supporters.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/calls_filter.php';
$me = require_login();
$b = current_branch();
$scope = [$b['company_code'], $b['branch_code']];

// Mark an open call completed from the dashboard (same rule as editing: its supporter or an admin),
// and undo it straight after: the call gets back its previous status and last-edit details.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'undo') {
        $u = $_SESSION['undo_call'] ?? null;
        unset($_SESSION['undo_call']);
        // Only if nobody has changed the call since it was completed here.
        $st = $u && $u['id'] === $id ? $conn->prepare("UPDATE support_calls SET status = ?, last_userdate = ?, last_userid = ?
            WHERE doc_id = ? AND status = 'C' AND last_userid = ? AND last_userdate = ?") : null;
        if ($st && $st->execute([$u['status'], $u['last_userdate'], $u['last_userid'], $id, $me['code'], $u['done_at']]) && $st->rowCount()) {
            flash('success', 'Support call #' . $id . ' is open again.');
        } else {
            flash('error', 'Support call #' . $id . ' could not be reopened here (it was changed since). Edit the call instead.');
        }
        redirect('dashboard.php#open-calls');
    }
    $st = $conn->prepare("SELECT doc_id, emp_code, status, last_userdate, last_userid FROM v_support_calls
        WHERE doc_id = ? AND company_code = ? AND branch_code = ? AND status IN ('P', 'O')");
    $st->execute(array_merge([$id], $scope));
    $c = $st->fetch();
    if ($c && can_edit_call($c)) {
        $done = $conn->prepare("UPDATE support_calls SET status = 'C', last_userdate = SYSDATETIME(), last_userid = ?
            OUTPUT INSERTED.last_userdate WHERE doc_id = ?");
        $done->execute([$me['code'], $c['doc_id']]);
        $_SESSION['undo_call'] = ['id' => (int) $c['doc_id'], 'status' => $c['status'], 'last_userdate' => $c['last_userdate'],
            'last_userid' => $c['last_userid'], 'done_at' => $done->fetchColumn(), 'shown' => false];
    } else {
        flash('error', 'That call is no longer open, or only its supporter or an administrator can complete it.');
    }
    redirect('dashboard.php#open-calls');
}
// The "completed" message with its Undo button is shown once, right after completing.
$undo = isset($_SESSION['undo_call']) && !$_SESSION['undo_call']['shown'] ? $_SESSION['undo_call'] : null;
if ($undo) {
    $_SESSION['undo_call']['shown'] = true;
}

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$k = $conn->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN doc_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS this_month,
        SUM(CASE WHEN type = 'Online' THEN 1 ELSE 0 END) AS online,
        SUM(CASE WHEN type = 'Onsite' THEN 1 ELSE 0 END) AS onsite,
        SUM(CASE WHEN status = 'P' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'O' THEN 1 ELSE 0 END) AS ongoing
    FROM v_support_calls WHERE company_code = ? AND branch_code = ?");
$k->execute(array_merge([$monthStart, $monthEnd], $scope));
$kpi = array_map('intval', $k->fetch());
$pct = function (int $n) use ($kpi) {
    return $kpi['total'] ? round($n * 100 / $kpi['total']) : 0;
};

// Calls by month: last 12 months including the current one, zero-filled.
$from = date('Y-m-01', strtotime('-11 months', strtotime($monthStart)));
$st = $conn->prepare('SELECT YEAR(doc_date) AS y, MONTH(doc_date) AS m, COUNT(*) AS n
    FROM v_support_calls WHERE company_code = ? AND branch_code = ? AND doc_date >= ? AND doc_date <= ?
    GROUP BY YEAR(doc_date), MONTH(doc_date)');
$st->execute(array_merge($scope, [$from, $monthEnd]));
$byMonthRaw = [];
foreach ($st as $r) {
    $byMonthRaw[sprintf('%04d-%02d', $r['y'], $r['m'])] = (int) $r['n'];
}
$months = [];
for ($i = 0; $i < 12; $i++) {
    $ts = strtotime("+$i months", strtotime($from));
    $months[] = ['label' => date('M', $ts), 'full' => date('F Y', $ts), 'n' => $byMonthRaw[date('Y-m', $ts)] ?? 0, 'current' => date('Y-m', $ts) === date('Y-m')];
}
$maxMonth = max(1, max(array_column($months, 'n')));
$sum12 = array_sum(array_column($months, 'n'));

$st = $conn->prepare('SELECT emp_code AS code, MAX(supporter_name) AS name, COUNT(*) AS n FROM v_support_calls
    WHERE company_code = ? AND branch_code = ? GROUP BY emp_code ORDER BY n DESC, MAX(supporter_name)');
$st->execute($scope);
$bySupporter = $st->fetchAll();
$maxSup = max(1, (int) ($bySupporter[0]['n'] ?? 1));

$st = $conn->prepare('SELECT TOP 10 ' . CALLS_COLUMNS . ' ' . CALLS_FROM . ' WHERE sc.company_code = ? AND sc.branch_code = ?
    ORDER BY sc.doc_date DESC, sc.user_date DESC, sc.doc_id DESC');
$st->execute($scope);
$recent = $st->fetchAll();

// Open (Pending / Ongoing) calls, oldest first: supporters see their own, administrators the whole branch.
$sql = 'SELECT ' . CALLS_COLUMNS . ' ' . CALLS_FROM . " WHERE sc.company_code = ? AND sc.branch_code = ? AND sc.status IN ('P', 'O')";
$args = $scope;
if ($me['role'] !== 'admin') {
    $sql .= ' AND sc.emp_code = ?';
    $args[] = $me['code'];
}
$st = $conn->prepare($sql . ' ORDER BY sc.doc_date, sc.doc_id');
$st->execute($args);
$open = $st->fetchAll();

page_start('Dashboard', 'dashboard');
?>
<div class="page-head">
    <div>
        <h1>Support Dashboard</h1>
        <p class="muted"><?= h($b['branch_name']) ?> · overview of recorded support calls</p>
    </div>
</div>

<?php if ($undo): ?>
<div class="alert alert-success" role="status"><?= icon('check_circle') ?>
    <div class="grow">Support call #<?= $undo['id'] ?> marked completed.</div>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="id" value="<?= $undo['id'] ?>">
        <button class="btn btn-sm"><?= icon('undo') ?>Undo</button></form>
</div>
<?php endif; ?>

<section class="kpis kpis-tiles kpis-5">
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Total support calls</span><?= icon('call_log') ?></div>
        <div class="kpi-value"><?= number_format($kpi['total']) ?></div>
        <div class="kpi-foot"><span class="chip">All time</span></div>
    </div>
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Support calls this month</span><?= icon('calendar_month') ?></div>
        <div class="kpi-value"><?= number_format($kpi['this_month']) ?></div>
        <div class="kpi-foot"><span class="chip"><?= date('M Y') ?></span><span class="muted mono"><?= date('d M', strtotime($monthStart)) ?> – <?= date('d M', strtotime($monthEnd)) ?></span></div>
    </div>
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Online calls</span><?= icon('desktop_windows') ?></div>
        <div class="kpi-value"><?= number_format($kpi['online']) ?></div>
        <div class="kpi-foot"><span class="chip"><?= $pct($kpi['online']) ?>% share</span></div>
    </div>
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Onsite calls</span><?= icon('location_on', 'text-red') ?></div>
        <div class="kpi-value"><?= number_format($kpi['onsite']) ?></div>
        <div class="kpi-foot"><span class="chip chip-red"><?= $pct($kpi['onsite']) ?>% share</span></div>
    </div>
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Open calls</span><?= icon('pending_actions') ?></div>
        <div class="kpi-value"><?= number_format($kpi['pending'] + $kpi['ongoing']) ?></div>
        <div class="kpi-foot">
            <a class="chip" href="calls.php?status=P">Pending <?= number_format($kpi['pending']) ?></a>
            <a class="chip" href="calls.php?status=O">Ongoing <?= number_format($kpi['ongoing']) ?></a>
        </div>
    </div>
</section>

<?php if ($open): ?>
<section class="card card-flush" id="open-calls">
    <div class="table-head">
        <div class="row-gap"><?= icon('pending_actions') ?><h2 class="card-title"><?= $me['role'] === 'admin' ? 'Open calls' : 'My open calls' ?></h2><span class="chip mono"><?= count($open) ?></span></div>
    </div>
    <div class="table-scroll">
        <table class="table table-cards">
            <thead><tr><th>Date</th><th>Customer</th><th>Supporter</th><th>Status</th><th>Task</th><th class="right">Action</th></tr></thead>
            <tbody>
            <?php foreach ($open as $r): ?>
                <tr>
                    <td class="mono nowrap c-date"><?= h(fmt_date($r['doc_date'])) ?></td>
                    <td class="strong c-cust"><a href="customer_view.php?code=<?= h($r['ac_code']) ?>"><?= h($r['customer_name']) ?></a></td>
                    <td class="nowrap c-sup"><span class="mini-avatar"><?= h(initial($r['supporter_name'])) ?></span><?= h($r['supporter_name']) ?></td>
                    <td class="c-status"><?= call_status_badge($r['status']) ?></td>
                    <td class="task c-task"><?= h($r['task_completed']) ?></td>
                    <td class="right nowrap c-actions"><div class="actions">
                        <a class="btn btn-sm" href="call_view.php?id=<?= (int) $r['doc_id'] ?>">View</a>
                        <?php if (can_edit_call($r)): ?>
                        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['doc_id'] ?>">
                            <button class="btn btn-sm"><?= icon('task_alt') ?>Mark completed</button></form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="charts">
    <div class="card chart-month">
        <div class="card-head">
            <div><h2 class="card-title">Support Calls by Month</h2><span class="muted small">Last 12 months, by support date</span></div>
            <span class="chip"><?= number_format($sum12) ?> calls</span>
        </div>
        <div class="bars">
            <?php foreach ($months as $m): $hgt = $m['n'] ? max(2, round($m['n'] * 100 / $maxMonth)) : 0; ?>
                <div class="bar-col<?= $m['current'] ? ' current' : '' ?>" title="<?= h($m['full']) ?>: <?= $m['n'] ?> calls">
                    <span class="bar-val"><?= $m['n'] ?></span>
                    <div class="bar-track"><div class="bar" style="height: <?= $hgt ?>%"></div></div>
                    <span class="bar-label"><?= h($m['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card-foot mono small"><span><?= h($months[0]['full']) ?> – <?= h($months[11]['full']) ?></span><span class="text-red">Avg <?= number_format($sum12 / 12, 1) ?> calls/mo</span></div>
    </div>

    <div class="card chart-type">
        <div class="card-head"><div><h2 class="card-title">Online vs. Onsite</h2><span class="muted small">All recorded calls</span></div></div>
        <?php $on = $pct($kpi['online']); $os = $kpi['total'] ? 100 - $on : 0; ?>
        <div class="donut-wrap">
            <svg class="donut" viewBox="0 0 36 36" role="img" aria-label="Online <?= $on ?>%, Onsite <?= $os ?>%">
                <circle cx="18" cy="18" r="15.9155" class="donut-bg"/>
                <?php if ($kpi['total']): ?>
                <circle cx="18" cy="18" r="15.9155" class="donut-online" stroke-dasharray="<?= $on ?> <?= 100 - $on ?>" stroke-dashoffset="25"/>
                <circle cx="18" cy="18" r="15.9155" class="donut-onsite" stroke-dasharray="<?= $os ?> <?= 100 - $os ?>" stroke-dashoffset="<?= 25 - $on ?>"/>
                <?php endif; ?>
            </svg>
            <div class="donut-center"><strong><?= number_format($kpi['total']) ?></strong><span class="label-caps">Total</span></div>
        </div>
        <div class="legend">
            <div><span class="sw sw-online"></span>Online<b class="mono"><?= number_format($kpi['online']) ?> (<?= $on ?>%)</b></div>
            <div><span class="sw sw-onsite"></span>Onsite<b class="mono"><?= number_format($kpi['onsite']) ?> (<?= $os ?>%)</b></div>
        </div>
    </div>

    <div class="card chart-supporter">
        <div class="card-head"><div><h2 class="card-title">Calls by Supporter</h2><span class="muted small">All recorded calls</span></div><?= icon('leaderboard', 'muted') ?></div>
        <div class="hbars">
            <?php if (!$bySupporter): ?><p class="muted small">No support calls recorded yet.</p><?php endif; ?>
            <?php foreach ($bySupporter as $s): ?>
                <a class="hbar" href="supporter_view.php?code=<?= h(urlencode($s['code'])) ?>">
                    <div class="hbar-top"><span><?= h($s['name']) ?></span><span class="mono"><?= (int) $s['n'] ?> calls <small class="muted">(<?= $pct((int) $s['n']) ?>%)</small></span></div>
                    <div class="hbar-track"><div class="hbar-fill" style="width: <?= round($s['n'] * 100 / $maxSup) ?>%"></div></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="card card-flush">
    <div class="table-head">
        <div class="row-gap"><?= icon('history') ?><h2 class="card-title">Recent Support Calls</h2></div>
        <a class="link" href="calls.php">View all <?= icon('arrow_forward') ?></a>
    </div>
    <?php render_call_table($recent); ?>
</section>
<?php page_end();

