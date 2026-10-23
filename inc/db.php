<?php
// Credentials live in config.local.php (project root) — same pattern as TaskManager.
// That file is gitignored: upload it on GearHost via File Manager / FTP after each deploy.

const APP_COMPANY = 'Infos';   // shown in the app header and on PDF reports

$local = __DIR__ . '/../config.local.php';
if (!is_readable($local)) {
    error_log('Missing config.local.php');
    echo '<div style="font-family:sans-serif;max-width:40rem;margin:3rem auto;padding:1.5rem;border:1px solid #ccc">';
    echo '<h2>Database configuration missing</h2>';
    echo '<p>Upload <code>config.local.php</code> to the site root on GearHost (same folder as <code>index.php</code>).</p>';
    echo '<p>Copy from <code>config.local.php.example</code> and set the GearHost database password — same values as TaskManager.</p>';
    echo '</div>';
    exit;
}

$cfg = require $local;
$db = $cfg['db'] ?? null;
if (!is_array($db) || empty($db['server']) || empty($db['database']) || empty($db['user'])) {
    error_log('config.local.php is missing db.server / db.database / db.user');
    echo '<div style="font-family:sans-serif;max-width:40rem;margin:3rem auto;padding:1.5rem;border:1px solid #ccc">';
    echo '<h2>Incomplete database settings</h2>';
    echo '<p><code>config.local.php</code> must include <code>db.server</code>, <code>db.database</code>, and <code>db.user</code>.</p>';
    echo '</div>';
    exit;
}

$server   = $db['server'];
$database = $db['database'];
$user     = $db['user'];
$password = (string) ($db['password'] ?? '');

date_default_timezone_set('Asia/Dubai');

try {
    // Same DSN flags as the working TaskManager site on GearHost
    $dsn  = "sqlsrv:Server=$server;Database=$database;Encrypt=1;TrustServerCertificate=1";
    $conn = new PDO($dsn, $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Connection failed: ' . $e->getMessage());
    echo '<div style="font-family:sans-serif;max-width:40rem;margin:3rem auto;padding:1.5rem;border:1px solid #ccc">';
    echo '<h2>Database connection error</h2>';
    echo '<p><b>Error:</b> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><b>Code:</b> ' . htmlspecialchars((string) $e->getCode()) . '</p>';
    echo '<p>Check server / database / user / password in <code>config.local.php</code> (GearHost → Databases).</p>';
    echo '</div>';
    exit;
}
