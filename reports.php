<?php
// Reports & Export. Filters by the manually entered support date.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/calls_filter.php';
require_login();

const PER_PAGE = 50;
$flt = build_call_filter($_GET, false);
$f = $flt['f'];
[$sort, $dir] = sort_from($_GET, CALL_SORTS, 'date');
$sum = call_summary($flt);
$pages = max(1, (int) ceil($sum['total'] / PER_PAGE));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = call_rows($flt, PER_PAGE, ($page - 1) * PER_PAGE, $sort, $dir);
$pct = function (int $n) use ($sum) {
    return $sum['total'] ? round($n * 100 / $sum['total'], 1) : 0;
};

$exportQs = http_build_query(array_filter([
    'from' => $f['from'], 'to' => $f['to'], 'customer' => $f['customer'] ?: '', 'supporter' => $f['supporter'] ?: '', 'type' => $f['type'], 'status' => $f['status'],
    'sort' => $sort, 'dir' => strtolower($dir),
]));
$exportQs = $exportQs ? '?' . $exportQs : '';

$customers = customers_with_calls();
$supporters = supporters_with_calls();

page_start('Call Report', 'reports-export');
?>
<div class="page-head">
    <div>
        <h1>Call Report</h1>
        <p class="muted">Analyse completed support calls and export the filtered report.</p>
    </div>
    <div class="actions">
        <a class="btn btn-lg" id="export-pdf" href="export_pdf.php<?= h($exportQs) ?>"><?= icon('picture_as_pdf', 'text-red') ?>Export PDF</a>
        <a class="btn btn-lg" id="export-xlsx" href="export_xlsx.php<?= h($exportQs) ?>"><?= icon('table_view') ?>Export Excel</a>
    </div>
</div>

<form class="card filters" method="get" data-live="report-results">
    <div class="card-head"><h2 class="card-title row-gap"><?= icon('tune') ?>Filters</h2></div>
    <details class="filter-fold" open data-active="<?= count(array_filter($f)) ?>">
    <summary class="btn"><?= icon('tune') ?>Filters<?= count(array_filter($f)) ? ' (' . count(array_filter($f)) . ')' : '' ?></summary>
    <div class="filter-grid">
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
        <span class="muted small mono grow"><?= icon('info') ?> Results update as you change the filters. Click a column heading to sort. Filtered by support date (when the support happened), not when it was entered.</span>
        <span class="live-status muted small" aria-live="polite"></span>
        <a class="btn" href="reports.php"><?= icon('restart_alt') ?>Reset</a>
        <noscript><button class="btn btn-primary"><?= icon('filter_alt') ?>Apply filters</button></noscript>
    </div>
</form>

<div id="report-results">
<section class="kpis kpis-3">
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Total matching calls</span></div>
        <div class="kpi-value"><?= number_format($sum['total']) ?></div>
        <div class="kpi-foot muted small"><?= h($flt['labels']['Period']) ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Online calls</span></div>
        <div class="kpi-value"><?= number_format($sum['online']) ?></div>
        <div class="kpi-foot mono small"><?= $pct($sum['online']) ?>% of total</div>
    </div>
    <div class="kpi">
        <div class="kpi-head"><span class="label-caps">Onsite calls</span></div>
        <div class="kpi-value text-red"><?= number_format($sum['onsite']) ?></div>
        <div class="kpi-foot mono small"><?= $pct($sum['onsite']) ?>% of total</div>
    </div>
</section>

<section class="card card-flush">
    <div class="table-head">
        <div>
            <h2 class="card-title">Report results</h2>
            <span class="muted small"><?= h(implode(' · ', array_map(function ($k, $v) { return "$k: $v"; }, array_keys($flt['labels']), $flt['labels']))) ?></span>
        </div>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><?= icon('search_off') ?><p>No support calls match these filters.</p></div>
    <?php else: ?>
        <?php render_call_table($rows, true, [$sort, $dir]); ?>
        <?= pager($page, $pages, $sum['total'], count($rows), 'calls') ?>
    <?php endif; ?>
</section>
</div>
<?php page_end();
