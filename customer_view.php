<?php
// Customer dossier: ERP details, status actions (admin), No Support history and support history.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$me = require_login();
$b = current_branch();

$code = trim((string) ($_GET['code'] ?? ''));
$c = find_customer($code);
if (!$c) {
    flash('error', 'Customer not found.');
    redirect('customers.php');
}

// ---- notes: any logged-in user adds one; its author or an administrator can edit or delete it
$canChangeNote = fn(array $n): bool => $me['role'] === 'admin' || strcasecmp(trim($n['user_id']), $me['code']) === 0;
$noteAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';
if (in_array($noteAction, ['add_note', 'edit_note', 'delete_note'], true)) {
    csrf_check();
    $note = trim((string) ($_POST['note'] ?? ''));
    $existing = null;
    if ($noteAction !== 'add_note') {
        $st = $conn->prepare('SELECT id, user_id FROM customer_notes WHERE id = ? AND company_code = ? AND ac_code = ?');
        $st->execute([(int) ($_POST['note_id'] ?? 0), $b['company_code'], $code]);
        $existing = $st->fetch();
    }
    if ($noteAction !== 'add_note' && (!$existing || !$canChangeNote($existing))) {
        flash('error', 'Only the person who wrote this note, or an administrator, can change it.');
    } elseif ($noteAction === 'delete_note') {
        $conn->prepare('DELETE FROM customer_notes WHERE id = ?')->execute([$existing['id']]);
        flash('success', 'Note deleted.');
    } elseif ($note === '' || mb_strlen($note) > 2000) {
        flash('error', $note === '' ? 'Write the note before saving it.' : 'A note can be at most 2000 characters.');
    } elseif ($noteAction === 'edit_note') {
        $conn->prepare('UPDATE customer_notes SET note = ?, last_userid = ?, last_userdate = SYSDATETIME() WHERE id = ?')
             ->execute([$note, $me['code'], $existing['id']]);
        flash('success', 'Note updated.');
    } else {
        $conn->prepare('INSERT INTO customer_notes (company_code, ac_code, note, user_id) VALUES (?, ?, ?, ?)')
             ->execute([$b['company_code'], $code, $note, $me['code']]);
        flash('success', 'Note added.');
    }
    redirect('customer_view.php?code=' . $code . '#notes');
}

// ---- status actions (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_admin();
    $action = $_POST['action'] ?? '';
    $conn->beginTransaction();
    $cur = lock_customer($code);
    $status = $cur['status'] ?? '';
    $ok = false;

    if ($action === 'nosupport_on' && $status === 'Active') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            $conn->rollBack();
            flash('error', 'Enter a reason for blocking the customer.');
            redirect('customer_view.php?code=' . $code . '#status');
        }
        $note = trim((string) ($_POST['note'] ?? ''));
        $conn->prepare('INSERT INTO no_support_history (company_code, ac_code, start_date, reason, note, user_id)
            VALUES (?, ?, ?, ?, ?, ?)')
             ->execute([$b['company_code'], $code, date('Y-m-d'), mb_substr($reason, 0, 500), $note === '' ? null : $note, $me['code']]);
        save_customer_flags($code, ['status' => 'NoSupport'], $me['code']);
        $ok = 'Customer blocked. New support calls cannot be logged.';
    } elseif ($action === 'nosupport_off' && $status === 'NoSupport' && !$cur['erp_blocked']) {
        $conn->prepare('UPDATE no_support_history SET end_date = ?, ended_by = ?
            WHERE company_code = ? AND ac_code = ? AND end_date IS NULL')
             ->execute([date('Y-m-d'), $me['code'], $b['company_code'], $code]);
        save_customer_flags($code, ['status' => 'Active'], $me['code']);
        $ok = 'Customer unblocked and Active again.';
    } elseif ($action === 'inactivate' && $status === 'Active') {
        save_customer_flags($code, ['status' => 'Inactive'], $me['code']);
        $ok = 'Customer marked Inactive. History is kept; new support calls are blocked.';
    } elseif ($action === 'reactivate' && $status === 'Inactive' && !$cur['erp_inactive']) {
        save_customer_flags($code, ['status' => 'Active'], $me['code']);
        $ok = 'Customer reactivated.';
    }

    if ($ok) {
        $conn->commit();
        flash('success', $ok);
    } else {
        $conn->rollBack();
        flash('error', 'That action is not available for the customer\'s current status.');
    }
    redirect('customer_view.php?code=' . $code);
}

