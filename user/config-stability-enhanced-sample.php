<?php
/**
 * YOURLS Enhanced Configuration (Stability & Resilience Features)
 *
 * This is an ENHANCED sample config file demonstrating all stability,
 * observability, and resilience features added in YOURLS 1.9.2+
 *
 * IMPORTANT:
 * - Copy user/config-sample.php to user/config.php for basic setup
 * - Use THIS file as a reference for advanced production configurations
 * - Only add constants you actually need; defaults are sensible
 *
 * For full documentation, see:
 * - YOURLS-STABILITY-FEATURES.md
 * - YOURLS-OPERATIONS-GUIDE.md
 * - YOURLS-ARCHITECTURE.md
 */

/*
 ** MySQL settings - You can get this info from your web host
 */

/** MySQL database username */
define( 'YOURLS_DB_USER', 'yourls_user' );

/** MySQL database password */
define( 'YOURLS_DB_PASS', 'your_secure_password_here' );

/** The name of the database for YOURLS */
define( 'YOURLS_DB_NAME', 'yourls_db' );

/** MySQL hostname (use 'localhost' unless your DB is on a different server) */
define( 'YOURLS_DB_HOST', 'localhost' );

/** MySQL tables prefix */
define( 'YOURLS_DB_PREFIX', 'yourls_' );

/*
 ** Site options
 */

/** YOURLS installation URL - ALL LOWERCASE, NO TRAILING SLASH
 ** Use HTTPS in production! */
define( 'YOURLS_SITE', 'https://your-short-domain.com' );

/** YOURLS language (leave empty for English, or set to 'fr_FR', 'es_ES', etc.) */
define( 'YOURLS_LANG', '' );

/** Allow multiple short URLs for a same long URL
 ** Set to TRUE to have only one pair of shortURL/longURL (default YOURLS behavior)
 ** Set to FALSE to allow multiple short URLs pointing to the same long URL */
define( 'YOURLS_UNIQUE_URLS', true );

/** Private means the Admin area will be protected with login/pass
 ** Set to FALSE for public usage (careful: anyone can create short URLs!)
 ** Read http://yourls.org/privatepublic for more details */
define( 'YOURLS_PRIVATE', true );

/** Optional: Make API public even if site is private
 ** Useful if you want a private admin but public API access */
// define( 'YOURLS_PRIVATE_API', false );

/** Optional: Make stats pages public even if site is private */
// define( 'YOURLS_PRIVATE_INFOS', false );

/** A random secret hash used to encrypt cookies
 ** Generate a unique one at https://yourls.org/cookie */
define( 'YOURLS_COOKIEKEY', 'generate-a-random-string-here' );

/** Username(s) and password(s) allowed to access the site
 ** Passwords can be plain text (auto-encrypted) or pre-hashed
 ** See https://yourls.org/userpassword for more information */
$yourls_user_passwords = [
	'admin' => 'SecurePassword123',
	// 'username2' => 'password2',
];

/** URL shortening method: 36 or 62
 ** 36: lowercase only (0-9, a-z)
 ** 62: mixed case (0-9, A-Z, a-z)
 ** Stick to one setting! Changing this will break existing short URLs */
define( 'YOURLS_URL_CONVERT', 36 );

/** Reserved keywords (won't be used for auto-generated short URLs) */
$yourls_reserved_URL = [
	'admin', 'api', 'status', 'health', 'index', 'home',
	// Add any other keywords you want to reserve
];

/*
 ** ===================================================================
 ** STABILITY & RESILIENCE FEATURES (New in 1.9.2+)
 ** ===================================================================
 */

/**
 * ──────────────────────────────────────────────────────────────────
 * LOGGING & OBSERVABILITY
 * ──────────────────────────────────────────────────────────────────
 */

/** Enable debug mode (required for yourls_log() to work)
 ** When enabled, YOURLS will log events to PHP's error_log
 ** Recommended: Enable in production with appropriate log level */
define( 'YOURLS_DEBUG', true );

/** Minimum log level to record
 ** Valid values: 'error', 'warning', 'info', 'debug'
 ** Default if not set: 'warning'
 **
 ** Production recommendation: 'warning' (logs only warnings and errors)
 ** Development: 'debug' (logs everything)
 */
define( 'YOURLS_LOG_LEVEL', 'warning' );

/** Sample rate for "unknown keyword" events
 ** Value of 1 = log all unknown keywords
 ** Value of 10 = log ~10% of unknown keywords
 ** Value of 100 = log ~1% of unknown keywords
 **
 ** Use this to reduce log volume for very high-traffic sites
 ** Default if not set: 1 (log all)
 */
define( 'YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD', 100 );

/** Sample rate for authentication failure events
 ** Value of 1 = log all auth failures
 ** Value of 10 = log ~10% of auth failures
 **
 ** Useful for reducing noise from brute-force attempts
 ** Default if not set: 1 (log all)
 */
define( 'YOURLS_LOG_SAMPLE_AUTH_FAILURE', 10 );

/**
 * ──────────────────────────────────────────────────────────────────
 * DEGRADED STATS MODE (High-Load / Performance)
 * ──────────────────────────────────────────────────────────────────
 */

/** Enable degraded stats mode
 ** When TRUE, YOURLS will:
 ** - Skip click count updates
 ** - Skip stats logging (no INSERT into log table)
 ** - Keep redirects working normally
 **
 ** Use this during:
 ** - Traffic spikes
 ** - DB performance issues
 ** - Planned maintenance with reduced write capacity
 **
 ** Default if not set: FALSE (normal stats operation)
 */
// define( 'YOURLS_DEGRADED_STATS', true );

