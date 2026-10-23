<?php
// Shared bootstrap: session, database connection, auth and view helpers.

if (session_status() === PHP_SESSION_NONE) {
    // Stay signed in for 30 days (every user has their own PC), also after closing the browser.
    // Sessions live in their own folder so other PHP apps' cleanup cannot end them early.
    $sessionLife = 30 * 86400;
    $sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'support_calls_sessions';
    if (is_dir($sessionDir) || @mkdir($sessionDir, 0700)) {
        session_save_path($sessionDir);
    }
    ini_set('session.gc_maxlifetime', (string) $sessionLife);
    session_set_cookie_params(['lifetime' => $sessionLife, 'httponly' => true, 'samesite' => 'Lax']);
    session_name('SUPPORTCALLS');
    session_start();
}

require __DIR__ . '/db.php';

// ---------------------------------------------------------------- output

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** The Support Calls list with the filters it was last shown with in this session. */
function calls_list_url(): string
{
    $qs = $_SESSION['calls_qs'] ?? '';
    return 'calls.php' . ($qs !== '' ? '?' . $qs : '');
}

function fmt_date(?string $d): string
{
    return $d ? date('d M Y', strtotime($d)) : '';
}

function fmt_datetime(?string $d): string
{
    return $d ? date('d M Y, H:i', strtotime($d)) : '';
}

/** True for a real calendar date in Y-m-d form. */
function valid_date(?string $s): bool
{
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

/** Current query string with some keys replaced (null removes a key). */
function qs(array $overrides = []): string
{
    $q = array_merge($_GET, $overrides);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '';
}

function type_badge(string $type): string
{
    $cls = $type === 'Onsite' ? 'badge badge-onsite' : 'badge badge-online';
    return '<span class="' . $cls . '"><span class="dot"></span>' . h($type) . '</span>';
}

const CALL_STATUSES = ['P' => 'Pending', 'O' => 'Ongoing', 'C' => 'Completed'];

function call_status_badge(string $status): string
{
    $cls = ['P' => 'badge-pending', 'O' => 'badge-ongoing', 'C' => 'badge-active'][$status] ?? '';
    return '<span class="badge ' . $cls . '"><span class="dot"></span>' . h(CALL_STATUSES[$status] ?? $status) . '</span>';
}

function status_label(string $status): string
{
    return ['Active' => 'Active', 'NoSupport' => 'Blocked', 'Inactive' => 'Inactive'][$status] ?? $status;
}

function status_badge(string $status): string
{
    $cls = ['Active' => 'badge-active', 'NoSupport' => 'badge-nosupport', 'Inactive' => 'badge-inactive'][$status] ?? '';
    return '<span class="badge ' . $cls . '"><span class="dot"></span>' . h(status_label($status)) . '</span>';
}

function initial(string $name): string
{
    return mb_strtoupper(mb_substr(trim($name), 0, 1));
}

// ---------------------------------------------------------------- flash messages

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, reload the page and try again.');
    }
}

// ---------------------------------------------------------------- ERP
//
// The app runs inside the ERP database. The ERP's own tables (customers, users, branches)
// are only ever read, and only through erp() so they stay easy to find; the app's three
// tables (sql/schema.sql) are the only ones it writes.

/** An ERP-owned table, e.g. erp('ac_master') -> dbo.ac_master */
function erp(string $table): string
{
    return 'dbo.' . $table;
}

function valid_ac_code(?string $s): bool
{
    return is_string($s) && (bool) preg_match('/^[0-9]{1,5}$/', $s);
}

function valid_user_code(?string $s): bool
{
    return is_string($s) && (bool) preg_match('/^\S{1,15}$/', $s);
}

