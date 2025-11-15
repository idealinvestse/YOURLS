# YOURLS Stability & Resilience Features

**Version:** 1.9.2+  
**Last Updated:** November 2025

This document describes the stability, resilience, and observability features added to YOURLS to improve production reliability, operational visibility, and graceful degradation under load.

---

## Table of Contents

1. [Overview](#overview)
2. [Logging & Observability](#logging--observability)
3. [Degraded Stats Mode](#degraded-stats-mode)
4. [Plugin Safe Mode](#plugin-safe-mode)
5. [Unknown Keyword Behavior](#unknown-keyword-behavior)
6. [Input Length Limits](#input-length-limits)
7. [Health Check Endpoint](#health-check-endpoint)
8. [Admin Status Page](#admin-status-page)
9. [Configuration Reference](#configuration-reference)
10. [Operational Scenarios](#operational-scenarios)

---

## Overview

YOURLS now includes opt-in features designed for:

- **Observability**: Structured logging with configurable levels and sampling.
- **Resilience**: Safe mode for plugin recovery, degraded stats mode for high load.
- **Control**: Configurable unknown keyword behavior and input limits.
- **Monitoring**: Health check endpoint and detailed admin status page.

All features are **backward compatible**. If you don't configure them, YOURLS behaves exactly as before.

---

## Logging & Observability

### What It Does

The `yourls_log()` function provides structured logging to PHP's `error_log` when `YOURLS_DEBUG` is enabled. Log entries include:

- **Level**: `error`, `warning`, `info`, `debug`
- **Event name**: e.g., `unknown_keyword`, `auth_failure`, `plugin_load_error`
- **Context**: JSON-encoded array with relevant details (IP, keyword, etc.)

**Format:**
```
[YOURLS] [warning] unknown_keyword {"keyword":"test123","context":"go"}
[YOURLS] [error] plugin_load_error {"plugin":"bad-plugin.php","error":"Parse error"}
```

### Configuration

```php
// Enable debug mode (required for logging to work)
define( 'YOURLS_DEBUG', true );

// Set minimum log level (optional, default: 'warning')
// Valid values: 'error', 'warning', 'info', 'debug'
define( 'YOURLS_LOG_LEVEL', 'warning' );

// Sample noisy events to reduce I/O (optional, default: 1 = log all)
// Example: log only 1 in 10 unknown_keyword events
define( 'YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD', 10 );

// Example: log only 1 in 5 auth failures
define( 'YOURLS_LOG_SAMPLE_AUTH_FAILURE', 5 );
```

### Events Logged

- **Unknown keywords**: When a short URL is not found (`unknown_keyword`)
- **Auth events**: Login success/failure (`auth_success`, `auth_failure`)
- **Plugin events**: Load/activate/deactivate errors (`plugin_load_error`, etc.)
- **API events**: Unknown or malformed API actions (`api_unknown_action`)
- **DB events**: Connection failures, missing config (`db_connection_error`, etc.)
- **Options events**: Empty or missing options table (`options_empty_or_missing`)
- **Input validation**: Keyword/URL too long (`keyword_too_long`, `api_url_too_long`)

### Level Guidelines

- **`error`**: Critical failures (DB down, plugin crash)
- **`warning`**: Recoverable issues (unknown keyword, auth failure)
- **`info`**: Normal operational events (plugin loaded, install check)
- **`debug`**: Verbose diagnostic info

### Production Use

**Recommended settings for production with logging:**

```php
define( 'YOURLS_DEBUG', true );
define( 'YOURLS_LOG_LEVEL', 'warning' );  // Only warnings and errors
define( 'YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD', 100 );  // Log 1%
define( 'YOURLS_LOG_SAMPLE_AUTH_FAILURE', 10 );      // Log 10%
```

This keeps logs useful without overwhelming `error_log`.

---

## Degraded Stats Mode

### What It Does

When enabled, `YOURLS_DEGRADED_STATS` **skips all stats writes** (click counts and redirect logs) while **preserving correct redirect behavior**. Use this during:

- Traffic spikes
- DB performance issues
- Capacity constraints

### Configuration

```php
// Enable degraded stats mode (optional, default: not defined / false)
define( 'YOURLS_DEGRADED_STATS', true );
```

### Behavior

When `YOURLS_DEGRADED_STATS` is `true`:

- **Click count updates** (`yourls_update_clicks`) are skipped (returns success without DB write).
- **Stats logging** (`yourls_log_redirect`) is disabled (no INSERT into log table).
- **Redirects still work** normally (users are sent to the correct long URL).
- **Short URL creation** still works (API and admin still function).

### When to Use

- **During an incident**: DB is slow or under heavy write load.
- **Planned maintenance**: Temporarily reduce write pressure.
- **High-traffic events**: Keep redirects fast, sacrifice stats temporarily.

**Note**: Existing `YOURLS_NOSTATS` still works. `YOURLS_DEGRADED_STATS` is checked *in addition*.

---

## Plugin Safe Mode

### What It Does

`YOURLS_SAFE_MODE` **disables all plugins** at bootstrap time, allowing you to:

- Recover from a broken plugin
- Diagnose plugin conflicts
- Access the admin area when plugins are causing errors

### Configuration

```php
// Enable safe mode (optional, default: not defined / false)
define( 'YOURLS_SAFE_MODE', true );
```

### Behavior

When `YOURLS_SAFE_MODE` is `true`:

- **No plugins are loaded** (the plugin directory is skipped entirely).
- Admin area is accessible without plugin interference.
- Short URLs still redirect normally (core functionality unaffected).
- API still works (core actions available).

### When to Use

- A plugin is causing fatal errors or crashes.
- You need to deactivate a problematic plugin via the admin UI.
- Diagnosing performance or compatibility issues.

**Recovery workflow:**

1. Add `define( 'YOURLS_SAFE_MODE', true );` to `user/config.php`.
2. Access admin area.
3. Deactivate the broken plugin.
4. Remove or comment out the `YOURLS_SAFE_MODE` line.
5. Reload and verify plugins work.

---

## Unknown Keyword Behavior

### What It Does

Controls what happens when a user requests a short URL that doesn't exist. Previously, YOURLS always performed a `302` redirect to `YOURLS_SITE`. Now you can choose:

- **`home`** (default): 302 redirect to `YOURLS_SITE` (original behavior)
- **`404`**: Return HTTP 404 with a "URL not found" page
- **`custom`**: Redirect to a custom URL (e.g., your own 404 page)

### Configuration

```php
// Unknown keyword behavior (optional, default: 'home')
// Valid values: 'home', '404', 'custom'
define( 'YOURLS_UNKNOWN_KEYWORD_BEHAVIOR', '404' );

// If using 'custom', specify the destination URL
define( 'YOURLS_UNKNOWN_KEYWORD_URL', 'https://example.com/not-found' );
```

### Examples

**Return a 404:**
```php
define( 'YOURLS_UNKNOWN_KEYWORD_BEHAVIOR', '404' );
```

**Redirect to a custom error page:**
```php
define( 'YOURLS_UNKNOWN_KEYWORD_BEHAVIOR', 'custom' );
define( 'YOURLS_UNKNOWN_KEYWORD_URL', 'https://yoursite.com/oops' );
```

**Keep default (redirect home):**
```php
// No configuration needed, or explicitly:
define( 'YOURLS_UNKNOWN_KEYWORD_BEHAVIOR', 'home' );
```

### Logging

All unknown keywords are logged (if `YOURLS_DEBUG` is enabled) as:
```
[YOURLS] [warning] unknown_keyword {"keyword":"badlink","context":"go"}
```

Use sampling (`YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD`) to control volume.

---

## Input Length Limits

### What It Does

Protects against pathological or malicious requests by enforcing maximum lengths on:

- **Keywords** (in `yourls-go.php` and API)
- **URLs** (in API shorten requests)

Requests exceeding limits are:

- **In `yourls-go.php`**: Treated as unknown keywords (logged and handled via `yourls_handle_unknown_keyword`)
- **In API**: Rejected with `414` error code and structured error message

### Configuration

```php
// Maximum keyword length (optional, default: 128)
// Set to 0 or negative to disable check
define( 'YOURLS_MAX_KEYWORD_LENGTH', 128 );

// Maximum URL length for API shorten (optional, default: 2048)
define( 'YOURLS_MAX_URL_LENGTH', 2048 );
```

### Behavior

**In `yourls-go.php` (public redirect):**

- After sanitizing the keyword, length is checked.
- If too long:
  - Logged as `keyword_too_long`
  - Routed through `yourls_handle_unknown_keyword()` (respects `YOURLS_UNKNOWN_KEYWORD_BEHAVIOR`)

**In `yourls-api.php` (API shorten):**

- Before processing `action=shorturl`:
  - `url` parameter checked against `YOURLS_MAX_URL_LENGTH`
  - `keyword` parameter checked against `YOURLS_MAX_KEYWORD_LENGTH`
- If too long:
  - Logged as `api_url_too_long` or `api_keyword_too_long`
  - Returns API error:
    ```json
    {
      "errorCode": "414",
      "message": "URL too long",
      "simple": "URL too long"
    }
    ```

### When to Adjust

- **Increase limits** if you legitimately need longer URLs or keywords.
- **Decrease limits** for stricter validation.
- **Set to 0** to disable checks entirely (not recommended).

---

## Health Check Endpoint

### What It Does

`health.php` provides a **JSON endpoint** for external monitoring systems. It reports:

- DB connectivity
- Install state
- Runtime flags (safe mode, degraded stats, debug, log level, unknown keyword behavior)
- Overall mode (`normal` or `degraded`)

### Usage

**Request:**
```
GET https://your-short-domain.com/health.php
```

**Response (healthy):**
```json
{
  "status": "ok",
  "version": "1.9.2",
  "installed": true,
  "db": "ok",
  "flags": {
    "safe_mode": false,
    "degraded_stats": false,
    "debug": true,
    "log_level": "warning",
    "unknown_keyword_behavior": "home"
  },
  "mode": "normal"
}
```

**Response (degraded - DB down):**
```json
{
  "status": "error",
  "version": "1.9.2",
  "installed": null,
  "db": "error",
  "error": "db_unreachable",
  "flags": { ... },
  "mode": "degraded"
}
```

**HTTP Status Codes:**

- `200 OK`: System healthy
- `503 Service Unavailable`: DB unreachable or install required

### Integration

Use this endpoint with:

- **Uptime monitors**: Pingdom, UptimeRobot, etc.
- **APM tools**: New Relic, Datadog, etc.
- **Load balancers**: Health checks for multi-instance deployments
- **CI/CD pipelines**: Post-deployment verification

**Example cURL check:**
```bash
curl -s https://sho.rt/health.php | jq '.status'
```

---

## Admin Status Page

### What It Does

`admin/status.php` provides a **human-friendly status dashboard** in the YOURLS admin area. It shows:

- DB connection status
- Install state
- Database schema (upgrade needed?)
- Options table state
- Runtime flags (safe mode, degraded stats, debug/log level, unknown keyword behavior)

### Access

Navigate to:
```
https://your-short-domain.com/admin/status.php
```

Requires authentication (respects `YOURLS_PRIVATE` setting).

### What You'll See

**Table columns:**

- **Check**: What is being tested
- **Status**: OK (green) or Problem (red)
- **Details**: Additional information

**Checks:**

1. **Database connection**: Can YOURLS reach the DB?
2. **YOURLS installed**: Are options present?
3. **Database schema up to date**: Does the DB need an upgrade?
4. **Options table**: Can options be loaded?
5. **Plugin safe mode**: Is `YOURLS_SAFE_MODE` enabled?
6. **Degraded stats mode**: Is `YOURLS_DEGRADED_STATS` enabled?
7. **Debug & log level**: Is debug on? What log level?
8. **Unknown keyword behavior**: Current setting (`home` / `404` / `custom`)

### Use Cases

- **Post-install verification**: Confirm everything is configured correctly.
- **Pre-upgrade check**: Verify DB state before upgrading.
- **Troubleshooting**: Quick overview of system health and config.
- **Auditing runtime flags**: See at a glance if safe mode or degraded stats is on.

---

## Configuration Reference

Quick reference for all stability-related constants:

```php
//
// Logging & Observability
//

// Enable debug mode (required for yourls_log to work)
define( 'YOURLS_DEBUG', true );

// Minimum log level ('error', 'warning', 'info', 'debug')
// Default: 'warning'
define( 'YOURLS_LOG_LEVEL', 'warning' );

// Sample noisy events (1 = log all, 10 = ~10%, 100 = ~1%)
define( 'YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD', 100 );
define( 'YOURLS_LOG_SAMPLE_AUTH_FAILURE', 10 );

//
// Degraded Stats Mode
//

// Skip click count and stats logging (redirects still work)
// Default: false (not defined)
define( 'YOURLS_DEGRADED_STATS', true );

//
// Plugin Safe Mode
//

// Disable all plugins temporarily
// Default: false (not defined)
define( 'YOURLS_SAFE_MODE', true );

//
// Unknown Keyword Behavior
//

// What to do when a short URL is not found
// Values: 'home' (default), '404', 'custom'
define( 'YOURLS_UNKNOWN_KEYWORD_BEHAVIOR', '404' );

// If behavior is 'custom', specify the redirect destination
define( 'YOURLS_UNKNOWN_KEYWORD_URL', 'https://example.com/not-found' );

//
// Input Length Limits
//

// Maximum keyword length (go + API)
// Default: 128, set to 0 to disable
define( 'YOURLS_MAX_KEYWORD_LENGTH', 128 );

// Maximum URL length (API shorten requests)
// Default: 2048, set to 0 to disable
define( 'YOURLS_MAX_URL_LENGTH', 2048 );
```

---

## Operational Scenarios

### Scenario 1: Responding to a Traffic Spike

**Problem**: Unexpected traffic spike causing DB write contention and slow redirects.

**Solution:**

1. Enable degraded stats mode:
   ```php
   define( 'YOURLS_DEGRADED_STATS', true );
   ```

2. Verify redirects are fast:
   ```bash
   time curl -I https://sho.rt/abc123
   ```

3. Monitor via health endpoint:
   ```bash
   curl -s https://sho.rt/health.php | jq '.mode'
   # Should return "degraded"
   ```

4. After traffic normalizes, disable degraded stats and verify logs/stats resume.

---

### Scenario 2: Recovering from a Bad Plugin

**Problem**: A plugin is causing fatal errors, admin area is inaccessible.

**Solution:**

1. Add to `user/config.php`:
   ```php
   define( 'YOURLS_SAFE_MODE', true );
   ```

2. Access `admin/` and deactivate the problematic plugin.

3. Remove `YOURLS_SAFE_MODE` from config.

4. Reload and verify plugins work.

---

### Scenario 3: Production Logging Setup

**Goal**: Keep logs useful without overwhelming `error_log`.

**Solution:**

```php
// Enable debug and set conservative log level
define( 'YOURLS_DEBUG', true );
define( 'YOURLS_LOG_LEVEL', 'warning' );  // Only warnings and errors

// Sample very noisy events
define( 'YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD', 100 );  // 1%
define( 'YOURLS_LOG_SAMPLE_AUTH_FAILURE', 10 );      // 10%
```

Monitor logs:
```bash
tail -f /var/log/php_errors.log | grep YOURLS
```

---

### Scenario 4: Monitoring with External Tools

**Goal**: Integrate YOURLS health checks into your monitoring stack.

**Solution:**

1. Configure your monitoring tool to check `https://sho.rt/health.php` every minute.

2. Alert on:
   - HTTP status `!= 200`
   - `"status": "error"`
   - `"mode": "degraded"`

3. Dashboard the `flags` object to track runtime config changes.

---

## Summary

These features transform YOURLS from a basic URL shortener into a production-ready service with:

- **Observability**: Structured logging with fine-grained control.
- **Resilience**: Graceful degradation and recovery mechanisms.
- **Control**: Configurable behavior for edge cases and validation.
- **Monitoring**: Programmatic and human-friendly status interfaces.

All features are **opt-in and backward compatible**. Start with defaults and tune as your operational needs evolve.

For architecture details, see `YOURLS-ARCHITECTURE.md`.  
For production operations, see `YOURLS-OPERATIONS-GUIDE.md`.

---

**EOF**
