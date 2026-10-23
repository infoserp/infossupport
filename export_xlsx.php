<?php
// Excel export of the filtered report: structured rows, real dates, filterable.
// ?report=customers exports the Customer Summary instead.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/calls_filter.php';
require __DIR__ . '/inc/xlsx.php';
require_login();

if (($_GET['report'] ?? '') === 'customers') {
    $rows = [];
    foreach (customer_totals(build_customer_summary_filter($_GET), ...sort_from($_GET, CUSTOMER_SORTS, 'total')) as $r) {
        $rows[] = [$r['ac_code'], $r['category_code'], $r['customer_name'], $r['total'], $r['online'], $r['onsite']];
    }
    $bytes = xlsx_build('Customer Summary', ['Code', 'Category', 'Customer', 'Total calls', 'Online', 'Onsite'], $rows, [10, 12, 50, 12, 10, 10]);
    $name = 'customer-summary-' . date('Ymd-His') . '.xlsx';
} else {
    $flt = build_call_filter($_GET, false);
    $rows = [];
    foreach (call_rows($flt, null, 0, ...sort_from($_GET, CALL_SORTS, 'date')) as $r) {
        $rows[] = [new XlsxDate($r['doc_date']), $r['customer_name'], $r['supporter_name'], $r['type'], CALL_STATUSES[$r['status']] ?? $r['status'], $r['task_completed'], (string) $r['comment']];
    }
    $bytes = xlsx_build(
        'Support Calls',
        ['Date', 'Customer', 'Supporter', 'Type', 'Status', 'Task', 'Comment'],
        $rows,
        [14, 32, 22, 10, 12, 60, 50]
    );
    $name = 'support-calls-' . date('Ymd-His') . '.xlsx';
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
