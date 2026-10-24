<?php
// Permanently delete a support call (POST only).
// Allowed for the supporter who entered the call, or any administrator — same rule as editing.
require __DIR__ . '/inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(calls_list_url());
}
$me = require_login();
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$b = current_branch();
$st = $conn->prepare('SELECT doc_id, emp_code FROM v_support_calls WHERE doc_id = ? AND company_code = ? AND branch_code = ?');
$st->execute([$id, $b['company_code'], $b['branch_code']]);
$call = $st->fetch();

if (!$call) {
    flash('error', 'Support call not found.');
    redirect(calls_list_url());
}
if (!can_edit_call($call)) {
    http_response_code(403);
    flash('error', 'Only the supporter who entered this support call, or an administrator, can delete it.');
    redirect('call_view.php?id=' . $id);
}

// The deleted row, with who deleted it and when, goes to support_calls_deleted in the same statement.
$conn->prepare('DELETE FROM support_calls
    OUTPUT DELETED.doc_id, DELETED.company_code, DELETED.branch_code, DELETED.doc_date, DELETED.ac_code, DELETED.customer_name, DELETED.emp_code, DELETED.supporter_name, DELETED.type, DELETED.task_completed, DELETED.status, DELETED.comment, DELETED.user_date, DELETED.last_userdate, DELETED.last_userid, DELETED.job_no, DELETED.time_from, DELETED.time_to, ?, SYSDATETIME()
    INTO support_calls_deleted (doc_id, company_code, branch_code, doc_date, ac_code, customer_name, emp_code, supporter_name, type, task_completed, status, comment, user_date, last_userdate, last_userid, job_no, time_from, time_to, deleted_userid, deleted_userdate)
    WHERE doc_id = ?')->execute([$me['code'], $id]);
flash('success', 'Support call #' . $id . ' deleted.');
redirect(calls_list_url());
