<?php
// Copy from db.php.example. Credentials live in config.local.php (project root).

const APP_COMPANY = 'Infos';   // shown in the app header and on PDF reports

$local = __DIR__ . '/../config.local.php';
if (!is_readable($local)) {
    error_log('Missing config.local.php');
    http_response_code(500);
    die('Server configuration error: config.local.php is missing.');
}

$cfg = require $local;
$db = $cfg['db'] ?? null;
if (!is_array($db) || empty($db['server']) || empty($db['database']) || empty($db['user'])) {
    error_log('config.local.php is missing db.server / db.database / db.user');
    http_response_code(500);
    die('Server configuration error: incomplete database settings.');
}

$server   = $db['server'];
$database = $db['database'];
$user     = $db['user'];
$password = (string) ($db['password'] ?? '');

date_default_timezone_set('Asia/Dubai');

try {
    $conn = new PDO(
        "sqlsrv:Server=$server;Database=$database;TrustServerCertificate=1",
        $user,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection error. Check GearHost server name, database, user, and password in config.local.php.');
}
