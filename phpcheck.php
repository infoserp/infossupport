<?php
// Temporary diagnostic — delete after the site works.
header('Content-Type: text/plain; charset=UTF-8');
echo "PHP version: " . PHP_VERSION . "\n";
echo "pdo_sqlsrv: " . (extension_loaded('pdo_sqlsrv') ? 'YES' : 'NO') . "\n";
echo "sqlsrv: " . (extension_loaded('sqlsrv') ? 'YES' : 'NO') . "\n";
echo "config.local.php: " . (is_readable(__DIR__ . '/config.local.php') ? 'FOUND' : 'MISSING — upload to site root') . "\n";
echo "\nThis app needs PHP 8.1+ (typed properties, match, never).\n";
echo "If version is below 8.1: GearHost → your CloudSite → Config → PHP Version → pick 8.x, then Restart.\n";