$hist = $conn->prepare('SELECT doc_id, doc_date, type, status, task_completed, user_date, emp_code, supporter_name
    FROM v_support_calls WHERE company_code = ? AND branch_code = ? AND ac_code = ?
    ORDER BY doc_date DESC, doc_id DESC');
$hist->execute([$b['company_code'], $b['branch_code'], $code]);
$calls = $hist->fetchAll();

$ns = $conn->prepare('SELECT *, dbo.fn_user_name(company_code, user_id) AS placed_by_name,
        dbo.fn_user_name(company_code, ended_by) AS ended_by_name FROM no_support_history WHERE company_code = ? AND ac_code = ? ORDER BY start_date DESC, id DESC');
$ns->execute([$b['company_code'], $code]);
$restrictions = $ns->fetchAll();

$nt = $conn->prepare('SELECT id, note, user_id, user_date, dbo.fn_user_name(company_code, user_id) AS by_name,
        last_userdate, dbo.fn_user_name(company_code, last_userid) AS edited_by
    FROM customer_notes WHERE company_code = ? AND ac_code = ? ORDER BY user_date DESC, id DESC');
$nt->execute([$b['company_code'], $code]);
$notes = $nt->fetchAll();

$address = trim(implode("\n", array_filter([trim((string) $c['address']), trim((string) $c['city'])])));
$navKey = ['Active' => 'customers-active', 'NoSupport' => 'customers-nosupport', 'Inactive' => 'customers-inactive'][$c['status']];
page_start($c['name'], $navKey);
?>
<div class="page-head">
    <div>
        <a class="back" href="customers.php?tab=<?= ['Active' => 'active', 'NoSupport' => 'nosupport', 'Inactive' => 'inactive'][$c['status']] ?>"><?= icon('arrow_back') ?>Customers</a>
        <h1 class="row-gap"><?= h($c['name']) ?> <?= status_badge($c['status']) ?></h1>
        <p class="muted small mono">Code <?= h($c['ac_code']) ?> · <?= count($calls) ?> support calls</p>
    </div>
    <div class="actions">
        <?php if (is_admin()): ?><a class="btn" href="customer_edit.php?code=<?= h($code) ?>"><?= icon('edit') ?>Edit customer</a><?php endif; ?>
        <form method="post" action="customer_edit.php?code=<?= h(urlencode($code)) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <button class="btn btn-danger-outline" data-confirm="<?= h($c['name']) ?> (<?= h($code) ?>) will be removed from the ERP customer list. This cannot be undone. Only possible while no support call, job card or task uses this customer."
                    data-confirm-title="Delete this customer?" data-confirm-ok="Delete customer" data-confirm-danger><?= icon('delete') ?>Delete</button>
        </form>
        <?php if ($c['status'] === 'Active'): ?>
            <a class="btn btn-primary" href="call_add.php?customer=<?= h($code) ?>"><?= icon('add_call') ?>Add Support Call</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($c['status'] === 'NoSupport' && $c['erp_blocked']): ?>
<div class="alert alert-stop"><?= icon('block') ?><div><strong>Support stopped</strong><br>This customer is blocked in the ERP<?= $c['blocked_by'] ? ' by ' . h(trim($c['blocked_by'])) : '' ?><?= $c['blocked_date'] ? ' on ' . h(fmt_date($c['blocked_date'])) : '' ?><?= trim((string) $c['blocked_remark']) !== '' ? ': ' . h(trim($c['blocked_remark'])) : '' ?>. New support calls cannot be logged for this customer.</div></div>
<?php elseif ($c['status'] === 'NoSupport'): ?>
<div class="alert alert-stop"><?= icon('block') ?><div><strong>Support stopped</strong><br>This customer is blocked. New support calls cannot be logged for this customer.</div></div>
<?php elseif ($c['status'] === 'Inactive'): ?>
<div class="alert alert-muted"><?= icon('inventory_2') ?><div><strong>Inactive customer</strong><br>New support calls cannot be logged. Support history remains available below and in reports.</div></div>
<?php endif; ?>

<div class="grid-side">
    <div class="stack">
        <section class="card">
            <h2 class="label-caps">Customer details</h2>
            <dl class="dl">
                <dt>Contact person</dt><dd><?= h($c['contact_name']) ?: '<span class="muted">—</span>' ?></dd>
                <dt>Mobile</dt><dd><?= h($c['contact_no']) ?: '<span class="muted">—</span>' ?></dd>
                <dt>Phone</dt><dd><?= h($c['tel_off']) ?: '<span class="muted">—</span>' ?></dd>
                <dt>Email</dt><dd><?= h($c['email']) ?: '<span class="muted">—</span>' ?></dd>
                <dt>Address</dt><dd class="pre"><?= h($address) ?: '<span class="muted">—</span>' ?></dd>
            </dl>
        </section>

        <section class="card" id="notes">
            <div class="card-head"><h2 class="label-caps">Notes</h2><span class="chip mono"><?= count($notes) ?></span></div>
            <form method="post" class="form-stack">
                <?= csrf_field() ?><input type="hidden" name="action" value="add_note">
                <label class="field"><span>Add a note</span><textarea name="note" rows="3" maxlength="2000" required placeholder="e.g. Called about the renewal; will confirm next week"></textarea></label>
                <div><button class="btn btn-primary"><?= icon('add_comment') ?>Add note</button></div>
            </form>
            <?php if (!$notes): ?><p class="muted small mt">No notes yet.</p><?php endif; ?>
            <?php foreach ($notes as $n): ?>
                <div class="note">
                    <div class="note-top">
                        <div class="small muted"><span class="mono"><?= h(fmt_datetime($n['user_date'])) ?></span><?= $n['by_name'] !== '' ? ' · ' . h($n['by_name']) : '' ?><?= $n['last_userdate'] ? ' · edited ' . h(fmt_datetime($n['last_userdate'])) . ($n['edited_by'] ? ' by ' . h($n['edited_by']) : '') : '' ?></div>
                        <?php if ($canChangeNote($n)): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete_note"><input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                            <button class="icon-btn" title="Delete note" aria-label="Delete note" data-confirm="This note will be removed permanently." data-confirm-title="Delete this note?" data-confirm-ok="Delete note" data-confirm-danger><?= icon('delete') ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="pre"><?= h($n['note']) ?></div>
                    <?php if ($canChangeNote($n)): ?>
                    <details class="note-edit">
                        <summary class="link small"><?= icon('edit') ?><span class="when-closed">Edit</span><span class="when-open">Cancel</span></summary>
                        <form method="post" class="form-stack mt">
                            <?= csrf_field() ?><input type="hidden" name="action" value="edit_note"><input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                            <label class="field"><span>Edit note</span><textarea name="note" rows="3" maxlength="2000" required><?= h($n['note']) ?></textarea></label>
                            <div><button class="btn btn-primary"><?= icon('check') ?>Save note</button></div>
                        </form>
                    </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <?php if (is_admin()): ?>
        <section class="card" id="status">
            <h2 class="label-caps">Support eligibility</h2>
            <?php if ($c['status'] === 'Active'): ?>
                <details class="disclosure">
                    <summary class="btn btn-danger-outline"><?= icon('block') ?>Block customer</summary>
                    <form method="post" class="form-stack mt">
                        <?= csrf_field() ?><input type="hidden" name="action" value="nosupport_on">
                        <label class="field"><span>Reason <b class="req">*</b></span><input name="reason" required maxlength="500" placeholder="e.g. Pending payment"></label>
                        <label class="field"><span>Administrative note</span><textarea name="note" rows="2" placeholder="Optional"></textarea></label>
                        <button class="btn btn-danger" data-confirm="No new support calls can be logged for <?= h($c['name']) ?> until they are unblocked." data-confirm-title="Block this customer?" data-confirm-ok="Block customer" data-confirm-danger><?= icon('block') ?>Confirm block</button>
                    </form>
                </details>
                <form method="post" class="mt">
                    <?= csrf_field() ?><input type="hidden" name="action" value="inactivate">
                    <button class="btn" data-confirm="<?= h($c['name']) ?> will be marked Inactive. Their support history is kept." data-confirm-title="Mark customer inactive?" data-confirm-ok="Mark inactive"><?= icon('inventory_2') ?>Mark Inactive</button>
                </form>
            <?php elseif ($c['status'] === 'NoSupport' && $c['erp_blocked']): ?>
                <p class="muted small">Blocked in the ERP. Unblock the customer there.</p>
            <?php elseif ($c['status'] === 'NoSupport'): ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="nosupport_off">
                    <button class="btn btn-primary" data-confirm="<?= h($c['name']) ?> becomes Active and can receive support again." data-confirm-title="Unblock this customer?" data-confirm-ok="Unblock customer"><?= icon('task_alt') ?>Unblock customer</button>
                </form>
                <p class="muted small mt">The customer becomes Active and can receive support again.</p>
            <?php elseif ($c['erp_inactive']): ?>
                <p class="muted small">Inactive in the ERP. Reactivate the customer there.</p>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reactivate">
                    <button class="btn btn-primary" data-confirm="<?= h($c['name']) ?> becomes Active again." data-confirm-title="Reactivate this customer?" data-confirm-ok="Reactivate"><?= icon('restart_alt') ?>Reactivate customer</button>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card">
            <div class="card-head"><h2 class="label-caps">Block history</h2><span class="chip mono"><?= count($restrictions) ?></span></div>
            <?php if (!$restrictions): ?><p class="muted small">This customer has never been blocked.</p><?php endif; ?>
            <?php foreach ($restrictions as $n): ?>
                <div class="restriction">
                    <div class="restriction-top">
                        <span class="mono small"><?= h(fmt_date($n['start_date'])) ?> – <?= $n['end_date'] ? h(fmt_date($n['end_date'])) : 'present' ?></span>
                        <?= $n['end_date'] ? '<span class="tag">Released</span>' : '<span class="tag-red">Active restriction</span>' ?>
                    </div>
                    <div><strong>Reason:</strong> <?= h($n['reason']) ?></div>
                    <?php if ($n['note']): ?><div class="small pre"><?= h($n['note']) ?></div><?php endif; ?>
                    <div class="small muted">Placed by <?= h($n['placed_by_name']) ?><?= $n['ended_by_name'] ? ' · Released by ' . h($n['ended_by_name']) : '' ?></div>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <section class="card card-flush">
        <div class="table-head">
            <div class="row-gap"><?= icon('history') ?><h2 class="card-title">Support history</h2></div>
            <span class="label-caps muted">Most recent first</span>
        </div>
        <?php if (!$calls): ?>
            <div class="empty"><?= icon('inbox') ?><p>No support calls recorded for this customer.</p></div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Date</th><th>Supporter</th><th>Type</th><th>Status</th><th>Task</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($calls as $r): ?>
                    <tr>
                        <td class="mono nowrap"><?= h(fmt_date($r['doc_date'])) ?></td>
                        <td class="nowrap"><span class="mini-avatar"><?= h(initial($r['supporter_name'])) ?></span><?= h($r['supporter_name']) ?></td>
                        <td><?= type_badge($r['type']) ?></td>
                        <td><?= call_status_badge($r['status']) ?></td>
                        <td class="task"><?= h($r['task_completed']) ?></td>
                        <td class="right"><a class="btn btn-sm" href="call_view.php?id=<?= (int) $r['doc_id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>
<?php page_end();
