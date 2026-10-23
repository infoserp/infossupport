<?php
// One filter definition shared by the Support Calls list, Reports and both exports,
// so an export always contains exactly what the screen showed.
// All date filtering uses the manually entered doc_date, never user_date.
// Every query is limited to the branch chosen at login.

const CALLS_FROM = 'FROM v_support_calls sc';

// Sortable columns: request key => SQL. Call lists default to newest support date first;
// the customer summary to most calls first.
const CALL_SORTS = [
    'date'      => 'sc.doc_date',
    'customer'  => 'sc.customer_name',
    'supporter' => 'sc.supporter_name',
    'type'      => 'sc.type',
    'status'    => 'sc.status',
    'created'   => 'sc.user_date',
];
const CUSTOMER_SORTS = [
    'customer' => 'MAX(sc.customer_name)',
    'code'     => 'sc.ac_code',
    'category' => 'MAX(ca.category_code)',
    'total'    => 'COUNT(*)',
    'online'   => "SUM(CASE WHEN sc.type = 'Online' THEN 1 ELSE 0 END)",
    'onsite'   => "SUM(CASE WHEN sc.type = 'Onsite' THEN 1 ELSE 0 END)",
];

/** [key, 'ASC'|'DESC'] from ?sort=&dir=, limited to the given columns. */
function sort_from(array $in, array $cols, string $default): array
{
    $key = array_key_exists($in['sort'] ?? '', $cols) ? $in['sort'] : $default;
    return [$key, ($in['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC'];
}

/** ORDER BY for call lists; ties fall back to newest support date, then newest entry. */
function call_order(string $sort, string $dir): string
{
    return CALL_SORTS[$sort] . " $dir" . ($sort === 'date' ? '' : ', sc.doc_date DESC') . ', sc.doc_id ' . ($sort === 'date' ? $dir : 'DESC');
}

const CALLS_COLUMNS = 'sc.doc_id, sc.doc_date, sc.type, sc.task_completed, sc.status, sc.comment, sc.user_date, sc.last_userdate,
    sc.ac_code, sc.customer_name, sc.emp_code, sc.supporter_name';

/**
 * @return array{where: string, params: array, f: array, labels: array}
 *   f      = the cleaned filter values (for re-filling the form)
 *   labels = human-readable description of the applied filters (for PDF/Excel headers)
 */
function build_call_filter(array $in, bool $withSearch = true): array
{
    global $conn;
    $b = current_branch();
    $where = ['sc.company_code = ?', 'sc.branch_code = ?'];
    $params = [$b['company_code'], $b['branch_code']];
    $f = [
        'from'     => valid_date($in['from'] ?? null) ? $in['from'] : '',
        'to'       => valid_date($in['to'] ?? null) ? $in['to'] : '',
        'customer' => valid_ac_code($in['customer'] ?? null) ? $in['customer'] : '',
        'supporter'=> valid_user_code($in['supporter'] ?? null) ? $in['supporter'] : '',
        'type'     => in_array($in['type'] ?? '', ['Online', 'Onsite'], true) ? $in['type'] : '',
        'status'   => isset(CALL_STATUSES[$in['status'] ?? '']) ? $in['status'] : '',
        'q'        => $withSearch ? trim((string) ($in['q'] ?? '')) : '',
    ];

    if ($f['from']) {
        $where[] = 'sc.doc_date >= ?';
        $params[] = $f['from'];
    }
    if ($f['to']) {
        $where[] = 'sc.doc_date <= ?';
        $params[] = $f['to'];
    }
    if ($f['customer'] !== '') {
        $where[] = 'sc.ac_code = ?';
        $params[] = $f['customer'];
    }
    if ($f['supporter'] !== '') {
        $where[] = 'sc.emp_code = ?';
        $params[] = $f['supporter'];
    }
    if ($f['type']) {
        $where[] = 'sc.type = ?';
        $params[] = $f['type'];
    }
    if ($f['status']) {
        $where[] = 'sc.status = ?';
        $params[] = $f['status'];
    }
    if ($f['q'] !== '') {
        $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $f['q']) . '%';
        $where[] = '(sc.task_completed LIKE ? OR sc.comment LIKE ? OR sc.customer_name LIKE ? OR sc.supporter_name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }

    // Human-readable labels
    $period = match (true) {
        $f['from'] && $f['to'] => fmt_long($f['from']) . ' - ' . fmt_long($f['to']),
        (bool) $f['from']      => 'From ' . fmt_long($f['from']),
        (bool) $f['to']        => 'Up to ' . fmt_long($f['to']),
        default                => 'All dates',
    };
    // Names come from the calls themselves, so a label works even if the ERP record is gone.
    $name = function (string $col, string $key, string $val) use ($conn, $b): string {
        $st = $conn->prepare("SELECT TOP 1 $col FROM v_support_calls WHERE company_code = ? AND $key = ? ORDER BY doc_id DESC");
        $st->execute([$b['company_code'], $val]);
        return (string) ($st->fetchColumn() ?: $val);
    };
    $labels = [
        'Period'    => $period,
        'Customer'  => $f['customer'] !== '' ? $name('customer_name', 'ac_code', $f['customer']) : 'All customers',
        'Supporter' => $f['supporter'] !== '' ? $name('supporter_name', 'emp_code', $f['supporter']) : 'All supporters',
        'Type'      => $f['type'] ?: 'All',
        'Status'    => $f['status'] ? CALL_STATUSES[$f['status']] : 'All',
    ];
    if ($f['q'] !== '') {
        $labels['Search'] = '"' . $f['q'] . '"';
    }

    return [
        'where'  => 'WHERE ' . implode(' AND ', $where),
        'params' => $params,
        'f'      => $f,
        'labels' => $labels,
    ];
}

function fmt_long(string $d): string
{
    return date('d F Y', strtotime($d));
}

/** Total / Online / Onsite counts for a filter. */
function call_summary(array $flt): array
{
    global $conn;
    $st = $conn->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN sc.type = 'Online' THEN 1 ELSE 0 END) AS online,
            SUM(CASE WHEN sc.type = 'Onsite' THEN 1 ELSE 0 END) AS onsite
        " . CALLS_FROM . " {$flt['where']}");
    $st->execute($flt['params']);
    $r = $st->fetch();
    return ['total' => (int) $r['total'], 'online' => (int) $r['online'], 'onsite' => (int) $r['onsite']];
}

/** The customer summary report filters by date range and, optionally, customer name or code. */
function build_customer_summary_filter(array $in): array
{
    $flt = build_call_filter(['from' => $in['from'] ?? '', 'to' => $in['to'] ?? ''], false);
    $flt['labels'] = array_intersect_key($flt['labels'], ['Period' => 1]);
    $q = trim((string) ($in['q'] ?? ''));
    $flt['f']['q'] = $q;
    if ($q !== '') {
        $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $q) . '%';
        $flt['where'] .= ' AND (sc.customer_name LIKE ? OR sc.ac_code LIKE ?)';
        array_push($flt['params'], $like, $like);
        $flt['labels']['Customer'] = '"' . $q . '"';
    }
    return $flt;
}

