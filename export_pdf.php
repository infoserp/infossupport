<?php
// PDF export of the filtered report. ?report=customers exports the Customer Summary instead.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/calls_filter.php';
require __DIR__ . '/inc/pdf.php';
$me = require_login();

$pdf = new SimplePdf();   // A4 landscape, 842 x 595 pt
$L = 36;
$R = 842 - 36;
$W = $R - $L;
$fs = 8.5;
$lh = 11;
$pad = 5;
$red = [0.72, 0.08, 0.07];
$ink = [0.11, 0.11, 0.11];

if (($_GET['report'] ?? '') === 'customers') {
    $flt = build_customer_summary_filter($_GET);
    $rows = customer_totals($flt, ...sort_from($_GET, CUSTOMER_SORTS, 'total'));
    $sum = ['total' => array_sum(array_column($rows, 'total')), 'online' => array_sum(array_column($rows, 'online')),
            'onsite' => array_sum(array_column($rows, 'onsite'))];
    $title = 'Customer Support Summary';
    $boxes = [['CUSTOMERS', count($rows), $ink], ['TOTAL CALLS', $sum['total'], $ink], ['ONLINE', $sum['online'], $ink], ['ONSITE', $sum['onsite'], $red]];
    $heads = ['Code', 'Category', 'Customer', 'Total calls', 'Online', 'Onsite'];
    $colW = [60, 70, $W - 60 - 70 - 3 * 90, 90, 90, 90];
    $right = [3, 4, 5];                 // number columns
    $cells = fn(array $r) => [[$r['ac_code']], [$r['category_code'] !== '' ? $r['category_code'] : '-'], $pdf->wrap($r['customer_name'], $colW[2] - 2 * $pad, $fs, true),
        [number_format($r['total'])], [number_format($r['online'])], [number_format($r['onsite'])]];
    $style = fn(array $r, int $ci) => [$ci === 2 || $ci === 3, $ci === 5 && $r['onsite'] ? $red : $ink];
    $total = ['', '', 'Total', number_format($sum['total']), number_format($sum['online']), number_format($sum['onsite'])];
    $empty = 'No support calls in this period.';
    $name = 'customer-summary-' . date('Ymd-His') . '.pdf';
} else {
    $flt = build_call_filter($_GET, false);
    $sum = call_summary($flt);
    $rows = call_rows($flt, null, 0, ...sort_from($_GET, CALL_SORTS, 'date'));
    $title = 'Support Call Report';
    $boxes = [['TOTAL CALLS', $sum['total'], $ink], ['ONLINE', $sum['online'], $ink], ['ONSITE', $sum['onsite'], $red]];
    $heads = ['Date', 'Customer', 'Supporter', 'Type', 'Status', 'Task', 'Comment'];
    $colW = [64, 135, 90, 46, 60, $W - 64 - 135 - 90 - 46 - 60 - 150, 150];
    $right = [];
    $cells = fn(array $r) => [
        [fmt_date($r['doc_date'])],
        $pdf->wrap($r['customer_name'], $colW[1] - 2 * $pad, $fs, true),
        $pdf->wrap($r['supporter_name'], $colW[2] - 2 * $pad, $fs, false),
        [$r['type']],
        [CALL_STATUSES[$r['status']] ?? $r['status']],
        $pdf->wrap($r['task_completed'], $colW[5] - 2 * $pad, $fs, false),
        $pdf->wrap((string) $r['comment'], $colW[6] - 2 * $pad, $fs, false),
    ];
    $style = fn(array $r, int $ci) => [$ci === 1 || $ci === 3, $ci === 3 && $r['type'] === 'Onsite' ? $red : $ink];
    $total = null;
    $empty = 'No support calls match the selected filters.';
    $name = 'support-call-report-' . date('Ymd-His') . '.pdf';
}
$generated = date('d M Y, H:i');

// Footer on every page (page count filled in at output time)
$pdf->onPageEnd = function (SimplePdf $p) use ($L, $R, $generated, $me) {
    $p->line($L, 572, $R, 572, 0.5, [0.77, 0.78, 0.78]);
    $p->text($L, 582, 'Report generated ' . $generated . ' by ' . $me['name'] . '  |  Filtered by support date', 7.5, false, [0.45, 0.47, 0.47]);
    $p->textRight($R, 582, 'Page {PAGE} of {PAGES}', 7.5, false, [0.45, 0.47, 0.47]);
};

$pdf->addPage();

// --- Header: company + title
$pdf->rect($L, 36, 6, 30, $red);
$pdf->text($L + 14, 50, APP_COMPANY, 11, true, [0.27, 0.28, 0.28]);
$pdf->text($L + 14, 70, $title, 20, true);
$pdf->textRight($R, 50, 'Generated ' . $generated, 8.5, false, [0.45, 0.47, 0.47]);
$pdf->textRight($R, 64, 'Report period: ' . $flt['labels']['Period'], 9.5, true);
$pdf->line($L, 84, $R, 84, 1.2);

