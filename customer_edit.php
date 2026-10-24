<?php
// Customer master: add or delete a customer (any user), or edit one (administrators), in the ERP
// (ac_master) with the same rules as TaskManager's customer module, so there is one customer list.
// ?code=N edits that customer; no code adds a new one. ?return=call_add.php… comes back to a
// support call being entered, with the new customer selected.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$me = require_login();
$cc = current_branch()['company_code'];

const CUST_NAME_MAX = 150;
// Editable ac_master columns: column => [label, max length, required, section, type, full width].
const CUST_FIELDS = [
    'tax_no'                => ['TRN', 50, false, 'customer', 'text', false],
    'active'                => ['Active', 1, false, 'other', 'checkbox', false],
    'contact_name'          => ['Contact person', 50, true, 'contact', 'text', false],
    'contact_no'            => ['Mobile no', 50, true, 'contact', 'text', false],
    'contact_designation'   => ['Designation', 50, false, 'contact', 'text', false],
    'contact_email'         => ['Contact e-mail', 50, false, 'contact', 'text', false],
    'tel_off'               => ['Telephone (office)', 50, false, 'contact', 'text', false],
    'tel_res'               => ['Telephone (residence)', 50, false, 'contact', 'text', false],
    'fax_off'               => ['Fax (office)', 50, false, 'contact', 'text', false],
    'address'               => ['Address', 250, false, 'address', 'text', true],
    'city'                  => ['City', 50, false, 'address', 'text', false],
    'country'               => ['Country', 50, false, 'address', 'text', false],
    'zip_code'              => ['Postal code', 50, false, 'address', 'text', false],
    'service_location_code' => ['Service location', 5, false, 'address', 'select', false],
    'email'                 => ['Company e-mail', 50, false, 'address', 'text', false],
    'www'                   => ['Website', 50, false, 'address', 'text', false],
    'project'               => ['Project', 250, false, 'terms', 'text', true],
    'payment_terms'         => ['Payment terms', 250, false, 'terms', 'text', true],
    'remark'                => ['Remark', 2000, false, 'terms', 'textarea', true],
];
const CUST_SECTIONS = [
    'customer' => 'Customer',
    'contact'  => 'Contact',
    'address'  => 'Address',
    'terms'    => 'Project and terms',
    'other'    => 'Other',
];

// A customer is a leaf account under the parent flagged parent_control.parent_type = 'C'.
$isCustomer = "company_code = ? AND ac_code = ? AND child_exist = 'N' AND parent_account_code IN (
        SELECT a.account_code FROM " . erp('ac_master') . ' a JOIN ' . erp('parent_control') . " p
        ON a.company_code = p.company_code AND a.ac_code = p.ac_code WHERE p.company_code = ? AND p.parent_type = 'C')";