/** Classic option: Disable all stats logging
 ** Existing YOURLS constant. More aggressive than DEGRADED_STATS
 ** When TRUE, no redirect logs are written at all
 ** Default if not set: stats are enabled
 */
// define( 'YOURLS_NOSTATS', true );

/**
 * ──────────────────────────────────────────────────────────────────
 * PLUGIN SAFE MODE (Recovery & Troubleshooting)
 * ──────────────────────────────────────────────────────────────────
 */

/** Enable safe mode (disable all plugins)
 ** When TRUE, YOURLS will not load ANY plugins
 ** Use this to:
 ** - Recover from a broken plugin causing crashes
 ** - Access admin area when plugins are interfering
 ** - Diagnose plugin conflicts
 **
 ** Recovery workflow:
 ** 1. Enable safe mode by uncommenting this line
 ** 2. Access admin area and deactivate the problematic plugin
 ** 3. Comment out this line again
 ** 4. Reload and verify
 **
 ** Default if not set: FALSE (plugins load normally)
 */
// define( 'YOURLS_SAFE_MODE', true );

/**
 * ──────────────────────────────────────────────────────────────────
 * UNKNOWN KEYWORD BEHAVIOR (Error Handling)
 * ──────────────────────────────────────────────────────────────────
 */

/** What to do when a short URL is not found
 ** Valid values:
 ** - 'home' (default): 302 redirect to YOURLS_SITE (original behavior)
 ** - '404': Return HTTP 404 "Not Found" error page
 ** - 'custom': Redirect to a custom URL (see YOURLS_UNKNOWN_KEYWORD_URL)
 **
 ** Examples:
 ** - SEO-friendly: Use '404' to return proper 404 status
 ** - Custom error page: Use 'custom' with your own 404 page URL
 ** - Classic behavior: Use 'home' or leave undefined
 */
define( 'YOURLS_UNKNOWN_KEYWORD_BEHAVIOR', 'home' );

/** Custom URL for unknown keywords (only if behavior='custom')
 ** If YOURLS_UNKNOWN_KEYWORD_BEHAVIOR is set to 'custom', users will
 ** be redirected to this URL when requesting an unknown short link
 */
// define( 'YOURLS_UNKNOWN_KEYWORD_URL', 'https://your-domain.com/404-page' );

/**
 * ──────────────────────────────────────────────────────────────────
 * INPUT VALIDATION LIMITS (Security & Performance)
 * ──────────────────────────────────────────────────────────────────
 */

/** Maximum keyword length
 ** Applies to both:
 ** - Direct short URL requests (yourls-go.php)
 ** - API keyword parameter
 **
 ** Keywords exceeding this length are:
 ** - In go.php: Treated as unknown (logged and handled per UNKNOWN_KEYWORD_BEHAVIOR)
 ** - In API: Rejected with 414 error
 **
 ** Default if not set: 128
 ** Set to 0 or negative to disable check
 */
define( 'YOURLS_MAX_KEYWORD_LENGTH', 128 );

/** Maximum URL length for API shorten requests
 ** Prevents abuse via extremely long URLs
 ** URLs exceeding this length are rejected with a 414 error
 **
 ** Default if not set: 2048
 ** Set to 0 or negative to disable check
 */
define( 'YOURLS_MAX_URL_LENGTH', 2048 );

/*
 ** ===================================================================
 ** ADVANCED / OPTIONAL SETTINGS
 ** ===================================================================
 */

/** Timezone offset (in hours, can be negative or decimal)
 ** Example: define( 'YOURLS_HOURS_OFFSET', -5 ); for US Eastern */
// define( 'YOURLS_HOURS_OFFSET', 0 );

/** Anti-flood protection: minimum seconds between URL creations from same IP
 ** Set to 0 to disable flood protection
 ** Logged-in users (if private mode) are not throttled */
define( 'YOURLS_FLOOD_DELAY_SECONDS', 10 );

/** IPs whitelisted from flood protection (comma-separated) */
// define( 'YOURLS_FLOOD_IP_WHITELIST', '192.168.1.100,10.0.0.5' );

/*
 ** ===================================================================
 ** PRODUCTION CHECKLIST
 ** ===================================================================
 **
 ** Before going live:
 **
 ** 1. Security:
 **    - Set YOURLS_PRIVATE to TRUE
 **    - Use strong passwords (or pre-hashed passwords)
 **    - Generate unique YOURLS_COOKIEKEY
 **    - Enable HTTPS (YOURLS_SITE should start with https://)
 **
 ** 2. Observability:
 **    - Enable YOURLS_DEBUG with appropriate YOURLS_LOG_LEVEL
 **    - Configure sampling for high-volume events
 **    - Set up monitoring on health.php endpoint
 **
 ** 3. Performance:
 **    - Configure your DB for production load
 **    - Enable OPcache in PHP
 **    - Consider YOURLS_DEGRADED_STATS for very high traffic
 **
 ** 4. Resilience:
 **    - Test YOURLS_SAFE_MODE for plugin recovery
 **    - Configure YOURLS_UNKNOWN_KEYWORD_BEHAVIOR appropriately
 **    - Set input limits (MAX_KEYWORD_LENGTH, MAX_URL_LENGTH)
 **
 ** 5. Monitoring:
 **    - Monitor: https://your-domain.com/health.php
 **    - Review: https://your-domain.com/admin/status.php
 **    - Watch logs: grep '\[YOURLS\]' /var/log/php_errors.log
 **
 ** For detailed guidance, see:
 ** - YOURLS-OPERATIONS-GUIDE.md
 ** - YOURLS-STABILITY-FEATURES.md
 */

/*
 ** Personal settings and custom constants can go below this line
 */