// --- Applied filters block: every value in full (wrapped); the customer's cell is twice as wide
$y = 96;
$weights = array_map(fn($k) => $k === 'Customer' ? 2 : 1, array_keys($flt['labels']));
$unit = $W / array_sum($weights);
$x = $L;
$hdr = [];
foreach (array_keys($flt['labels']) as $n => $k) {
    $hdr[] = [$x, $k, $pdf->wrap($flt['labels'][$k], $weights[$n] * $unit - 16, 9.5, false)];
    $x += $weights[$n] * $unit;
}
$blockH = 28 + 12 * max(array_map(fn($c) => count($c[2]), $hdr));
$pdf->rect($L, $y, $W, $blockH, [0.96, 0.95, 0.95]);
foreach ($hdr as [$cx, $k, $lines]) {
    $pdf->text($cx + 10, $y + 14, strtoupper($k), 7, true, [0.45, 0.47, 0.47]);
    foreach ($lines as $li => $ln) $pdf->text($cx + 10, $y + 29 + 12 * $li, $ln, 9.5);
}
$dy = $blockH - 40;   // everything below moves down when the block grows

// --- Summary statistics
$y = 146 + $dy;
$bw = ($W - 10 * (count($boxes) - 1)) / count($boxes);
foreach ($boxes as $n => [$lbl, $val, $col]) {
    $x = $L + $n * ($bw + 10);
    $pdf->rectStroke($x, $y, $bw, 46, 0.6, [0.77, 0.78, 0.78]);
    $pdf->rect($x, $y + 43, $bw, 3, $col);
    $pdf->text($x + 10, $y + 15, $lbl, 7, true, [0.45, 0.47, 0.47]);
    $share = $sum['total'] && in_array($lbl, ['ONLINE', 'ONSITE'], true) ? '  (' . round($val * 100 / $sum['total'], 1) . '%)' : '';
    $pdf->text($x + 10, $y + 35, number_format($val), 17, true, $col);
    if ($share) $pdf->text($x + 10 + $pdf->width(number_format($val), 17, true) + 2, $y + 35, $share, 9, false, [0.45, 0.47, 0.47]);
}

// --- Table
$drawHead = function (float $y) use ($pdf, $L, $W, $colW, $heads, $right): float {
    $pdf->rect($L, $y, $W, 18, [0.11, 0.11, 0.11]);
    $x = $L;
    foreach ($heads as $i => $hd) {
        in_array($i, $right, true)
            ? $pdf->textRight($x + $colW[$i] - 5, $y + 12.5, strtoupper($hd), 7.5, true, [1, 1, 1])
            : $pdf->text($x + 5, $y + 12.5, strtoupper($hd), 7.5, true, [1, 1, 1]);
        $x += $colW[$i];
    }
    return $y + 18;
};
$drawRow = function (array $cellLines, float $y, callable $styleOf) use ($pdf, $L, $colW, $right, $pad, $lh, $fs): void {
    $x = $L;
    foreach ($cellLines as $ci => $lns) {
        [$bold, $color] = $styleOf($ci);
        foreach ($lns as $li => $ln) {
            in_array($ci, $right, true)
                ? $pdf->textRight($x + $colW[$ci] - $pad, $y + $pad + 7 + $li * $lh, $ln, $fs, $bold, $color)
                : $pdf->text($x + $pad, $y + $pad + 7 + $li * $lh, $ln, $fs, $bold, $color);
        }
        $x += $colW[$ci];
    }
};

$y = $drawHead(206 + $dy);
if (!$rows) {
    $pdf->text($L + 5, $y + 18, $empty, 9.5, false, [0.45, 0.47, 0.47]);
}
foreach ($rows as $idx => $r) {
    $cellLines = $cells($r);
    $rh = max(array_map('count', $cellLines)) * $lh + 2 * $pad - 2;
    if ($y + $rh > 560) {
        $pdf->addPage();
        $y = $drawHead(36);
    }
    if ($idx % 2 === 1) $pdf->rect($L, $y, $W, $rh, [0.98, 0.976, 0.973]);
    $drawRow($cellLines, $y, fn(int $ci) => $style($r, $ci));
    $y += $rh;
    $pdf->line($L, $y, $R, $y, 0.4, [0.85, 0.85, 0.85]);
}
if ($rows && $total) {
    $rh = $lh + 2 * $pad - 2;
    if ($y + $rh > 560) {
        $pdf->addPage();
        $y = $drawHead(36);
    }
    $pdf->line($L, $y, $R, $y, 1, $ink);
    $drawRow(array_map(fn($v) => [$v], $total), $y, fn(int $ci) => [true, $ci === count($total) - 1 ? $red : $ink]);
}

$bytes = $pdf->output();
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