// ---------------------------------------------------------------- auth
//
// Users are the ERP's own logins:
//   branch_user (user_type 'A') -> Administrator, may hold several branches
//   emp_master  (emp_type  'U') -> Supporter, tied to their own branch
// Passwords are compared exactly as the ERP stores them. They are never written here.
// An admin login mapped in admin_emp_map is recorded under that employee's code and name,
// so a person who has both an admin and an employee login is one supporter.
/** The code and name a user is recorded under. */
function admin_identity(string $login, string $loginName, string $companyCode): array
{
    global $conn;
    $st = $conn->prepare('SELECT TOP 1 m.emp_code, e.emp_name FROM admin_emp_map m
        JOIN ' . erp('emp_master') . ' e ON e.company_code = m.company_code AND e.emp_code = m.emp_code
        WHERE m.company_code = ? AND m.user_id = ?');
    $st->execute([$companyCode, $login]);
    $r = $st->fetch();
    return $r
        ? ['code' => trim($r['emp_code']), 'name' => trim((string) $r['emp_name']) ?: trim($r['emp_code'])]
        : ['code' => $login, 'name' => $loginName !== '' ? $loginName : $login];
}

/**
 * Check ERP credentials. Returns the user and the branches the password opens, or null.
 * An administrator can have a different password per branch, so every branch row is checked.
 */
function authenticate(string $code, string $password): ?array
{
    global $conn;
    if ($code === '' || $password === '') {
        return null;
    }

    $st = $conn->prepare('SELECT bu.user_id, bu.user_name, bu.edit_password, b.company_code, b.branch_code, b.branch_name
        FROM ' . erp('branch_user') . ' bu
        JOIN ' . erp('branch_master') . " b ON b.branch_code = bu.branch_code
        WHERE bu.user_id = ? AND bu.user_type = 'A'
        ORDER BY b.branch_name");
    $st->execute([$code]);
    $login = null;
    $branches = [];
    foreach ($st as $r) {
        if (hash_equals((string) $r['edit_password'], $password)) {
            $login = trim($r['user_id']);
            $loginName = trim((string) $r['user_name']);
            $branches[] = ['company_code' => trim($r['company_code']), 'branch_code' => trim($r['branch_code']), 'branch_name' => trim($r['branch_name'])];
        }
    }
    if ($login !== null) {
        return admin_identity($login, $loginName, $branches[0]['company_code'])
            + ['login' => $login, 'role' => 'admin', 'branches' => $branches];
    }

    $st = $conn->prepare('SELECT e.emp_code, e.emp_name, e.emp_pwd, e.company_code, e.branch_code, b.branch_name
        FROM ' . erp('emp_master') . ' e
        JOIN ' . erp('branch_master') . " b ON b.company_code = e.company_code AND b.branch_code = e.branch_code
        WHERE e.emp_code = ? AND e.emp_type = 'U' AND ISNULL(e.active, 'Y') <> 'N'");
    $st->execute([$code]);
    $r = $st->fetch();
    if ($r && hash_equals((string) $r['emp_pwd'], $password)) {
        return [
            'code' => trim($r['emp_code']), 'name' => trim((string) $r['emp_name']), 'login' => trim($r['emp_code']), 'role' => 'supporter',
            'branches' => [['company_code' => trim($r['company_code']), 'branch_code' => trim($r['branch_code']), 'branch_name' => trim($r['branch_name'])]],
        ];
    }
    return null;
}

function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = ['code' => $u['code'], 'login' => $u['login'], 'role' => $u['role']];
    $_SESSION['branches'] = $u['branches'];
    unset($_SESSION['branch']);
}

/** The logged-in user, re-read from the ERP each request so a removed login takes effect immediately. */
function current_user(): ?array
{
    global $conn;
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $s = $_SESSION['user'] ?? null;
    if (!$s) {
        return null;
    }

    $login = $s['login'] ?? $s['code'];
    if ($s['role'] === 'admin') {
        $sql = 'SELECT TOP 1 user_name AS name FROM ' . erp('branch_user') . " WHERE user_id = ? AND user_type = 'A'";
        $args = [$login];
        if ($b = current_branch()) {
            $sql .= ' AND branch_code = ?';
            $args[] = $b['branch_code'];
        }
    } else {
        $sql = 'SELECT emp_name AS name FROM ' . erp('emp_master') . " WHERE emp_code = ? AND emp_type = 'U' AND ISNULL(active, 'Y') <> 'N'";
        $args = [$login];
    }
    $st = $conn->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();

    if (!$row) {
        unset($_SESSION['user'], $_SESSION['branches'], $_SESSION['branch']);
        return null;
    }
    $name = trim((string) $row['name']);
    if ($s['role'] === 'admin') {
        $company = current_branch()['company_code'] ?? ($_SESSION['branches'][0]['company_code'] ?? '');
        $user = admin_identity($login, $name, $company) + ['login' => $login, 'role' => 'admin'];
    } else {
        $user = ['code' => $login, 'name' => $name !== '' ? $name : $login, 'login' => $login, 'role' => 'supporter'];
    }
    return $user;
}

/** @return array{company_code: string, branch_code: string, branch_name: string}|null */
function current_branch(): ?array
{
    return $_SESSION['branch'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    if (!current_branch()) {
        redirect('branch.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        flash('error', 'Only administrators can do that.');
        redirect('dashboard.php');
    }
    return $u;
}

/** A support call can be edited by the supporter who entered it, or by any administrator. */
function can_edit_call(array $call): bool
{
    $u = current_user();
    return $u && ($u['role'] === 'admin' || strcasecmp((string) $call['emp_code'], $u['code']) === 0);
}

// ---------------------------------------------------------------- customers
//
// A customer is an ERP leaf account under the parent flagged parent_control.parent_type = 'C',
// the same definition the ERP and TaskManager use. Local status lives in customer_flags, notes in customer_notes.
// The ERP's own restrictions win: inactive there counts as Inactive here, and blocked there
// counts as No Support. Both can only be lifted in the ERP.

const CUSTOMER_STATUS = "CASE WHEN a.active = 'N' THEN 'Inactive' WHEN a.blocked = 'Y' THEN 'NoSupport' ELSE ISNULL(f.status, 'Active') END";

const CUSTOMER_COLUMNS = "a.company_code, RTRIM(a.ac_code) AS ac_code, a.description AS name, a.contact_name, a.contact_no,
    a.tel_off, a.email, a.address, a.city, RTRIM(a.category_code) AS category_code, cm.category_name,
    CASE WHEN a.active = 'N' THEN 1 ELSE 0 END AS erp_inactive,
    CASE WHEN a.blocked = 'Y' THEN 1 ELSE 0 END AS erp_blocked, a.blocked_remark, a.blocked_by, a.blocked_date,
    " . CUSTOMER_STATUS . ' AS status';

/** FROM/WHERE for the current company's customers. Takes one parameter: company_code. */
function customer_from(): string
{
    return 'FROM ' . erp('ac_master') . " a
        LEFT JOIN customer_flags f ON f.company_code = a.company_code AND f.ac_code = a.ac_code
        LEFT JOIN " . erp('category_master') . " cm ON cm.category_code = a.category_code
        WHERE a.company_code = ? AND a.child_exist = 'N'
          AND a.parent_account_code IN (
              SELECT p.account_code FROM " . erp('ac_master') . ' p
              JOIN ' . erp('parent_control') . " pc ON pc.company_code = p.company_code AND pc.ac_code = p.ac_code
              WHERE pc.company_code = a.company_code AND pc.parent_type = 'C')";
}

function find_customer(string $acCode): ?array
{
    global $conn;
    if (!valid_ac_code($acCode)) {
        return null;
    }
    $st = $conn->prepare('SELECT ' . CUSTOMER_COLUMNS . ' ' . customer_from() . ' AND a.ac_code = ?');
    $st->execute([current_branch()['company_code'], $acCode]);
    return $st->fetch() ?: null;
}

/**
 * Lock the customer's local status (even before a flags row exists) and return the customer.
 * Call inside a transaction, so a status check and the write that depends on it cannot be split.
 */
function lock_customer(string $acCode): ?array
{
    global $conn;
    $conn->prepare('SELECT 1 FROM customer_flags WITH (UPDLOCK, HOLDLOCK) WHERE company_code = ? AND ac_code = ?')
         ->execute([current_branch()['company_code'], $acCode]);
    return find_customer($acCode);
}

/** Insert or update the customer's local flags. $fields keys are column names chosen by the caller, never by the request. */
function save_customer_flags(string $acCode, array $fields, string $by): void
{
    global $conn;
    $cc = current_branch()['company_code'];
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
    $st = $conn->prepare("UPDATE customer_flags SET $sets, last_userdate = SYSDATETIME(), last_userid = ? WHERE company_code = ? AND ac_code = ?");
    $st->execute([...array_values($fields), $by, $cc, $acCode]);
    if ($st->rowCount() === 0) {
        $cols = implode(', ', array_keys($fields));
        $ph = implode(', ', array_fill(0, count($fields), '?'));
        $conn->prepare("INSERT INTO customer_flags (company_code, ac_code, $cols, last_userid) VALUES (?, ?, $ph, ?)")
             ->execute([$cc, $acCode, ...array_values($fields), $by]);
    }
}

/** "AMC - ANNUAL MAINTENANCE CONTRACT", or '' when the customer has no category in the ERP. */
function customer_category(array $c): string
{
    $code = trim((string) ($c['category_code'] ?? ''));
    $name = trim((string) ($c['category_name'] ?? ''));
    return $code === '' ? '' : $code . ($name !== '' ? ' - ' . $name : '');
}

function customer_label(array $c): string
{
    return $c['name'] . ' [' . $c['ac_code'] . ']' . ($c['status'] !== 'Active' ? ' — ' . status_label($c['status']) : '');
}

// ---------------------------------------------------------------- lookups

/** Customers with calls in the current branch, for list and report filters. */
function customers_with_calls(): array
{
    global $conn;
    $b = current_branch();
    $st = $conn->prepare('SELECT ac_code, MAX(customer_name) AS name FROM v_support_calls
        WHERE company_code = ? AND branch_code = ? GROUP BY ac_code ORDER BY MAX(customer_name)');
    $st->execute([$b['company_code'], $b['branch_code']]);
    return $st->fetchAll();
}

/** Supporters with calls in the current branch, for list and report filters. */
function supporters_with_calls(): array
{
    global $conn;
    $b = current_branch();
    $st = $conn->prepare('SELECT emp_code AS code, MAX(supporter_name) AS name FROM v_support_calls
        WHERE company_code = ? AND branch_code = ? GROUP BY emp_code ORDER BY MAX(supporter_name)');
    $st->execute([$b['company_code'], $b['branch_code']]);
    return $st->fetchAll();
}

/** Renders Previous / page x of y / Next. */
function pager(int $page, int $pages, int $total, int $shown, string $noun = 'records'): string
{
    $out = '<div class="table-foot"><span>Showing ' . $shown . ' of ' . number_format($total) . ' ' . $noun . '</span><div class="pager">';
    $out .= $page > 1
        ? '<a class="btn btn-sm" href="' . h(qs(['page' => $page - 1])) . '">Previous</a>'
        : '<span class="btn btn-sm disabled">Previous</span>';
    $out .= '<span class="mono">Page ' . $page . ' of ' . max(1, $pages) . '</span>';
    $out .= $page < $pages
        ? '<a class="btn btn-sm" href="' . h(qs(['page' => $page + 1])) . '">Next</a>'
        : '<span class="btn btn-sm disabled">Next</span>';
    return $out . '</div></div>';
}
