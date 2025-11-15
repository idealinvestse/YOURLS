<?php
/*
 * Functions relative to debugging
 */

/**
 * Add a message to the debug log
 *
 * When in debug mode ( YOURLS_DEBUG == true ) the debug log is echoed in yourls_html_footer()
 * Log messages are appended to $ydb->debug_log array, which is instanciated within class Database\YDB
 *
 * @since 1.7
 * @param string $msg Message to add to the debug log
 * @return string The message itself
 */
function yourls_debug_log( $msg ) {
    yourls_do_action( 'debug_log', $msg );
    // Get the DB object ($ydb), get its profiler (\Aura\Sql\Profiler\Profiler), its logger (\Aura\Sql\Profiler\MemoryLogger) and
    // pass it a unused argument (loglevel) and the message
    // Check if function exists to allow usage of the function in very early stages
    if(function_exists('yourls_debug_log')) {
        yourls_get_db()->getProfiler()->getLogger()->log( 'debug', $msg);
    }
    return $msg;
}

/**
 * Get the debug log
 *
 * @since  1.7.3
 * @return array
 */
function yourls_get_debug_log() {
    return yourls_get_db()->getProfiler()->getLogger()->getMessages();
}

/**
 * Get number of SQL queries performed
 *
 * @return int
 */
function yourls_get_num_queries() {
	return yourls_apply_filter( 'get_num_queries', yourls_get_db()->get_num_queries() );
}

/**
 * Debug mode set
 *
 * @since 1.7.3
 * @param bool $bool Debug on or off
 * @return void
 */
function yourls_debug_mode( $bool ) {
    // log queries if true
    yourls_get_db()->getProfiler()->setActive( (bool)$bool );

    // report notices if true
    $level = $bool ? -1 : ( E_ERROR | E_PARSE );
    error_reporting( $level );
}

/**
 * Return YOURLS debug mode
 *
 * @since 1.7.7
 * @return bool
 */
function yourls_get_debug_mode() {
    return defined( 'YOURLS_DEBUG' ) && YOURLS_DEBUG;
}

/**
 * Lightweight structured logger.
 *
 * Logs messages to PHP's error_log only when YOURLS_DEBUG is true, so
 * production behavior is unchanged by default.
 *
 * @since 1.9.2
 * @param string $level   Log level: debug, info, warning, error
 * @param string $message Log message
 * @param array  $context Optional contextual data to JSON-encode
 * @return void
 */
function yourls_log( $level, $message, array $context = [] ) {
    if ( !yourls_get_debug_mode() ) {
        return;
    }

    // Normalize level
    $level = strtolower( (string) $level );

    // Simple level mapping so we can compare severity
    $levels = [
        'error'   => 0,
        'warning' => 1,
        'info'    => 2,
        'debug'   => 3,
    ];

    if ( !isset( $levels[ $level ] ) ) {
        $level = 'info';
    }

    // Determine minimum level to actually log. If not defined, default to
    // 'warning' to avoid excessive noise in production when debug is on.
    $min_level_name = 'warning';
    if ( defined( 'YOURLS_LOG_LEVEL' ) && is_string( YOURLS_LOG_LEVEL ) ) {
        $candidate = strtolower( YOURLS_LOG_LEVEL );
        if ( isset( $levels[ $candidate ] ) ) {
            $min_level_name = $candidate;
        }
    }

    $min_level = $levels[ $min_level_name ];
    if ( $levels[ $level ] > $min_level ) {
        // Below threshold: do not log.
        return;
    }

    // Basic sampling for particularly noisy events. Sampling values are
    // configured via optional constants; a value of 1 means "log all".
    $sampling = [];
    $sampling['unknown_keyword'] = defined( 'YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD' ) ? (int) YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD : 1;
    $sampling['auth_failure']    = defined( 'YOURLS_LOG_SAMPLE_AUTH_FAILURE' )    ? (int) YOURLS_LOG_SAMPLE_AUTH_FAILURE    : 1;

    if ( isset( $sampling[ $message ] ) ) {
        $rate = (int) $sampling[ $message ];
        if ( $rate > 1 ) {
            // Log roughly 1 out of $rate events.
            if ( mt_rand( 1, $rate ) !== 1 ) {
                return;
            }
        }
    }

    // Basic context serialization; ignore failures silently to avoid
    // introducing new errors in debug logging itself.
    $context_str = '';
    if ( !empty( $context ) ) {
        $encoded = json_encode( $context );
        if ( is_string( $encoded ) ) {
            $context_str = ' ' . $encoded;
        }
    }

    $line = sprintf('[YOURLS] [%s] %s%s', $level, $message, $context_str );
    error_log( $line );
}