/** One row per customer with calls in the filter: total, online and onsite counts (most calls first by default). */
function customer_totals(array $flt, string $sort = 'total', string $dir = 'DESC'): array
{
    global $conn;
    $st = $conn->prepare("SELECT sc.ac_code, MAX(sc.customer_name) AS customer_name, COUNT(*) AS total,
            SUM(CASE WHEN sc.type = 'Online' THEN 1 ELSE 0 END) AS online,
            SUM(CASE WHEN sc.type = 'Onsite' THEN 1 ELSE 0 END) AS onsite, MAX(RTRIM(ca.category_code)) AS category_code
        " . CALLS_FROM . "
        LEFT JOIN " . erp('ac_master') . " ca ON ca.company_code = sc.company_code AND ca.ac_code = sc.ac_code
        {$flt['where']}
        GROUP BY sc.ac_code
        ORDER BY " . CUSTOMER_SORTS[$sort] . " $dir, " . ($sort === 'customer' ? 'sc.ac_code' : 'MAX(sc.customer_name)'));
    $st->execute($flt['params']);
    return array_map(fn($r) => ['ac_code' => trim($r['ac_code']), 'customer_name' => $r['customer_name'], 'category_code' => (string) $r['category_code'],
        'total' => (int) $r['total'], 'online' => (int) $r['online'], 'onsite' => (int) $r['onsite']], $st->fetchAll());
}

/** Every matching row, newest support date first unless sorted otherwise (used by reports + exports). */
function call_rows(array $flt, ?int $limit = null, int $offset = 0, string $sort = 'date', string $dir = 'DESC'): array
{
    global $conn;
    $sql = 'SELECT ' . CALLS_COLUMNS . ' ' . CALLS_FROM . " {$flt['where']}
        ORDER BY " . call_order($sort, $dir);
    if ($limit !== null) {
        $sql .= ' OFFSET ' . max(0, $offset) . ' ROWS FETCH NEXT ' . max(1, $limit) . ' ROWS ONLY';
    }
    $st = $conn->prepare($sql);
    $st->execute($flt['params']);
    return $st->fetchAll();
}
