<?php
// Customer lookup for the support-call form (JSON). Searched on the server so the page never
// has to carry the whole ERP customer list.
require __DIR__ . '/inc/bootstrap.php';
require_login();

$q = trim((string) ($_GET['q'] ?? ''));
$params = [current_branch()['company_code']];
$where = '';
if ($q !== '') {
    $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $q) . '%';
    $where = ' AND (a.description LIKE ? OR a.ac_code LIKE ? OR a.contact_name LIKE ? OR a.contact_no LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}

$st = $conn->prepare('SELECT TOP 30 ' . CUSTOMER_COLUMNS . ' ' . customer_from() . $where . ' ORDER BY a.description');
$st->execute($params);

header('Content-Type: application/json');
echo json_encode(array_map(function ($c) {
    return ['v' => $c['ac_code'], 't' => customer_label($c), 's' => $c['status'], 'c' => customer_category($c)];
}, $st->fetchAll()));
