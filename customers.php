<?php
// Customers (from the ERP): Active / No Support / Inactive / All.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();
$b = current_branch();

const PER_PAGE = 25;
$tabs = [
    'all'       => ['label' => 'All Customers', 'status' => null, 'nav' => 'customers'],
    'active'    => ['label' => 'Active Customers', 'status' => 'Active', 'nav' => 'customers-active'],
    'nosupport' => ['label' => 'Blocked', 'status' => 'NoSupport', 'nav' => 'customers-nosupport'],
    'inactive'  => ['label' => 'Inactive Customers', 'status' => 'Inactive', 'nav' => 'customers-inactive'],
];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'active';
$q = trim((string) ($_GET['q'] ?? ''));
$ns = $tab === 'nosupport';

$counts = ['all' => 0, 'Active' => 0, 'NoSupport' => 0, 'Inactive' => 0];
$st = $conn->prepare('SELECT ' . CUSTOMER_STATUS . ' AS status, COUNT(*) AS n ' . customer_from() . ' GROUP BY ' . CUSTOMER_STATUS);
$st->execute([$b['company_code']]);
foreach ($st as $r) {
    $counts[$r['status']] = (int) $r['n'];
    $counts['all'] += (int) $r['n'];
}

$where = [];
$params = [];
if ($tabs[$tab]['status']) {
    $where[] = 'c.status = ?';
    $params[] = $tabs[$tab]['status'];
}
if ($q !== '') {
    $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $q) . '%';
    $where[] = '(c.name LIKE ? OR c.ac_code LIKE ? OR c.contact_name LIKE ? OR c.contact_no LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$inner = 'SELECT ' . CUSTOMER_COLUMNS . ' ' . customer_from();

$cnt = $conn->prepare("SELECT COUNT(*) FROM ($inner) c $whereSql");
$cnt->execute([$b['company_code'], ...$params]);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / PER_PAGE));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

// Call counts are for the current branch; No Support applies to the customer as a whole.
$st = $conn->prepare("SELECT c.*,
        (SELECT COUNT(*) FROM support_calls sc WHERE sc.company_code = c.company_code AND sc.branch_code = ? AND sc.ac_code = c.ac_code) AS call_count,
        (SELECT MAX(doc_date) FROM support_calls sc WHERE sc.company_code = c.company_code AND sc.branch_code = ? AND sc.ac_code = c.ac_code) AS last_call,
        (SELECT TOP 1 reason FROM no_support_history n WHERE n.company_code = c.company_code AND n.ac_code = c.ac_code AND n.end_date IS NULL ORDER BY n.id DESC) AS ns_reason,
        (SELECT TOP 1 start_date FROM no_support_history n WHERE n.company_code = c.company_code AND n.ac_code = c.ac_code AND n.end_date IS NULL ORDER BY n.id DESC) AS ns_since,
        (SELECT TOP 1 dbo.fn_user_name(n.company_code, n.user_id) FROM no_support_history n WHERE n.company_code = c.company_code AND n.ac_code = c.ac_code AND n.end_date IS NULL ORDER BY n.id DESC) AS ns_by
    FROM ($inner) c $whereSql ORDER BY c.name
    OFFSET " . (($page - 1) * PER_PAGE) . ' ROWS FETCH NEXT ' . PER_PAGE . ' ROWS ONLY');
$st->execute([$b['branch_code'], $b['branch_code'], $b['company_code'], ...$params]);
$rows = $st->fetchAll();

page_start('Customers', $tabs[$tab]['nav']);
?>
<div class="page-head">
    <div>
        <h1>Customers</h1>
        <p class="muted">Support eligibility and support history.</p>
    </div>
    <div class="actions"><a class="btn btn-primary" href="customer_edit.php"><?= icon('person_add') ?>Add customer</a></div>
</div>

<div class="tabbar">
    <nav class="tabs">
        <?php foreach ($tabs as $key => $t):
            $n = $key === 'all' ? $counts['all'] : $counts[$t['status']]; ?>
            <a class="tab<?= $tab === $key ? ' on' : '' ?><?= $key === 'nosupport' ? ' tab-red' : '' ?>" href="customers.php?tab=<?= $key ?>">
                <?= h($t['label']) ?> <span class="count"><?= number_format($n) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="row-gap">
        <form method="get" class="inline-search" data-live="customer-results">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <?= icon('search') ?><input type="search" name="q" value="<?= h($q) ?>" placeholder="Filter by name, code, contact or phone">
        </form>
        <a class="btn" href="customers.php?tab=<?= h($tab) ?>"><?= icon('restart_alt') ?>Reset</a>
    </div>
</div>

<section class="card card-flush" id="customer-results">
    <?php if (!$rows): ?>
        <div class="empty"><?= icon('domain') ?><p><?= $q !== '' ? 'No customers match your search.' : 'No customers in this list.' ?></p></div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Code</th><th>Company name</th><th>Category</th><th>Contact person</th><th>Phone</th><th>Status</th><?php if ($ns): ?><th>Blocked by</th><?php endif; ?><th class="right">Calls</th><th>Last support</th><th class="right">Action</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr class="<?= $c['status'] === 'NoSupport' ? 'row-stop' : ($c['status'] === 'Inactive' ? 'row-muted' : '') ?>">
                    <td class="mono small"><?= h($c['ac_code']) ?></td>
                    <td class="strong"><a href="customer_view.php?code=<?= h($c['ac_code']) ?>"><?= h($c['name']) ?></a>
                        <?php $remark = trim((string) $c['blocked_remark']); ?>
                        <?php if ($c['status'] === 'NoSupport' && $c['erp_blocked'] && (!$ns || $remark !== '')): ?><div class="small text-red"><?= $ns ? h($remark) : 'Blocked in ERP' . ($remark !== '' ? ' · ' . h($remark) : '') ?></div>
                        <?php elseif ($c['status'] === 'NoSupport' && !$c['erp_blocked'] && $c['ns_reason']): ?><div class="small text-red"><?= $ns ? '' : 'Since ' . h(fmt_date($c['ns_since'])) . ' · ' ?><?= h($c['ns_reason']) ?></div><?php endif; ?>
                    </td>
                    <td class="small"><?= h(trim((string) ($c['category_name'] ?: $c['category_code']))) ?: '<span class="muted">—</span>' ?></td>
                    <td><?= h($c['contact_name']) ?></td>
                    <td class="small nowrap"><?= h($c['contact_no'] ?: $c['tel_off']) ?></td>
                    <td><?= status_badge($c['status']) ?></td>
                    <?php if ($ns): ?>
                    <td class="small nowrap"><?php if ($c['erp_blocked']): ?><?= h($c['blocked_by'] ?: '—') ?> <span class="tag">ERP</span><div class="muted mono"><?= h(fmt_date($c['blocked_date'])) ?></div><?php else: ?><?= h($c['ns_by'] ?: '—') ?><div class="muted mono"><?= h(fmt_date($c['ns_since'])) ?></div><?php endif; ?></td>
                    <?php endif; ?>
                    <td class="right mono"><?= (int) $c['call_count'] ?></td>
                    <td class="mono small nowrap"><?= h(fmt_date($c['last_call'])) ?: '<span class="muted">—</span>' ?></td>
                    <td class="right nowrap"><a class="btn btn-sm" href="customer_view.php?code=<?= h($c['ac_code']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pager($page, $pages, $total, count($rows), 'customers') ?>
    <?php endif; ?>
</section>
<?php page_end();