/** Next free customer code, continuing the shared ac_code / u_key sequence of the whole chart of accounts. */
function next_customer_code(string $cc): string
{
    global $conn;
    $st = $conn->prepare('SELECT ISNULL(MAX(n), 0) + 1 FROM (
            SELECT CAST(ac_code AS INT) AS n FROM ' . erp('ac_master') . " WHERE company_code = ? AND LEN(ac_code) > 0 AND ac_code NOT LIKE '%[^0-9]%'
            UNION ALL SELECT CAST(u_key AS INT) FROM " . erp('ac_master') . ' WHERE company_code = ? AND u_key IS NOT NULL) codes');
    $st->execute([$cc, $cc]);
    return str_pad((string) $st->fetchColumn(), 5, '0', STR_PAD_LEFT);
}

// Only a support-call form may be returned to (never an outside address).
$return = (string) ($_GET['return'] ?? '');
if (!preg_match('/^call_add\.php(\?id=\d+)?$/', $return)) $return = '';

$code = trim((string) ($_GET['code'] ?? ''));
$existing = null;
if ($code !== '') {
    $deleting = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete';
    if (!is_admin() && !$deleting) {
        http_response_code(403);
        flash('error', 'Only administrators can edit customers.');
        redirect('customer_view.php?code=' . urlencode($code));
    }
    $st = $conn->prepare('SELECT ac_code, description, ' . implode(', ', array_keys(CUST_FIELDS)) . ' FROM ' . erp('ac_master') . " WHERE $isCustomer");
    $st->execute([$cc, $code, $cc]);
    $existing = $st->fetch();
    if (!$existing) {
        flash('error', 'Customer not found.');
        redirect('customers.php');
    }
}

$errors = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

// ---- delete (only a customer nothing refers to)
if ($posted && $existing && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $uses = [];
    foreach ([['support call', 'support_calls'], ['job card', erp('job_card_h')], ['task', erp('task_master')]] as [$noun, $table]) {
        $st = $conn->prepare("SELECT COUNT(*) FROM $table WHERE company_code = ? AND ac_code = ?");
        $st->execute([$cc, $code]);
        if ($n = (int) $st->fetchColumn()) $uses[] = $n . ' ' . $noun . ($n === 1 ? '' : 's');
    }
    if ($uses) {
        flash('error', 'This customer cannot be deleted: it is still used by ' . implode(', ', $uses) . '.');
        redirect('customer_view.php?code=' . urlencode($code));
    }
    try {
        $conn->beginTransaction();
        foreach (['customer_notes', 'no_support_history', 'customer_flags'] as $t) {   // this app's own records of the customer
            $conn->prepare("DELETE FROM $t WHERE company_code = ? AND ac_code = ?")->execute([$cc, $code]);
        }
        $conn->prepare('DELETE FROM ' . erp('ac_master') . ' WHERE company_code = ? AND ac_code = ?')->execute([$cc, $code]);
        $conn->commit();
        flash('success', 'Customer ' . $code . ' (' . trim($existing['description']) . ') deleted.');
        redirect('customers.php');
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log('Delete customer ' . $code . ': ' . $e->getMessage());
        flash('error', $e->getCode() === '23000'
            ? 'This customer cannot be deleted: the ERP has transactions recorded against it (invoices, orders, payments or similar).'
            : 'The customer could not be deleted.');
        redirect('customer_view.php?code=' . urlencode($code));
    }
}

// ---- values: posted, or the customer's own, or a blank new customer (active)
$v = ['description' => trim((string) ($posted ? ($_POST['description'] ?? '') : ($existing['description'] ?? '')))];
foreach (CUST_FIELDS as $col => [$label, $max, $req, $sec, $type]) {
    $v[$col] = $type === 'checkbox'
        ? ($posted ? (($_POST[$col] ?? '') === 'Y' ? 'Y' : 'N') : (($existing[$col] ?? 'Y') === 'N' ? 'N' : 'Y'))
        : trim((string) ($posted ? ($_POST[$col] ?? '') : ($existing[$col] ?? '')));
}

if ($posted) {
    csrf_check();
    if ($v['description'] === '') $errors[] = 'Customer name is required.';
    elseif (mb_strlen($v['description']) > CUST_NAME_MAX) $errors[] = 'Customer name must be ' . CUST_NAME_MAX . ' characters or fewer.';
    foreach (CUST_FIELDS as $col => [$label, $max, $req]) {
        if ($req && $v[$col] === '') $errors[] = $label . ' is required.';
        elseif (mb_strlen($v[$col]) > $max) $errors[] = $label . ' must be ' . $max . ' characters or fewer.';
    }
    $fieldVals = array_map(function ($x) {
        return $x === '' ? null : $x;
    }, array_intersect_key($v, CUST_FIELDS));

    if (!$errors && $existing) {
        // Only descriptive fields change; the code, u_key and parent links never do.
        $sets = implode(', ', array_map(function ($c) {
            return "$c = ?";
        }, array_keys($fieldVals)));
        $conn->prepare('UPDATE ' . erp('ac_master') . " SET description = ?, $sets, last_userid = ?, last_userdate = ? WHERE $isCustomer")
             ->execute(array_merge([$v['description']], array_values($fieldVals), [$me['login'], date('Y-m-d H:i:s'), $cc, $code, $cc]));
        flash('success', 'Customer ' . $code . ' updated.');
        redirect('customer_view.php?code=' . urlencode($code));
    }
    if (!$errors) {
        $st = $conn->prepare('SELECT TOP 1 a.ac_code, a.account_code FROM ' . erp('ac_master') . ' a JOIN ' . erp('parent_control') . " p
            ON a.company_code = p.company_code AND a.ac_code = p.ac_code WHERE p.company_code = ? AND p.parent_type = 'C' ORDER BY p.set_default DESC");
        $st->execute([$cc]);
        $parent = $st->fetch();
        if (!$parent) {
            $errors[] = 'No customer parent account is set up in the ERP (parent_control.parent_type = C).';
        } else {
            $cols = array_merge(['company_code', 'ac_code', 'account_code', 'parent_account_code', 'parent_ac_code', 'description',
                'child_exist', 'u_key', 'user_id', 'user_date'], array_keys($fieldVals));
            $ins = $conn->prepare('INSERT INTO ' . erp('ac_master') . ' (' . implode(', ', $cols) . ') VALUES ('
                . implode(', ', array_fill(0, count($cols), '?')) . ')');
            // Another user may take the same code between reading and inserting: the key refuses it, so try the next.
            for ($try = 1; ; $try++) {
                $new = next_customer_code($cc);
                if (strlen($new) > 5) { $errors[] = 'No customer codes left (ac_code is 5 characters).'; break; }
                try {
                    $ins->execute(array_merge([$cc, $new, trim($parent['account_code']) . '-' . $new, trim($parent['account_code']), trim($parent['ac_code']),
                        $v['description'], 'N', (int) $new, $me['login'], date('Y-m-d H:i:s')], array_values($fieldVals)));
                    flash('success', 'Customer ' . $new . ' (' . $v['description'] . ') created.');
                    redirect($return !== '' ? $return . (strpos($return, '?') === false ? '?' : '&') . 'customer=' . urlencode($new)
                                            : 'customer_view.php?code=' . urlencode($new));
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000' && $try < 5) continue;
                    error_log('Create customer: ' . $e->getMessage());
                    $errors[] = 'The customer could not be created.';
                    break;
                }
            }
        }
    }
}

$locations = $conn->query('SELECT service_location_code AS code, service_location_name AS name FROM ' . erp('service_location')
    . ' ORDER BY sort_order, service_location_name')->fetchAll();
$codeShown = $existing ? trim($existing['ac_code']) : next_customer_code($cc);

$field = function (string $col) use ($v, $locations): string {
    [$label, $max, $req, , $type, $wide] = CUST_FIELDS[$col];
    $name = h($col);
    if ($type === 'checkbox') {
        return '<div class="field"><span>Status</span><label class="check"><input type="checkbox" name="' . $name . '" value="Y"'
            . ($v[$col] === 'Y' ? ' checked' : '') . '> Active customer</label></div>';
    }
    $out = '<label class="field' . ($type === 'textarea' ? ' span-all' : ($wide ? ' span-2' : '')) . '"><span>' . h($label) . ($req ? ' <b class="req">*</b>' : '') . '</span>';
    if ($type === 'select') {
        $out .= '<select name="' . $name . '"><option value="">None</option>';
        foreach ($locations as $l) {
            $out .= '<option value="' . h(trim($l['code'])) . '"' . (trim($l['code']) === $v[$col] ? ' selected' : '') . '>' . h(trim($l['name'])) . '</option>';
        }
        $out .= '</select>';
    } elseif ($type === 'textarea') {
        $out .= '<textarea name="' . $name . '" rows="3" maxlength="' . $max . '">' . h($v[$col]) . '</textarea>';
    } else {
        $out .= '<input name="' . $name . '" maxlength="' . $max . '"' . ($req ? ' required' : '') . ' value="' . h($v[$col]) . '">';
    }
    return $out . '</label>';
};

$cancel = $return !== '' ? $return : ($existing ? 'customer_view.php?code=' . urlencode($code) : 'customers.php');
$title = $existing ? trim($existing['description']) : 'New customer';
page_start($existing ? 'Edit customer' : 'New customer', 'customers');
?>
<div class="page-head">
    <div>
        <a class="back" href="<?= h($cancel) ?>"><?= icon('arrow_back') ?><?= $return !== '' ? 'Back to the support call' : ($existing ? 'Customer' : 'Customers') ?></a>
        <h1><?= h($title) ?></h1>
        <?php if ($existing): ?><p class="muted small">Code <span class="mono"><?= h($codeShown) ?></span> · Editing the ERP customer record</p><?php endif; ?>
    </div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= icon('error') ?><div><?= h($e) ?></div></div><?php endforeach; ?>

<form method="post" class="card fs-card" novalidate>
    <?= csrf_field() ?>
    <?php foreach (CUST_SECTIONS as $sec => $name): ?>
    <section class="fs-row">
        <h2 class="fs-title"><?= h($name) ?></h2>
        <div class="fs-fields<?= $sec === 'customer' ? ' fs-cust' : '' ?>">
            <?php if ($sec === 'customer'): ?>
                <label class="field"><span>Code</span><input class="mono" value="<?= h($codeShown) ?>" readonly tabindex="-1"></label>
                <label class="field"><span>Customer name <b class="req">*</b></span>
                    <input name="description" maxlength="<?= CUST_NAME_MAX ?>" required value="<?= h($v['description']) ?>"<?= $existing ? '' : ' autofocus' ?>></label>
            <?php endif; ?>
            <?php foreach (CUST_FIELDS as $col => $f) if ($f[3] === $sec) echo $field($col); ?>
        </div>
    </section>
    <?php endforeach; ?>
    <div class="fs-foot">
        <span class="muted small"><?= $existing ? 'Changes are saved to the ERP customer record.' : '' ?></span>
        <div class="actions">
            <a class="btn" href="<?= h($cancel) ?>">Cancel</a>
            <button class="btn btn-primary"><?= icon('check') ?><?= $existing ? 'Save changes' : 'Create customer' ?></button>
        </div>
    </div>
</form>

<?php if ($existing): ?>
<section class="card fs-card fs-danger">
    <div class="fs-row">
        <div class="fs-label"><h2>Delete customer</h2><p>Only possible while no support call, job card or task uses this customer.</p></div>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <button class="btn btn-danger-outline" data-confirm="<?= h(trim($existing['description'])) ?> (<?= h($code) ?>) will be removed from the ERP customer list. This cannot be undone."
                    data-confirm-title="Delete this customer?" data-confirm-ok="Delete customer" data-confirm-danger><?= icon('delete') ?>Delete customer</button>
        </form>
    </div>
</section>
<?php endif; ?>
<?php page_end();
