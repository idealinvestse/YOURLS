# YOURLS Architecture Guide

**Version:** 1.9.2+  
**Audience:** Developers, plugin authors, contributors  
**Last Updated:** November 2025

This document explains the internal architecture, bootstrap sequence, routing, database layer, plugin system, and key design patterns of YOURLS.

---

## Table of Contents

1. [Project Structure](#project-structure)
2. [Bootstrap Sequence](#bootstrap-sequence)
3. [Routing & Request Dispatch](#routing--request-dispatch)
4. [Database Layer](#database-layer)
5. [Options API](#options-api)
6. [Plugin System](#plugin-system)
7. [Hook System](#hook-system)
8. [Authentication](#authentication)
9. [Logging & Observability](#logging--observability)
10. [Design Patterns](#design-patterns)

---

## Project Structure

```
yourls/
├── admin/                    # Admin UI pages
│   ├── index.php            # Main admin dashboard
│   ├── plugins.php          # Plugin management
│   ├── tools.php            # Admin tools
│   └── status.php           # System status page (new)
├── css/                      # Stylesheets
├── images/                   # Static images
├── includes/                 # Core library files
│   ├── Config/
│   │   ├── Config.php       # Default config values
│   │   └── Init.php         # Bootstrap initialization
│   ├── Database/
│   │   ├── YDB.php          # DB wrapper (extends Aura SQL)
│   │   └── Options.php      # Options table abstraction
│   ├── functions*.php       # Core helper functions
│   ├── class-mysql.php      # Legacy DB connection helper
│   ├── load-yourls.php      # Main bootstrap entry point
│   ├── geo/                 # GeoIP data
│   └── vendor/              # Third-party libraries (Composer)
├── js/                       # JavaScript files
├── user/                     # User-configurable files
│   ├── config.php           # User configuration (created from config-sample.php)
│   ├── plugins/             # Installed plugins
│   └── languages/           # Translation files
├── yourls-loader.php         # Front controller (routing entry point)
├── yourls-go.php             # Short URL redirect controller
├── yourls-infos.php          # Stats/info page controller
├── yourls-api.php            # API endpoint
├── health.php                # Health check endpoint (new)
├── index.php                 # Optional: custom front page
└── readme.html               # Documentation
```

---

## Bootstrap Sequence

### Entry Points

YOURLS has multiple entry points depending on the request:

- **`yourls-loader.php`**: Parses path, dispatches to `go`, `infos`, or fallback
- **`yourls-go.php`**: Direct entry for redirects (if rewrite rules point here)
- **`yourls-api.php`**: API requests
- **`admin/*.php`**: Admin pages
- **`health.php`**: Health check

All entry points eventually call:

```php
require_once( dirname(__FILE__) . '/includes/load-yourls.php' );
```

### `load-yourls.php` Sequence

1. **Define `YOURLS_ABSPATH`**: Base directory path
2. **Load user config**: `user/config.php`
   - Sets DB credentials, site URL, user passwords, etc.
   - Plugins cannot modify config constants here (already defined)
3. **Load default config**: `includes/Config/Config.php`
   - Provides fallback values for undefined constants
4. **Load core functions**: Various `includes/functions*.php` files
5. **Initialize**: `includes/Config/Init.php`
   - Connect to database
   - Load all options from DB (sets `installed` flag)
   - Load active plugins (unless `YOURLS_SAFE_MODE` is true)
   - Check for required upgrades
6. **Ready**: Core is initialized, plugins are loaded, DB is connected

**Flow diagram:**

```
Entry Point (loader/go/api/admin)
    ↓
load-yourls.php
    ↓
User Config (user/config.php)
    ↓
Default Config (includes/Config/Config.php)
    ↓
Core Functions (includes/functions*.php)
    ↓
Database Connection (class-mysql.php, YDB.php)
    ↓
Load Options (Options.php)
    ↓
Load Plugins (functions-plugins.php, unless SAFE_MODE)
    ↓
Check Upgrade (yourls_upgrade_is_needed)
    ↓
Application Ready
```

---

## Routing & Request Dispatch

### `yourls-loader.php`

The loader is the front controller for all short URL requests when rewrite rules are configured.

**Process:**

1. **Parse request**: Extract keyword from `REQUEST_URI` or `PATH_INFO`
2. **Determine action**:
   - If path matches a "page" pattern (e.g., `/abc123+`): Route to `yourls-infos.php`
   - Otherwise: Route to `yourls-go.php` with the keyword
3. **Fallback**: If routing fails, call `yourls_handle_unknown_keyword()`

**Key code:**

```php
// Extract keyword from request
$request = yourls_get_request();

// Check if it's an info/stats page
if ( yourls_is_page($request) ) {
    require_once( YOURLS_ABSPATH . '/yourls-infos.php' );
    exit;
}

// Otherwise, treat as a short URL redirect
$keyword = $request;
require_once( YOURLS_ABSPATH . '/yourls-go.php' );
```

### `yourls-go.php`

Handles short URL redirection.

**Process:**

1. **Sanitize keyword**: `yourls_sanitize_keyword($keyword)`
2. **Input validation**: Check against `YOURLS_MAX_KEYWORD_LENGTH` (new)
3. **Check if page**: Call `yourls_is_page()` and render if true
4. **Lookup long URL**: `yourls_get_keyword_longurl($keyword)`
5. **Redirect**: If found, call `yourls_redirect_shorturl($url, $keyword)`
   - Updates click count
   - Logs redirect
   - Sends HTTP 301/302
6. **Unknown keyword**: If not found, call `yourls_handle_unknown_keyword($keyword, 'go')`

### `yourls-infos.php`

Displays statistics for a short URL.

**Process:**

1. **Parse keyword**: Extract from request (e.g., `/abc123+`)
2. **Fetch stats**: Query click log table
3. **Render**: Display charts, referrers, geographic data, etc.

### `yourls-api.php`

Handles programmatic API requests.

**Process:**

1. **Authenticate**: `yourls_maybe_require_auth()` (if private)
2. **Parse action**: `$_REQUEST['action']`
3. **Input validation**: Check URL/keyword lengths against limits (new)
4. **Dispatch**: Call registered action via filter: `yourls_apply_filter('api_action_' . $action, false)`
5. **Output**: Format response as JSON, XML, or simple text

**Registered actions:**

- `shorturl`: Create or retrieve short URL
- `expand`: Get long URL from short
- `stats`: Get link statistics
- `db-stats`: Get global stats
- `url-stats`: Get per-URL stats
- `version`: Get YOURLS version

Plugins can add custom actions by hooking `api_actions` filter.

---

## Database Layer

### Connection: `class-mysql.php` + `YDB.php`

**Legacy wrapper** (`class-mysql.php`):

```php
function yourls_db_connect() {
    // Check for required constants
    // Create PDO connection
    // Return YOURLS\Database\YDB instance
}
```

**Modern wrapper** (`YDB.php`):

Extends `Aura\Sql\ExtendedPdo`. Provides:

- **Query execution**: `fetchValue()`, `fetchOne()`, `fetchAll()`, `fetchAffected()`
- **Profiling**: Query logging when debug mode is on
- **Options caching**: Stores options in memory
- **Install state tracking**: `is_installed()`, `set_installed()`

**Accessing the DB:**

```php
$ydb = yourls_get_db();
$ydb->fetchValue('SELECT url FROM yourls_url WHERE keyword = :kw', ['kw' => 'abc']);
```

### Schema

**Core tables:**

1. **`yourls_url`**: Short URL mappings
   - `keyword` (PK): Short URL identifier
   - `url`: Long URL
   - `title`: Page title
   - `timestamp`: Creation time
   - `ip`: Creator IP
   - `clicks`: Click counter

2. **`yourls_options`**: Key-value configuration
   - `option_id` (PK, auto-increment)
   - `option_name`: Unique key
   - `option_value`: Serialized value

3. **`yourls_log`**: Click log (optional, for stats)
   - `click_id` (PK, auto-increment)
   - `click_time`: Timestamp
   - `shorturl`: Keyword
   - `referrer`: HTTP referrer
   - `user_agent`: Client UA
   - `ip_address`: Client IP
   - `country_code`: GeoIP result

**Indexes:**

- `keyword` on `yourls_url` (primary key)
- `option_name` on `yourls_options` (unique key)
- `shorturl`, `click_time` on `yourls_log`

---

## Options API

### `Options.php`

Provides object-oriented interface to `yourls_options` table.

**Key methods:**

- `get($name, $default)`: Fetch an option
- `add($name, $value)`: Add a new option
- `update($name, $value)`: Update existing option
- `delete($name)`: Remove an option
- `get_all_options()`: Load all options into cache

**Caching:**

- Options are cached in `YDB::$option` after first load
- `get_all_options()` is called once during bootstrap
- Subsequent reads hit cache, not DB

### Function wrappers (`functions-options.php`)

User-facing helpers:

```php
yourls_get_option( $name, $default = false )
yourls_add_option( $name, $value )
yourls_update_option( $name, $value )
yourls_delete_option( $name )
yourls_get_all_options()
```

**Filters:**

- `shunt_option_{name}`: Short-circuit get
- `get_option_{name}`: Modify retrieved value
- `shunt_all_options`: Short-circuit bulk load

---

## Plugin System

### Plugin Structure

A plugin is a PHP file in `user/plugins/{plugin-name}/plugin.php` that:

1. Declares metadata via `Plugin Name:` header comment
2. Registers hooks via `yourls_add_action()` / `yourls_add_filter()`

**Example:**

```php
<?php
/*
Plugin Name: Example Plugin
Plugin URI: https://example.com/
Description: Does something cool
Version: 1.0
Author: Your Name
Author URI: https://yourname.com/
*/

// Hook into URL creation
yourls_add_filter( 'add_new_link', 'my_plugin_intercept' );

function my_plugin_intercept( $args ) {
    // Modify $args before link is created
    return $args;
}
```

### Loading Process (`functions-plugins.php`)

**1. Discovery:**

```php
yourls_discover_plugins( $directory = 'user/plugins' )
```

Scans for `plugin.php` files, parses headers.

**2. Activation:**

Plugins are activated via admin UI or programmatically. Active plugin list stored in `active_plugins` option.

**3. Loading:**

```php
yourls_load_plugins()
```

Called during bootstrap (`includes/Config/Init.php`):

- Skipped if `YOURLS_SAFE_MODE` is true (new)
- Reads `active_plugins` from options
- For each active plugin:
  - Sandboxes loading in `yourls_load_plugin()`
  - Logs errors if plugin fails (`plugin_load_error` event, new)

**4. Execution:**

Plugins are now in memory. Hooks are registered and will fire when triggered.

### Plugin Hooks

Plugins interact with YOURLS via the hook system (see next section).

**Common hooks:**

- **Actions** (void return):
  - `plugins_loaded`: After all plugins loaded
  - `redirect_shorturl`: Before redirect
  - `pre_add_new_link`: Before creating short URL
  
- **Filters** (return modified value):
  - `add_new_link`: Modify link creation args
  - `api_action_{action}`: Handle custom API actions
  - `get_option_{name}`: Modify option values

### Safe Mode (New)

When `YOURLS_SAFE_MODE` is enabled:

```php
if ( defined( 'YOURLS_SAFE_MODE' ) && YOURLS_SAFE_MODE ) {
    // Skip plugin loading entirely
    return;
}
```

Allows recovery from broken plugins without manual file edits.

---

## Hook System

YOURLS uses a WordPress-style hook system.

### Actions

**Register:**

```php
yourls_add_action( 'hook_name', 'callback_function', $priority = 10 );
```

**Trigger:**

```php
yourls_do_action( 'hook_name', $arg1, $arg2, ... );
```

**Behavior:**

- Callbacks are executed in priority order (lower = earlier)
- Return values are ignored
- Used for side effects (logging, sending email, updating state)

### Filters

**Register:**

```php
yourls_add_filter( 'filter_name', 'callback_function', $priority = 10 );
```

**Trigger:**

```php
$value = yourls_apply_filter( 'filter_name', $value, $arg1, $arg2, ... );
```

**Behavior:**

- Callbacks receive `$value` as first parameter
- Must return (potentially modified) value
- Used for transforming data (modifying URLs, customizing output)

### Implementation (`functions-plugins.php`)

Both actions and filters use the same underlying storage:

```php
global $yourls_filters;
$yourls_filters[ $hook ][ $priority ][] = [ 'function' => $callback ];
```

When triggered:

1. Sort callbacks by priority
2. For filters: Thread value through each callback
3. For actions: Just execute callbacks

---

## Authentication

### User Storage

Users defined in `user/config.php`:

```php
$yourls_user_passwords = [
    'admin' => 'password123',          // Plain text (auto-encrypted)
    'user2' => 'phpass:$P$B...',       // Pre-hashed
];
```

YOURLS will automatically encrypt plain-text passwords on first use.

### Authentication Check

```php
yourls_is_valid_user()
```

Returns `true` if:

- Valid username/password provided (form or HTTP basic auth)
- Valid signature token (passwordless API)

**Flow:**

1. Check for existing valid session cookie
2. If not, check submitted credentials
3. Hash and compare password
4. Set session cookie if valid
5. Return true/false

### Authorization

- **Public mode** (`YOURLS_PRIVATE = false`): No auth required
- **Private mode** (`YOURLS_PRIVATE = true`): Auth required for admin, optionally for API/stats
- **Granular control**:
  - `YOURLS_PRIVATE_API`: Auth for API
  - `YOURLS_PRIVATE_INFOS`: Auth for stats pages

**Check in code:**

```php
if ( yourls_is_private() ) {
    yourls_maybe_require_auth();  // Exits if not authenticated
}
```

### Logging (New)

Authentication events are now logged:

- `auth_success`: Successful login
- `auth_failure`: Failed login attempt

Helps detect brute-force attacks or misconfigurations.

---

## Logging & Observability

### Structured Logging (`functions-debug.php`)

**New in 1.9.2+:**

```php
yourls_log( $level, $message, array $context = [] )
```

**Levels:**

- `error`: Critical failures
- `warning`: Recoverable issues
- `info`: Normal events
- `debug`: Verbose diagnostics

**Behavior:**

- Only logs when `YOURLS_DEBUG` is true
- Respects `YOURLS_LOG_LEVEL` threshold (default: `warning`)
- Applies per-event sampling (e.g., `YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD`)
- Outputs to PHP `error_log` in structured format:

```
[YOURLS] [level] event_name {"key":"value",...}
```

### Logged Events

- **Unknown keywords**: `unknown_keyword`
- **Auth**: `auth_success`, `auth_failure`
- **Plugins**: `plugin_load_error`, `plugin_activate_error`, etc.
- **API**: `api_unknown_action`, `api_url_too_long`, `api_keyword_too_long`
- **DB**: `db_connection_error`, `db_config_missing`
- **Options**: `options_empty_or_missing`
- **Input validation**: `keyword_too_long`

### Health Check (`health.php`)

Returns JSON status for monitoring:

```json
{
  "status": "ok | error | install_required",
  "version": "1.9.2",
  "installed": true | false,
  "db": "ok | error",
  "flags": {
    "safe_mode": false,
    "degraded_stats": false,
    "debug": true,
    "log_level": "warning",
    "unknown_keyword_behavior": "home"
  },
  "mode": "normal | degraded"
}
```

### Admin Status Page (`admin/status.php`)

Human-friendly dashboard showing:

- DB connection
- Install state
- Upgrade needed?
- Options table health
- Runtime flags (safe mode, degraded stats, debug, log level, unknown keyword behavior)

---

## Design Patterns

### Singleton-ish DB Connection

`yourls_get_db()` returns a global `$ydb` instance. Not a true singleton (no private constructor), but effectively one instance per request.

### Filter-based Extension

Core behavior is modified via filters, not subclassing. Examples:

- `add_new_link`: Intercept URL creation
- `redirect_location`: Modify redirect destination
- `shunt_*`: Bypass core entirely

This is flexible but can lead to unexpected behavior if plugins conflict.

### Lazy Loading

- Options are loaded once and cached
- Plugins are loaded once during bootstrap
- DB connection is established once

### Graceful Degradation

**New patterns:**

- `YOURLS_DEGRADED_STATS`: Skip writes, keep redirects fast
- `YOURLS_SAFE_MODE`: Disable plugins, keep core functional
- Input limits: Reject bad requests early, log for diagnostics

### Centralized Unknown Keyword Handler

**New in 1.9.2+:**

`yourls_handle_unknown_keyword($keyword, $context)` consolidates fallback logic:

- Fires existing hooks (`redirect_keyword_not_found`, `loader_failed`)
- Logs event (`unknown_keyword`)
- Routes to configured behavior (`home` / `404` / `custom`)

Eliminates duplication and makes behavior tunable.

---

## Summary

YOURLS architecture is:

- **Entry-point driven**: Different controllers for different request types
- **Bootstrap-centric**: All paths converge on `load-yourls.php`
- **Hook-based extension**: Plugins modify behavior via actions/filters
- **Lazy-loaded**: DB, options, plugins loaded once and cached
- **Filter-heavy**: Core behavior is overrideable at many points
- **Resilient** (1.9.2+): Safe mode, degraded stats, structured logging, configurable fallback behavior

For operational guidance, see `YOURLS-OPERATIONS-GUIDE.md`.  
For stability features, see `YOURLS-STABILITY-FEATURES.md`.

---

**EOF**
