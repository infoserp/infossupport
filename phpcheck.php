<?php
// Temporary diagnostic — delete after the site works.
header('Content-Type: text/plain; charset=UTF-8');
echo "PHP version: " . PHP_VERSION . "\n";
echo "pdo_sqlsrv: " . (extension_loaded('pdo_sqlsrv') ? 'YES' : 'NO') . "\n";
echo "sqlsrv: " . (extension_loaded('sqlsrv') ? 'YES' : 'NO') . "\n";
echo "config.local.php: " . (is_readable(__DIR__ . '/config.local.php') ? 'FOUND' : 'MISSING — upload to site root') . "\n";
echo "\nApp syntax target: PHP 7.1+\n";
if (version_compare(PHP_VERSION, '7.1.0', '<')) {
    echo "ERROR: PHP 7.1 or newer is required.\n";
} else {
    echo "OK: PHP version is compatible.\n";
}
