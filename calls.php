<?php
// All Support Calls: search, sort, pagination, date/customer/supporter/type filters.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/calls_filter.php';
$me = require_login();

const PER_PAGE = 25;
[$sort, $dir] = sort_from($_GET, CALL_SORTS, 'date');

$flt = build_call_filter($_GET);
$_SESSION['calls_qs'] = http_build_query($_GET);
$f = $flt['f'];

$cnt = $conn->prepare('SELECT COUNT(*) ' . CALLS_FROM . " {$flt['where']}");
$cnt->execute($flt['params']);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / PER_PAGE));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

$rows = call_rows($flt, PER_PAGE, ($page - 1) * PER_PAGE, $sort, $dir);

$customers = customers_with_calls();
$supporters = supporters_with_calls();
$filtered = array_filter($f) !== [];

page_start('Support Calls', 'calls-all');
?>
<div class="page-head">
    <div>
        <h1>Support Calls</h1>
    </div>
</div>

<form class="card filters" method="get" data-live="call-results">
    <details class="filter-fold" open data-active="<?= count(array_filter($f)) ?>">
    <summary class="btn"><?= icon('tune') ?>Filters<?= count(array_filter($f)) ? ' (' . count(array_filter($f)) . ')' : '' ?></summary>
    <div class="filter-grid">
        <label class="field span-2"><span>Search</span><input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Task, comment, customer or supporter"></label>
        <label class="field"><span>Date from</span><input type="date" name="from" value="<?= h($f['from']) ?>"></label>
        <label class="field"><span>Date to</span><input type="date" name="to" value="<?= h($f['to']) ?>"></label>
        <label class="field"><span>Customer</span>
            <select name="customer" data-searchable><option value="">All customers</option>
                <?php foreach ($customers as $c): ?><option value="<?= h($c['ac_code']) ?>" <?= $f['customer'] === $c['ac_code'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span>Supporter</span>
            <select name="supporter" data-searchable><option value="">All supporters</option>
                <?php foreach ($supporters as $s): ?><option value="<?= h($s['code']) ?>" <?= $f['supporter'] === $s['code'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span>Type</span>
            <select name="type" data-searchable><option value="">All</option>
                <?php foreach (['Online', 'Onsite'] as $t): ?><option <?= $f['type'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span>Status</span>
            <select name="status" data-searchable><option value="">All</option>
                <?php foreach (CALL_STATUSES as $k => $t): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
            </select></label>
    </div>
    </details>
    <input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="dir" value="<?= strtolower($dir) ?>">
    <div class="filter-actions">
        <span class="live-status muted small" aria-live="polite"></span>
        <a class="btn" href="calls.php"><?= icon('restart_alt') ?>Reset</a>
        <noscript><button class="btn btn-primary"><?= icon('filter_alt') ?>Apply filters</button></noscript>
    </div>
</form>

<section class="card card-flush" id="call-results">
    <div class="table-head">
        <div class="row-gap"><h2 class="card-title">All Support Calls</h2><span class="chip mono"><?= number_format($total) ?> <?= $filtered ? 'matching' : 'records' ?></span></div>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><?= icon('inbox') ?><p><?= $filtered ? 'No support calls match these filters.' : 'No support calls recorded yet.' ?></p></div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table table-cards">
            <thead><tr>
                <?= sort_th('date', 'Date', $sort, $dir) ?>
                <?= sort_th('customer', 'Customer', $sort, $dir) ?>
                <?= sort_th('supporter', 'Supporter', $sort, $dir) ?>
                <?= sort_th('type', 'Type', $sort, $dir) ?>
                <?= sort_th('status', 'Status', $sort, $dir) ?>
                <th>Task</th>
                <?= sort_th('created', 'Created', $sort, $dir, 'c-created') ?>
                <th class="right">Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="mono nowrap c-date"><?= h(fmt_date($r['doc_date'])) ?></td>
                    <td class="strong c-cust"><a href="customer_view.php?code=<?= h($r['ac_code']) ?>"><?= h($r['customer_name']) ?></a></td>
                    <td class="nowrap c-sup"><span class="mini-avatar"><?= h(initial($r['supporter_name'])) ?></span><?= h($r['supporter_name']) ?></td>
                    <td class="c-type"><?= type_badge($r['type']) ?></td>
                    <td class="c-status"><?= call_status_badge($r['status']) ?></td>
                    <td class="task c-task"><?= h($r['task_completed']) ?></td>
                    <td class="mono small muted nowrap c-created"><?= h(fmt_datetime($r['user_date'])) ?></td>
                    <td class="right nowrap c-actions"><a class="btn btn-sm" href="call_view.php?id=<?= (int) $r['doc_id'] ?>">View</a>
                        <?php if (can_edit_call($r)): ?><a class="btn btn-sm" href="call_add.php?id=<?= (int) $r['doc_id'] ?>">Edit</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pager($page, $pages, $total, count($rows), 'calls') ?>
    <?php endif; ?>
</section>
<?php page_end();
