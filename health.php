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
    // Runtime flags provide additional observability without breaking
    // existing consumers.
    'flags'     => [
        'safe_mode'               => (bool) ( defined('YOURLS_SAFE_MODE') && YOURLS_SAFE_MODE ),
        'degraded_stats'          => (bool) ( defined('YOURLS_DEGRADED_STATS') && YOURLS_DEGRADED_STATS ),
        'debug'                   => (bool) ( function_exists('yourls_get_debug_mode') ? yourls_get_debug_mode() : ( defined('YOURLS_DEBUG') && YOURLS_DEBUG ) ),
        'log_level'               => defined('YOURLS_LOG_LEVEL') ? strtolower((string) YOURLS_LOG_LEVEL) : 'warning',
        'unknown_keyword_behavior'=> defined('YOURLS_UNKNOWN_KEYWORD_BEHAVIOR') ? strtolower((string) YOURLS_UNKNOWN_KEYWORD_BEHAVIOR) : 'home',
    ],
    // Simple high-level mode indicator: 'normal' or 'degraded'.
    'mode'      => 'normal',
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
    $status['mode']   = 'degraded';
    $code = 503;
} elseif ($status['db'] !== 'ok') {
    $status['status'] = 'error';
    $status['mode']   = 'degraded';
}

// If runtime flags indicate we're intentionally running with degraded stats,
// surface this as a degraded mode as well, even if DB and install are ok.
if ($status['mode'] === 'normal' && !empty($status['flags']['degraded_stats'])) {
    $status['mode'] = 'degraded';
}

// Send headers
if (!headers_sent()) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($status);

exit;
