<?php
// Register a completed support call, or edit one (?id=N).
// Fields entered: support date, customer, type, task. Supporter and created time are automatic.
// The supporter who entered a call, or an administrator, can edit it; the supporter and created time never change.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
// Login expired while the form was open: keep what was typed and bring it back after signing in.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !current_user()) {
    $_SESSION['pending_call'] = ['id' => (int) ($_GET['id'] ?? 0)]
        + array_intersect_key($_POST, array_flip(['doc_date', 'ac_code', 'type', 'task_completed', 'status', 'comment']));
    $_SESSION['after_login'] = 'call_add.php' . (empty($_GET['id']) ? '' : '?id=' . (int) $_GET['id']);
    flash('error', 'Your login had expired, so the support call was not saved. Sign in again: what you typed has been kept.');
}
$me = require_login();
$b = current_branch();

const SUPPORT_STOPPED = 'This customer is blocked. New support calls cannot be logged for this customer.';
const CUSTOMER_INACTIVE = 'This customer is Inactive. New support calls cannot be logged for inactive customers.';

$id = (int) ($_GET['id'] ?? 0);
$call = null;
if ($id) {
    $st = $conn->prepare('SELECT doc_id, doc_date, ac_code, customer_name, emp_code, supporter_name, type, task_completed, status, comment, user_date
        FROM v_support_calls WHERE doc_id = ? AND company_code = ? AND branch_code = ?');
    $st->execute([$id, $b['company_code'], $b['branch_code']]);
    $call = $st->fetch();
    if (!$call) {
        flash('error', 'Support call not found.');
        redirect(calls_list_url());
    }
    if (!can_edit_call($call)) {
        http_response_code(403);
        flash('error', 'Only the supporter who entered this support call, or an administrator, can edit it.');
        redirect('call_view.php?id=' . $id);
    }
}

$errors = [];
$blocked = '';
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = $posted ? $_POST : null;
if (!$posted && isset($_SESSION['pending_call'])) {
    if ($_SESSION['pending_call']['id'] === $id) {
        $in = $_SESSION['pending_call'];
        flash('success', 'What you typed before your login expired has been restored. Check it and press Save.');
    }
    unset($_SESSION['pending_call']);
}
$v = [
    'doc_date'   => (string) ($in ? ($in['doc_date'] ?? '') : ($call['doc_date'] ?? date('Y-m-d'))),
    'ac_code'        => trim((string) ($in ? ($in['ac_code'] ?? '') : ($call['ac_code'] ?? ($_GET['customer'] ?? '')))),
    'type'           => (string) ($in ? ($in['type'] ?? '') : ($call['type'] ?? '')),
    'task_completed' => trim((string) ($in ? ($in['task_completed'] ?? '') : ($call['task_completed'] ?? ''))),
    'status'         => (string) ($in ? ($in['status'] ?? '') : ($call['status'] ?? 'C')),
    'comment'        => trim((string) ($in ? ($in['comment'] ?? '') : ($call['comment'] ?? ''))),
];

if ($posted) {
    csrf_check();
    if (!valid_date($v['doc_date'])) {
        $errors[] = 'Enter the date the support actually took place.';
    } elseif ($v['doc_date'] > date('Y-m-d')) {
        $errors[] = 'Support date cannot be in the future.';
    }
    if (!valid_ac_code($v['ac_code'])) $errors[] = 'Select a customer.';
    if (!in_array($v['type'], ['Online', 'Onsite'], true)) $errors[] = 'Choose Online or Onsite.';
    if ($v['task_completed'] === '') $errors[] = 'Describe the task.';
    if (mb_strlen($v['task_completed']) > 2000) $errors[] = 'Task must be 2000 characters or fewer.';
    if (!isset(CALL_STATUSES[$v['status']])) $errors[] = 'Choose a status.';
    if (mb_strlen($v['comment']) > 2000) $errors[] = 'Comment must be 2000 characters or fewer.';

    if (!$errors) {
        // Lock the customer's status in the same transaction as the write, so the
        // No Support / Inactive rule cannot be bypassed by anyone, including admins.
        // An edit may keep the call's original customer even if that customer has since been
        // restricted or removed from the ERP (it is the same past support), but cannot move
        // the call to a restricted one.
        $conn->beginTransaction();
        $keepsCustomer = $call && $call['ac_code'] === $v['ac_code'];
        $customerName = $keepsCustomer ? $call['customer_name'] : null;
        if (!$keepsCustomer) {
            $cust = lock_customer($v['ac_code']);
            if (!$cust) {
                $errors[] = 'Selected customer does not exist.';
            } elseif ($cust['status'] !== 'Active') {
                $blocked = $cust['status'] === 'NoSupport' ? SUPPORT_STOPPED : CUSTOMER_INACTIVE;
            } else {
                $customerName = $cust['name'];
            }
        }

        if ($errors || $blocked) {
            $conn->rollBack();
        } elseif ($call) {
            $conn->prepare('UPDATE support_calls SET doc_date = ?, ac_code = ?, customer_name = ?, type = ?, task_completed = ?,
                status = ?, comment = ?, last_userdate = SYSDATETIME(), last_userid = ? WHERE doc_id = ?')
                 ->execute([$v['doc_date'], $v['ac_code'], $customerName, $v['type'], $v['task_completed'],
                     $v['status'], $v['comment'] === '' ? null : $v['comment'], $me['code'], $id]);
            $conn->commit();
            flash('success', 'Support call #' . $id . ' updated.');
            redirect('call_view.php?id=' . $id);
        } else {
            $ins = $conn->prepare('INSERT INTO support_calls (company_code, branch_code, doc_date, ac_code, customer_name,
                    emp_code, supporter_name, type, task_completed, status, comment)
                OUTPUT INSERTED.doc_id VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$b['company_code'], $b['branch_code'], $v['doc_date'], $v['ac_code'], $customerName,
                $me['code'], $me['name'], $v['type'], $v['task_completed'], $v['status'], $v['comment'] === '' ? null : $v['comment']]);
            $newId = (int) $ins->fetchColumn();
            $conn->commit();
            flash('success', 'Support call #' . $newId . ' for ' . $customerName . ' saved.');
            redirect(isset($_POST['another']) ? 'call_add.php' : 'call_view.php?id=' . $newId);
        }
    }
}

