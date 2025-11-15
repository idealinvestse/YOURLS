<?php
// YOURLS health check endpoint
// Returns a simple JSON payload indicating basic system health.

define('YOURLS_HEALTHCHECK', true);

require_once __DIR__ . '/includes/load-yourls.php';

// Default response structure
$status = [
    'status'    => 'ok',
    'version'   => defined('YOURLS_VERSION') ? YOURLS_VERSION : null,
    'installed' => null,
    'db'        => 'unknown',
];

$code = 200;

// Check install state
try {
    $installed = yourls_is_installed();
    $status['installed'] = (bool)$installed;
} catch (Exception $e) {
    $status['installed'] = null;
}

// Check DB connectivity with a trivial query
try {
    $db = yourls_get_db();
    // Use a very lightweight query on URL table
    $db->fetchValue('SELECT 1');
    $status['db'] = 'ok';
} catch (Exception $e) {
    $status['db'] = 'error';
    $status['error'] = 'db_unreachable';
    $code = 503;
}

if ($status['installed'] === false) {
    $status['status'] = 'install_required';
    $code = 503;
} elseif ($status['db'] !== 'ok') {
    $status['status'] = 'error';
}

// Send headers
if (!headers_sent()) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($status);

exit;
