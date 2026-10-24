<?php
// Page chrome: top bar, sidebar navigation and flash messages.

function icon(string $name, string $extra = ''): string
{
    return '<span class="mi ' . $extra . '" aria-hidden="true">' . h($name) . '</span>';
}

/** Light/dark switch. Starts from the OS setting; the choice is remembered per browser. */
function theme_toggle(): string
{
    return '<button type="button" class="icon-btn theme-toggle" data-theme-toggle title="Switch light / dark mode" aria-label="Switch light or dark mode">'
        . icon('dark_mode', 'ico-dark') . icon('light_mode', 'ico-light') . '</button>';
}

function head_tags(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Infos Support</title>
<meta name="color-scheme" content="light dark">
<script>/* apply the saved theme before first paint */try{var t=localStorage.getItem('theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
<link rel="stylesheet" href="<?= asset('assets/fonts.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
    <?php
}

function render_flashes(): void
{
    foreach (take_flashes() as [$type, $msg]) {
        echo '<div class="alert alert-' . h($type) . '">' . icon($type === 'error' ? 'error' : 'check_circle') . '<div>' . h($msg) . '</div></div>';
    }
}

/**
 * @param string $active  nav key: dashboard | calls | customers | customers-active | customers-nosupport |
 *                        customers-inactive | supporters | reports | account
 */
function page_start(string $title, string $active = ''): void
{
    $u = current_user();
    head_tags($title);
    $nav = function (string $key, string $href, string $ico, string $label, bool $sub = false) use ($active) {
        $on = $active === $key || (!$sub && strpos($active, $key . '-') === 0);
        echo '<a class="nav-link' . ($sub ? ' nav-sub' : '') . ($on ? ' active' : '') . '" href="' . h($href) . '">'
            . ($ico ? icon($ico) : '') . '<span>' . h($label) . '</span></a>';
    };
    ?>
<body>
<header class="topbar">
    <div class="topbar-left">
        <a class="brand" href="dashboard.php"><?= icon('support_agent') ?><span>Infos Support</span></a>
        <form class="top-search" action="calls.php" method="get" role="search">
            <?= icon('search') ?>
            <input type="search" name="q" placeholder="Search support calls…" value="<?= h($active === 'calls' ? ($_GET['q'] ?? '') : '') ?>">
        </form>
    </div>
    <div class="topbar-right">
        <a class="btn btn-primary" href="call_add.php"><?= icon('add') ?><span>Add Support Call</span></a>
        <span class="divider-v"></span>
        <?= theme_toggle() ?>
        <a class="user-chip" href="account.php" title="My account">
            <span class="avatar"><?= h(initial($u['name'])) ?></span>
            <span class="user-meta"><strong><?= h($u['name']) ?></strong><small><?= $u['role'] === 'admin' ? 'Administrator' : 'Supporter' ?> · <?= h(current_branch()['branch_name'] ?? '') ?></small></span>
        </a>
        <form action="logout.php" method="post" class="inline">
            <?= csrf_field() ?>
            <button class="icon-btn" title="Log out"><?= icon('logout') ?></button>
        </form>
    </div>
</header>
<div class="shell">
    <aside class="sidebar">
        <nav>
            <?php
            $nav('dashboard', 'dashboard.php', 'dashboard', 'Dashboard');
            echo '<div class="nav-group">';
            $nav('calls', 'calls.php', 'headset_mic', 'Support Calls');
            echo '</div><div class="nav-group">';
            $nav('customers', 'customers.php', 'group', 'Customers');
            $nav('customers-active', 'customers.php?tab=active', '', 'Active Customers', true);
            $nav('customers-nosupport', 'customers.php?tab=nosupport', '', 'Blocked', true);
            $nav('customers-inactive', 'customers.php?tab=inactive', '', 'Inactive Customers', true);
            echo '</div>';
            $nav('supporters', 'supporters.php', 'badge', 'Supporters');
            echo '<div class="nav-group">';
            $nav('reports', 'reports.php', 'bar_chart', 'Reports');
            $nav('reports-export', 'reports.php', '', 'Call Report', true);
            $nav('reports-customers', 'report_customers.php', '', 'Customer Summary', true);
            echo '</div>';
            ?>
        </nav>
        <div class="sidebar-foot">
            <span class="company"><?= h(APP_COMPANY) ?></span>
        </div>
    </aside>
    <main class="main">
    <?php
    render_flashes();
}

function page_end(): void
{
    ?>
    </main>
</div>
<script src="<?= asset('assets/app.js') ?>"></script>
</body>
</html>
    <?php
}

/** Minimal centred layout for login and first-run setup. */
function auth_page_start(string $title): void
{
    head_tags($title);
    echo '<body class="auth-body"><div class="auth-theme">' . theme_toggle() . '</div><div class="auth-wrap"><div class="auth-brand">' . icon('support_agent') . '<span>Infos Support</span></div>';
    render_flashes();
}

function asset(string $path): string
{
    return $path . '?v=' . (int) @filemtime(__DIR__ . '/../' . $path);
}

function auth_page_end(): void
{
    echo '<p class="auth-foot">' . h(APP_COMPANY) . ' · Support Call Tracking</p></div><script src="' . asset('assets/app.js') . '"></script></body></html>';
}

/** Column heading that sorts the table by $key; a second click reverses the order. */
function sort_th(string $key, string $label, string $sort, string $dir, string $class = ''): string
{
    $active = $sort === $key;
    $next = $active && $dir === 'DESC' ? 'asc' : 'desc';
    $arrow = $active ? icon($dir === 'DESC' ? 'arrow_downward' : 'arrow_upward', 'sort-ico') : '';
    return '<th' . ($class ? ' class="' . $class . '"' : '') . '><a class="sort' . ($active ? ' on' : '') . '" href="' . h(qs(['sort' => $key, 'dir' => $next, 'page' => null])) . '">' . h($label) . $arrow . '</a></th>';
}

/** Shared table markup (dashboard, reports). Pass [$sort, $dir] to make the headings sort the table. */
function render_call_table(array $rows, bool $withComment = false, ?array $sorted = null): void
{
    if (!$rows) {
        echo '<div class="empty">' . icon('inbox') . '<p>No support calls recorded yet.</p><a class="btn btn-primary" href="call_add.php">' . icon('add') . 'Add the first support call</a></div>';
        return;
    }
    $th = function (string $key, string $label) use ($sorted) {
        return $sorted ? sort_th($key, $label, ...$sorted) : '<th>' . $label . '</th>';
    };
    echo '<div class="table-scroll"><table class="table table-cards"><thead><tr>' . $th('date', 'Date') . $th('customer', 'Customer') . $th('supporter', 'Supporter')
        . $th('type', 'Type') . $th('status', 'Status') . '<th>Task</th>' . ($withComment ? '<th>Comment</th>' : '') . '<th class="right">Action</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>'
            . '<td class="mono nowrap c-date">' . h(fmt_date($r['doc_date'])) . '</td>'
            . '<td class="strong c-cust"><a href="customer_view.php?code=' . h($r['ac_code']) . '">' . h($r['customer_name']) . '</a></td>'
            . '<td class="nowrap c-sup"><span class="mini-avatar">' . h(initial($r['supporter_name'])) . '</span>' . h($r['supporter_name']) . '</td>'
            . '<td class="c-type">' . type_badge($r['type']) . '</td>'
            . '<td class="c-status">' . call_status_badge($r['status']) . '</td>'
            . '<td class="task c-task">' . h($r['task_completed']) . '</td>'
            . ($withComment ? '<td class="task c-comment">' . ((string) $r['comment'] !== '' ? h($r['comment']) : '<span class="muted">—</span>') . '</td>' : '')
            . '<td class="right nowrap c-actions"><a class="btn btn-sm" href="call_view.php?id=' . (int) $r['doc_id'] . '">View</a>'
            . (can_edit_call($r) ? ' <a class="btn btn-sm" href="call_add.php?id=' . (int) $r['doc_id'] . '">Edit</a>' : '')
            . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div>';
}