// The customer currently in the field, so the picker shows it before any search.
$selected = valid_ac_code($v['ac_code']) ? find_customer($v['ac_code']) : null;
if (!$selected && $call && $v['ac_code'] === $call['ac_code']) {
    $selected = ['ac_code' => $call['ac_code'], 'name' => $call['customer_name'], 'status' => 'Active'];
}
$isOriginal = $selected && $call && $selected['ac_code'] === $call['ac_code'];

$cancel = $call ? 'call_view.php?id=' . $id : calls_list_url();
page_start($call ? 'Edit Support Call #' . $id : 'Add Support Call', 'calls-all');
?>
<div class="form-page">
    <div class="card form-card">
        <div class="form-card-head">
            <h1 class="h-modal"><?= $call ? icon('edit_note') . 'Edit Support Call #' . $id : icon('add_task') . 'Add Support Call' ?></h1>
            <a class="icon-btn" href="<?= h($cancel) ?>" title="Close"><?= icon('close') ?></a>
        </div>

        <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= icon('error') ?><div><?= h($e) ?></div></div><?php endforeach; ?>

        <div class="alert alert-stop<?= $blocked ? '' : ' hidden' ?>" id="stop-banner" role="alert">
            <?= icon('block') ?>
            <div><strong>Support stopped</strong><br><span id="stop-text"><?= h($blocked ?: SUPPORT_STOPPED) ?></span></div>
        </div>

        <form method="post" class="form-stack" id="call-form" novalidate>
            <?= csrf_field() ?>
            <label class="field">
                <span>Support Date <b class="req">*</b></span>
                <input type="date" name="doc_date" required max="<?= date('Y-m-d') ?>" value="<?= h($v['doc_date']) ?>">
            </label>

            <label class="field">
                <span>Customer <b class="req">*</b></span>
                <select name="ac_code" id="customer-select" required data-searchable data-source="customer_search.php"
                        data-msg-nosupport="<?= h(SUPPORT_STOPPED) ?>" data-msg-inactive="<?= h(CUSTOMER_INACTIVE) ?>">
                    <option value="">Search customer by name, code, contact or phone…</option>
                    <?php if ($selected): ?>
                        <option value="<?= h($selected['ac_code']) ?>" data-status="<?= h($selected['status']) ?>" data-category="<?= h(customer_category($selected)) ?>"<?= $isOriginal ? ' data-original="1"' : '' ?> selected><?= h(customer_label($selected)) ?></option>
                    <?php endif; ?>
                </select>
            </label>

            <label class="field">
                <span>Category</span>
                <input id="customer-category" readonly tabindex="-1" placeholder="No category" value="<?= h($selected ? customer_category($selected) : '') ?>">
            </label>

            <fieldset class="field">
                <legend>Type <b class="req">*</b></legend>
                <div class="radio-cards">
                    <label class="radio-card"><input type="radio" name="type" value="Online" required <?= $v['type'] === 'Online' ? 'checked' : '' ?>><?= icon('cloud') ?><span><strong>Online</strong><small>Remote session or phone</small></span></label>
                    <label class="radio-card"><input type="radio" name="type" value="Onsite" <?= $v['type'] === 'Onsite' ? 'checked' : '' ?>><?= icon('apartment') ?><span><strong>Onsite</strong><small>At the customer's premises</small></span></label>
                </div>
            </fieldset>

            <label class="field">
                <span>Task <b class="req">*</b></span>
                <textarea name="task_completed" rows="4" maxlength="2000" required placeholder="e.g. Configured Outlook on customer's computers"><?= h($v['task_completed']) ?></textarea>
            </label>

            <fieldset class="field">
                <legend>Status <b class="req">*</b></legend>
                <div class="segmented">
                    <?php foreach (CALL_STATUSES as $k => $st): ?>
                        <label><input type="radio" name="status" value="<?= $k ?>" required <?= $v['status'] === $k ? 'checked' : '' ?>><span><?= $st ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <label class="field">
                <span>Comment</span>
                <textarea name="comment" rows="3" maxlength="2000"><?= h($v['comment']) ?></textarea>
            </label>

            <div class="form-foot">
                <span class="muted small mono"><span class="dot-red"></span>Supporter: <?= h($call ? $call['supporter_name'] : $me['name']) ?></span>
                <div class="actions">
                    <a class="btn" href="<?= h($cancel) ?>">Cancel</a>
                    <?php if (!$call): ?><button class="btn" name="another" value="1" data-save>Save &amp; add another</button><?php endif; ?>
                    <button class="btn btn-primary" data-save><?= icon('check') ?><?= $call ? 'Save changes' : 'Save Support Call' ?></button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php page_end();
