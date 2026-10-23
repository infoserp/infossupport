<?php
// Customer Summary: every customer supported in a date range, with total / online / onsite calls.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/calls_filter.php';
require_login();

$flt = build_customer_summary_filter($_GET);
$f = $flt['f'];
[$sort, $dir] = sort_from($_GET, CUSTOMER_SORTS, 'total');
$rows = customer_totals($flt, $sort, $dir);
$sum = ['total' => array_sum(array_column($rows, 'total')), 'online' => array_sum(array_column($rows, 'online')),
        'onsite' => array_sum(array_column($rows, 'onsite'))];

$dates = array_filter(['from' => $f['from'], 'to' => $f['to']]);
$exportQs = '?' . http_build_query(['report' => 'customers'] + $dates + array_filter(['q' => $f['q']]) + ['sort' => $sort, 'dir' => strtolower($dir)]);

page_start('Customer Summary', 'reports-customers');
?>
<div class="page-head">
    <div>
        <h1>Customer Summary</h1>
        <p class="muted">Customers supported in a period, with the number of online and onsite calls.</p>
    </div>
    <div class="actions">
        <a class="btn btn-lg" id="export-pdf" href="export_pdf.php<?= h($exportQs) ?>"><?= icon('picture_as_pdf', 'text-red') ?>Export PDF</a>
        <a class="btn btn-lg" id="export-xlsx" href="export_xlsx.php<?= h($exportQs) ?>"><?= icon('table_view') ?>Export Excel</a>
    </div>
</div>

<form class="card filters" method="get" data-live="report-results">
    <div class="card-head"><h2 class="card-title row-gap"><?= icon('tune') ?>Filters</h2></div>
    <div class="filter-grid">
        <label class="field"><span>Date from</span><input type="date" name="from" value="<?= h($f['from']) ?>"></label>
        <label class="field"><span>Date to</span><input type="date" name="to" value="<?= h($f['to']) ?>"></label>
        <label class="field span-2"><span>Customer</span><input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Search by customer name or code" autocomplete="off"></label>
    </div>
    <input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="dir" value="<?= strtolower($dir) ?>">
    <div class="filter-actions">
        <span class="muted small mono grow"><?= icon('info') ?> Results update as you change the filters. Click a column heading to sort. Filtered by support date (when the support happened), not when it was entered.</span>
        <span class="live-status muted small" aria-live="polite"></span>
        <a class="btn" href="report_customers.php"><?= icon('restart_alt') ?>Reset</a>
        <noscript><button class="btn btn-primary"><?= icon('filter_alt') ?>Apply filters</button></noscript>
    </div>
</form>

<div id="report-results">
<section class="kpis kpis-3">
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Customers supported</span></div>
        <div class="kpi-value"><?= number_format(count($rows)) ?></div>
        <div class="kpi-foot muted small"><?= h($flt['labels']['Period']) ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Online calls</span></div>
        <div class="kpi-value"><?= number_format($sum['online']) ?></div>
        <div class="kpi-foot mono small">of <?= number_format($sum['total']) ?> calls</div>
    </div>
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Onsite calls</span></div>
        <div class="kpi-value text-red"><?= number_format($sum['onsite']) ?></div>
        <div class="kpi-foot mono small">of <?= number_format($sum['total']) ?> calls</div>
    </div>
</section>

<section class="card card-flush">
    <div class="table-head">
        <div>
            <h2 class="card-title">Customers</h2>
            <span class="muted small"><?= h(implode(' · ', array_map(fn($k, $v) => "$k: $v", array_keys($flt['labels']), $flt['labels']))) ?></span>
        </div>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><?= icon('search_off') ?><p>No support calls in this period.</p></div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr>
                <?= sort_th('customer', 'Customer', $sort, $dir) ?>
                <?= sort_th('code', 'Code', $sort, $dir) ?>
                <?= sort_th('category', 'Category', $sort, $dir) ?>
                <?= sort_th('total', 'Total calls', $sort, $dir, 'right') ?>
                <?= sort_th('online', 'Online', $sort, $dir, 'right') ?>
                <?= sort_th('onsite', 'Onsite', $sort, $dir, 'right') ?>
                <th class="right">Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="strong"><a href="customer_view.php?code=<?= h($r['ac_code']) ?>"><?= h($r['customer_name']) ?></a></td>
                    <td class="mono"><?= h($r['ac_code']) ?></td>
                    <td class="mono"><?= $r['category_code'] !== '' ? h($r['category_code']) : '<span class="muted">—</span>' ?></td>
                    <td class="right mono strong"><?= number_format($r['total']) ?></td>
                    <td class="right mono"><?= number_format($r['online']) ?></td>
                    <td class="right mono<?= $r['onsite'] ? ' text-red' : '' ?>"><?= number_format($r['onsite']) ?></td>
                    <td class="right nowrap"><a class="btn btn-sm" href="calls.php?<?= h(http_build_query(['customer' => $r['ac_code']] + $dates)) ?>">View calls</a></td>
                </tr>
            <?php endforeach; ?>
                <tr class="strong">
                    <td>Total</td><td></td><td></td>
                    <td class="right mono"><?= number_format($sum['total']) ?></td>
                    <td class="right mono"><?= number_format($sum['online']) ?></td>
                    <td class="right mono text-red"><?= number_format($sum['onsite']) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
</div>
<?php page_end();
